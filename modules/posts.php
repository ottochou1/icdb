<?php
/** 帖子、课程讨论、回复、点赞 */

function h_posts_list()
{
    $viewer = current_user();
    $type = param_str('type', '', 16);
    $courseId = param_str('courseId', '', 40);
    $tag = param_str('tag', '', 20);
    $q = param_str('q', '', 60);
    $page = param_int('page', 1, 1, 9999);
    $pageSize = param_int('pageSize', 15, 1, 60);

    $where = [];
    $params = [];
    if ($type !== '') {
        $where[] = 'p.type = ?';
        $params[] = $type;
    }
    if ($courseId !== '') {
        $where[] = 'p.course_id = ?';
        $params[] = $courseId;
    }
    if ($tag !== '') {
        $where[] = 'p.tags LIKE ?';
        $params[] = '%"' . like_escape($tag) . '"%';
    }
    if ($q !== '') {
        $kw = '%' . like_escape($q) . '%';
        $where[] = '(p.title LIKE ? OR p.content LIKE ?)';
        $params[] = $kw;
        $params[] = $kw;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int) q_val('SELECT COUNT(*) FROM ' . t('posts') . ' p' . $whereSql, $params);
    $rows = q_all(
        'SELECT p.* FROM ' . t('posts') . ' p' . $whereSql . ' ORDER BY p.pinned DESC, p.created_at DESC LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) (($page - 1) * $pageSize),
        $params
    );

    return [
        'items' => array_map(function ($r) use ($viewer) { return post_dto($r, $viewer); }, $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'pageCount' => max(1, (int) ceil($total / $pageSize)),
    ];
}

function h_posts_get()
{
    $viewer = current_user();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('posts'), $id, '帖子不存在或已被删除');
    $comments = q_all('SELECT * FROM ' . t('comments') . ' WHERE post_id = ? ORDER BY created_at ASC', [$id]);
    return [
        'post' => post_dto($row, $viewer),
        'comments' => array_map(function ($c) use ($viewer) { return comment_dto($c, $viewer); }, $comments),
    ];
}

function h_posts_create()
{
    $user = require_verified();
    $type = param_str('type', 'chat', 12) === 'course' ? 'course' : 'chat';
    $title = param_str('title', '', 80);
    $content = param_str('content', '', 8000);
    if (mb_strlen($title) < 2) {
        throw new ApiException('请填写标题');
    }
    if (mb_strlen($content) < 2) {
        throw new ApiException('内容不能为空');
    }

    $courseId = null;
    if ($type === 'course') {
        $cid = param_str('courseId', '', 40);
        find_or_fail(t('courses'), $cid, '请选择对应的课程');
        $courseId = $cid;
    }

    $id = new_id('p_');
    q(
        'INSERT INTO ' . t('posts') . ' (id, type, course_id, user_id, title, content, tags, pinned, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,0,?,?)',
        [
            $id, $type, $courseId, $user['id'], $title, $content,
            json_encode(param_tags('tags', 3), JSON_UNESCAPED_UNICODE),
            now_ms(), now_ms(),
        ]
    );
    return ['post' => post_dto(find_or_fail(t('posts'), $id, '帖子不存在'), $user)];
}

function h_posts_update()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('posts'), $id, '帖子不存在');
    if ($row['user_id'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只能编辑自己的帖子', 403);
    }
    q(
        'UPDATE ' . t('posts') . ' SET title = ?, content = ?, tags = ?, pinned = ?, updated_at = ? WHERE id = ?',
        [
            param_str('title', $row['title'], 80),
            param_str('content', $row['content'], 8000),
            json_encode(param_tags('tags', 3), JSON_UNESCAPED_UNICODE),
            (param_bool('pinned') && !empty($user['is_admin'])) ? 1 : (int) $row['pinned'],
            now_ms(),
            $id,
        ]
    );
    return ['post' => post_dto(find_or_fail(t('posts'), $id, '帖子不存在'), $user)];
}

function h_posts_delete()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('posts'), $id, '帖子不存在');
    if ($row['user_id'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只能删除自己的帖子', 403);
    }
    q('DELETE FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ?', ['post', $id]);
    q('DELETE FROM ' . t('comments') . ' WHERE post_id = ?', [$id]);
    q('DELETE FROM ' . t('posts') . ' WHERE id = ?', [$id]);
    return ['ok' => true];
}

function h_posts_like()
{
    $id = param_str('id', '', 40);
    find_or_fail(t('posts'), $id, '帖子不存在');
    return toggle_like('post', $id);
}

function h_comments_create()
{
    $user = require_verified();
    $postId = param_str('id', param_str('postId', param_str('post_id', '', 40), 40), 40);
    find_or_fail(t('posts'), $postId, '帖子不存在');
    $content = param_str('content', '', 3000);
    if ($content === '') {
        throw new ApiException('回复内容不能为空');
    }
    $id = new_id('m_');
    q('INSERT INTO ' . t('comments') . ' (id, post_id, user_id, content, created_at) VALUES (?,?,?,?,?)', [
        $id, $postId, $user['id'], $content, now_ms(),
    ]);
    return ['comment' => comment_dto(find_or_fail(t('comments'), $id, '回复不存在'), $user)];
}

function h_comments_delete()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    $row = find_or_fail(t('comments'), $id, '回复不存在');
    if ($row['user_id'] !== $user['id'] && empty($user['is_admin'])) {
        throw new ApiException('只能删除自己的回复', 403);
    }
    q('DELETE FROM ' . t('likes') . ' WHERE target_type = ? AND target_id = ?', ['comment', $id]);
    q('DELETE FROM ' . t('comments') . ' WHERE id = ?', [$id]);
    return ['ok' => true];
}

function h_comments_like()
{
    $id = param_str('id', '', 40);
    find_or_fail(t('comments'), $id, '回复不存在');
    return toggle_like('comment', $id);
}
