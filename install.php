<?php
/**
 * 安装向导：生成 config.php、建表、导入演示数据
 * 安装完成后请务必删除本文件
 */
// 安装锁定：安装完成后会生成 install.lock，避免被再次执行而清空数据
if (is_file(__DIR__ . '/install.lock')) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif">安装已完成并锁定。如需重新安装，请先删除 install.lock（并备份好数据），或直接删除 install.php。</p>';
    exit;
}

$errors = [];
$done = false;
$log = [];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');

$alreadyInstalled = is_file(__DIR__ . '/config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = [
        'db_host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
        'db_port' => trim((string) ($_POST['db_port'] ?? '3306')),
        'db_name' => trim((string) ($_POST['db_name'] ?? '')),
        'db_user' => trim((string) ($_POST['db_user'] ?? '')),
        'db_pass' => (string) ($_POST['db_pass'] ?? ''),
        'db_charset' => 'utf8mb4',
        'table_prefix' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['table_prefix'] ?? 'hz_')) ?: 'hz_',
        'session_days' => 30,
    ];
    $withSeed = !empty($_POST['with_seed']);

    // 管理员账号：必填。若留空且未导入演示数据，库里将没有任何用户，
    // 而「设为管理员」按钮又只有管理员能点 —— 会变成永远进不去后台的死循环。
    $admin = [
        'email' => strtolower(trim((string) ($_POST['admin_email'] ?? ''))),
        'nickname' => trim((string) ($_POST['admin_nickname'] ?? '')),
        'pass' => (string) ($_POST['admin_pass'] ?? ''),
    ];

    if ($cfg['db_name'] === '' || $cfg['db_user'] === '') {
        $errors[] = '请填写数据库名和用户名';
    }
    if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = '请填写有效的管理员邮箱（登录用）';
    }
    if (strlen($admin['pass']) < 6) {
        $errors[] = '管理员密码至少 6 位';
    }
    if ($admin['nickname'] === '') {
        $admin['nickname'] = '管理员';
    }
    if (function_exists('mb_strlen') ? mb_strlen($admin['nickname'], 'UTF-8') > 48 : strlen($admin['nickname']) > 48) {
        $errors[] = '管理员昵称不能超过 48 个字符';
    }

    if (!$errors) {
        try {
            // 1. 连接服务器并建库（虚拟主机可能不允许建库，失败则继续）
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']),
                $cfg['db_user'],
                $cfg['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $log[] = '已连接到 MySQL 服务器';
            try {
                $pdo->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $cfg['db_name'])
                ));
                $log[] = '数据库已就绪：' . $cfg['db_name'];
            } catch (PDOException $e) {
                $log[] = '提示：无法自动建库（' . $e->getMessage() . '），请确认数据库已存在';
            }

            // 2. 写配置文件（含防直接访问保护）
            $content = "<?php\n"
                . "// 由安装向导生成，可手动修改\n"
                . "if (basename((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === 'config.php') { http_response_code(403); exit; }\n"
                . "return " . var_export($cfg, true) . ";\n";
            if (file_put_contents(__DIR__ . '/config.php', $content) === false) {
                throw new RuntimeException('无法写入 config.php，请检查目录权限');
            }
            $log[] = '已写入 config.php';

            // 3. 建表
            $pdo->exec('USE `' . str_replace('`', '', $cfg['db_name']) . '`');
            $schemaFile = __DIR__ . '/schema.sql';
            if (!is_file($schemaFile)) {
                throw new RuntimeException('缺少 schema.sql');
            }
            $sql = str_replace('{{PREFIX}}', $cfg['table_prefix'], file_get_contents($schemaFile));
            $count = 0;
            foreach (explode(';', $sql) as $statement) {
                // 去掉整行注释后再判断是否为空
                $statement = trim(preg_replace('/^\s*--.*$/m', '', (string) $statement));
                if ($statement === '') {
                    continue;
                }
                $pdo->exec($statement);
                $count++;
            }
            $log[] = "数据表创建完成（{$count} 张）";

            // 4. 演示数据
            if ($withSeed) {
                $seedFile = __DIR__ . '/modules/seed.json';
                $data = json_decode(is_file($seedFile) ? file_get_contents($seedFile) : '[]', true);
                if (!$data) {
                    $log[] = '提示：未找到演示数据，跳过';
                } else {
                    foreach (['likes', 'comments', 'reviews', 'posts', 'courses', 'sessions', 'users'] as $tb) {
                        $pdo->exec('DELETE FROM `' . $cfg['table_prefix'] . $tb . '`');
                    }
                    foreach ($data['users'] as $u) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO `' . $cfg['table_prefix'] . 'users`
                             (id, email, nickname, password_hash, student_id, grade, bio, avatar_color, is_admin, joined_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?)'
                        );
                        $stmt->execute([
                            $u['id'], $u['email'], $u['nickname'], password_hash($u['password'], PASSWORD_DEFAULT),
                            $u['student_id'], $u['grade'], $u['bio'], $u['avatar_color'], $u['is_admin'], $u['joined_at'],
                        ]);
                    }
                    foreach ($data['courses'] as $c) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO `' . $cfg['table_prefix'] . 'courses`
                             (id, name, teacher, code, credit, category, college, intro, created_by, created_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        $stmt->execute([
                            $c['id'], $c['name'], $c['teacher'], $c['code'], $c['credit'], $c['category'],
                            $c['college'], $c['intro'], $c['created_by'], $c['created_at'], $c['updated_at'],
                        ]);
                    }
                    foreach ($data['reviews'] as $r) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO `' . $cfg['table_prefix'] . 'reviews`
                             (id, course_id, user_id, rating, title, content, tags, take_again, anonymous, semester, created_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        $stmt->execute([
                            $r['id'], $r['course_id'], $r['user_id'], $r['rating'], $r['title'], $r['content'],
                            json_encode($r['tags'], JSON_UNESCAPED_UNICODE), $r['take_again'], $r['anonymous'],
                            $r['semester'], $r['created_at'], $r['updated_at'],
                        ]);
                    }
                    foreach ($data['posts'] as $p) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO `' . $cfg['table_prefix'] . 'posts`
                             (id, type, course_id, user_id, title, content, tags, pinned, created_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?)'
                        );
                        $stmt->execute([
                            $p['id'], $p['type'], $p['course_id'], $p['user_id'], $p['title'], $p['content'],
                            json_encode($p['tags'], JSON_UNESCAPED_UNICODE), $p['pinned'], $p['created_at'], $p['updated_at'],
                        ]);
                    }
                    foreach ($data['comments'] as $c) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO `' . $cfg['table_prefix'] . 'comments` (id, post_id, user_id, content, created_at) VALUES (?,?,?,?,?)'
                        );
                        $stmt->execute([$c['id'], $c['post_id'], $c['user_id'], $c['content'], $c['created_at']]);
                    }
                    foreach ($data['likes'] as $l) {
                        $stmt = $pdo->prepare(
                            'INSERT IGNORE INTO `' . $cfg['table_prefix'] . 'likes` (id, target_type, target_id, user_id, created_at) VALUES (?,?,?,?,?)'
                        );
                        $stmt->execute([$l['id'], $l['target_type'], $l['target_id'], $l['user_id'], $l['created_at']]);
                    }
                    $log[] = '演示数据已导入：' . count($data['courses']) . ' 门课程 / ' . count($data['reviews']) . ' 条短评 / ' . count($data['posts']) . ' 个话题';
                }
            }

            // 5. 管理员账号（放在演示数据之后，避免被 seed 的 DELETE 清掉）
            $nowMs = (int) round(microtime(true) * 1000);
            $tableUsers = '`' . $cfg['table_prefix'] . 'users`';

            // 演示数据里的管理员 verified 默认为 0，会被「未认证不能发帖/评分」拦住，
            // 这里统一给所有管理员补上认证状态。
            $pdo->prepare("UPDATE $tableUsers SET verified = 1, verified_at = ? WHERE is_admin = 1 AND verified = 0")
                ->execute([$nowMs]);

            $st = $pdo->prepare("SELECT id FROM $tableUsers WHERE email = ?");
            $st->execute([$admin['email']]);
            $existId = $st->fetchColumn();

            if ($existId) {
                $pdo->prepare("UPDATE $tableUsers SET is_admin = 1, verified = 1, verified_at = ?, banned = 0 WHERE id = ?")
                    ->execute([$nowMs, $existId]);
                $log[] = '已把 ' . $admin['email'] . ' 设为管理员（已认证）';
            } else {
                // 昵称是唯一键，重复会让安装失败，这里提前给出明确提示
                $stNick = $pdo->prepare("SELECT id FROM $tableUsers WHERE nickname = ?");
                $stNick->execute([$admin['nickname']]);
                if ($stNick->fetchColumn()) {
                    throw new RuntimeException('昵称「' . $admin['nickname'] . '」已被占用（演示数据里有同昵称账号），请换一个管理员昵称');
                }
                $newId = 'u_' . date('ymdHis') . bin2hex(random_bytes(5));
                $pdo->prepare(
                    "INSERT INTO $tableUsers
                     (id, email, nickname, password_hash, avatar_color, is_admin, verified, verified_at, joined_at)
                     VALUES (?,?,?,?,?,1,1,?,?)"
                )->execute([
                    $newId,
                    $admin['email'],
                    $admin['nickname'],
                    password_hash($admin['pass'], PASSWORD_DEFAULT),
                    '#2f7d51',
                    $nowMs,
                    $nowMs,
                ]);
                $log[] = '管理员账号已创建：' . $admin['email'];
            }

            @file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));
            $log[] = '已生成 install.lock，本文件将不再执行';
            $done = true;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>安装 · 华政选课</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f6f2;color:#23262b;margin:0;padding:24px;line-height:1.7}
