<?php
/** 学生认证：提交证明材料、查询状态、撤回申请 */

define('VERIFY_MAX_BYTES', 10 * 1024 * 1024); // 10MB

function verify_upload_dir()
{
    $dir = __DIR__ . '/../uploads/verify';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\nphp_flag engine off\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** 扩展名 => 允许的 MIME 类型 */
function verify_allowed_types()
{
    return [
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'],
    ];
}

function upload_error_msg($code)
{
    $map = [
        UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
        UPLOAD_ERR_NO_FILE => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
        UPLOAD_ERR_EXTENSION => '上传被扩展阻止',
    ];
    return isset($map[$code]) ? $map[$code] : '未知错误';
}

/** 删除证明材料物理文件，并把记录里的路径清空（不保留敏感文件） */
function verify_delete_file($row)
{
    if (empty($row['file_path'])) {
        return false;
    }
    $dir = verify_upload_dir();
    $path = $dir . '/' . basename((string) $row['file_path']);
    if (is_file($path)) {
        @unlink($path);
    }
    q('UPDATE ' . t('verifications') . ' SET file_path = ? WHERE id = ?', ['', $row['id']]);
    return true;
}

function verification_dto($row, $forAdmin = false)
{
    $out = [
        'id' => $row['id'],
        'userId' => $row['user_id'],
        'status' => $row['status'],
        'fileName' => (string) $row['file_name'],
        'fileExt' => (string) $row['file_ext'],
        'fileSize' => (int) $row['file_size'],
        'hasFile' => $row['file_path'] !== '',
        'note' => (string) $row['note'],
        'adminNote' => (string) $row['admin_note'],
        'createdAt' => (int) $row['created_at'],
        'reviewedAt' => (int) $row['reviewed_at'],
        'user' => public_user(get_user($row['user_id'])),
    ];
    if ($forAdmin && $row['file_path'] !== '') {
        $out['fileUrl'] = 'api.php?r=admin.verification.file&id=' . urlencode($row['id']);
    }
    return $out;
}

function h_verify_status()
{
    $user = require_login();
    $row = q_one(
        'SELECT * FROM ' . t('verifications') . ' WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
        [$user['id']]
    );
    return [
        'verified' => can_participate($user),
        'canPost' => can_participate($user),
        'isAdmin' => !empty($user['is_admin']),
        'banned' => !empty($user['banned']),
        'banReason' => (string) ($user['ban_reason'] ?? ''),
        'verifiedAt' => (int) ($user['verified_at'] ?? 0),
        'application' => $row ? verification_dto($row) : null,
    ];
}

function h_verify_submit()
{
    $user = require_login();
    if (!empty($user['banned'])) {
        throw new ApiException('账号已被限制发言，无法提交认证', 403, 'BANNED');
    }
    if (can_participate($user)) {
        throw new ApiException('你已经通过认证了，无需再次提交');
    }
    if (q_val('SELECT COUNT(*) FROM ' . t('verifications') . ' WHERE user_id = ? AND status = ?', [$user['id'], 'pending'])) {
        throw new ApiException('你已有一个待审核的申请，请耐心等待管理员处理');
    }

    if (empty($_FILES['file'])) {
        throw new ApiException('请选择要上传的学生证或在校证明');
    }
    $file = $_FILES['file'];
    if (is_array($file['error'])) {
        throw new ApiException('一次只能上传一个文件');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ApiException('上传失败：' . upload_error_msg($file['error']));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ApiException('上传文件不合法');
    }
    $size = (int) @filesize($file['tmp_name']);
    if ($size <= 0) {
        throw new ApiException('文件内容为空，请重新选择');
    }
    if ($size > VERIFY_MAX_BYTES) {
        throw new ApiException('文件不能超过 10MB，当前约 ' . round($size / 1048576, 1) . 'MB');
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = verify_allowed_types();
    if (!isset($allowed[$ext])) {
        throw new ApiException('只支持 PDF 或图片格式（jpg / png / gif / webp），当前是 .' . $ext);
    }

    // 内容类型校验，防止改扩展名伪装
    $detected = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = (string) @finfo_file($fi, $file['tmp_name']);
            @finfo_close($fi);
        }
    } elseif (function_exists('mime_content_type')) {
        $detected = (string) @mime_content_type($file['tmp_name']);
    }
    if ($detected !== '' && !in_array($detected, $allowed[$ext], true)) {
        throw new ApiException('文件内容与扩展名不一致（检测到 ' . $detected . '），请上传真实的学生证或证明');
    }

    $dir = verify_upload_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new ApiException('服务器上传目录不可写，请联系管理员', 500);
    }
    $safeName = 'v_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new ApiException('保存文件失败，请稍后重试', 500);
    }
    @chmod($dest, 0640);

    $note = isset($_POST['note']) ? mb_substr(trim((string) $_POST['note']), 0, 400) : '';
    $id = new_id('vf_');
    try {
        q(
            'INSERT INTO ' . t('verifications') . ' (id, user_id, status, file_name, file_ext, file_size, file_path, note, admin_note, reviewer_id, created_at, reviewed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,0)',
            [$id, $user['id'], 'pending', mb_substr((string) $file['name'], 0, 200), $ext, $size, $safeName, $note, '', '', now_ms()]
        );
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }

    notify_user(
        $user['id'],
        '认证申请已提交',
        '我们已收到你的在校证明，管理员审核后会通过站内信通知你。审核期间你仍可浏览和搜索课程。',
        '#/verify',
        'verify'
    );

    return ['application' => verification_dto(find_or_fail(t('verifications'), $id, '申请不存在'))];
}

function h_verify_cancel()
{
    $user = require_login();
    $row = q_one(
        'SELECT * FROM ' . t('verifications') . ' WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1',
        [$user['id'], 'pending']
    );
    if (!$row) {
        throw new ApiException('没有待审核的申请');
    }
    verify_delete_file($row);
    q('DELETE FROM ' . t('verifications') . ' WHERE id = ?', [$row['id']]);
    return ['ok' => true];
}
