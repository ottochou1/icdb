import { api } from '../api.js';
import { esc, timeAgo, starsHTML, emptyHTML } from '../util.js';
import { courseCardHTML, reviewItemHTML, postItemHTML, bindLikeActions } from '../components.js';
import { session } from '../session.js';

export async function renderHome() {
  const d = await api.home();
  const { stats } = d;

  const courseList = (list) => (list.length ? `<div class="course-grid">${list.map(courseCardHTML).join('')}</div>` : emptyHTML('还没有课程'));

  const rankList = d.hotCourses.slice(0, 8).map((c, i) => `
    <div style="display:flex;gap:8px;align-items:baseline;padding:6px 0;border-bottom:1px solid #eef0ea">
      <b style="color:${i < 3 ? '#c04851' : '#9aa0a8'};width:18px">${i + 1}</b>
      <a href="#/courses/${esc(c.id)}" style="flex:1">${esc(c.name)}</a>
      <span class="small" style="color:#ff9c2b">${c.stats.reviewCount ? c.stats.avgRating.toFixed(1) : '-'}</span>
    </div>`).join('');

  const reviews = d.latestReviews.length
    ? d.latestReviews.map((r) => reviewItemHTML(r)).join('')
    : emptyHTML('还没有短评，去给上过的课打个分吧');

  const posts = d.latestPosts.length
    ? `<div class="post-list">${d.latestPosts.map(postItemHTML).join('')}</div>`
    : emptyHTML('还没有讨论');

  return `
    <section class="card" style="background:linear-gradient(120deg,#f3f8f4,#ffffff)">
      <h1 style="margin-bottom:6px">华政选课 · 学生共建的选课参考</h1>
      <p class="muted" style="margin-bottom:14px">课程信息大家共同维护，评分与讨论来自真实上课体验，帮你在选课季少踩坑。</p>
      <div class="stat-row">
        <div><div class="num">${stats.courseCount}</div><div class="muted small">门课程</div></div>
        <div><div class="num">${stats.reviewCount}</div><div class="muted small">条短评</div></div>
        <div><div class="num">${stats.postCount}</div><div class="muted small">个讨论</div></div>
        <div><div class="num">${stats.userCount}</div><div class="muted small">位同学</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-primary" href="#/courses">开始找课</a>
        ${session.user && session.user.canUploadCourses ? '<a class="btn" href="#/new-course">添加课程</a>' : ''}
        <a class="btn" href="#/chat">去友邻聊聊</a>
      </div>
    </section>

    <div class="layout" style="margin-top:16px">
      <div>
        <div class="card">
          <div class="card-title"><h2>高分课程</h2><a class="small" href="#/courses?sort=top">查看全部</a></div>
          ${d.topCourses.length ? `<div class="course-grid">${d.topCourses.map(courseCardHTML).join('')}</div>` : emptyHTML('还没有评分，快去当第一个')}
        </div>

        <div class="card">
          <div class="card-title"><h2>最新短评</h2><a class="small" href="#/courses">去评分</a></div>
          ${reviews}
        </div>

        <div class="card">
          <div class="card-title"><h2>最新讨论</h2><a class="small" href="#/chat">进入友邻</a></div>
          ${posts}
        </div>
      </div>

      <aside>
        <div class="card">
          <div class="card-title"><h3>讨论热度榜</h3></div>
          ${d.hotCourses.length ? rankList : emptyHTML('暂无数据')}
        </div>
        <div class="card">
          <div class="card-title"><h3>最新添加</h3></div>
          ${d.newCourses.slice(0, 6).map((c) => `
            <div style="padding:5px 0;border-bottom:1px solid #eef0ea">
              <a href="#/courses/${esc(c.id)}">${esc(c.name)}</a>
              <div class="muted small">${esc(c.teacher)}${c.stats.reviewCount ? ` · ${starsHTML(c.stats.avgRating)}` : ''}</div>
            </div>`).join('') || emptyHTML('暂无课程')}
        </div>
        <div class="card">
          <div class="card-title"><h3>共建入口</h3></div>
          <p class="small muted" style="margin-bottom:10px">${session.user ? `欢迎回来，${esc(session.user.nickname)}` : '登录并完成认证后可以评分和发帖'}</p>
          <div style="display:flex;flex-direction:column;gap:8px">
            ${session.user && session.user.canUploadCourses ? `
              <a class="btn" href="#/new-course">添加一门课程</a>
              <a class="btn" href="#/import">CSV 批量导入</a>` : '<span class="small muted">课程上传需要管理员单独授权。</span>'}
          </div>
        </div>
      </aside>
    </div>
  `;
}

export function mountHome(root) {
  bindLikeActions(root, () => window.dispatchEvent(new HashChangeEvent('hashchange')));
}