.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e4e6e0;border-radius:10px;padding:24px}
h1{font-size:20px;margin:0 0 8px}
label{display:block;margin:12px 0 4px;font-size:13px;color:#4a4f56}
input{width:100%;padding:8px 10px;border:1px solid #e4e6e0;border-radius:6px;font-size:14px;box-sizing:border-box}
button{margin-top:18px;width:100%;padding:10px;background:#2f7d51;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer}
.muted{color:#7a7f87;font-size:13px}
.err{background:#fdecec;color:#c04851;padding:10px;border-radius:6px;font-size:13px;margin-bottom:12px}
.ok{background:#eaf3ee;color:#256243;padding:10px;border-radius:6px;font-size:13px;margin-bottom:12px}
ul{font-size:13px;color:#4a4f56;padding-left:20px}
</style>
</head>
<body>
<div class="box">
  <h1>华政选课 · 安装</h1>
  <p class="muted">填写数据库信息，程序会自动建表。需要 PHP 7.4+ 与 MySQL 5.6+/MariaDB 10.1+。</p>

  <?php if ($errors): ?>
    <?php foreach ($errors as $err): ?><div class="err"><?= e($err) ?></div><?php endforeach; ?>
  <?php endif; ?>

  <?php if ($done): ?>
    <div class="ok"><b>安装完成！</b></div>
    <ul>
      <?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
    </ul>
    <p class="muted" style="margin-top:14px">
      接下来：<b>请删除 install.php</b>（安全要求），然后访问 <a href="index.php">index.php</a>，
      用管理员邮箱 <b><?= e($admin['email']) ?></b> 登录后即可在右上角进入管理后台。
    </p>
  <?php else: ?>
    <?php if ($alreadyInstalled): ?>
      <div class="err">检测到已存在 config.php。再次安装会覆盖配置并重置演示数据，请确认后继续。</div>
    <?php endif; ?>
    <form method="post" accept-charset="UTF-8">
      <label>数据库主机</label>
      <input name="db_host" value="localhost" required />
      <label>端口</label>
      <input name="db_port" value="3306" required />
      <label>数据库名</label>
      <input name="db_name" value="huazheng_course" required />
      <label>数据库用户名</label>
      <input name="db_user" required />
      <label>数据库密码</label>
      <input name="db_pass" type="password" />
      <label>表前缀（同库多站点时区分）</label>
      <input name="table_prefix" value="hz_" />
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="with_seed" value="1" checked style="width:auto" /> 导入演示数据（会清空已存在的同名表数据）
      </label>

      <hr style="border:none;border-top:1px solid #e4e6e0;margin:18px 0" />
      <p class="muted" style="margin:0 0 4px">
        <b>管理员账号（必填）</b><br />
        这是站点的第一个管理员。<b>不导入演示数据时，这是唯一的账号</b>——没有它你将无法进入后台。
      </p>
      <label>管理员邮箱（登录用）</label>
      <input name="admin_email" type="email" placeholder="you@example.com" required />
      <label>管理员昵称</label>
      <input name="admin_nickname" placeholder="管理员" />
      <p class="muted" style="margin:2px 0 0;font-size:12px">导入演示数据时请勿使用「选课小助手」，该昵称已被演示账号占用。</p>
      <label>管理员密码（至少 6 位）</label>
      <input name="admin_pass" type="password" minlength="6" required />

      <button type="submit">开始安装</button>
    </form>
    <p class="muted" style="margin-top:14px">也可以手动导入 schema.sql（把 {{PREFIX}} 换成你的表前缀），再自行创建 config.php。</p>
  <?php endif; ?>
</div>
</body>
</html>
