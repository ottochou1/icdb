<?php
/**
 * 统一 API 入口：api.php?r=xxx
 * 需要 PHP 7.4+ / PDO MySQL
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/modules/auth.php';
require_once __DIR__ . '/modules/courses.php';
require_once __DIR__ . '/modules/posts.php';
require_once __DIR__ . '/modules/users.php';
require_once __DIR__ . '/modules/verify.php';
require_once __DIR__ . '/modules/messages.php';
require_once __DIR__ . '/modules/admin.php';

$routes = [
    'config' => 'h_config',
    'home' => 'h_home',

    'auth.register' => 'h_auth_register',
    'auth.login' => 'h_auth_login',
    'auth.logout' => 'h_auth_logout',
    'auth.me' => 'h_auth_me',
    'auth.profile' => 'h_auth_profile',

    'courses.list' => 'h_courses_list',
    'courses.facets' => 'h_courses_facets',
    'courses.get' => 'h_courses_get',
    'courses.create' => 'h_courses_create',
    'courses.update' => 'h_courses_update',
    'courses.delete' => 'h_courses_delete',
    'courses.import' => 'h_courses_import',
    'courses.export' => 'h_courses_export',

    'reviews.list' => 'h_reviews_list',
    'reviews.create' => 'h_reviews_create',
    'reviews.update' => 'h_reviews_update',
    'reviews.delete' => 'h_reviews_delete',
    'reviews.like' => 'h_reviews_like',

    'posts.list' => 'h_posts_list',
    'posts.get' => 'h_posts_get',
    'posts.create' => 'h_posts_create',
    'posts.update' => 'h_posts_update',
    'posts.delete' => 'h_posts_delete',
    'posts.like' => 'h_posts_like',

    'comments.create' => 'h_comments_create',
    'comments.delete' => 'h_comments_delete',
    'comments.like' => 'h_comments_like',

    'users.get' => 'h_users_get',
    'me.feed' => 'h_me_feed',

    // 学生认证
    'verify.status' => 'h_verify_status',
    'verify.submit' => 'h_verify_submit',
    'verify.cancel' => 'h_verify_cancel',

    // 站内信与通知
    'messages.conversations' => 'h_messages_conversations',
    'messages.thread' => 'h_messages_thread',
    'messages.send' => 'h_messages_send',
    'messages.read' => 'h_messages_read',
    'messages.unread' => 'h_messages_unread',
    'notifications.list' => 'h_notifications_list',
    'notifications.read' => 'h_notifications_read',

    // 管理后台
    'admin.stats' => 'h_admin_stats',
    'admin.verifications' => 'h_admin_verifications',
    'admin.verification.file' => 'h_admin_verification_file',
    'admin.verification.review' => 'h_admin_verification_review',
    'admin.users' => 'h_admin_users',
    'admin.user.update' => 'h_admin_user_update',
    'admin.posts' => 'h_admin_posts',
    'admin.reviews' => 'h_admin_reviews',
    'admin.content.delete' => 'h_admin_content_delete',
    'admin.courses' => 'h_admin_courses',
    'admin.course.update' => 'h_admin_course_update',
    'admin.course.delete' => 'h_admin_course_delete',
    'admin.course.merge' => 'h_admin_course_merge',
    'admin.courses.import' => 'h_admin_courses_import',
    'admin.edu.import' => 'h_admin_edu_import',
    'admin.maintenance' => 'h_admin_maintenance',
    'admin.maintenance.update' => 'h_admin_maintenance_update',
];

// 未列出的接口默认只允许 GET；所有写接口显式限定方法，避免通过 GET 链接触发状态变更。
$routeMethods = [
    'auth.register' => 'POST', 'auth.login' => 'POST', 'auth.logout' => 'POST', 'auth.profile' => 'POST',
    'courses.create' => 'POST', 'courses.update' => 'POST', 'courses.delete' => 'DELETE', 'courses.import' => 'POST',
    'reviews.create' => 'POST', 'reviews.update' => 'POST', 'reviews.delete' => 'DELETE', 'reviews.like' => 'POST',
    'posts.create' => 'POST', 'posts.update' => 'POST', 'posts.delete' => 'DELETE', 'posts.like' => 'POST',
    'comments.create' => 'POST', 'comments.delete' => 'DELETE', 'comments.like' => 'POST',
    'verify.submit' => 'POST', 'verify.cancel' => 'POST',
    'messages.send' => 'POST', 'messages.read' => 'POST', 'notifications.read' => 'POST',
    'admin.verification.review' => 'POST', 'admin.user.update' => 'POST', 'admin.content.delete' => 'POST',
    'admin.course.update' => 'POST', 'admin.course.delete' => 'POST', 'admin.course.merge' => 'POST',
    'admin.courses.import' => 'POST', 'admin.edu.import' => 'POST', 'admin.maintenance.update' => 'POST',
];

$route = isset($_GET['r']) ? trim((string) $_GET['r']) : '';

try {
    if ($route === '') {
        throw new ApiException('缺少接口名，请使用 api.php?r=xxx', 400);
    }
    if (!isset($routes[$route]) || !function_exists($routes[$route])) {
        throw new ApiException('接口不存在', 404);
    }
    $allowedMethod = isset($routeMethods[$route]) ? $routeMethods[$route] : 'GET';
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if ($requestMethod !== $allowedMethod) {
        header('Allow: ' . $allowedMethod);
        throw new ApiException('请求方法不被允许', 405, 'METHOD_NOT_ALLOWED');
    }

    // 闭站维护期间只保留登录态检查、管理员登录和退出；其余接口仅管理员可访问。
    if (maintenance_mode() && !in_array($route, ['auth.login', 'auth.logout', 'auth.me'], true)) {
        $viewer = current_user();
        if (!$viewer || empty($viewer['is_admin'])) {
            throw new ApiException('站点正在闭站维护，目前仅管理员可以进入', 503, 'MAINTENANCE');
        }
    }
    $data = call_user_func($routes[$route]);
    ok_response($data === null ? new stdClass() : $data);
} catch (ApiException $e) {
    fail_response($e->getMessage(), $e->getStatus(), $e->getErrorCode());
} catch (Throwable $e) {
    error_log('[huazheng-course] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail_response('服务器开小差了，请稍后再试', 500);
}
