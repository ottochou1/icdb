<?php
/**
 * 数据库升级：学生认证 + 站内信 + 通知
 *
 * 用法：
 *   命令行  php upgrade.php          （直接执行，推荐）
 *   浏览器  http://站点/upgrade.php   （需管理员登录）
 *
 * 幂等：已存在的字段/表会自动跳过，可重复执行。
 */

require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
$log = [];

function upgrade_table($name)
{
    $cfg = app_config();
    return $cfg['table_prefix'] . $name;
}

function table_exists($table)
{
    return (bool) q_val(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
}

function column_exists($table, $column)
{
    return (bool) q_val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
}

function add_column_if_missing($table, $column, $definition)
{
    if (column_exists($table, $column)) {
        return false;
    }
    db()->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $column . ' ' . $definition);
    return true;
}

function run_step($label, callable $fn)
{
    global $log;
    try {
        $changed = $fn();
        $log[] = [$changed ? '+' : '=', $label, $changed ? '已添加/更新' : '已存在，跳过'];
    } catch (Throwable $e) {
        $log[] = ['!', $label, '失败：' . $e->getMessage()];
    }
}

/* ------------------------- 执行 ------------------------- */

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $me = current_user();
        if (!$me || empty($me['is_admin'])) {
            http_response_code(403);
            echo "需要管理员登录后才能执行升级。\n";
            echo "命令行方式：php upgrade.php\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo '无法读取配置：' . $e->getMessage() . "\n";
        exit;
    }
}

$users = upgrade_table('users');
$settings = upgrade_table('settings');
$verifications = upgrade_table('verifications');
$notifications = upgrade_table('notifications');
$conversations = upgrade_table('conversations');
$messages = upgrade_table('messages');
$courses = upgrade_table('courses');
$courseCodes = upgrade_table('course_codes');

