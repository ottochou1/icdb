/** 路由与页面装配 */
import { esc } from './util.js';
import { bootstrapSession, setReload, renderHeader, session, syncAccessUI } from './session.js';
import * as home from './views/home.js';
import * as courses from './views/courses.js';
import * as forum from './views/forum.js';
import * as auth from './views/auth.js';
import * as verify from './views/verify.js';
import * as messages from './views/messages.js';
import * as admin from './views/admin.js';

const routes = [
  { re: /^\/?$/, render: home.renderHome, mount: home.mountHome, nav: '/' },
  { re: /^\/courses$/, render: courses.renderCourseList, mount: courses.mountCourseList, nav: '/courses' },
  { re: /^\/courses\/([^/]+)\/edit$/, render: courses.renderCourseForm, mount: courses.mountCourseForm, nav: '/courses' },
  { re: /^\/courses\/([^/]+)$/, render: courses.renderCourseDetail, mount: courses.mountCourseDetail, nav: '/courses' },
  { re: /^\/new-course$/, render: courses.renderCourseForm, mount: courses.mountCourseForm, nav: '/courses' },
  { re: /^\/import$/, render: courses.renderImport, mount: courses.mountImport, nav: '/courses' },
  { re: /^\/chat$/, render: forum.renderChat, mount: forum.mountChat, nav: '/chat' },
  { re: /^\/new-post$/, render: forum.renderNewPost, mount: forum.mountNewPost, nav: '/chat' },
  { re: /^\/post\/([^/]+)$/, render: forum.renderPost, mount: forum.mountPost, nav: '/chat' },
  { re: /^\/login$/, render: auth.renderLogin, mount: auth.mountLogin, nav: '' },
  { re: /^\/register$/, render: auth.renderRegister, mount: auth.mountRegister, nav: '' },
  { re: /^\/me$/, render: auth.renderMe, mount: auth.mountMe, nav: '' },
  { re: /^\/u\/([^/]+)$/, render: auth.renderUser, mount: auth.mountUser, nav: '' },
  { re: /^\/verify$/, render: verify.renderVerify, mount: verify.mountVerify, nav: '' },
  { re: /^\/messages$/, render: messages.renderMessages, mount: messages.mountMessages, nav: '' },
  { re: /^\/admin$/, render: admin.renderAdmin, mount: admin.mountAdmin, nav: '' },
];

function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const [path, search = ''] = raw.split('?');
  return { path: path || '/', params: new URLSearchParams(search) };
}

function highlightNav(nav) {
  document.querySelectorAll('.main-nav a').forEach((a) => {
    const href = a.getAttribute('href').replace('#', '');
    a.classList.toggle('active', !!nav && href === nav);
  });
  document.querySelectorAll('[data-mobile-nav]').forEach((a) => {
    const target = a.getAttribute('data-mobile-nav');
    a.classList.toggle('active', target === nav || (!nav && target === '/me' && /^\/(me|messages|verify)/.test(parseHash().path)));
  });
}

let rendering = false;

async function renderRoute() {
  if (rendering) return;
  rendering = true;
  const app = document.getElementById('app');
  const { path, params } = parseHash();

  if (session.maintenanceMode && (!session.user || !session.user.isAdmin) && path !== '/login') {
    rendering = false;
    if (location.hash !== '#/login') location.hash = '#/login';
    else renderRoute();
    return;
  }

  const matched = routes.map((r) => ({ r, m: path.match(r.re) })).find((x) => x.m);
  highlightNav(matched ? matched.r.nav : '');

  if (!matched) {
    app.innerHTML = `<div class="card"><div class="empty">页面不存在</div><div style="text-align:center"><a class="btn" href="#/">回到首页</a></div></div>`;
    rendering = false;
    return;
  }

  app.innerHTML = `<div class="empty">加载中…</div>`;
  const args = matched.m.slice(1);
  try {
    const html = await matched.r.render(params, ...args);
    // 渲染期间路由可能已变化
    if (parseHash().path !== path) {
      rendering = false;
      return renderRoute();
    }
    app.innerHTML = html;
    matched.r.mount && matched.r.mount(app, params, ...args);
    if (!params.get('noscroll')) window.scrollTo({ top: 0 });
  } catch (err) {
    app.innerHTML = `<div class="card"><div class="empty">${esc(err.message || '加载失败')}</div><div style="text-align:center"><button class="btn" onclick="location.reload()">重试</button></div></div>`;
  }
  rendering = false;
}

function bindGlobal() {
  const form = document.getElementById('header-search');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const q = new FormData(form).get('q');
    location.hash = q ? `#/courses?q=${encodeURIComponent(q)}` : '#/courses';
  });
  window.addEventListener('hashchange', renderRoute);
}

(async function start() {
  await bootstrapSession();
  syncAccessUI();
  renderHeader();
  setReload(renderRoute);
  bindGlobal();
  if (!location.hash) location.hash = '#/';
  renderRoute();
})();
