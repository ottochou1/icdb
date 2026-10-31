/** 可复用的页面片段 */
import { esc, timeAgo, starsHTML, avatarHTML, userLink, trimText, toast } from './util.js';
import { api } from './api.js';
import { session, requireLogin } from './session.js';

export function courseCardHTML(course) {
  const s = course.stats || {};
  return `
    <a class="course-card" href="#/courses/${esc(course.id)}">
      <div class="cc-name">${esc(course.name)}</div>
      <div class="cc-meta">${esc(course.teacher)}${course.code ? ` · ${esc(course.code)}` : ''}</div>
      <div class="cc-meta">${course.credit ? `${esc(course.credit)} 学分` : '学分未填'}${course.category ? ` · ${esc(course.category)}` : ''}</div>
      <div class="cc-foot">
        ${s.reviewCount ? `<span>${starsHTML(s.avgRating)} <span class="muted small">${s.avgRating.toFixed(1)}</span></span>` : '<span class="muted small">暂无评分</span>'}
        <span class="muted small">${s.reviewCount || 0} 评 · ${s.postCount || 0} 帖</span>
      </div>
    </a>`;
}

export function reviewItemHTML(review, { showCourse = true, compact = false } = {}) {
  const tags = (review.tags || []).map((t) => `<span class="tag">${esc(t)}</span>`).join('');
  return `
    <div class="feed-item" data-review="${esc(review.id)}">
      <div class="feed-head">
        ${avatarHTML(review.author, 26)}
        ${userLink(review.author)}
        ${starsHTML(review.rating)}
        ${review.semester ? `<span class="muted small">${esc(review.semester)}</span>` : ''}
        <span class="muted small">${timeAgo(review.createdAt)}</span>
      </div>
      ${review.title ? `<div style="font-weight:600;margin-bottom:3px">${esc(review.title)}</div>` : ''}
      ${showCourse && review.course ? `<div class="muted small">课程：<a href="#/courses/${esc(review.course.id)}">${esc(review.course.name)}</a> · ${esc(review.course.teacher)}</div>` : ''}
      <div class="feed-body${compact ? ' post-excerpt' : ''}">${esc(review.content)}</div>
      <div>${tags}${review.takeAgain ? '<span class="tag">会再选</span>' : ''}</div>
      <div class="feed-actions">
        <button data-action="like-review" data-id="${esc(review.id)}" class="${review.liked ? 'liked' : ''}">有用 ${review.likeCount || 0}</button>
        ${review.canEdit ? `<button data-action="edit-review" data-id="${esc(review.id)}" data-course="${esc(review.courseId)}">编辑</button>` : ''}
        ${review.canEdit ? `<button data-action="del-review" data-id="${esc(review.id)}">删除</button>` : ''}
      </div>
    </div>`;
}

export function postItemHTML(post) {
  return `
    <div class="post-item">
      ${avatarHTML(post.author, 32)}
      <div class="post-main">
        <a class="post-title" href="#/post/${esc(post.id)}">${esc(post.title)}</a>
        ${(post.tags || []).length ? `<div>${post.tags.map((t) => `<span class="tag">${esc(t)}</span>`).join('')}</div>` : ''}
        <div class="post-excerpt">${esc(trimText(post.content, 110))}</div>
        <div class="post-meta">
          <span>${userLink(post.author)}</span>
          <span>${timeAgo(post.createdAt)}</span>
          ${post.course ? `<span>课程：<a href="#/courses/${esc(post.course.id)}">${esc(post.course.name)}</a></span>` : ''}
          <span class="reply-count">${post.commentCount || 0} 回复</span>
          <span>${post.likeCount || 0} 赞</span>
        </div>
      </div>
    </div>`;
}

export function commentItemHTML(comment) {
  return `
    <div class="feed-item">
      <div class="feed-head">
        ${avatarHTML(comment.author, 24)}
        ${userLink(comment.author)}
        <span class="muted small">${timeAgo(comment.createdAt)}</span>
      </div>
      <div class="feed-body">${esc(comment.content)}</div>
      <div class="feed-actions">
        <button data-action="like-comment" data-id="${esc(comment.id)}" class="${comment.liked ? 'liked' : ''}">赞同 ${comment.likeCount || 0}</button>
        ${comment.canEdit ? `<button data-action="del-comment" data-id="${esc(comment.id)}">删除</button>` : ''}
      </div>
    </div>`;
}

/** 星级选择控件：返回 HTML，配合 bindStarInput 使用 */
export function starInputHTML(name = 'rating', value = 0) {
  return `<div class="rate-input" data-star-input="${esc(name)}" data-value="${value || 0}">${[1, 2, 3, 4, 5]
    .map((i) => `<span data-v="${i}" class="${i <= value ? 'on' : ''}">★</span>`)
    .join('')}<span class="muted small" style="margin-left:8px;align-self:center" data-star-label></span></div>`;
}

export function bindStarInput(root) {
  root.querySelectorAll('[data-star-input]').forEach((box) => {
    const spans = box.querySelectorAll('span[data-v]');
    const label = box.querySelector('[data-star-label]');
    const words = ['', '很坑，别选', '一般般', '还行', '不错', '强推'];
    const paint = (v) => {
      box.dataset.value = String(v);
      spans.forEach((s) => s.classList.toggle('on', Number(s.dataset.v) <= v));
      if (label) label.textContent = words[v] || '';
    };
    paint(Number(box.dataset.value) || 0);
    box.addEventListener('click', (e) => {
      const span = e.target.closest('span[data-v]');
      if (span) paint(Number(span.dataset.v));
    });
    box.addEventListener('mouseover', (e) => {
      const span = e.target.closest('span[data-v]');
      if (span) spans.forEach((s) => s.classList.toggle('on', Number(s.dataset.v) <= Number(span.dataset.v)));
    });
    box.addEventListener('mouseleave', () => paint(Number(box.dataset.value) || 0));
  });
}

export function starValue(root, name = 'rating') {
  const box = root.querySelector(`[data-star-input="${name}"]`);
  return box ? Number(box.dataset.value) || 0 : 0;
}

/** 统一处理点赞等通用交互 */
export function bindLikeActions(root, refresh) {
  root.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id;
    if (!session.user) {
      toast('请先登录后再操作', 'error');
      return;
    }
    try {
      if (action === 'like-review') await api.likeReview(id);
      else if (action === 'like-post') await api.likePost(id);
      else if (action === 'like-comment') await api.likeComment(id);
      else return;
      refresh && refresh();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

export function tagChipsHTML(tags, selected = []) {
  return tags
    .map((t) => `<span class="chip ${selected.includes(t) ? 'active' : ''}" data-tag="${esc(t)}">${esc(t)}</span>`)
    .join('');
}

export function selectedChips(root, selector = '[data-tag]') {
  return Array.from(root.querySelectorAll(`${selector}.active`)).map((el) => el.dataset.tag);
}

export { requireLogin };
