<?php
/** 管理后台：认证审核、用户管理、内容管理 */

function h_admin_stats()
{
    require_admin();
    return [
        'users' => (int) q_val('SELECT COUNT(*) FROM ' . t('users')),
        'verified' => (int) q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE verified = 1 OR is_admin = 1'),
        'pending' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['pending']),
        'approved' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['approved']),
        'rejected' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['rejected']),
        'courses' => (int) q_val('SELECT COUNT(*) FROM ' . t('courses')),
        'reviews' => (int) q_val('SELECT COUNT(*) FROM ' . t('reviews')),
        'posts' => (int) q_val('SELECT COUNT(*) FROM ' . t('posts')),
        'comments' => (int) q_val('SELECT COUNT(*) FROM ' . t('comments')),
        'banned' => (int) q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE banned = 1'),
        'courseUploaders' => (int) q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE can_upload_courses = 1 OR is_admin = 1'),
        'pendingCourseUploads' => (int) q_val('SELECT COUNT(*) FROM ' . t('course_upload_applications') . ' WHERE status = ?', ['pending']),
        'maintenanceMode' => maintenance_mode(),
    ];
}

function h_admin_maintenance()
{
    require_admin();
    return ['maintenanceMode' => maintenance_mode()];
}

function h_admin_maintenance_update()
{
    require_admin();
    $enabled = param_bool('enabled');
    setting_set('maintenance_mode', $enabled ? '1' : '0');
    return ['maintenanceMode' => $enabled];
}

function h_admin_verifications()
{
    require_admin();
    $status = param_str('status', 'pending', 16);
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        $status = 'pending';
    }
    $where = $status === 'all' ? '' : ' WHERE v.status = ?';
    $args = $status === 'all' ? [] : [$status];
    $rows = q_all(
        'SELECT v.* FROM ' . t('verifications') . ' v' . $where . ' ORDER BY v.created_at DESC LIMIT 200',
        $args
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = verification_dto($r, true);
    }
    return [
        'items' => $out,
        'counts' => [
            'pending' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['pending']),
            'approved' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['approved']),
            'rejected' => (int) q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE status = ?', ['rejected']),
        ],
    ];
}

/** 管理员查看证明材料：走 PHP 读取，目录本身禁止直接访问 */
function h_admin_verification_file()
{
    require_admin();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('verifications'), $id, '申请不存在');
    if (empty($row['file_path'])) {
        throw new ApiException('该申请的证明材料已按隐私策略删除', 404);
    }
    $path = verify_upload_dir() . '/' . basename((string) $row['file_path']);
    if (!is_file($path)) {
        throw new ApiException('文件不存在或已被清理', 404);
    }
    $mimes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
    ];
    $ext = strtolower((string) $row['file_ext']);
    $mime = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="proof.' . $ext . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
}

function h_admin_verification_review()
{
    $admin = require_admin();
    $id = param_str('id', '', 40);
    $action = param_str('action', '', 16);
    $note = mb_substr(trim((string) param_raw('note', '')), 0, 400);

    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new ApiException('操作类型不正确');
    }
    $row = find_or_fail(t('verifications'), $id, '申请不存在');
    if ($row['status'] !== 'pending') {
        throw new ApiException('该申请已处理过了');
    }

    $now = now_ms();
    if ($action === 'approve') {
        q('UPDATE ' . t('users') . ' SET verified = 1, verified_at = ? WHERE id = ?', [$now, $row['user_id']]);
        q(
            'UPDATE ' . t('verifications') . ' SET status = ?, admin_note = ?, reviewer_id = ?, reviewed_at = ? WHERE id = ?',
            ['approved', $note, $admin['id'], $now, $id]
        );
        verify_delete_file($row); // 通过后立即删除证明材料，不留存敏感文件
        notify_user(
            $row['user_id'],
            '学生认证已通过',
            '恭喜，你已通过学生认证，现在可以发布课程评价、参与讨论和给短评点赞了。你上传的证明材料已从服务器删除。',
            '#/',
            'verify'
        );
    } else {
        if ($note === '') {
            $note = '材料不清晰或无法确认在校身份';
        }
        q(
            'UPDATE ' . t('verifications') . ' SET status = ?, admin_note = ?, reviewer_id = ?, reviewed_at = ? WHERE id = ?',
            ['rejected', $note, $admin['id'], $now, $id]
        );
        verify_delete_file($row);
        notify_user(
            $row['user_id'],
            '学生认证未通过',
            '很遗憾，本次认证未通过。原因：' . $note . '。你可以重新提交一份清晰的学生证或在读证明。原文件已删除。',
            '#/verify',
            'verify'
        );
    }

    return [
        'ok' => true,
        'application' => verification_dto(find_or_fail(t('verifications'), $id, '申请不存在'), true),
    ];
}

