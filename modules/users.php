<?php
/** 用户主页与「我的」 */

function h_users_get()
{
    $viewer = current_user();
    $id = param_str('id', '', 40);
    $user = find_or_fail(t('users'), $id, '用户不存在');
    $self = $viewer && $viewer['id'] === $user['id'];

    $stats = [
        'courses' => (int) q_val('SELECT COUNT(*) FROM ' . t('courses') . ' WHERE created_by = ?', [$id]),
        'reviews' => (int) q_val('SELECT COUNT(*) FROM ' . t('reviews') . ' WHERE user_id = ?', [$id]),
        'posts' => (int) q_val('SELECT COUNT(*) FROM ' . t('posts') . ' WHERE user_id = ?', [$id]),
    ];

    $reviews = q_all('SELECT * FROM ' . t('reviews') . ' WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', [$id]);
    $posts = q_all('SELECT * FROM ' . t('posts') . ' WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', [$id]);
    $courses = q_all('SELECT * FROM ' . t('courses') . ' WHERE created_by = ? ORDER BY created_at DESC LIMIT 10', [$id]);

    return [
        'user' => public_user($user, $self),
        'stats' => $stats,
        'reviews' => array_map(function ($r) use ($viewer) { return review_dto($r, $viewer); }, $reviews),
        'posts' => array_map(function ($r) use ($viewer) { return post_dto($r, $viewer); }, $posts),
        'courses' => array_map(function ($r) use ($viewer) { return course_dto($r, $viewer); }, $courses),
    ];
}

function h_me_feed()
{
    $user = require_login();
    $reviews = q_all('SELECT * FROM ' . t('reviews') . ' WHERE user_id = ? ORDER BY created_at DESC', [$user['id']]);
    $posts = q_all('SELECT * FROM ' . t('posts') . ' WHERE user_id = ? ORDER BY created_at DESC', [$user['id']]);
    $courses = q_all('SELECT * FROM ' . t('courses') . ' WHERE created_by = ? ORDER BY created_at DESC', [$user['id']]);

    return [
        'reviews' => array_map(function ($r) use ($user) { return review_dto($r, $user); }, $reviews),
        'posts' => array_map(function ($r) use ($user) { return post_dto($r, $user); }, $posts),
        'courses' => array_map(function ($r) use ($user) { return course_dto($r, $user); }, $courses),
    ];
}
