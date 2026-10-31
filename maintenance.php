<?php
/** 维护状态独立保存，无需数据库迁移。状态文件由 PHP 保护。 */
function maintenance_state()
{
    $file = __DIR__ . '/maintenance-state.php';
    $default = ['enabled' => false, 'message' => '网站正在维护升级，请稍后再来。'];
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    $data = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true);
    if (!is_array($data) || !isset($data['enabled'], $data['message']) || !is_bool($data['enabled']) || !is_string($data['message'])) {
        throw new ApiException('维护配置读取失败，请联系管理员', 503);
    }
    return $data;
}

function maintenance_admin()
{
    $user = current_user();
    return $user && !empty($user['is_admin']);
}

function h_maintenance_status()
{
    return maintenance_state();
}

function h_admin_maintenance()
{
    require_admin();
    return maintenance_state();
}

function h_admin_maintenance_update()
{
    require_admin();
    // 自定义头 + JSON 防止跨站表单/GET 请求修改配置，不启用跨域许可。
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new ApiException('请使用 POST 请求', 405);
    }
    if (($_SERVER['HTTP_X_MAINTENANCE_REQUEST'] ?? '') !== '1'
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new ApiException('请求验证失败，请刷新后台重试', 403);
    }
    $body = input_body();
    if (!isset($body['enabled']) || !is_bool($body['enabled']) || !isset($body['message']) || !is_string($body['message'])) {
        throw new ApiException('维护设置格式不正确');
    }
    $message = trim($body['message']);
    if (mb_strlen($message) > 300) throw new ApiException('维护提示最多 300 个字');
    $state = ['enabled' => $body['enabled'], 'message' => $message === '' ? '网站正在维护升级，请稍后再来。' : $message];
    $file = __DIR__ . '/maintenance-state.php';
    $temp = tempnam(__DIR__, '.maintenance-');
    if ($temp === false) throw new ApiException('无法保存维护设置，请检查网站目录写入权限', 500);
    $content = "<?php exit; ?>\n" . json_encode($state, JSON_UNESCAPED_UNICODE);
    if (file_put_contents($temp, $content, LOCK_EX) === false || !rename($temp, $file)) {
        @unlink($temp);
        throw new ApiException('无法保存维护设置，请检查网站目录写入权限', 500);
    }
    return $state;
}

function maintenance_guard($route)
{
    $state = maintenance_state();
    if (!$state['enabled']) return;
    if (in_array($route, ['maintenance.status', 'auth.login', 'auth.me', 'auth.logout'], true)) return;
    if (maintenance_admin()) return;
    header('Retry-After: 3600');
    throw new ApiException($state['message'], 503, 'MAINTENANCE');
}
