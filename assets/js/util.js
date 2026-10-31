/** 通用工具：转义、时间、星级、头像、事件绑定、提示 */

export function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function timeAgo(ts) {
  if (!ts) return '';
  const diff = Date.now() - ts;
  if (diff < 60 * 1000) return '刚刚';
  if (diff < 60 * 60 * 1000) return `${Math.floor(diff / 60000)} 分钟前`;
  if (diff < 24 * 60 * 60 * 1000) return `${Math.floor(diff / 3600000)} 小时前`;
  if (diff < 30 * 24 * 3600 * 1000) return `${Math.floor(diff / 86400000)} 天前`;
  const d = new Date(ts);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function starsHTML(rating, { text = false } = {}) {
  const r = Math.round(Number(rating) || 0);
  const on = '★'.repeat(r);
  const off = '☆'.repeat(Math.max(0, 5 - r));
  return `<span class="stars">${on}<span class="off">${off}</span></span>${text ? ` <b>${(Number(rating) || 0).toFixed(1)}</b>` : ''}`;
}

export function scoreText(rating, count) {
  const avg = Number(rating) || 0;
  if (!count) return '<span class="muted small">暂无评分</span>';
  return `<span class="score">${avg.toFixed(1)}</span> <span class="muted small">${count} 人评</span>`;
}

export function avatarHTML(user, size = 28) {
  const name = user?.nickname || '?';
  const color = user?.avatarColor || '#2f7d51';
  const initial = esc([...name][0] || '?');
  return `<span class="avatar" style="width:${size}px;height:${size}px;background:${esc(color)};font-size:${Math.round(size * 0.45)}px">${initial}</span>`;
}

export function userLink(user) {
  if (!user || !user.id) return `<span class="muted">${esc(user?.nickname || '匿名')}</span>`;
  return `<a href="#/u/${esc(user.id)}">${esc(user.nickname)}</a>`;
}

export function toast(message, type = '') {
  const box = document.getElementById('toast-box');
  if (!box) return;
  const node = document.createElement('div');
  node.className = `toast ${type}`;
  node.textContent = message;
  box.appendChild(node);
  setTimeout(() => node.remove(), 2400);
}

export function qs(root, selector) {
  return root.querySelector(selector);
}

export function qsa(root, selector) {
  return Array.from(root.querySelectorAll(selector));
}

/** 在容器上做事件委托绑定 */
export function on(root, selectorOrEvent, maybeEvent, maybeHandler) {
  if (typeof maybeEvent === 'function') {
    const [selector, handler] = [selectorOrEvent, maybeEvent];
    root.querySelectorAll(selector).forEach((el) => handler(el));
    return;
  }
  const [selector, event, handler] = [selectorOrEvent, maybeEvent, maybeHandler];
  root.addEventListener(event, (e) => {
    const target = e.target.closest(selector);
    if (target && root.contains(target)) handler(e, target);
  });
}

export function pagerHTML(page, pageCount) {
  if (pageCount <= 1) return '';
  const parts = [];
  const add = (p, label, cls = '') => parts.push(`<button class="btn btn-sm ${cls}" data-page="${p}">${label}</button>`);
  if (page > 1) add(page - 1, '上一页');
  const start = Math.max(1, page - 2);
  const end = Math.min(pageCount, start + 4);
  for (let p = start; p <= end; p++) add(p, String(p), p === page ? 'current' : '');
  if (page < pageCount) add(page + 1, '下一页');
  return `<div class="pager">${parts.join('')}</div>`;
}

export function emptyHTML(text = '这里还什么都没有', hint = '') {
  return `<div class="empty">${esc(text)}${hint ? `<div class="small">${esc(hint)}</div>` : ''}</div>`;
}

export function trimText(text, n = 90) {
  const s = String(text || '').replace(/\s+/g, ' ').trim();
  return s.length > n ? `${s.slice(0, n)}…` : s;
}
