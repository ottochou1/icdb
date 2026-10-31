import { api, qsOf } from '../api.js';
import { esc, timeAgo, starsHTML, scoreText, emptyHTML, pagerHTML, toast, trimText } from '../util.js';
import {
  courseCardHTML, reviewItemHTML, postItemHTML, starInputHTML, bindStarInput, starValue,
  bindLikeActions, tagChipsHTML, selectedChips,
} from '../components.js';
import { session, navigate, reload, requireLogin, loadConfig } from '../session.js';

const SORTS = [
  { key: 'popular', label: '热度' },
  { key: 'top', label: '高分' },
  { key: 'discuss', label: '讨论多' },
  { key: 'rating', label: '评价多' },
  { key: 'newest', label: '最新' },
  { key: 'credit', label: '学分多' },
];

const RATING_OPTS = [
  { key: '', label: '不限评分' },
  { key: '3', label: '3 分以上' },
  { key: '4', label: '4 分以上' },
  { key: '4.5', label: '4.5 分以上' },
];

/** 把 facets 选项渲染成下拉项，带每项课程数；取值统一按字符串比较 */
function facetOptionsHTML(items, current, allLabel) {
  let opts = `<option value="">${esc(allLabel)}</option>`;
  for (const it of items || []) {
    const v = typeof it === 'string' ? it : it.value;
    const n = typeof it === 'string' ? 0 : (it.count || 0);
    const sel = String(v) === String(current || '') ? ' selected' : '';
    opts += `<option value="${esc(v)}"${sel}>${esc(v)}${n ? `（${n}）` : ''}</option>`;
  }
  return opts;
}

/** 学分按数值升序，避免出现 1、10、2 这种字典序 */
function sortCredits(items) {
  return (items || []).slice().sort((a, b) => {
    const na = parseFloat(a && a.value);
    const nb = parseFloat(b && b.value);
    if (!isNaN(na) && !isNaN(nb)) return na - nb;
    return String(a && a.value).localeCompare(String(b && b.value), 'zh');
  });
}

/** 在保留现有筛选条件的前提下只覆盖传入项 */
function queryWith(params, extra = {}) {
  const base = {};
  for (const k of ['q', 'teacher', 'category', 'college', 'credit', 'examMode', 'language', 'minRating', 'sort', 'page']) {
    const v = params.get(k) || '';
    if (v !== '') base[k] = v;
  }
  return qsOf({ ...base, ...extra });
}

/* ------------------------- 课程列表 ------------------------- */