function h_admin_users()
{
    require_admin();
    $keyword = param_str('q', param_str('keyword', '', 40), 40);
    $page = max(1, param_int('page', 1));
    $size = min(50, max(5, param_int('pageSize', 20)));
    $offset = ($page - 1) * $size;

    $where = '';
    $args = [];
    if ($keyword !== '') {
        $like = '%' . like_escape($keyword) . '%';
        $where = ' WHERE nickname LIKE ? OR email LIKE ? OR student_id LIKE ?';
        $args = [$like, $like, $like];
    }
    $total = (int) q_val('SELECT COUNT(*) FROM ' . t('users') . $where, $args);
    $rows = q_all(
        'SELECT * FROM ' . t('users') . $where . ' ORDER BY joined_at DESC LIMIT ' . (int) $size . ' OFFSET ' . (int) $offset,
        $args
    );
    $items = [];
    foreach ($rows as $r) {
        $u = public_user($r, true);
        $u['pendingApplication'] = (bool) q_val(
            'SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE user_id = ? AND status = ?',
            [$r['id'], 'pending']
        );
        $items[] = $u;
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $size];
}

function h_admin_user_update()
{
    $admin = require_admin();
    $id = param_str('id', '', 40);
    $target = find_or_fail(t('users'), $id, '用户不存在');

    $isAdminRaw = param_raw('isAdmin');
    $verifiedRaw = param_raw('verified');
    $bannedRaw = param_raw('banned');
    $canUploadRaw = param_raw('canUploadCourses');
    $banReason = mb_substr(trim((string) param_raw('banReason', '')), 0, 200);

    // param_raw() 对未提交字段返回 null；必须保留 null，不能让 filter_var(null) 误变成 false。
    $optionalBool = function ($raw) {
        return $raw === null ? null : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    };
    $isAdmin = $optionalBool($isAdminRaw);
    $verified = $optionalBool($verifiedRaw);
    $banned = $optionalBool($bannedRaw);
    $canUpload = $optionalBool($canUploadRaw);

    // 不允许把自己降级或封禁，避免后台把自己锁死
    if ($id === $admin['id']) {
        if ($isAdmin === false) {
            throw new ApiException('不能取消自己的管理员权限');
        }
        if ($banned === true) {
            throw new ApiException('不能封禁自己');
        }
    }

    $sets = [];
    $params = [];
    if ($isAdmin !== null) {
        $sets[] = 'is_admin = ?';
        $params[] = $isAdmin ? 1 : 0;
    }
    if ($verified !== null) {
        $sets[] = 'verified = ?';
        $params[] = $verified ? 1 : 0;
        $sets[] = 'verified_at = ?';
        $params[] = $verified ? now_ms() : 0;
    }
    if ($banned !== null) {
        $sets[] = 'banned = ?';
        $params[] = $banned ? 1 : 0;
        $sets[] = 'ban_reason = ?';
        $params[] = $banned ? $banReason : '';
    }
    if ($canUpload !== null) {
        $sets[] = 'can_upload_courses = ?';
        $params[] = $canUpload ? 1 : 0;
    }
    if (!$sets) {
        throw new ApiException('没有需要更新的内容');
    }
    $params[] = $id;
    q('UPDATE ' . t('users') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

    $updated = get_user($id);
    if (!empty($updated['verified'])) {
        // 手动认证：清掉遗留材料
        $app = q_one(
            'SELECT * FROM ' . t('verifications') . ' WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1',
            [$id, 'pending']
        );
        if ($app) {
            verify_delete_file($app);
            q('UPDATE ' . t('verifications') . ' SET status = ?, admin_note = ?, reviewer_id = ?, reviewed_at = ? WHERE id = ?',
                ['approved', '管理员手动认证', $admin['id'], now_ms(), $app['id']]);
        }
    }

    if ($canUpload !== null && empty($updated['is_admin'])) {
        notify_user(
            $id,
            $canUpload ? '已获得课程上传权限' : '课程上传权限已被收回',
            $canUpload
                ? '管理员已允许你添加和批量导入课程。该权限仍需在学生认证有效且账号未被限制时使用。'
                : '管理员已收回你的课程上传权限。已有课程与评价不会受影响。',
            $canUpload ? '#/new-course' : '#/courses',
            'permission'
        );
    }

    return ['user' => public_user(get_user($id), true)];
}

function h_admin_posts()
{
    require_admin();
    $keyword = param_str('q', '', 40);
    $where = '';
    $args = [];
    if ($keyword !== '') {
        $like = '%' . like_escape($keyword) . '%';
        $where = ' WHERE title LIKE ? OR content LIKE ?';
        $args = [$like, $like];
    }
    $rows = q_all(
        'SELECT * FROM ' . t('posts') . $where . ' ORDER BY created_at DESC LIMIT 60',
        $args
    );
    $viewer = current_user();
    return ['items' => array_map(function ($r) use ($viewer) {
        return post_dto($r, $viewer);
    }, $rows)];
}

function h_admin_reviews()
{
    require_admin();
    $keyword = param_str('q', '', 40);
    $where = '';
    $args = [];
    if ($keyword !== '') {
        $like = '%' . like_escape($keyword) . '%';
        $where = ' WHERE title LIKE ? OR content LIKE ?';
        $args = [$like, $like];
    }
    $rows = q_all(
        'SELECT * FROM ' . t('reviews') . $where . ' ORDER BY created_at DESC LIMIT 60',
        $args
    );
    $viewer = current_user();
    return ['items' => array_map(function ($r) use ($viewer) {
        return review_dto($r, $viewer);
    }, $rows)];
}

function h_admin_content_delete()
{
    require_admin();
    $type = param_str('type', '', 16);
    $id = param_str('id', '', 40);
    $map = [
        'post' => 'posts',
        'review' => 'reviews',
        'comment' => 'comments',
        'course' => 'courses',
    ];
    if (!isset($map[$type])) {
        throw new ApiException('内容类型不正确');
    }
    $table = t($map[$type]);
    $row = find_or_fail($table, $id, '内容不存在或已被删除');
    q('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
    q('DELETE FROM ' . t('likes') . ' WHERE target_id = ?', [$id]);

    if ($type === 'post') {
        q('DELETE FROM ' . t('comments') . ' WHERE post_id = ?', [$id]);
    }

    if ($type !== 'course' && !empty($row['user_id'])) {
        $label = $type === 'post' ? '帖子' : ($type === 'review' ? '短评' : '回复');
        notify_user(
            $row['user_id'],
            '你的' . $label . '已被管理员删除',
            '内容标题：' . mb_substr((string) ($row['title'] ?: $row['content']), 0, 60) . '。如有疑问可通过站内信联系管理员。',
            '',
            'moderation'
        );
    }

    return ['ok' => true, 'deleted' => $id];
}

/* ------------------------- 课程管理（管理员） ------------------------- */

/**
 * 后台课程列表：支持关键词（课名 / 老师 / 课程代码 / 开课部门）、类别与部门筛选、排序、分页。
 * 与前台 courses.list 的区别：不要求登录者认证、可以看到全部字段，且返回筛选用的枚举。
 */
function h_admin_courses()
{
    $admin = require_admin();
    $q = param_str('q', '', 60);
    $category = param_str('category', '', 20);
    $college = param_str('college', '', 40);
    $sort = param_str('sort', 'newest', 16);
    $page = param_int('page', 1, 1, 9999);
    $size = param_int('pageSize', 20, 5, 60);

    $where = [];
    $params = [];
    if ($q !== '') {
        $kw = '%' . like_escape($q) . '%';
        $where[] = '(c.name LIKE ? OR c.code LIKE ? OR c.teacher LIKE ? OR c.college LIKE ?
                     OR EXISTS (SELECT 1 FROM ' . t('course_codes') . ' cc WHERE cc.course_id = c.id AND cc.code LIKE ?))';
        $params = array_merge($params, [$kw, $kw, $kw, $kw, $kw]);
    }
    if ($category !== '') {
        $where[] = 'c.category = ?';
        $params[] = $category;
    }
    if ($college !== '') {
        $where[] = 'c.college = ?';
        $params[] = $college;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $orderMap = [
        'newest' => 'c.created_at DESC',
        'oldest' => 'c.created_at ASC',
        'reviews' => 'review_count DESC, c.created_at DESC',
        'rating' => 'avg_rating DESC, review_count DESC',
        'name' => 'c.name ASC',
        'updated' => 'c.updated_at DESC',
    ];
    $order = isset($orderMap[$sort]) ? $orderMap[$sort] : $orderMap['newest'];

    $total = (int) q_val('SELECT COUNT(*) FROM ' . t('courses') . ' c' . $whereSql, $params);
    $offset = ($page - 1) * $size;
    $rows = q_all(
        course_query_sql() . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . (int) $size . ' OFFSET ' . (int) $offset,
        $params
    );

    $config = config_data();
    return [
        'items' => array_map(function ($r) use ($admin) {
            return course_dto($r, $admin);
        }, $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $size,
        'pageCount' => max(1, (int) ceil($total / $size)),
        'categories' => $config['categories'],
        'colleges' => $config['colleges'],
    ];
}

/** 把 codes 参数（数组或「顿号/逗号/分号」分隔的字符串）统一成代码数组 */
function admin_parse_codes($value)
{
    if ($value === null) {
        return null;
    }
    if (is_array($value)) {
        $list = $value;
    } else {
        $list = preg_split('/[、，,;；\s]+/u', (string) $value) ?: [];
    }
    $out = [];
    foreach ($list as $c) {
        $c = trim((string) $c);
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = mb_substr($c, 0, 32);
        }
    }
    return $out;
}

/**
 * 后台改课程：字段传了才改（传空字符串表示清空），课程代码整体覆盖。
 * 课程代码没有单独的主/副之分，第一个代码会同步写回 courses.code，保证旧逻辑（按 code 查）仍然可用。
 */
function h_admin_course_update()
{
    $admin = require_admin();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('courses'), $id, '课程不存在');

    $nameRaw = param_raw('name');
    $teacherRaw = param_raw('teacher');
    if ($nameRaw !== null && !is_array($nameRaw)) {
        $name = trim((string) $nameRaw);
        if (mb_strlen($name) < 2) {
            throw new ApiException('课程名称至少 2 个字');
        }
    }
    if ($teacherRaw !== null && !is_array($teacherRaw)) {
        $teacher = trim((string) $teacherRaw);
        if ($teacher === '') {
            throw new ApiException('请填写授课教师');
        }
    }

    $fields = [
        'name' => [80, 'name'],
        'teacher' => [40, 'teacher'],
        'credit' => [8, 'credit'],
        'category' => [20, 'category'],
        'examMode' => [20, 'exam_mode'],
        'language' => [20, 'language'],
        'college' => [40, 'college'],
        'intro' => [1200, 'intro'],
    ];
    $sets = [];
    $params = [];
    foreach ($fields as $key => [$max, $column]) {
        $value = param_raw($key);
        if ($value === null || is_array($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value !== '' && mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }
        $sets[] = $column . ' = ?';
        $params[] = $value;
    }

    $codes = admin_parse_codes(param_raw('codes'));
    if ($codes !== null) {
        $sets[] = 'code = ?';
        $params[] = $codes ? $codes[0] : '';
    }

    if ($sets) {
        $sets[] = 'updated_at = ?';
        $params[] = now_ms();
        $params[] = $id;
        q('UPDATE ' . t('courses') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }
    if ($codes !== null) {
        set_course_codes($id, $codes);
    }

    return ['course' => course_dto(find_or_fail(t('courses'), $id, '课程不存在'), $admin)];
}

/** 后台删课：短评与帖子靠外键级联删除，点赞没有外键需要手工清理 */
function h_admin_course_delete()
{
    require_admin();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('courses'), $id, '课程不存在');

    $reviewIds = array_column(q_all('SELECT id FROM ' . t('reviews') . ' WHERE course_id = ?', [$id]), 'id');
    $postIds = array_column(q_all('SELECT id FROM ' . t('posts') . ' WHERE course_id = ?', [$id]), 'id');
    $ids = array_merge($reviewIds, $postIds, [$id]);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    q('DELETE FROM ' . t('likes') . ' WHERE target_id IN (' . $placeholders . ')', $ids);
    q('DELETE FROM ' . t('courses') . ' WHERE id = ?', [$id]);

    if (!empty($row['created_by'])) {
        notify_user(
            $row['created_by'],
            '你添加的课程已被管理员删除',
            '课程：' . $row['name'] . '（' . $row['teacher'] . '）。如有疑问可通过站内信联系管理员。',
            '',
            'moderation'
        );
    }

    return [
        'ok' => true,
        'deleted' => $id,
        'reviewCount' => count($reviewIds),
        'postCount' => count($postIds),
    ];
}

/**
 * 合并两门重复课程：把 from 的短评、讨论帖、课程代码并入 to，然后删除 from。
 * 同一用户在两门课都评过分时保留目标课程的评分（一人一课一评的唯一约束），来源的那条会被丢弃并计入 droppedReviews。
 */
function h_admin_course_merge()
{
    $admin = require_admin();
    $from = param_str('from', param_str('sourceId', '', 40), 40);
    $to = param_str('to', param_str('targetId', '', 40), 40);
    if ($from === '' || $to === '') {
        throw new ApiException('请选择来源课程与目标课程');
    }
    if ($from === $to) {
        throw new ApiException('不能把课程合并到它自己');
    }
    $source = find_or_fail(t('courses'), $from, '来源课程不存在');
    $target = find_or_fail(t('courses'), $to, '目标课程不存在');

    $movedReviews = 0;
    $droppedReviews = 0;
    $droppedIds = [];
    foreach (q_all('SELECT id, user_id FROM ' . t('reviews') . ' WHERE course_id = ?', [$from]) as $r) {
        $dup = (int) q_val(
            'SELECT COUNT(*) FROM ' . t('reviews') . ' WHERE course_id = ? AND user_id = ?',
            [$to, $r['user_id']]
        );
        if ($dup > 0) {
            $droppedReviews++;
            $droppedIds[] = $r['id'];
            continue;
        }
        q('UPDATE ' . t('reviews') . ' SET course_id = ? WHERE id = ?', [$to, $r['id']]);
        $movedReviews++;
    }

    $movedPosts = (int) q('UPDATE ' . t('posts') . ' SET course_id = ? WHERE course_id = ?', [$to, $from])->rowCount();

    // 课程代码合并：目标原有代码 + 来源代码，去重后整体写回
    $codes = course_codes_of($to, (string) $target['code']);
    foreach (course_codes_of($from, (string) $source['code']) as $c) {
        if (!in_array($c, $codes, true)) {
            $codes[] = $c;
        }
    }
    set_course_codes($to, $codes);
    q(
        'UPDATE ' . t('courses') . ' SET code = ?, updated_at = ? WHERE id = ?',
        [$codes ? $codes[0] : '', now_ms(), $to]
    );

    // 删来源课程：重复评分先单独清掉（连同点赞），其余短评/帖子由外键级联
    if ($droppedIds) {
        $placeholders = implode(',', array_fill(0, count($droppedIds), '?'));
        q('DELETE FROM ' . t('likes') . ' WHERE target_id IN (' . $placeholders . ')', $droppedIds);
        q('DELETE FROM ' . t('reviews') . ' WHERE id IN (' . $placeholders . ')', $droppedIds);
    }
    $leftReviews = array_column(q_all('SELECT id FROM ' . t('reviews') . ' WHERE course_id = ?', [$from]), 'id');
    $leftPosts = array_column(q_all('SELECT id FROM ' . t('posts') . ' WHERE course_id = ?', [$from]), 'id');
    $cleanIds = array_merge($leftReviews, $leftPosts, [$from]);
    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    q('DELETE FROM ' . t('likes') . ' WHERE target_id IN (' . $placeholders . ')', $cleanIds);
    q('DELETE FROM ' . t('courses') . ' WHERE id = ?', [$from]);

    if (!empty($source['created_by'])) {
        notify_user(
            $source['created_by'],
            '你添加的课程已被合并',
            '课程：' . $source['name'] . '（' . $source['teacher'] . '）已合并到「' . $target['name'] . '（' . $target['teacher'] . '）」，评分与讨论已一并转移。',
            '#/courses/' . $to,
            'moderation'
        );
    }

    return [
        'ok' => true,
        'mergedFrom' => $from,
        'mergedTo' => $to,
        'movedReviews' => $movedReviews,
        'droppedReviews' => $droppedReviews,
        'movedPosts' => $movedPosts,
        'codes' => $codes,
        'course' => course_dto(find_or_fail(t('courses'), $to, '目标课程不存在'), $admin),
    ];
}

/* ------------------------- 课程批量导入（管理员） ------------------------- */

/*
 * 与前台 courses.import 的区别：
 *   1. 支持直接上传 Excel(.xlsx)，无需另存为 CSV
 *   2. 支持 dry=1 预演，先看结果再决定写入
 *   3. 支持 mode=update，用表格更新已有课程的学分/学院/类别/简介
 *   4. 单次上限 2000 行（前台是 500）
 */

/* 把文本统一成 UTF-8：Excel 在中文 Windows 导出的 CSV 通常是 GBK，不转码会乱码 */
function import_text_to_utf8($text)
{
    if ($text === '') {
        return '';
    }
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        return substr($text, 3);
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }
    $converted = @mb_convert_encoding($text, 'UTF-8', 'GB18030,GBK,GB2312,BIG5');
    return ($converted === false || $converted === '') ? $text : $converted;
}

function find_course_row($name, $teacher, $code)
{
    if ($code !== '') {
        $row = q_one(
            'SELECT * FROM ' . t('courses') . ' WHERE name = ? AND teacher = ? AND code = ? LIMIT 1',
            [$name, $teacher, $code]
        );
        if ($row) {
            return $row;
        }
    }
    return q_one(
        'SELECT * FROM ' . t('courses') . ' WHERE name = ? AND teacher = ? LIMIT 1',
        [$name, $teacher]
    );
}

function h_admin_courses_import()
{
    $user = require_admin();
    $dry = param_int('dry', 0, 0, 1) === 1;
    $mode = param_str('mode', 'skip', 8);
    if ($mode !== 'update') {
        $mode = 'skip';
    }

    $source = '';
    $items = [];

    if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
                UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
                UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            ];
            throw new ApiException($map[$file['error']] ?? '文件上传失败');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new ApiException('文件不能超过 10MB');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new ApiException('只支持 .csv / .txt / .xlsx 文件');
        }
        $raw = (string) file_get_contents($file['tmp_name']);
        if ($ext === 'xlsx') {
            if (substr($raw, 0, 2) !== 'PK') {
                throw new ApiException('这不是有效的 Excel 文件（.xlsx 应为 zip 格式）');
            }
            $items = xlsx_to_courses($raw, true);
            $source = 'xlsx';
        } else {
            $items = csv_to_courses(import_text_to_utf8($raw), true);
            $source = 'csv';
        }
    } else {
        $text = (string) param_raw('csv', '');
        if (trim($text) === '') {
            throw new ApiException('请先上传文件或粘贴表格内容');
        }
        $items = csv_to_courses(import_text_to_utf8($text), true);
        $source = 'text';
    }

    if (!$items) {
        throw new ApiException('没有解析到有效的课程数据，请检查表头是否为「课程名称 / 老师」等列名');
    }

    $limit = 2000;
    $total = count($items);
    $items = array_slice($items, 0, $limit);

    $now = now_ms();
    $created = 0;
    $updated = 0;
    $skipped = [];
    $preview = [];

    foreach ($items as $item) {
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 80);
        $teacher = mb_substr(trim((string) ($item['teacher'] ?? '')), 0, 40);
        if ($name === '' || $teacher === '') {
            $skipped[] = ['name' => $name ?: '(空行)', 'reason' => '缺少课程名称或老师'];
            continue;
        }

        $code = mb_substr(trim((string) ($item['code'] ?? '')), 0, 32);
        $credit = mb_substr(trim((string) ($item['credit'] ?? '')), 0, 8);
        $college = mb_substr(trim((string) ($item['college'] ?? '')), 0, 30);
        $category = mb_substr(trim((string) ($item['category'] ?? '')), 0, 20);
        $intro = mb_substr(trim((string) ($item['intro'] ?? '')), 0, 1200);

        $existing = find_course_row($name, $teacher, $code);
        $action = '';

        if ($existing) {
            if ($mode === 'update') {
                if (!$dry) {
                    q(
                        'UPDATE ' . t('courses') . ' SET code = ?, credit = ?, college = ?, category = ?, intro = ?, updated_at = ? WHERE id = ?',
                        [
                            $code !== '' ? $code : $existing['code'],
                            $credit !== '' ? $credit : $existing['credit'],
                            $college !== '' ? $college : $existing['college'],
                            $category !== '' ? $category : $existing['category'],
                            $intro !== '' ? $intro : $existing['intro'],
                            $now,
                            $existing['id'],
                        ]
                    );
                }
                $updated++;
                $action = '更新';
            } else {
                $skipped[] = ['name' => $name, 'reason' => '已存在同名同老师的课程'];
                continue;
            }
        } else {
            if (!$dry) {
                q(
                    'INSERT INTO ' . t('courses') . ' (id, name, teacher, code, credit, category, college, intro, created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        new_id('c_'), $name, $teacher, $code, $credit,
                        $category ?: '其他', $college, $intro,
                        $user['id'], $now, $now,
                    ]
                );
            }
            $created++;
            $action = '新增';
        }

        if (count($preview) < 20) {
            $preview[] = [
                'action' => $action,
                'name' => $name,
                'teacher' => $teacher,
                'code' => $code,
                'credit' => $credit,
                'college' => $college,
                'category' => $category ?: '其他',
            ];
        }
    }

    return [
        'dry' => $dry,
        'mode' => $mode,
        'source' => $source,
        'total' => $total,
        'parsed' => count($items),
        'created' => $created,
        'updated' => $updated,
        'skippedCount' => count($skipped),
        'skipped' => array_slice($skipped, 0, 100),
        'preview' => $preview,
    ];
}

