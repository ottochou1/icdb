<?php
/** 注册、登录、资料 */

function h_auth_register()
{
    $email = strtolower(param_str('email', '', 120));
    $nickname = param_str('nickname', '', 24);
    $password = (string) param_raw('password', '');
    $studentId = param_str('studentId', '', 32);
    $grade = param_str('grade', '', 16);

    if (!is_email_addr($email)) {
        throw new ApiException('请输入有效的邮箱地址');
    }
    if (mb_strlen($nickname) < 2) {
        throw new ApiException('昵称至少 2 个字符');
    }
    if (strlen($password) < 6) {
        throw new ApiException('密码至少 6 位');
    }
    if (q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE email = ?', [$email])) {
        throw new ApiException('该邮箱已注册，请直接登录', 409);
    }
    if (q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE nickname = ?', [$nickname])) {
        throw new ApiException('昵称已被占用', 409);
    }

    $userCount = (int) q_val('SELECT COUNT(*) FROM ' . t('users'));
    $id = new_id('u_');
    q(
        'INSERT INTO ' . t('users') . ' (id, email, nickname, password_hash, student_id, grade, bio, avatar_color, is_admin, joined_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [
            $id, $email, $nickname, password_hash($password, PASSWORD_DEFAULT),
            $studentId, $grade, '', avatar_color_for($nickname), $userCount === 0 ? 1 : 0, now_ms(),
        ]
    );
    auth_start_session($id);
    $isAdmin = (bool) q_val('SELECT is_admin FROM ' . t('users') . ' WHERE id = ?', [$id]);

    if ($isAdmin) {
        // 管理员无需认证，直接开通全部权限
        q('UPDATE ' . t('users') . ' SET verified = 1, verified_at = ? WHERE id = ?', [now_ms(), $id]);
    } else {
        notify_user(
            $id,
            '欢迎加入华政选课 · 请完成学生认证',
            '为保证社区内容都来自在校同学，发帖、评分和评论需要先通过在读认证。请上传学生证或在读证明（PDF 或图片，不超过 10MB），管理员审核通过后立即开通权限。证明材料审核后会自动删除，我们不会保存这些敏感文件。',
            '#/verify',
            'verify'
        );
    }

    $user = q_one('SELECT * FROM ' . t('users') . ' WHERE id = ? LIMIT 1', [$id]);
    return ['user' => public_user($user, true), 'needVerify' => !$isAdmin];
}

function h_auth_login()
{
    $email = strtolower(param_str('email', '', 120));
    $password = (string) param_raw('password', '');
    $row = q_one('SELECT * FROM ' . t('users') . ' WHERE email = ? LIMIT 1', [$email]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        throw new ApiException('邮箱或密码不正确', 401);
    }
    if (maintenance_mode() && empty($row['is_admin'])) {
        throw new ApiException('站点正在闭站维护，目前仅管理员可以登录', 403, 'MAINTENANCE');
    }
    auth_start_session($row['id']);
    return ['user' => public_user($row, true)];
}

function h_auth_logout()
{
    $token = isset($_COOKIE[COOKIE_NAME]) ? (string) $_COOKIE[COOKIE_NAME] : '';
    if ($token !== '') {
        q('DELETE FROM ' . t('sessions') . ' WHERE token = ?', [$token]);
    }
    clear_session_cookie();
    return ['ok' => true];
}

function h_auth_me()
{
    $user = current_user();
    $maintenance = maintenance_mode();
    if ($maintenance && $user && empty($user['is_admin'])) {
        $user = null;
    }
    return [
        'user' => $user ? public_user($user, true) : null,
        'maintenanceMode' => $maintenance,
    ];
}

function h_auth_profile()
{
    $user = require_login();
    $nickname = param_str('nickname', '', 24);
    if (mb_strlen($nickname) < 2) {
        throw new ApiException('昵称至少 2 个字符');
    }
    if (q_val('SELECT COUNT(*) FROM ' . t('users') . ' WHERE nickname = ? AND id <> ?', [$nickname, $user['id']])) {
        throw new ApiException('昵称已被占用', 409);
    }
    q(
        'UPDATE ' . t('users') . ' SET nickname = ?, student_id = ?, grade = ?, bio = ?, avatar_color = ? WHERE id = ?',
        [
            $nickname,
            param_str('studentId', '', 32),
            param_str('grade', '', 16),
            param_str('bio', '', 200),
            param_str('avatarColor', $user['avatar_color'] ?: '#2f7d51', 16),
            $user['id'],
        ]
    );
    return ['user' => public_user(get_user($user['id']), true)];
}

function auth_start_session($userId)
{
    $token = bin2hex(random_bytes(24));
    q('INSERT INTO ' . t('sessions') . ' (token, user_id, created_at, expires_at) VALUES (?,?,?,?)', [
        $token,
        $userId,
        now_ms(),
        now_ms() + ((int) app_config()['session_days']) * 86400000,
    ]);
    set_session_cookie($token);
    $_COOKIE[COOKIE_NAME] = $token;
}
