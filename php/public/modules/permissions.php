<?php
/** 课程上传权限申请 */

function upload_permission_dto($row, $withUser = false)
{
    if (!$row) {
        return null;
    }
    $out = [
        'id' => (string) $row['id'],
        'status' => (string) $row['status'],
        'direction' => (string) $row['direction'],
        'plannedCourses' => (string) $row['planned_courses'],
        'reason' => (string) $row['reason'],
        'adminNote' => (string) $row['admin_note'],
        'createdAt' => (int) $row['created_at'],
        'reviewedAt' => (int) $row['reviewed_at'],
    ];
    if ($withUser) {
        $out['user'] = public_user(get_user($row['user_id']), true);
    }
    return $out;
}

function h_upload_permission_status()
{
    $user = require_login();
    $row = q_one(
        'SELECT * FROM ' . t('course_upload_applications') . ' WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
        [$user['id']]
    );
    return [
        'canUploadCourses' => !empty($user['is_admin']) || !empty($user['can_upload_courses']),
        'verified' => !empty($user['verified']) || !empty($user['is_admin']),
        'application' => upload_permission_dto($row),
    ];
}

function h_upload_permission_submit()
{
    $user = require_verified();
    if (!empty($user['is_admin']) || !empty($user['can_upload_courses'])) {
        throw new ApiException('你已经拥有课程上传权限');
    }
    $pending = q_one(
        'SELECT id FROM ' . t('course_upload_applications') . ' WHERE user_id = ? AND status = ? LIMIT 1',
        [$user['id'], 'pending']
    );
    if ($pending) {
        throw new ApiException('你已有一份申请正在等待审核');
    }

    $directions = ['单门课程补充', '批量课程整理', '课程信息纠错', '其他共建'];
    $direction = param_str('direction', '', 40);
    $planned = mb_substr(trim((string) param_raw('plannedCourses', '')), 0, 300);
    $reason = mb_substr(trim((string) param_raw('reason', '')), 0, 500);
    if (!in_array($direction, $directions, true)) {
        throw new ApiException('请选择申请方向');
    }
    if (mb_strlen($planned) < 2) {
        throw new ApiException('请填写计划补充的课程或院系');
    }
    if (mb_strlen($reason) < 10) {
        throw new ApiException('申请说明至少需要 10 个字');
    }

    $id = new_id('cua');
    q(
        'INSERT INTO ' . t('course_upload_applications') .
        ' (id, user_id, status, direction, planned_courses, reason, admin_note, reviewer_id, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $user['id'], 'pending', $direction, $planned, $reason, '', '', now_ms(), 0]
    );
    return ['application' => upload_permission_dto(find_or_fail(t('course_upload_applications'), $id, '申请不存在'))];
}

function h_upload_permission_cancel()
{
    $user = require_login();
    $row = q_one(
        'SELECT * FROM ' . t('course_upload_applications') . ' WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1',
        [$user['id'], 'pending']
    );
    if (!$row) {
        throw new ApiException('没有可撤回的申请');
    }
    q(
        'UPDATE ' . t('course_upload_applications') . ' SET status = ?, reviewed_at = ? WHERE id = ?',
        ['cancelled', now_ms(), $row['id']]
    );
    return ['ok' => true];
}

function h_admin_upload_applications()
{
    require_admin();
    $status = param_str('status', 'pending', 16);
    if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
        $status = 'pending';
    }
    $where = $status === 'all' ? '' : ' WHERE status = ?';
    $args = $status === 'all' ? [] : [$status];
    $rows = q_all(
        'SELECT * FROM ' . t('course_upload_applications') . $where . ' ORDER BY created_at DESC LIMIT 200',
        $args
    );
    $items = [];
    foreach ($rows as $row) {
        $items[] = upload_permission_dto($row, true);
    }
    $counts = [];
    foreach (['pending', 'approved', 'rejected', 'cancelled'] as $key) {
        $counts[$key] = (int) q_val(
            'SELECT COUNT(*) FROM ' . t('course_upload_applications') . ' WHERE status = ?',
            [$key]
        );
    }
    return ['items' => $items, 'counts' => $counts];
}

function h_admin_upload_application_review()
{
    $admin = require_admin();
    $id = param_str('id', '', 40);
    $action = param_str('action', '', 16);
    $note = mb_substr(trim((string) param_raw('note', '')), 0, 400);
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new ApiException('操作类型不正确');
    }
    $row = find_or_fail(t('course_upload_applications'), $id, '申请不存在');
    if ($row['status'] !== 'pending') {
        throw new ApiException('该申请已处理过了');
    }
    $now = now_ms();
    $status = $action === 'approve' ? 'approved' : 'rejected';
    if ($action === 'reject' && $note === '') {
        $note = '申请信息不足，请补充具体课程范围与维护计划后重新提交';
    }
    if ($action === 'approve') {
        q('UPDATE ' . t('users') . ' SET can_upload_courses = 1 WHERE id = ?', [$row['user_id']]);
    }
    q(
        'UPDATE ' . t('course_upload_applications') . ' SET status = ?, admin_note = ?, reviewer_id = ?, reviewed_at = ? WHERE id = ?',
        [$status, $note, $admin['id'], $now, $id]
    );
    notify_user(
        $row['user_id'],
        $action === 'approve' ? '课程上传权限申请已通过' : '课程上传权限申请未通过',
        $action === 'approve'
            ? '你的课程共建申请已通过，现在可以添加课程或批量导入课程信息。请确保所填信息真实、准确。'
            : '本次课程共建申请未通过。原因：' . $note . '。你可以完善信息后重新申请。',
        $action === 'approve' ? '#/new-course' : '#/',
        'permission'
    );
    return ['application' => upload_permission_dto(find_or_fail(t('course_upload_applications'), $id, '申请不存在'), true)];
}
