import { api } from '../api.js';
import { esc, timeAgo, emptyHTML, starsHTML, avatarHTML, toast } from '../util.js';
import { reviewItemHTML, postItemHTML, courseCardHTML, bindLikeActions } from '../components.js';
import { session, setUser, navigate, reload } from '../session.js';

/* ------------------------- 登录 / 注册 ------------------------- */

function redirectTo(params) {
  const to = params.get('redirect');
  navigate(to ? `#${to}` : '#/');
}

export function renderLogin(params) {
  return `
    <div style="max-width:420px;margin:20px auto">
      <div class="card">
        <h2 style="margin-bottom:14px">登录</h2>
        <form id="login-form">
          <div class="field">
            <label>邮箱</label>
            <input type="email" name="email" required placeholder="you@example.com" />
          </div>
          <div class="field">
            <label>密码</label>
            <input type="password" name="password" required placeholder="至少 6 位" />
          </div>
          <button class="btn btn-primary" type="submit" style="width:100%">登录</button>
        </form>
        <div class="divider"></div>
        <div class="small muted">还没有账号？<a href="#/register">立即注册</a></div>
        <div class="small muted" style="margin-top:6px">演示账号：demo@hz.example / 123456</div>
      </div>
    </div>
  `;
}

export function mountLogin(root, params) {
  root.querySelector('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const { user } = await api.login({ email: fd.get('email'), password: fd.get('password') });
      setUser(user);
      toast(`欢迎回来，${user.nickname}`, 'ok');
      redirectTo(params);
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

export function renderRegister(params) {
  return `
    <div style="max-width:460px;margin:20px auto">
      <div class="card">
        <h2 style="margin-bottom:4px">注册</h2>
        <p class="muted small">注册后才可以评分、发帖和添加课程。</p>
        <form id="register-form">
          <div class="field">
            <label>邮箱 *</label>
            <input type="email" name="email" required placeholder="you@example.com" />
            <div class="hint">用于登录，不会公开展示</div>
          </div>
          <div class="field">
            <label>昵称 *</label>
            <input type="text" name="nickname" required placeholder="社区里显示的名字" />
          </div>
          <div class="field">
            <label>密码 *</label>
            <input type="password" name="password" required placeholder="至少 6 位" />
          </div>
          <div class="row">
            <div class="field">
              <label>学号（选填）</label>
              <input type="text" name="studentId" placeholder="仅自己可见" />
            </div>
            <div class="field">
              <label>届别 / 年级（选填）</label>
              <input type="text" name="grade" placeholder="例如 2024 级" />
            </div>
          </div>
          <button class="btn btn-primary" type="submit" style="width:100%">注册并登录</button>
        </form>
        <div class="divider"></div>
        <div class="small muted">已有账号？<a href="#/login">去登录</a></div>
      </div>
    </div>
  `;
}

export function mountRegister(root, params) {
  root.querySelector('#register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const { user } = await api.register(Object.fromEntries(fd.entries()));
      setUser(user);
      toast('注册成功，欢迎加入', 'ok');
      redirectTo(params);
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

/* ------------------------- 我的主页 ------------------------- */

export async function renderMe(params) {
  if (!session.user) {
    return `<div class="card">${emptyHTML('请先登录')}<div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div></div>`;
  }
  const feed = await api.myFeed();
  const u = session.user;
  const tab = params.get('tab') || 'reviews';

  const tabs = [
    { key: 'reviews', label: `我的短评 (${feed.reviews.length})` },
    { key: 'posts', label: `我的帖子 (${feed.posts.length})` },
    { key: 'courses', label: `我添加的课 (${feed.courses.length})` },
    { key: 'profile', label: '资料设置' },
  ];

  const body = {
    reviews: feed.reviews.length ? feed.reviews.map((r) => reviewItemHTML(r)).join('') : emptyHTML('还没有写过短评'),
    posts: feed.posts.length ? `<div class="post-list">${feed.posts.map(postItemHTML).join('')}</div>` : emptyHTML('还没有发过帖'),
    courses: feed.courses.length ? `<div class="course-grid">${feed.courses.map(courseCardHTML).join('')}</div>` : emptyHTML('还没有添加课程'),
    profile: `
      <form id="profile-form" style="max-width:420px">
        <div class="field"><label>昵称</label><input type="text" name="nickname" value="${esc(u.nickname)}" required /></div>
        <div class="field"><label>学号（仅自己可见）</label><input type="text" name="studentId" value="${esc(u.studentId || '')}" /></div>
        <div class="field"><label>年级 / 届别</label><input type="text" name="grade" value="${esc(u.grade || '')}" placeholder="例如 2024 级" /></div>
        <div class="field"><label>个人简介</label><textarea name="bio" style="min-height:70px">${esc(u.bio || '')}</textarea></div>
        <button class="btn btn-primary" type="submit">保存</button>
      </form>`,
  }[tab];

  return `
    <div class="card">
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        ${avatarHTML(u, 56)}
        <div style="flex:1">
          <h2 style="margin:0">${esc(u.nickname)}</h2>
          <div class="muted small">${esc(u.email)}${u.isAdmin ? ' · 管理员' : ''} · 加入于 ${timeAgo(u.joinedAt)}</div>
          ${u.bio ? `<div style="margin-top:4px">${esc(u.bio)}</div>` : ''}
        </div>
      </div>
      <div style="margin-top:14px">${tabs.map((t) => `<a class="chip ${tab === t.key ? 'active' : ''}" href="#/me?tab=${t.key}">${t.label}</a>`).join('')}</div>
    </div>
    <div class="card" style="margin-top:16px">${body}</div>
  `;
}

export function mountMe(root, params) {
  bindLikeActions(root, reload);
  root.querySelector('#profile-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const { user } = await api.profile(Object.fromEntries(fd.entries()));
      setUser(user);
      toast('资料已保存', 'ok');
      reload();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

/* ------------------------- 他人主页 ------------------------- */

export async function renderUser(params, id) {
  const data = await api.user(id);
  const u = data.user;
  return `
    <div class="breadcrumb"><a href="#/">首页</a> / ${esc(u.nickname)}</div>
    <div class="card">
      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        ${avatarHTML(u, 52)}
        <div style="flex:1">
          <h2 style="margin:0">${esc(u.nickname)}${u.isAdmin ? ' <span class="tag">管理员</span>' : ''}${u.verified ? ' <span class="verified-tag">在读认证</span>' : ''}</h2>
          <div class="muted small">加入于 ${timeAgo(u.joinedAt)}${u.grade ? ` · ${esc(u.grade)}` : ''}</div>
          ${u.bio ? `<div style="margin-top:4px">${esc(u.bio)}</div>` : ''}
        </div>
        <div class="stat-row" style="margin:0">
          <div><div class="num">${data.stats.reviews}</div><div class="muted small">短评</div></div>
          <div><div class="num">${data.stats.posts}</div><div class="muted small">帖子</div></div>
          <div><div class="num">${data.stats.courses}</div><div class="muted small">课程</div></div>
        </div>
        ${session.user && session.user.id !== u.id ? `<a class="btn btn-sm btn-primary" href="#/messages?to=${esc(u.id)}">发私信</a>` : ''}
      </div>
    </div>
    <div class="card" style="margin-top:16px">
      <div class="card-title"><h3>TA 的短评</h3></div>
      ${data.reviews.length ? data.reviews.map((r) => reviewItemHTML(r)).join('') : emptyHTML('还没有短评')}
    </div>
    <div class="card">
      <div class="card-title"><h3>TA 的帖子</h3></div>
      ${data.posts.length ? `<div class="post-list">${data.posts.map(postItemHTML).join('')}</div>` : emptyHTML('还没有帖子')}
    </div>
    <div class="card">
      <div class="card-title"><h3>TA 添加的课程</h3></div>
      ${data.courses.length ? `<div class="course-grid">${data.courses.map(courseCardHTML).join('')}</div>` : emptyHTML('还没有添加课程')}
    </div>
  `;
}

export function mountUser(root) {
  bindLikeActions(root, reload);
}
