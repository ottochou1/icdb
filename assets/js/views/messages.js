import { api } from '../api.js';
import { esc, timeAgo, toast, avatarHTML, emptyHTML } from '../util.js';
import { session, reload, refreshUnread } from '../session.js';

function noticeItemHTML(n) {
  return `
    <div class="notice-row ${n.isRead ? '' : 'unread'}">
      <div class="notice-head">
        <b>${esc(n.title || '通知')}</b>
        <span class="small muted">${timeAgo(n.createdAt)}</span>
      </div>
      <div class="small">${esc(n.content)}</div>
      <div class="notice-actions">
        ${n.link ? `<a class="btn btn-sm" href="${esc(n.link)}">前往处理</a>` : ''}
        ${n.isRead ? '' : `<button class="btn btn-sm" data-read-notice="${esc(n.id)}">标记已读</button>`}
      </div>
    </div>`;
}

function convItemHTML(c) {
  return `
    <a class="conv-row" href="#/messages?tab=chats&conv=${esc(c.id)}">
      ${avatarHTML(c.other, 36)}
      <div class="conv-main">
        <div class="conv-top">
          <b>${esc(c.other ? c.other.nickname : '未知用户')}</b>
          <span class="small muted">${c.lastAt ? timeAgo(c.lastAt) : ''}</span>
        </div>
        <div class="small muted conv-last">${esc(c.lastMessage || '（还没有消息）')}</div>
      </div>
      ${c.unread ? `<span class="unread-badge">${c.unread > 99 ? '99+' : c.unread}</span>` : ''}
    </a>`;
}

function msgBubbleHTML(m) {
  return `
    <div class="msg ${m.mine ? 'mine' : ''}">
      ${m.mine ? '' : avatarHTML(m.author, 28)}
      <div class="bubble">
        <div>${esc(m.content)}</div>
        <div class="small muted">${timeAgo(m.createdAt)}</div>
      </div>
    </div>`;
}