export async function renderCourseList(params) {
  const q = params.get('q') || '';
  const teacher = params.get('teacher') || '';
  const category = params.get('category') || '';
  const college = params.get('college') || '';
  const credit = params.get('credit') || '';
  const examMode = params.get('examMode') || '';
  const language = params.get('language') || '';
  const minRating = params.get('minRating') || '';
  const sort = params.get('sort') || 'popular';
  const page = Number(params.get('page') || 1);

  // 下拉栏选项直接取自课程表的真实取值（带课程数），
  // 不再依赖 config.php 里写死的类别列表——那份列表跟教务表格导入的值对不上。
  const [facetRes, res] = await Promise.all([
    api.courseFacets().catch(() => ({ facets: {} })),
    api.courses(qsOf({ q, teacher, category, college, credit, examMode, language, minRating, sort, page, pageSize: 12 })),
  ]);
  const f = (facetRes && facetRes.facets) || {};

  return `
    <div class="breadcrumb"><a href="#/">首页</a> / 找课</div>
    <div class="card">
      <form class="filter-bar" id="course-filter">
        <div class="filter-grid">
          <div class="fg-wide">
            <label class="small muted">关键词</label>
            <input type="text" name="q" value="${esc(q)}" placeholder="课程名 / 课号 / 简介，空格分隔多个词" />
          </div>
          <div>
            <label class="small muted">授课教师</label>
            <input type="text" name="teacher" value="${esc(teacher)}" placeholder="支持模糊匹配" />
          </div>
          <div class="fg-actions">
            <button class="btn btn-primary" type="submit">筛选</button>
            ${hasAnyFilter(params) ? '<a class="btn" href="#/courses">重置</a>' : ''}
          </div>
        </div>
        <div class="filter-grid" style="margin-top:10px">
          ${facetSelectHTML(f.categories, 'category', category, '全部类别', '课程类别')}
          ${facetSelectHTML(f.colleges, 'college', college, '全部部门', '开课部门')}
          ${facetSelectHTML(sortCredits(f.credits), 'credit', credit, '全部学分', '学分')}
          ${facetSelectHTML(f.examModes, 'examMode', examMode, '全部方式', '考核方式')}
          ${facetSelectHTML(f.languages, 'language', language, '全部语言', '授课语言')}
          <div>
            <label class="small muted">最低评分</label>
            <select name="minRating">
              ${RATING_OPTS.map((o) => `<option value="${esc(o.key)}" ${o.key === minRating ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}
            </select>
          </div>
        </div>
      </form>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-title">
        <h2>共 ${res.total} 门课程</h2>
        <div class="small">
          ${SORTS.map((s) => `<a href="#/courses?${queryWith(params, { sort: s.key, page: '' })}" style="margin-left:10px;${s.key === sort ? 'font-weight:700' : ''}">${s.label}</a>`).join('')}
        </div>
      </div>
      ${res.items.length ? `<div class="course-grid">${res.items.map(courseCardHTML).join('')}</div>` : emptyHTML('没有找到匹配的课程', '换个关键词，或者自己添加一门')}
      ${pagerHTML(res.page, res.pageCount)}
    </div>
  `;
}

/** 选项为空时整块不渲染，避免出现只有「全部」的无用下拉 */
function facetSelectHTML(items, name, current, allLabel, label) {
  if (!items || !items.length) return '';
  return `<div>
    <label class="small muted">${esc(label)}</label>
    <select name="${esc(name)}">${facetOptionsHTML(items, current, allLabel)}</select>
  </div>`;
}

/** 当前是否启用了任意筛选条件 */
function hasAnyFilter(params) {
  return ['q', 'teacher', 'category', 'college', 'credit', 'examMode', 'language', 'minRating']
    .some((k) => (params.get(k) || '') !== '');
}

export function mountCourseList(root, params) {
  const form = root.querySelector('#course-filter');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    navigate(`#/courses?${qsOf({
      q: fd.get('q'),
      teacher: fd.get('teacher'),
      category: fd.get('category'),
      college: fd.get('college'),
      credit: fd.get('credit'),
      examMode: fd.get('examMode'),
      language: fd.get('language'),
      minRating: fd.get('minRating'),
      sort: params.get('sort'),
    })}`);
  });

  root.querySelectorAll('select[name="category"]').forEach((sel) => {
    sel.addEventListener('change', () => form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })));
  });

  root.querySelectorAll('.pager button[data-page]').forEach((btn) => {
    btn.addEventListener('click', () => {
      navigate(`#/courses?${queryWith(params, { page: btn.dataset.page })}`);
    });
  });
}

/* ------------------------- 课程详情 ------------------------- */

