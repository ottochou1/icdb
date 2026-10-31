<?php
/** 站内私信与系统通知 */

function h_messages_unread()
{
    $user = current_user();
    return ['unread' => unread_counts($user ? $user['id'] : null)];
}

function h_messages_conversations()
{
    $user = require_login();
    $rows = q_all(
        'SELECT * FROM ' . t('conversations') . ' WHERE user1_id = ? OR user2_id = ? ORDER BY last_at DESC, id DESC LIMIT 100',
        [$user['id'], $user['id']]
    );
    $viewerId = $user['id'];
    return [
        'items' => array_map(function ($r) use ($viewerId) {
            return conversation_dto($r, $viewerId);
        }, $rows),
        'unread' => unread_counts($viewerId),
    ];
}

function h_messages_thread()
{
    $user = require_login();
    $convId = param_str('id', param_str('conversationId', param_str('conv', '', 40), 40), 40);
    $conv = find_or_fail(t('conversations'), $convId, '会话不存在');
    if ($conv['user1_id'] !== $user['id'] && $conv['user2_id'] !== $user['id']) {
        throw new ApiException('无权查看该会话', 403);
    }
    $rows = q_all(
        'SELECT * FROM ' . t('messages') . ' WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 300',
        [$convId]
    );
    q(
        'UPDATE ' . t('messages') . ' SET is_read = 1 WHERE conversation_id = ? AND is_read = 0 AND sender_id <> ?',
        [$convId, $user['id']]
    );
    $otherId = $conv['user1_id'] === $user['id'] ? $conv['user2_id'] : $conv['user1_id'];
    $viewerId = $user['id'];
    return [
        'conversation' => conversation_dto($conv, $viewerId),
        'other' => public_user(get_user($otherId)),
        'items' => array_map(function ($m) use ($viewerId) {
            return [
                'id' => $m['id'],
                'senderId' => $m['sender_id'],
                'content' => (string) $m['content'],
                'createdAt' => (int) $m['created_at'],
                'isRead' => (bool) $m['is_read'],
                'mine' => $m['sender_id'] === $viewerId,
                'author' => public_user(get_user($m['sender_id'])),
            ];
        }, $rows),
    ];
}

function h_messages_send()
{
    $user = require_verified();
    $toId = param_str('userId', param_str('toUserId', param_str('to', '', 40), 40), 40);
    $content = trim((string) param_raw('content', ''));
    if ($toId === '') {
        throw new ApiException('请选择收信人');
    }
    if ($content === '') {
        throw new ApiException('消息内容不能为空');
    }
    if (mb_strlen($content) > 1000) {
        $content = mb_substr($content, 0, 1000);
    }
    $to = get_user($toId);
    if (!$to) {
        throw new ApiException('收信人不存在');
    }
    if ($toId === $user['id']) {
        throw new ApiException('不能给自己发私信');
    }

    $conv = get_or_create_conversation($user['id'], $toId);
    $id = new_id('ms_');
    $now = now_ms();
    q(
        'INSERT INTO ' . t('messages') . ' (id, conversation_id, sender_id, content, is_read, created_at) VALUES (?,?,?,?,0,?)',
        [$id, $conv['id'], $user['id'], $content, $now]
    );
    q(
        'UPDATE ' . t('conversations') . ' SET last_message = ?, last_sender_id = ?, last_at = ? WHERE id = ?',
        [mb_substr($content, 0, 300), $user['id'], $now, $conv['id']]
    );

    notify_user(
        $toId,
        $user['nickname'] . ' 给你发了一条私信',
        mb_substr($content, 0, 100),
        '#/messages?conv=' . $conv['id'],
        'dm'
    );

    return [
        'conversationId' => $conv['id'],
        'message' => [
            'id' => $id,
            'senderId' => $user['id'],
            'content' => $content,
            'createdAt' => $now,
            'isRead' => false,
            'mine' => true,
            'author' => public_user($user),
        ],
    ];
}

function h_messages_read()
{
    $user = require_login();
    $convId = param_str('id', param_str('conversationId', '', 40), 40);
    if ($convId === '') {
        q(
            'UPDATE ' . t('messages') . ' m INNER JOIN ' . t('conversations') . ' c ON c.id = m.conversation_id
             SET m.is_read = 1
             WHERE m.is_read = 0 AND m.sender_id <> ? AND (c.user1_id = ? OR c.user2_id = ?)',
            [$user['id'], $user['id'], $user['id']]
        );
    } else {
        $conv = find_or_fail(t('conversations'), $convId, '会话不存在');
        if ($conv['user1_id'] !== $user['id'] && $conv['user2_id'] !== $user['id']) {
            throw new ApiException('无权操作该会话', 403);
        }
        q(
            'UPDATE ' . t('messages') . ' SET is_read = 1 WHERE conversation_id = ? AND is_read = 0 AND sender_id <> ?',
            [$convId, $user['id']]
        );
    }
    return ['ok' => true, 'unread' => unread_counts($user['id'])];
}

function h_notifications_list()
{
    $user = require_login();
    $rows = q_all(
        'SELECT * FROM ' . t('notifications') . ' WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100',
        [$user['id']]
    );
    return [
        'items' => array_map(function ($n) {
            return [
                'id' => $n['id'],
                'kind' => $n['kind'],
                'title' => (string) $n['title'],
                'content' => (string) $n['content'],
                'link' => (string) $n['link'],
                'isRead' => (bool) $n['is_read'],
                'createdAt' => (int) $n['created_at'],
            ];
        }, $rows),
        'unread' => unread_counts($user['id']),
    ];
}

function h_notifications_read()
{
    $user = require_login();
    $id = param_str('id', '', 40);
    if ($id === '') {
        q('UPDATE ' . t('notifications') . ' SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$user['id']]);
    } else {
        q('UPDATE ' . t('notifications') . ' SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $user['id']]);
    }
    return ['ok' => true, 'unread' => unread_counts($user['id'])];
}
