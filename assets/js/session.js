/** 前端登录态与顶部导航渲染 */
import { api } from './api.js';
import { esc, avatarHTML, toast } from './util.js';

export const session = { user: null, config: null, unread: { notifications: 0, messages: 0, total: 0 } };

export function setUser(user) {
  session.user = user;
  renderHeader();
  refreshUnread().then(renderHeader);
}

let reloadFn = () => location.reload();

/** 由路由注册实际的重新渲染函数 */
export function setReload(fn) {
  reloadFn = fn;
}

/** 重新渲染当前页面（用于点赞、发帖后刷新） */
export function reload() {
  reloadFn();
}

export function navigate(hash) {
  if (location.hash === hash) {
    reload();
  } else {
    location.hash = hash;
  }
}

export function requireLogin() {
  if (session.user) return true;
  toast('请先登录后再操作', 'error');
  navigate('#/login');
  return false;
}

/** 发帖、评分、评论、点赞、私信前调用 */
export function requireVerified() {
  if (!session.user) {
    toast('请先登录后再操作', 'error');
    navigate('#/login');
    return false;
  }
  if (session.user.banned) {
    toast('账号已被限制发言，如有疑问请通过站内信联系管理员', 'error');
    return false;
  }
  if (!session.user.verified) {
    toast('完成学生认证后才能发帖、评分和评论', 'error');
    navigate('#/verify');
    return false;
  }
  return true;
}

export async function refreshUnread() {
  if (!session.user) {
    session.unread = { notifications: 0, messages: 0, total: 0 };
    return;
  }
  try {
    session.unread = await api.unread();
  } catch {
    session.unread = { notifications: 0, messages: 0, total: 0 };
  }
}

export function renderHeader() {
  const box = document.getElementById('user-area');
  if (!box) return;
  if (session.user) {
    const u = session.user;
    const unread = session.unread || { total: 0 };
    const badge = unread.total > 0
      ? `<span class="unread-badge">${unread.total > 99 ? '99+' : unread.total}</span>`
      : '';
    const verifiedTag = u.verified
      ? '<span class="verified-tag" title="已通过学生认证">在读认证</span>'
      : '<a class="btn btn-sm btn-warn" href="#/verify" title="完成认证后可发帖评分">去认证</a>';
    box.innerHTML = `
      <a class="msg-link" href="#/messages" title="站内信">✉${badge}</a>
      ${u.isAdmin ? '<a class="btn btn-sm" href="#/admin">管理后台</a>' : ''}
      <a class="avatar-btn" href="#/me" title="我的主页">
        ${avatarHTML(u, 28)}
        <span>${esc(u.nickname)}</span>
      </a>
      ${verifiedTag}
      <button class="btn btn-sm" id="logout-btn">退出</button>
    `;
    box.querySelector('#logout-btn').addEventListener('click', async () => {
      try {
        await api.logout();
      } catch {}
      setUser(null);
      toast('已退出登录', 'ok');
      navigate('#/');
    });
  } else {
    box.innerHTML = `<a class="btn btn-sm" href="#/login">登录</a><a class="btn btn-sm btn-primary" href="#/register">注册</a>`;
  }
}

export async function loadConfig() {
  if (session.config) return session.config;
  try {
    session.config = await api.config();
  } catch {
    session.config = { categories: [], colleges: [], reviewTags: [], chatTags: [] };
  }
  return session.config;
}

export async function bootstrapSession() {
  try {
    const data = await api.me();
    session.user = data.user;
    await refreshUnread();
  } catch {
    session.user = null;
  }
  renderHeader();
}