export async function renderCourseDetail(params, id) {
  const { course } = await api.course(id);
  const sort = params.get('sort') || 'newest';
  const rating = params.get('rating') || '';
  const revRes = await api.reviews(id, qsOf({ sort, rating }));
  const postRes = await api.posts(qsOf({ type: 'course', courseId: id, pageSize: 10 }));
  const s = course.stats;
  const total = s.reviewCount || 0;
  const myReview = revRes.items.find((r) => session.user && r.author && r.author.id === session.user.id) || null;

  // 同一门课可能有多个课程代码（教务表格里不同教学班 / 不同代码会合并到这里）
  const codes = course.codes && course.codes.length ? course.codes : (course.code ? [course.code] : []);
  const metaTags = [
    course.category,
    course.college,
    course.examMode ? `考核：${course.examMode}` : '',
    course.language ? `授课语言：${course.language}` : '',
  ].filter(Boolean);

  const distHTML = [5, 4, 3, 2, 1].map((star) => {
    const count = s.dist[star] || 0;
    const pct = total ? Math.round((count / total) * 100) : 0;
    return `<div class="dist-row"><span style="width:26px">${star}星</span><span class="dist-bar"><i style="width:${pct}%"></i></span><span style="width:34px;text-align:right">${count}</span></div>`;
  }).join('');

  return `
    <div class="breadcrumb"><a href="#/">首页</a> / <a href="#/courses">找课</a> / ${esc(course.name)}</div>

    <div class="card">
      <div class="detail-head">
        <div class="detail-title">
          <h1>${esc(course.name)}</h1>
          <div class="muted" style="margin-bottom:8px">
            任课老师：<b>${esc(course.teacher)}</b>
            ${course.credit ? ` · ${esc(course.credit)} 学分` : ''}
          </div>
          <div class="course-code-row">
            <span class="muted small">课程代码</span>
            ${codes.length ? codes.map((c) => `<span class="code-chip">${esc(c)}</span>`).join('') : '<span class="muted small">未填写</span>'}
          </div>
          <div style="margin-top:8px">${metaTags.map((t) => `<span class="tag">${esc(t)}</span>`).join('')}</div>
          <p style="margin-top:10px">${course.intro ? esc(course.intro) : '<span class="muted">还没有课程简介，欢迎补充</span>'}</p>
          <div class="muted small">
            由 ${course.author ? esc(course.author.nickname) : '匿名'} 添加 · ${timeAgo(course.createdAt)}
            ${session.user && (session.user.id === course.createdBy || session.user.isAdmin) ? ` · <a href="#/courses/${esc(course.id)}/edit">编辑</a> · <button class="btn btn-sm" id="del-course" style="padding:0">删除</button>` : ''}
          </div>
        </div>
        <div class="detail-score">
          ${total ? `<div class="score">${s.avgRating.toFixed(1)}</div>${starsHTML(s.avgRating)}<div class="muted small">${total} 人评价</div>` : '<div class="muted">暂无评分</div>'}
          <div style="margin-top:10px;text-align:left">${distHTML}</div>
          <div style="margin-top:12px">
            <button class="btn btn-primary" id="write-review">${myReview ? '修改我的评价' : '写短评'}</button>
          </div>
          ${s.reviewCount ? `<div class="muted small" style="margin-top:6px">${Math.round(((s.dist[4] || 0) + (s.dist[5] || 0)) / total * 100)}% 的人给了 4 星以上</div>` : ''}
        </div>
      </div>

      <div id="review-form-box" style="display:none;margin-top:16px;border-top:1px solid #eef0ea;padding-top:16px">
        ${reviewFormHTML(myReview)}
      </div>
    </div>

    <div class="layout" style="margin-top:16px">
      <div>
        <div class="card">
          <div class="card-title">
            <h2>短评 ${total ? `(${total})` : ''}</h2>
            <div class="small">
              <a href="#/courses/${esc(course.id)}?sort=newest" style="margin-right:10px;${sort === 'newest' ? 'font-weight:700' : ''}">最新</a>
              <a href="#/courses/${esc(course.id)}?sort=helpful" style="margin-right:10px;${sort === 'helpful' ? 'font-weight:700' : ''}">最有用</a>
              <a href="#/courses/${esc(course.id)}?sort=high" style="margin-right:10px;${sort === 'high' ? 'font-weight:700' : ''}">高分</a>
              <a href="#/courses/${esc(course.id)}?sort=low" style="${sort === 'low' ? 'font-weight:700' : ''}">低分</a>
            </div>
          </div>
          <div style="margin-bottom:8px">
            ${[5, 4, 3, 2, 1, 0].map((r) => `<a class="chip ${r === 0 ? (!rating ? 'active' : '') : (String(rating) === String(r) ? 'active' : '')}" href="#/courses/${esc(course.id)}?sort=${esc(sort)}${r ? `&rating=${r}` : ''}">${r ? `${r} 星` : '全部'}</a>`).join('')}
          </div>
          ${revRes.items.length ? revRes.items.map((r) => reviewItemHTML(r, { showCourse: false })).join('') : emptyHTML('还没有短评', '写下第一条，帮后面的同学避坑')}
        </div>
      </div>

      <aside>
        <div class="card">
          <div class="card-title"><h3>课程讨论</h3><a class="small" href="#/new-post?courseId=${esc(course.id)}">发起</a></div>
          ${postRes.items.length ? `<div class="post-list">${postRes.items.map(postItemHTML).join('')}</div>` : emptyHTML('还没有讨论')}
        </div>
        <div class="card">
          <div class="card-title"><h3>快速操作</h3></div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <button class="btn" id="quick-review">写一条短评</button>
            <a class="btn" href="#/new-post?courseId=${esc(course.id)}">发起课程讨论</a>
            <a class="btn" href="#/courses">看看别的课</a>
          </div>
        </div>
      </aside>
    </div>
  `;
}

