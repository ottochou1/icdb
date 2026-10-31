<?php
/**
 * 统一 API 入口：api.php?r=xxx
 * 需要 PHP 7.4+ / PDO MySQL
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/modules/auth.php';
require_once __DIR__ . '/modules/courses.php';
require_once __DIR__ . '/modules/posts.php';
require_once __DIR__ . '/modules/users.php';
require_once __DIR__ . '/modules/verify.php';
require_once __DIR__ . '/modules/messages.php';
require_once __DIR__ . '/modules/admin.php';

$routes = [
    'maintenance.status' => 'h_maintenance_status',
    'admin.maintenance' => 'h_admin_maintenance',
    'admin.maintenance.update' => 'h_admin_maintenance_update',
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
];

$route = isset($_GET['r']) ? trim((string) $_GET['r']) : '';

try {
    if ($route === '') {
        throw new ApiException('缺少接口名，请使用 api.php?r=xxx', 400);
    }
    if (!isset($routes[$route]) || !function_exists($routes[$route])) {
        throw new ApiException('接口不存在', 404);
    }
    maintenance_guard($route);
    $data = call_user_func($routes[$route]);
    ok_response($data === null ? new stdClass() : $data);
} catch (ApiException $e) {
    fail_response($e->getMessage(), $e->getStatus(), $e->getErrorCode());
} catch (Throwable $e) {
    error_log('[huazheng-course] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail_response('服务器开小差了，请稍后再试', 500);
}