export async function renderMessages(params) {
  if (!session.user) {
    return `
      <div style="max-width:520px;margin:20px auto">
        <div class="card">
          ${emptyHTML('请先登录查看站内信')}
          <div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div>
        </div>
      </div>`;
  }

  const convId = params.get('conv');
  const toId = params.get('to');
  const tab = params.get('tab') || (convId || toId ? 'chats' : 'notifications');
  const u = session.user;

  let body = '';
  if (tab === 'notifications') {
    const data = await api.notifications();
    const items = data.items || [];
    body = `
      <div class="notice-toolbar">
        <span class="small muted">共 ${items.length} 条，未读 ${data.unread ? data.unread.notifications : 0} 条</span>
        <button class="btn btn-sm" id="read-all-notices">全部标为已读</button>
      </div>
      ${items.length ? `<div class="notice-list">${items.map(noticeItemHTML).join('')}</div>` : emptyHTML('还没有通知')}`;
  } else {
    const data = await api.conversations();
    const convs = data.items || [];

    if (convId) {
      let thread = null;
      let threadError = '';
      try {
        thread = await api.thread(convId);
      } catch (err) {
        threadError = err.message;
      }
      if (threadError) {
        body = `
          <div class="notice error">
            <b>无法打开这个会话</b>
            <div class="small">${esc(threadError)}</div>
            <div style="margin-top:10px"><a class="btn btn-sm" href="#/messages?tab=chats">返回会话列表</a></div>
          </div>`;
      } else {
      const other = thread.other;
      const canSend = u.verified && !u.banned;
      body = `
        <div class="thread">
          <div class="thread-head">
            <a class="small muted" href="#/messages?tab=chats">← 返回会话列表</a>
            <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
              ${avatarHTML(other, 32)}
              <b>${esc(other ? other.nickname : '未知用户')}</b>
              ${other && other.id ? `<a class="small" href="#/u/${esc(other.id)}">查看主页</a>` : ''}
            </div>
          </div>
          <div class="msg-list">${(thread.items || []).map(msgBubbleHTML).join('') || emptyHTML('还没有消息，打个招呼吧')}</div>
          ${canSend ? `
          <form id="msg-form" class="msg-form" data-to="${esc(other ? other.id : '')}">
            <textarea name="content" placeholder="输入消息…" required></textarea>
            <button class="btn btn-primary btn-sm" type="submit">发送</button>
          </form>` : `
          <div class="notice pending small">${u.banned ? '账号已被限制发言，无法发送私信。' : '完成学生认证后才能发送私信。'}</div>`}
        </div>`;
      }
    } else if (toId) {
      const target = await api.user(toId).catch(() => null);
      const canSend = u.verified && !u.banned;
      body = `
        <div class="thread">
          <div class="thread-head">
            <a class="small muted" href="#/messages?tab=chats">← 返回会话列表</a>
            <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
              ${avatarHTML(target && target.user, 32)}
              <b>发给 ${esc((target && target.user && target.user.nickname) || '对方')}</b>
            </div>
          </div>
          <div class="msg-list">${emptyHTML('新的对话')}</div>
          ${canSend ? `
          <form id="new-msg-form" class="msg-form" data-to="${esc(toId)}">
            <textarea name="content" placeholder="输入消息…" required></textarea>
            <button class="btn btn-primary btn-sm" type="submit">发送</button>
          </form>` : `
          <div class="notice pending small">${u.banned ? '账号已被限制发言，无法发送私信。' : '完成学生认证后才能发送私信。'}</div>`}
        </div>`;
    } else {
      body = convs.length
        ? `<div class="conv-list">${convs.map(convItemHTML).join('')}</div>`
        : emptyHTML('还没有私信会话', '在同学主页点「发私信」即可开始对话');
    }
  }

  const unread = session.unread || { notifications: 0, messages: 0 };
  return `
    <div style="max-width:720px;margin:20px auto">
      <div class="card">
        <h2 style="margin-bottom:12px">站内信</h2>
        <div class="tabs">
          <a class="tab ${tab === 'notifications' ? 'active' : ''}" href="#/messages?tab=notifications">
            通知${unread.notifications ? ` <span class="unread-badge">${unread.notifications}</span>` : ''}
          </a>
          <a class="tab ${tab === 'chats' ? 'active' : ''}" href="#/messages?tab=chats">
            私信${unread.messages ? ` <span class="unread-badge">${unread.messages}</span>` : ''}
          </a>
        </div>
        <div class="divider"></div>
        ${body}
      </div>
    </div>`;
}

export function mountMessages(root, params) {
  const convId = params.get('conv');

  const readAll = root.querySelector('#read-all-notices');
  if (readAll) {
    readAll.addEventListener('click', async () => {
      try {
        await api.readNotifications();
        await refreshUnread();
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }

  root.querySelectorAll('[data-read-notice]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await api.readNotifications(btn.getAttribute('data-read-notice'));
        await refreshUnread();
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  const sendForm = root.querySelector('#msg-form');
  if (sendForm && convId) {
    sendForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      if (!String(content).trim()) return;
      const btn = e.target.querySelector('button');
      btn.disabled = true;
      try {
        await api.sendMessage(sendForm.getAttribute('data-to'), content);
        e.target.reset();
        await refreshUnread();
        reload();
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      }
    });
  }

  const newForm = root.querySelector('#new-msg-form');
  if (newForm) {
    newForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      const to = newForm.getAttribute('data-to');
      if (!String(content).trim()) return;
      const btn = e.target.querySelector('button');
      btn.disabled = true;
      try {
        const res = await api.sendMessage(to, content);
        toast('已发送', 'ok');
        location.hash = `#/messages?tab=chats&conv=${res.conversationId}`;
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      }
    });
  }

  // 打开会话时后端已标记已读，这里同步一下顶部徽标
  if (convId) refreshUnread().then(() => window.dispatchEvent(new Event('unread-changed')));
}
