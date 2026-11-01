import { api } from '../api.js';
import { esc, timeAgo, emptyHTML, toast } from '../util.js';
import { courseCardHTML, bindLikeActions } from '../components.js';
import { session, navigate } from '../session.js';

const APPLICATION_STATUS = {
  pending: ['申请审核中', '管理员审核后会通过站内通知告知结果。'],
  approved: ['申请已通过', '你现在可以添加和批量导入课程。'],
  rejected: ['上次申请未通过', '完善维护计划后可以重新提交申请。'],
  cancelled: ['申请已撤回', '需要时可以重新提交。'],
};

function rankList(courses) {
  return courses.slice(0, 5).map((course, index) => `
    <a class="radar-course" href="#/courses/${esc(course.id)}">
      <span class="rank ${index === 0 ? 'first' : ''}">${index + 1}</span>
      <span><b>${esc(course.name)}</b><small>${esc(course.teacher)}</small></span>
      <strong>${course.stats.reviewCount ? Number(course.stats.avgRating).toFixed(1) : '–'}</strong>
    </a>`).join('');
}

function discussionList(posts) {
  if (!posts.length) return emptyHTML('还没有讨论', '来友邻社区发起第一个话题');
  return posts.slice(0, 4).map((post, index) => `
    <article>
      <span class="discussion-avatar tone-${index % 4}">${esc([...(post.author?.nickname || '?')][0])}</span>
      <div>
        <div class="discussion-meta"><b>${esc(post.author?.nickname || '匿名同学')}</b><span>${timeAgo(post.createdAt)}</span></div>
        <a class="discussion-title" href="#/post/${esc(post.id)}">${esc(post.title)}</a>
        <p>${esc(post.content || '')}</p>
        <footer><span>${post.commentCount || 0} 条回复</span><span>${post.likeCount || 0} 个赞</span></footer>
      </div>
    </article>`).join('');
}

function applicationCard(permission) {
  if (!session.user) return '<button class="btn-white" data-application-login>登录后申请</button>';
  if (session.user.canUploadCourses) return '<a class="btn-white" href="#/new-course">添加一门课程</a>';
  if (!session.user.verified) return '<a class="btn-white" href="#/verify">先完成在读认证</a>';
  if (permission?.application?.status === 'pending') return '<button class="btn-white" data-application-status>查看申请状态</button>';
  return '<button class="btn-white application-trigger">申请上传权限</button>';
}

function applicationModal(permission) {
  if (!session.user || !session.user.verified || session.user.canUploadCourses) return '';
  const application = permission?.application;
  const pending = application?.status === 'pending';
  const statusText = application ? APPLICATION_STATUS[application.status] : null;
  return `
    <div class="application-backdrop" hidden></div>
    <section class="application-dialog" aria-hidden="true" aria-labelledby="application-title">
      <header>
        <div><span>COURSE CONTRIBUTOR</span><h2 id="application-title">申请课程上传权限</h2><p>在读认证与上传权限相互独立，申请须经管理员审核。</p></div>
        <button class="application-close" type="button" aria-label="关闭">×</button>
      </header>
      <div class="application-content">
        <div class="applicant-summary">
          <span class="applicant-avatar">${esc([...session.user.nickname][0] || '?')}</span>
          <div><b>${esc(session.user.nickname)}</b><small>${esc(session.user.email || '')}</small></div>
          <span class="verified-mark">✓ 在读认证</span>
        </div>
        ${statusText ? `<div class="application-status ${esc(application.status)}"><b>${statusText[0]}</b><span>${statusText[1]}</span>${application.adminNote ? `<small>管理员备注：${esc(application.adminNote)}</small>` : ''}</div>` : ''}
        ${pending ? `
          <div class="application-readonly"><span>申请方向</span><b>${esc(application.direction)}</b><span>计划维护</span><b>${esc(application.plannedCourses)}</b><span>申请说明</span><p>${esc(application.reason)}</p></div>
          <div class="application-actions"><button class="btn application-cancel-request" type="button">撤回申请</button><button class="btn btn-primary application-close-secondary" type="button">知道了</button></div>` : `
          <form id="application-form">
            <label>申请方向<select name="direction" required><option value="">请选择</option><option>单门课程补充</option><option>批量课程整理</option><option>课程信息纠错</option><option>其他共建</option></select></label>
            <label>计划补充的课程或院系<input name="plannedCourses" maxlength="300" required placeholder="例如：国际法学院 2026 培养方案课程" /></label>
            <label>申请说明<textarea name="reason" minlength="10" maxlength="500" required placeholder="请说明信息来源、预计维护范围与更新频率"></textarea><small>至少 10 个字</small></label>
            <label class="application-check"><input type="checkbox" required /><span>我承诺仅提交真实、准确的课程信息，并接受管理员对异常内容的撤回处理。</span></label>
            <footer><button class="btn application-cancel" type="button">取消</button><button class="btn btn-primary" type="submit">提交申请</button></footer>
          </form>`}
      </div>
    </section>`;
}

