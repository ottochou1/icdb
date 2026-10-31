import { api, qsOf } from '../api.js';
import { esc, timeAgo, emptyHTML, pagerHTML, toast, avatarHTML, userLink } from '../util.js';
import { postItemHTML, commentItemHTML, bindLikeActions, tagChipsHTML, selectedChips } from '../components.js';
import { session, navigate, reload, requireLogin, loadConfig } from '../session.js';

/* ------------------------- 友邻广场 ------------------------- */

export async function renderChat(params) {
  const tag = params.get('tag') || '';
  const q = params.get('q') || '';
  const page = Number(params.get('page') || 1);
  const config = await loadConfig();
  const res = await api.posts(qsOf({ type: 'chat', tag, q, page, pageSize: 15 }));

  return `
    <div class="breadcrumb"><a href="#/">首页</a> / 友邻</div>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
          <h2 style="margin-bottom:4px">友邻广场</h2>
          <p class="muted small" style="margin:0">选课之外的自由地：吐槽、求助、二手、约自习，什么都可以聊。</p>
        </div>
        <a class="btn btn-primary" href="#/new-post">发个帖</a>
      </div>
      <div style="margin-top:14px">
        ${tagChipsHTML(['全部', ...config.chatTags], [tag || '全部'])}
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-title"><h3>共 ${res.total} 个话题</h3></div>
      ${res.items.length ? `<div class="post-list">${res.items.map(postItemHTML).join('')}</div>` : emptyHTML('还没有话题', '来当第一个发帖的人')}
      ${pagerHTML(res.page, res.pageCount)}
    </div>
  `;
}

export function mountChat(root, params) {
  root.querySelectorAll('[data-tag]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const value = chip.dataset.tag === '全部' ? '' : chip.dataset.tag;
      navigate(`#/chat${value ? `?tag=${encodeURIComponent(value)}` : ''}`);
    });
  });
  root.querySelectorAll('.pager button[data-page]').forEach((btn) => {
    btn.addEventListener('click', () => {
      navigate(`#/chat?${qsOf({ tag: params.get('tag'), page: btn.dataset.page })}`);
    });
  });
  bindLikeActions(root, reload);
}

/* ------------------------- 帖子详情 ------------------------- */

export async function renderPost(params, id) {
  const { post, comments } = await api.post(id);
  const isChat = post.type === 'chat';

  return `
    <div class="breadcrumb"><a href="#/">首页</a> / <a href="#/${isChat ? 'chat' : `courses/${esc(post.courseId || '')}`}">${isChat ? '友邻' : '课程'}</a> / 话题</div>
    <div class="card">
      <h1 style="margin-bottom:8px">${esc(post.title)}</h1>
      <div class="feed-head">
        ${avatarHTML(post.author, 30)}
        ${userLink(post.author)}
        <span class="muted small">${timeAgo(post.createdAt)}</span>
        ${(post.tags || []).map((t) => `<span class="tag">${esc(t)}</span>`).join('')}
        ${post.course ? `<a class="tag" href="#/courses/${esc(post.course.id)}">${esc(post.course.name)}</a>` : ''}
      </div>
      <div class="feed-body" style="margin-top:10px;font-size:14.5px">${esc(post.content)}</div>
      <div class="feed-actions">
        <button data-action="like-post" data-id="${esc(post.id)}" class="${post.liked ? 'liked' : ''}">赞 ${post.likeCount || 0}</button>
        <span>${post.commentCount || 0} 条回复</span>
        ${post.canEdit ? `<button id="del-post">删除</button>` : ''}
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-title"><h3>全部回复</h3></div>
      ${comments.length ? comments.map(commentItemHTML).join('') : emptyHTML('还没有回复')}
      <div class="divider"></div>
      ${session.user ? `
        <form id="comment-form">
          <div class="field">
            <textarea name="content" style="min-height:80px" placeholder="友善发言，理性讨论…" required></textarea>
          </div>
          <button class="btn btn-primary" type="submit">回复</button>
        </form>` : `<div class="muted small">登录后参与讨论 · <a href="#/login">登录</a> / <a href="#/register">注册</a></div>`}
    </div>
  `;
}

export function mountPost(root, params, id) {
  bindLikeActions(root, reload);

  root.querySelector('#comment-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireLogin()) return;
    const content = new FormData(e.target).get('content');
    try {
      await api.createComment(id, content);
      toast('回复成功', 'ok');
      reload();
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  root.querySelector('#del-post')?.addEventListener('click', async () => {
    if (!window.confirm('确定删除这个话题吗？')) return;
    try {
      await api.deletePost(id);
      toast('已删除', 'ok');
      navigate('#/chat');
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  root.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action="del-comment"]');
    if (!btn) return;
    if (!window.confirm('删除这条回复？')) return;
    try {
      await api.deleteComment(btn.dataset.id);
      reload();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

/* ------------------------- 发帖 ------------------------- */

export async function renderNewPost(params) {
  if (!session.user) {
    return `<div class="card">${emptyHTML('登录后才能发帖')}<div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div></div>`;
  }
  const config = await loadConfig();
  const courseId = params.get('courseId') || '';
  let course = null;
  if (courseId) {
    try {
      course = (await api.course(courseId)).course;
    } catch {
      course = null;
    }
  }
  const isCourse = !!course;

  return `
    <div class="breadcrumb"><a href="#/">首页</a> / ${isCourse ? `<a href="#/courses/${esc(course.id)}">${esc(course.name)}</a>` : '<a href="#/chat">友邻</a>'} / 发帖</div>
    <div class="card" style="max-width:640px">
      <h2 style="margin-bottom:12px">${isCourse ? `在《${esc(course.name)}》下发起讨论` : '在友邻发个帖'}</h2>
      <form id="post-form">
        <input type="hidden" name="type" value="${isCourse ? 'course' : 'chat'}" />
        <input type="hidden" name="courseId" value="${esc(courseId)}" />
        <div class="field">
          <label>标题 *</label>
          <input type="text" name="title" required placeholder="一句话说清你想聊什么" />
        </div>
        <div class="field">
          <label>正文 *</label>
          <textarea name="content" style="min-height:150px" required placeholder="说说具体情况，别人更容易帮你"></textarea>
        </div>
        <div class="field">
          <label>标签（最多 3 个）</label>
          <div id="post-tags">${(isCourse ? ['求助', '选课咨询', '闲聊'] : config.chatTags).map((t) => `<span class="chip" data-tag="${esc(t)}">${esc(t)}</span>`).join('')}</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary" type="submit">发布</button>
          <a class="btn" href="#/${isCourse ? `courses/${esc(course.id)}` : 'chat'}">取消</a>
        </div>
      </form>
    </div>
  `;
}

export function mountNewPost(root) {
  root.querySelectorAll('#post-tags [data-tag]').forEach((chip) => {
    chip.addEventListener('click', () => chip.classList.toggle('active'));
  });
  root.querySelector('#post-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const res = await api.createPost({
        type: fd.get('type'),
        courseId: fd.get('courseId'),
        title: fd.get('title'),
        content: fd.get('content'),
        tags: selectedChips(root, '#post-tags [data-tag]'),
      });
      toast('发布成功', 'ok');
      navigate(`#/post/${res.post.id}`);
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}