run_step('users.can_upload_courses（课程上传授权）', function () use ($users) {
    return add_column_if_missing($users, 'can_upload_courses', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin");
});

run_step('表 settings（站点设置）', function () use ($settings) {
    if (table_exists($settings)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $settings . '` (
            setting_key VARCHAR(64) NOT NULL,
            setting_value TEXT NOT NULL,
            updated_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

run_step('users.verified（是否已通过学生认证）', function () use ($users) {
    return add_column_if_missing($users, 'verified', "TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin");
});
run_step('users.verified_at（认证通过时间）', function () use ($users) {
    return add_column_if_missing($users, 'verified_at', "BIGINT NOT NULL DEFAULT 0 AFTER verified");
});
run_step('users.banned（禁言）', function () use ($users) {
    return add_column_if_missing($users, 'banned', "TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_at");
});
run_step('users.ban_reason（禁言原因）', function () use ($users) {
    return add_column_if_missing($users, 'ban_reason', "VARCHAR(200) NOT NULL DEFAULT '' AFTER banned");
});

run_step('表 verifications（认证申请）', function () use ($verifications, $users) {
    if (table_exists($verifications)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $verifications . '` (
            id VARCHAR(32) NOT NULL,
            user_id VARCHAR(24) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            file_name VARCHAR(200) NOT NULL DEFAULT \'\',
            file_ext VARCHAR(16) NOT NULL DEFAULT \'\',
            file_size INT NOT NULL DEFAULT 0,
            file_path VARCHAR(255) NOT NULL DEFAULT \'\',
            note VARCHAR(500) NOT NULL DEFAULT \'\',
            admin_note VARCHAR(500) NOT NULL DEFAULT \'\',
            reviewer_id VARCHAR(24) NOT NULL DEFAULT \'\',
            created_at BIGINT NOT NULL DEFAULT 0,
            reviewed_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_user (user_id),
            CONSTRAINT fk_ver_user FOREIGN KEY (user_id) REFERENCES `' . $users . '` (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

run_step('表 notifications（系统通知）', function () use ($notifications, $users) {
    if (table_exists($notifications)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $notifications . '` (
            id VARCHAR(32) NOT NULL,
            user_id VARCHAR(24) NOT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT \'system\',
            title VARCHAR(120) NOT NULL DEFAULT \'\',
            content VARCHAR(2000) NOT NULL,
            link VARCHAR(200) NOT NULL DEFAULT \'\',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_read (is_read),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES `' . $users . '` (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

run_step('表 conversations（私信会话）', function () use ($conversations, $users) {
    if (table_exists($conversations)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $conversations . '` (
            id VARCHAR(32) NOT NULL,
            user1_id VARCHAR(24) NOT NULL,
            user2_id VARCHAR(24) NOT NULL,
            last_message VARCHAR(300) NOT NULL DEFAULT \'\',
            last_sender_id VARCHAR(24) NOT NULL DEFAULT \'\',
            last_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_pair (user1_id, user2_id),
            KEY idx_u1 (user1_id),
            KEY idx_u2 (user2_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

run_step('表 messages（私信消息）', function () use ($messages, $conversations, $users) {
    if (table_exists($messages)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $messages . '` (
            id VARCHAR(32) NOT NULL,
            conversation_id VARCHAR(24) NOT NULL,
            sender_id VARCHAR(24) NOT NULL,
            content VARCHAR(2000) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_conv (conversation_id),
            KEY idx_sender (sender_id),
            CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES `' . $conversations . '` (id) ON DELETE CASCADE,
            CONSTRAINT fk_msg_user FOREIGN KEY (sender_id) REFERENCES `' . $users . '` (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

/* 上传目录与访问控制 */
run_step('上传目录 uploads/verify', function () {
    $dir = __DIR__ . '/uploads/verify';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\nphp_flag engine off\n");
    @file_put_contents($dir . '/index.html', '');
    @file_put_contents(__DIR__ . '/uploads/index.html', '');
    return is_dir($dir);
});

/* 已存在的旧表：把 id 扩容到 32，避免 new_id() 生成的 25 位主键被截断 */
run_step('新表主键扩容至 VARCHAR(32)', function () use ($conversations, $messages, $verifications, $notifications) {
    $changed = false;
    $idLen = function ($table) {
        return (int) q_val(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'id']
        );
    };

    // conversations 与 messages.conversation_id 有外键，需先摘掉约束再同步扩容
    if (table_exists($conversations) && $idLen($conversations) < 32) {
        $fk = q_val(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$messages, 'conversation_id']
        );
        if ($fk) {
            try {
                db()->exec('ALTER TABLE `' . $messages . '` DROP FOREIGN KEY `' . $fk . '`');
            } catch (Throwable $e) {
                // 约束可能已不存在，忽略
            }
        }
        db()->exec('ALTER TABLE `' . $conversations . '` MODIFY id VARCHAR(32) NOT NULL');
        db()->exec('ALTER TABLE `' . $messages . '` MODIFY conversation_id VARCHAR(32) NOT NULL');
        try {
            db()->exec(
                'ALTER TABLE `' . $messages . '` ADD CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
                 REFERENCES `' . $conversations . '` (id) ON DELETE CASCADE'
            );
        } catch (Throwable $e) {
            // 已存在则忽略
        }
        $changed = true;
    }

    foreach ([$verifications, $notifications, $messages] as $table) {
        if (table_exists($table) && $idLen($table) < 32) {
            db()->exec('ALTER TABLE `' . $table . '` MODIFY id VARCHAR(32) NOT NULL');
            $changed = true;
        }
    }
    return $changed;
});

/* 管理员默认视为已认证 */
run_step('管理员账号自动标记为已认证', function () use ($users) {
    $n = q('UPDATE `' . $users . '` SET verified = 1, verified_at = joined_at WHERE is_admin = 1 AND verified = 0')->rowCount();
    return $n > 0;
});

/* ------------------------- 教务表格字段 ------------------------- */

run_step('courses.exam_mode（考核方式）', function () use ($courses) {
    return add_column_if_missing($courses, 'exam_mode', "VARCHAR(20) NOT NULL DEFAULT '' AFTER category");
});
run_step('courses.language（授课语言）', function () use ($courses) {
    return add_column_if_missing($courses, 'language', "VARCHAR(20) NOT NULL DEFAULT '' AFTER exam_mode");
});

/* 同一门课（同名同教师）可能对应多个课程代码，单独建表以便按任一代码检索 */
run_step('表 course_codes（课程的多个课程代码）', function () use ($courses, $courseCodes) {
    if (table_exists($courseCodes)) {
        return false;
    }
    db()->exec(
        'CREATE TABLE `' . $courseCodes . '` (
            `course_id` varchar(32) NOT NULL,
            `code` varchar(32) NOT NULL,
            PRIMARY KEY (`course_id`, `code`),
            KEY `idx_codes_code` (`code`),
            CONSTRAINT `fk_cc_course` FOREIGN KEY (`course_id`)
                REFERENCES `' . $courses . '` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    return true;
});

/* 把已有课程的 code 补进新表，避免升级后出现「有课程但检索不到」 */
run_step('迁移已有课程代码到 course_codes', function () use ($courses, $courseCodes) {
    if (!table_exists($courseCodes)) {
        return false;
    }
    $rows = q_all('SELECT id, code FROM `' . $courses . "` WHERE code <> ''");
    $n = 0;
    foreach ($rows as $r) {
        $n += q(
            'INSERT IGNORE INTO `' . $courseCodes . '` (course_id, code) VALUES (?, ?)',
            [$r['id'], $r['code']]
        )->rowCount();
    }
    return $n > 0;
});

/* ------------------------- 输出 ------------------------- */

if ($isCli) {
    echo "\n数据库升级：\n";
    foreach ($log as [$mark, $label, $detail]) {
        echo "  {$mark} {$label} — {$detail}\n";
    }
    $errors = array_filter($log, function ($r) { return $r[0] === '!'; });
    echo $errors ? "\n有步骤失败，请检查上面的错误信息。\n" : "\n升级完成。\n";
    exit($errors ? 1 : 0);
}

echo "数据库升级结果：\n\n";
foreach ($log as [$mark, $label, $detail]) {
    echo "  {$mark} {$label} — {$detail}\n";
}
echo "\n升级完成后建议删除或改名 upgrade.php。\n";