function reviewFormHTML(review) {
  const tags = ['给分好', '点名少', '作业少', '硬核', '水课', '有意思', '老师好', '考试难', '签到严', '展示多'];
  return `
    <form id="review-form">
      <div class="field">
        <label>总体评分</label>
        ${starInputHTML('rating', review ? review.rating : 0)}
      </div>
      <div class="field">
        <label>标签（可多选，最多 5 个）</label>
        <div id="review-tags">${tags.map((t) => `<span class="chip ${review && review.tags.includes(t) ? 'active' : ''}" data-tag="${esc(t)}">${esc(t)}</span>`).join('')}</div>
      </div>
      <div class="row">
        <div class="field">
          <label>上课学期</label>
          <input type="text" name="semester" value="${esc(review?.semester || '')}" placeholder="例如 2025 秋" />
        </div>
        <div class="field">
          <label>标题（选填）</label>
          <input type="text" name="title" value="${esc(review?.title || '')}" placeholder="一句话总结" />
        </div>
      </div>
      <div class="field">
        <label>详细评价</label>
        <textarea name="content" placeholder="上课方式、考核形式、给分情况、作业量…写得越具体越有用">${esc(review?.content || '')}</textarea>
      </div>
      <div class="field">
        <label style="display:flex;gap:16px;align-items:center">
          <span><input type="checkbox" name="takeAgain" style="width:auto" ${review?.takeAgain ? 'checked' : ''}> 还会再选</span>
          <span><input type="checkbox" name="anonymous" style="width:auto" ${review?.anonymous ? 'checked' : ''}> 匿名发布</span>
        </label>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" type="submit">${review ? '更新评价' : '发布短评'}</button>
        <button class="btn" type="button" id="cancel-review">取消</button>
      </div>
    </form>
  `;
}