export async function renderHome() {
  const data = await api.home();
  const { stats } = data;
  let permission = null;
  if (session.user && !session.user.canUploadCourses) {
    try { permission = await api.uploadPermissionStatus(); } catch {}
  }
  const featured = data.topCourses.length ? data.topCourses.slice(0, 4) : data.newCourses.slice(0, 4);

  return `
    <section class="home-hero">
      <div class="hero-copy">
        <span class="eyebrow">ECUPL COURSE DATABASE</span>
        <h1>发现好课，<em>少走弯路。</em></h1>
        <p>由华政同学共同维护的课程数据库。查课程、看评价、聊选课，让每一次选择都有据可循。</p>
        <form class="hero-search" id="home-search">
          <span aria-hidden="true">⌕</span>
          <input name="q" type="search" placeholder="搜索课程、教师或课程代码" autocomplete="off" />
          <button type="submit">搜索课程</button>
        </form>
        <div class="quick-row"><span>热门搜索</span><button data-query="民法">民法</button><button data-query="刑法">刑法</button><button data-query="英语">大学英语</button><button data-query="体育">体育</button></div>
        <div class="hero-stats">
          <div><strong>${stats.courseCount}</strong><span>门课程</span></div>
          <div><strong>${stats.reviewCount}</strong><span>条真实评价</span></div>
          <div><strong>${stats.userCount}</strong><span>位共建同学</span></div>
        </div>
      </div>
      <aside class="radar-card">
        <header><div><span>本周课程雷达</span><h2>同学们正在关注</h2></div><i>LIVE</i></header>
        <div>${rankList(data.hotCourses)}</div>
        <a href="#/courses?sort=popular">查看完整热度榜 <b>→</b></a>
      </aside>
    </section>

    <div class="home-layout">
      <div class="home-main">
        <section>
          <div class="home-section-heading"><div><span>精选课程</span><h2>高分课程推荐</h2></div><a href="#/courses?sort=top">浏览全部课程 →</a></div>
          ${featured.length ? `<div class="course-grid home-course-grid">${featured.map(courseCardHTML).join('')}</div>` : emptyHTML('课程库正在建设中')}
        </section>
        <section class="home-discussions">
          <div class="home-section-heading"><div><span>友邻社区</span><h2>最新讨论</h2></div><a href="#/chat">进入社区 →</a></div>
          <div class="discussion-list">${discussionList(data.latestPosts)}</div>
        </section>
      </div>
      <aside class="home-sidebar">
        <section class="contributor-card">
          <span class="side-icon">＋</span>
          <h3>申请成为课程共建者</h3>
          <p>帮助完善课程信息，让更多同学受益。上传权限由管理员单独审核授予。</p>
          ${applicationCard(permission)}
        </section>
        <section class="side-card activity-card">
          <div class="side-title"><h3>社区动态</h3><span>实时</span></div>
          <ul>${data.latestReviews.slice(0, 3).map((review) => `<li><span class="activity-dot"></span><p><b>${esc(review.author?.nickname || '同学')}</b> 评价了 <a href="#/courses/${esc(review.course?.id || '')}">${esc(review.course?.name || '课程')}</a><small>${timeAgo(review.createdAt)}</small></p></li>`).join('') || '<li class="muted small">暂无动态</li>'}</ul>
        </section>
        <section class="side-card notice-card"><div class="side-title"><h3>社区公约</h3><a href="#/chat">查看</a></div><p><span>01</span>尊重真实体验，拒绝人身攻击</p><p><span>02</span>保护个人隐私，不公开敏感信息</p><p><span>03</span>课程信息共建，提交前请核对</p></section>
      </aside>
    </div>
    ${applicationModal(permission)}
  `;
}

export function mountHome(root) {
  bindLikeActions(root, () => window.dispatchEvent(new HashChangeEvent('hashchange')));
  const search = root.querySelector('#home-search');
  search?.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = new FormData(search).get('q')?.trim();
    navigate(query ? `#/courses?q=${encodeURIComponent(query)}` : '#/courses');
  });
  root.querySelectorAll('[data-query]').forEach((button) => button.addEventListener('click', () => {
    search.querySelector('input').value = button.dataset.query;
    search.requestSubmit();
  }));
  root.querySelector('[data-application-login]')?.addEventListener('click', () => navigate('#/login'));

  const dialog = root.querySelector('.application-dialog');
  const backdrop = root.querySelector('.application-backdrop');
  if (!dialog || !backdrop) return;
  const open = () => { backdrop.hidden = false; dialog.classList.add('open'); dialog.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open'); };
  const close = () => { dialog.classList.remove('open'); dialog.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open'); setTimeout(() => { backdrop.hidden = true; }, 220); };
  root.querySelectorAll('.application-trigger, [data-application-status]').forEach((button) => button.addEventListener('click', open));
  root.querySelectorAll('.application-close, .application-cancel, .application-close-secondary').forEach((button) => button.addEventListener('click', close));
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && dialog.classList.contains('open')) close(); });

  root.querySelector('#application-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form));
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      await api.submitUploadPermission(data);
      close();
      toast('申请已提交，审核结果会通过站内通知告知', 'ok');
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    } catch (err) {
      toast(err.message, 'error');
      submit.disabled = false;
    }
  });
  root.querySelector('.application-cancel-request')?.addEventListener('click', async () => {
    if (!window.confirm('确认撤回这份申请？')) return;
    try {
      await api.cancelUploadPermission();
      close();
      toast('申请已撤回', 'ok');
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    } catch (err) { toast(err.message, 'error'); }
  });
}