/**
 * 教务处导出表格专用导入
 * 11 列：课程代码 / 课程名称 / 学分 / 课程序号 / 教学班 / 课程类别 / 开课部门 /
 *        授课教师 / 日期时间地点人员 / 考核方式 / 授课语言
 * 只取其中 8 列；同名同教师的多行（不同教学班）合并为一门课，课程代码全部保留。
 */
function h_admin_edu_import()
{
    $user = require_admin();
    $dry = param_int('dry', 0, 0, 1) === 1;
    $mode = param_str('mode', 'skip', 8);
    if ($mode !== 'update') {
        $mode = 'skip';
    }

    $source = '';
    $rawRows = [];

    if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器限制（PHP 的 upload_max_filesize）',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
                UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
                UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            ];
            throw new ApiException($map[$file['error']] ?? '文件上传失败');
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new ApiException('文件不能超过 10MB');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new ApiException('只支持 .csv / .txt / .xlsx 文件');
        }
        $raw = (string) file_get_contents($file['tmp_name']);
        if ($ext === 'xlsx') {
            if (substr($raw, 0, 2) !== 'PK') {
                throw new ApiException('这不是有效的 Excel 文件（.xlsx 应为 zip 格式）');
            }
            $rawRows = xlsx_to_rows($raw);
            $source = 'xlsx';
        } else {
            $rawRows = csv_parse(import_text_to_utf8($raw));
            $source = 'csv';
        }
    } else {
        $text = (string) param_raw('csv', '');
        if (trim($text) === '') {
            throw new ApiException('请先上传文件或粘贴表格内容');
        }
        $rawRows = csv_parse(import_text_to_utf8($text));
        $source = 'text';
    }

    $headerInfo = edu_header_map($rawRows);
    if (!in_array('name', $headerInfo['map'], true)) {
        throw new ApiException(
            '没有识别到「课程名称」列。实际读到的表头：' . implode(' / ', array_column($headerInfo['fields'], 'header'))
        );
    }

    $items = edu_rows_to_courses($rawRows);
    if (!$items) {
        throw new ApiException('表头识别成功，但没解析出课程行，请确认表格里有数据');
    }

    $dataRows = max(0, count($rawRows) - 1);
    $items = array_slice($items, 0, 2000);

    $now = now_ms();
    $created = 0;
    $updated = 0;
    $mergedGroups = 0;
    $skipped = [];
    $preview = [];

    foreach ($items as $item) {
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 80);
        $teacher = mb_substr(trim((string) ($item['teacher'] ?? '')), 0, 40);
        if ($name === '') {
            $skipped[] = ['name' => '(空行)', 'reason' => '缺少课程名称'];
            continue;
        }
        if ($teacher === '') {
            $skipped[] = ['name' => $name, 'reason' => '缺少授课教师'];
            continue;
        }

        $credit = mb_substr(trim((string) ($item['credit'] ?? '')), 0, 8);
        $category = mb_substr(trim((string) ($item['category'] ?? '')), 0, 20);
        $college = mb_substr(trim((string) ($item['college'] ?? '')), 0, 30);
        $examMode = mb_substr(trim((string) ($item['examMode'] ?? '')), 0, 20);
        $language = mb_substr(trim((string) ($item['language'] ?? '')), 0, 20);

        $codes = [];
        foreach ((array) ($item['codes'] ?? []) as $c) {
            $c = mb_substr(trim((string) $c), 0, 32);
            if ($c !== '' && !in_array($c, $codes, true)) {
                $codes[] = $c;
            }
        }
        $code = $codes ? $codes[0] : '';
        $merged = (int) ($item['merged'] ?? 1);
        if ($merged > 1) {
            $mergedGroups++;
        }

        $existing = find_course_row($name, $teacher, '');
        $action = '';

        if ($existing) {
            if ($mode === 'update') {
                if (!$dry) {
                    q(
                        'UPDATE ' . t('courses') . ' SET code = ?, credit = ?, category = ?, exam_mode = ?, language = ?, college = ?, updated_at = ? WHERE id = ?',
                        [
                            $code !== '' ? $code : $existing['code'],
                            $credit !== '' ? $credit : $existing['credit'],
                            $category !== '' ? $category : $existing['category'],
                            $examMode !== '' ? $examMode : $existing['exam_mode'],
                            $language !== '' ? $language : $existing['language'],
                            $college !== '' ? $college : $existing['college'],
                            $now,
                            $existing['id'],
                        ]
                    );
                    // 合并已有代码与新代码，不会把旧代码清掉
                    $codes = array_values(array_unique(array_merge(course_codes_of($existing['id']), $codes)));
                    set_course_codes($existing['id'], $codes);
                }
                $updated++;
                $action = '更新';
            } else {
                $skipped[] = ['name' => $name . '（' . $teacher . '）', 'reason' => '已存在同名同老师的课程'];
                continue;
            }
        } else {
            if (!$dry) {
                $id = new_id('c_');
                q(
                    'INSERT INTO ' . t('courses') . ' (id, name, teacher, code, credit, category, exam_mode, language, college, intro, created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$id, $name, $teacher, $code, $credit, $category ?: '其他', $examMode, $language, $college, '',
                     $user['id'], $now, $now]
                );
                set_course_codes($id, $codes);
            }
            $created++;
            $action = '新增';
        }

        if (count($preview) < 20) {
            $preview[] = [
                'action' => $action,
                'name' => $name,
                'teacher' => $teacher,
                'codes' => $codes,
                'credit' => $credit,
                'college' => $college,
                'category' => $category ?: '其他',
                'examMode' => $examMode,
                'language' => $language,
                'merged' => $merged,
            ];
        }
    }

    return [
        'dry' => $dry,
        'mode' => $mode,
        'source' => $source,
        'rows' => $dataRows,
        'parsed' => count($items),
        'mergedGroups' => $mergedGroups,
        'created' => $created,
        'updated' => $updated,
        'skippedCount' => count($skipped),
        'skipped' => array_slice($skipped, 0, 100),
        'preview' => $preview,
        'header' => $headerInfo['fields'],
    ];
}