export function mountCourseDetail(root, params, id) {
  bindLikeActions(root, reload);
  bindStarInput(root);

  const box = root.querySelector('#review-form-box');
  const openForm = () => {
    if (!requireLogin()) return;
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  root.querySelector('#write-review')?.addEventListener('click', openForm);
  root.querySelector('#quick-review')?.addEventListener('click', openForm);
  root.querySelector('#cancel-review')?.addEventListener('click', () => {
    box.style.display = 'none';
  });

  root.querySelectorAll('#review-tags [data-tag]').forEach((chip) => {
    chip.addEventListener('click', () => chip.classList.toggle('active'));
  });

  root.querySelector('#review-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!requireLogin()) return;
    const fd = new FormData(e.target);
    const payload = {
      rating: starValue(root),
      title: fd.get('title'),
      content: fd.get('content'),
      semester: fd.get('semester'),
      tags: selectedChips(root, '#review-tags [data-tag]'),
      takeAgain: !!fd.get('takeAgain'),
      anonymous: !!fd.get('anonymous'),
    };
    try {
      const existing = await api.reviews(id).then((r) => r.items.find((x) => x.author && x.author.id === session.user.id));
      if (existing) await api.updateReview(existing.id, payload);
      else await api.createReview(id, payload);
      toast(existing ? '评价已更新' : '短评发布成功', 'ok');
      reload();
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  root.querySelector('#del-course')?.addEventListener('click', async () => {
    if (!window.confirm('确定删除这门课程吗？相关短评与讨论也会一并删除。')) return;
    try {
      await api.deleteCourse(id);
      toast('课程已删除', 'ok');
      navigate('#/courses');
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

/* ------------------------- 添加 / 编辑课程 ------------------------- */

export async function renderCourseForm(params, id) {
  const config = await loadConfig();
  let course = null;
  if (id) {
    const res = await api.course(id);
    course = res.course;
    if (!session.user || (session.user.id !== course.createdBy && !session.user.isAdmin)) {
      return emptyHTML('你没有权限编辑这门课程');
    }
  } else if (!session.user) {
    return `<div class="card">${emptyHTML('登录后才能添加课程')}<div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div></div>`;
  }

  const c = course || {};
  return `
    <div class="breadcrumb"><a href="#/">首页</a> / <a href="#/courses">找课</a> / ${id ? '编辑课程' : '添加课程'}</div>
    <div class="card" style="max-width:640px">
      <h2 style="margin-bottom:14px">${id ? '编辑课程' : '添加一门课程'}</h2>
      <form id="course-form">
        <div class="row">
          <div class="field" style="flex:2 1 240px">
            <label>课程名称 *</label>
            <input type="text" name="name" value="${esc(c.name || '')}" required placeholder="例如：刑法总论" />
          </div>
          <div class="field">
            <label>任课老师 *</label>
            <input type="text" name="teacher" value="${esc(c.teacher || '')}" required placeholder="例如：李老师" />
          </div>
        </div>
        <div class="row">
          <div class="field">
            <label>课程代码</label>
            <input type="text" name="code" value="${esc(c.code || '')}" placeholder="例如：LAW1001" />
          </div>
          <div class="field">
            <label>学分</label>
            <input type="text" name="credit" value="${esc(c.credit || '')}" placeholder="例如：3" />
          </div>
          <div class="field">
            <label>课程类别</label>
            <input type="text" name="category" list="category-list" value="${esc(c.category || '')}" placeholder="可留空或自定义" />
            <datalist id="category-list">${(config.categories || []).map((x) => `<option value="${esc(x)}"></option>`).join('')}</datalist>
          </div>
        </div>
        <div class="field">
          <label>开课学院</label>
          <input type="text" name="college" list="college-list" value="${esc(c.college || '')}" placeholder="可留空" />
          <datalist id="college-list">${config.colleges.map((x) => `<option value="${esc(x)}"></option>`).join('')}</datalist>
        </div>
        <div class="field">
          <label>课程简介</label>
          <textarea name="intro" placeholder="上课内容、考核方式、适合什么方向的同学…">${esc(c.intro || '')}</textarea>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary" type="submit">${id ? '保存修改' : '添加课程'}</button>
          <a class="btn" href="#/courses">返回</a>
        </div>
      </form>
    </div>
  `;
}

export function mountCourseForm(root, params, id) {
  root.querySelector('#course-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());
    try {
      const res = id ? await api.updateCourse(id, payload) : await api.createCourse(payload);
      toast(id ? '已保存' : '课程已添加，快去评分吧', 'ok');
      navigate(`#/courses/${res.course.id}`);
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

/* ------------------------- CSV 批量导入 ------------------------- */

const SAMPLE = '课程名称,老师,课程代码,学分,开课学院,课程类别,课程简介\n刑法总论,李文博,LAW1001,4,刑事司法学院,专业必修,犯罪构成与刑罚体系\n';

export async function renderImport() {
  if (!session.user) {
    return `<div class="card">${emptyHTML('登录后才能批量导入课程')}<div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div></div>`;
  }
  return `
    <div class="breadcrumb"><a href="#/">首页</a> / 批量导入</div>
    <div class="card" style="max-width:720px">
      <h2>CSV 批量导入课程</h2>
      <p class="muted small">适合一次性导入培养方案里的整批课程。表头支持「课程名称 / 老师 / 课程代码 / 学分 / 开课学院 / 课程类别 / 课程简介」，顺序不限；已存在（同名同老师）的课程会自动跳过。</p>
      <div class="field">
        <label>上传 CSV 文件</label>
        <input type="file" id="csv-file" accept=".csv,.txt" />
      </div>
      <div class="field">
        <label>或直接粘贴表格内容</label>
        <textarea id="csv-text" style="min-height:170px;font-family:ui-monospace,Menlo,Consolas,monospace" placeholder="${esc(SAMPLE.trim())}"></textarea>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" id="do-import">开始导入</button>
        <button class="btn" id="download-tpl">下载模板</button>
        <a class="btn" href="#/courses">返回课程列表</a>
      </div>
      <div id="import-result" style="margin-top:16px"></div>
    </div>
  `;
}

export function mountImport(root) {
  const textarea = root.querySelector('#csv-text');
  root.querySelector('#csv-file')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    textarea.value = await file.text();
  });

  root.querySelector('#download-tpl')?.addEventListener('click', () => {
    const blob = new Blob([`\uFEFF${SAMPLE}`], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '课程导入模板.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  root.querySelector('#do-import')?.addEventListener('click', async () => {
    const csv = textarea.value.trim();
    if (!csv) return toast('请先粘贴内容或选择文件', 'error');
    try {
      const res = await api.importCourses(csv);
      root.querySelector('#import-result').innerHTML = `
        <div class="card" style="background:#f4f9f6">
          <b>导入完成：新增 ${res.created} 门，跳过 ${res.skipped.length} 条</b>
          ${res.skipped.length ? `<div class="table-wrap" style="margin-top:8px"><table class="data"><tr><th>课程</th><th>原因</th></tr>${res.skipped.map((s) => `<tr><td>${esc(s.name)}</td><td>${esc(s.reason)}</td></tr>`).join('')}</table></div>` : ''}
          <div style="margin-top:10px"><a class="btn btn-primary" href="#/courses">去查看课程</a></div>
        </div>`;
      toast(`导入完成，新增 ${res.created} 门`, 'ok');
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}
