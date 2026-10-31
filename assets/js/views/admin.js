import { api, qsOf } from '../api.js?v=maintenance-1';
import { esc, timeAgo, toast, avatarHTML, emptyHTML, pagerHTML } from '../util.js';
import { session, reload } from '../session.js';

const STATUS_TEXT = { pending: '待审核', approved: '已通过', rejected: '未通过' };

function fmtSize(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1048576).toFixed(1)} MB`;
}

function verificationCardHTML(v) {
  return `
    <div class="admin-card">
      <div class="admin-card-head">
        ${avatarHTML(v.user, 32)}
        <div>
          <b>${esc(v.user ? v.user.nickname : '?')}</b>
          <div class="small muted">${esc(v.user ? v.user.email : '')} · 注册于 ${timeAgo(v.user ? v.user.joinedAt : 0)}</div>
        </div>
        <span class="status-tag ${esc(v.status)}">${STATUS_TEXT[v.status] || v.status}</span>
      </div>
      <div class="small">材料：${esc(v.fileName)}（${esc(v.fileExt)}，${fmtSize(v.fileSize)}） · 提交于 ${timeAgo(v.createdAt)}</div>
      ${v.note ? `<div class="small">留言：${esc(v.note)}</div>` : ''}
      ${v.adminNote ? `<div class="small muted">管理员备注：${esc(v.adminNote)}</div>` : ''}
      <div class="admin-actions">
        ${v.hasFile && v.fileUrl
          ? `<a class="btn btn-sm" href="${esc(v.fileUrl)}" target="_blank" rel="noopener">查看证明材料</a>`
          : '<span class="small muted">材料已按隐私策略删除</span>'}
        ${v.status === 'pending' ? `
          <button class="btn btn-sm btn-primary" data-approve="${esc(v.id)}">通过并授予权限</button>
          <button class="btn btn-sm btn-danger" data-reject="${esc(v.id)}">驳回</button>` : ''}
      </div>
    </div>`;
}

function userRowHTML(u) {
  return `
    <div class="admin-row">
      ${avatarHTML(u, 28)}
      <div class="admin-row-main">
        <div>
          <b>${esc(u.nickname)}</b>
          ${u.isAdmin ? '<span class="status-tag admin">管理员</span>' : ''}
          ${u.verified ? '<span class="verified-tag">在读认证</span>' : '<span class="small muted">未认证</span>'}
          ${u.banned ? '<span class="status-tag rejected">已封禁</span>' : ''}
        </div>
        <div class="small muted">${esc(u.email)} · 注册 ${timeAgo(u.joinedAt)}${u.banReason ? ` · 原因：${esc(u.banReason)}` : ''}</div>
      </div>
      <div class="admin-actions">
        <button class="btn btn-sm" data-toggle-admin="${esc(u.id)}" data-value="${u.isAdmin ? '0' : '1'}">${u.isAdmin ? '取消管理员' : '设为管理员'}</button>
        <button class="btn btn-sm" data-toggle-verify="${esc(u.id)}" data-value="${u.verified ? '0' : '1'}">${u.verified ? '取消认证' : '标记已认证'}</button>
        <button class="btn btn-sm btn-danger" data-toggle-ban="${esc(u.id)}" data-value="${u.banned ? '0' : '1'}">${u.banned ? '解除封禁' : '封禁'}</button>
      </div>
    </div>`;
}

function contentRowHTML(item, type) {
  const title = item.title || (item.content || '').slice(0, 40);
  return `
    <div class="admin-row">
      <div class="admin-row-main">
        <div><b>${esc(title)}</b></div>
        <div class="small muted">${esc((item.author && item.author.nickname) || '匿名')} · ${timeAgo(item.createdAt)}${item.course ? ` · ${esc(item.course.name)}` : ''}</div>
      </div>
      <div class="admin-actions">
        ${type === 'post' ? `<a class="btn btn-sm" href="#/post/${esc(item.id)}" target="_blank">查看</a>` : ''}
        <button class="btn btn-sm btn-danger" data-del-type="${type}" data-del-id="${esc(item.id)}">删除</button>
      </div>
    </div>`;
}

/* ------------------------- 课程管理 ------------------------- */

const COURSE_SORTS = [
  ['newest', '最新添加'],
  ['updated', '最近修改'],
  ['reviews', '短评最多'],
  ['rating', '评分最高'],
  ['name', '按名称'],
  ['oldest', '最早添加'],
];

function selectOptions(list, current, placeholder) {
  const opts = [` <option value="">${esc(placeholder)}</option>`];
  (list || []).forEach((v) => {
    opts.push(`<option value="${esc(v)}"${v === current ? ' selected' : ''}>${esc(v)}</option>`);
  });
  return opts.join('');
}

function courseEditHTML(c, categories, colleges) {
  return `
    <div class="course-panel" id="edit-${esc(c.id)}" hidden>
      <div class="field">
        <label>课程名称</label>
        <input type="text" data-f="name" value="${esc(c.name)}" />
      </div>
      <div class="field">
        <label>授课教师（多位老师用顿号分隔）</label>
        <input type="text" data-f="teacher" value="${esc(c.teacher)}" />
      </div>
      <div class="field">
        <label>课程代码（多个用顿号 / 逗号分隔，第一个作为主代码）</label>
        <input type="text" data-f="codes" value="${esc((c.codes || []).join('、'))}" />
      </div>
      <div class="field-row">
        <div class="field"><label>学分</label><input type="text" data-f="credit" value="${esc(c.credit || '')}" /></div>
        <div class="field"><label>考核方式</label><input type="text" data-f="examMode" value="${esc(c.examMode || '')}" /></div>
        <div class="field"><label>授课语言</label><input type="text" data-f="language" value="${esc(c.language || '')}" /></div>
      </div>
      <div class="field-row">
        <div class="field"><label>课程类别</label><select data-f="category">${selectOptions(categories, c.category || '', '未设置')}</select></div>
        <div class="field"><label>开课部门</label><select data-f="college">${selectOptions(colleges, c.college || '', '未设置')}</select></div>
      </div>
      <div class="field">
        <label>课程简介</label>
        <textarea data-f="intro" style="min-height:90px">${esc(c.intro || '')}</textarea>
      </div>
      <div class="admin-actions">
        <button class="btn btn-sm btn-primary" data-course-save="${esc(c.id)}">保存修改</button>
        <button class="btn btn-sm" data-panel-close="edit-${esc(c.id)}">取消</button>
        <span class="small muted">留空的字段会被清空，不改动就不要动它。</span>
      </div>
    </div>`;
}

function courseMergeHTML(c) {
  return `
    <div class="course-panel" id="merge-${esc(c.id)}" hidden>
      <div class="small muted" style="line-height:1.7">
        把「<b>${esc(c.name)}（${esc(c.teacher)}）</b>」的短评、讨论帖和课程代码并入下面选中的课程，然后<b>删除这门课</b>。
        处理重名重复课时用合并，别直接删除，评分才不会丢。
        同一位同学在两门课都评过分的，保留目标课程那条，来源那条会被丢弃。
      </div>
      <div class="admin-filter" style="margin-top:8px">
        <input type="search" data-merge-input placeholder="搜索目标课程：课名 / 老师 / 课程代码" />
        <button class="btn btn-sm" data-merge-search="${esc(c.id)}">搜索</button>
      </div>
      <div class="merge-list" data-merge-list></div>
      <div class="admin-actions">
        <button class="btn btn-sm" data-panel-close="merge-${esc(c.id)}">收起</button>
      </div>
    </div>`;
}

function courseManageRowHTML(c, categories, colleges) {
  const s = c.stats || {};
  const codes = (c.codes || []).map((code) => `<span class="code-chip">${esc(code)}</span>`).join(' ');
  return `
    <div class="admin-card">
      <div class="admin-card-head">
        <div>
          <b>${esc(c.name)}</b>
          <span class="muted"> · ${esc(c.teacher)}</span>
          ${c.category ? `<span class="tag">${esc(c.category)}</span>` : ''}
        </div>
        <span class="small muted">${timeAgo(c.createdAt)}</span>
      </div>
      <div class="small" style="margin-bottom:4px">
        ${codes || '<span class="muted">（无课程代码）</span>'}
      </div>
      <div class="small muted">
        ${c.credit ? `${esc(c.credit)} 学分 · ` : ''}${c.college ? `${esc(c.college)} · ` : ''}${c.examMode ? `考核：${esc(c.examMode)} · ` : ''}${c.language ? `${esc(c.language)} · ` : ''}
        评分 ${s.avgRating || 0}${s.reviewCount ? `（${s.reviewCount} 条短评）` : '（暂无短评）'} · ${s.postCount || 0} 条讨论
      </div>
      <div class="admin-actions">
        <a class="btn btn-sm" href="#/courses/${esc(c.id)}" target="_blank" rel="noopener">查看</a>
        <button class="btn btn-sm" data-course-edit="${esc(c.id)}">编辑</button>
        <button class="btn btn-sm btn-danger" data-course-del="${esc(c.id)}"
          data-name="${esc(c.name)}" data-reviews="${s.reviewCount || 0}" data-posts="${s.postCount || 0}">删除</button>
        <button class="btn btn-sm" data-course-merge="${esc(c.id)}">合并到…</button>
      </div>
      ${courseEditHTML(c, categories, colleges)}
      ${courseMergeHTML(c)}
    </div>`;
}

const IMPORT_SAMPLE = '课程名称,老师,课程代码,学分,开课学院,课程类别,课程简介\n刑法总论,李文博,LAW1001,4,刑事司法学院,专业必修,犯罪构成与刑罚体系\n';

const SOURCE_TEXT = { csv: 'CSV 文件', xlsx: 'Excel 文件', text: '粘贴文本' };

/* 教务处导出的 11 列表格样例 */
const EDU_SAMPLE = [
  '课程代码,课程名称,学分,课程序号,教学班,课程类别,开课部门,授课教师,日期时间地点人员,考核方式,授课语言',
  'LAW1001,民法学（上）,4,001,01班,专业必修,法律学院,张三（主讲）,周一1-2 明法楼A201,考试,中文',
].join('\n');

// 表头字段 → 中文说明，用于展示「识别到了哪些列」
const EDU_FIELD_LABEL = {
  code: '课程代码', name: '课程名称', credit: '学分', category: '课程类别',
  college: '开课部门', teacher: '授课教师', examMode: '考核方式', language: '授课语言',
};

function eduTabHTML() {
  return `
    <div class="edu-note">
      专用于<b>教务处开课查询导出的 11 列表格</b>。系统会：
      <br>① 只取 <b>课程代码 / 课程名称 / 学分 / 课程类别 / 开课部门 / 授课教师 / 考核方式 / 授课语言</b> 这 8 列，其余列（课程序号、教学班、日期时间地点人员）忽略；
      <br>② 教师姓名里的「（主讲）」等括号标记自动剔除，只保留姓名；
      <br>③ <b>课程名称 + 授课教师 相同的多行合并为一门课</b>（不同教学班不再重复建课），但<b>课程代码全部保留</b>，任何一个代码都能搜到；
      <br>④ 单行上限 2000 行，文件 ≤ 10MB，支持 .csv / .txt / .xlsx。
    </div>
    <div class="field" style="margin-top:14px">
      <label>上传文件（≤ 10MB）</label>
      <input type="file" id="edu-file" accept=".csv,.txt,.xlsx" />
    </div>
    <div class="field">
      <label>或粘贴表格内容（含表头）</label>
      <textarea id="edu-text" style="min-height:150px;font-family:ui-monospace,Menlo,Consolas,monospace" placeholder="${esc(EDU_SAMPLE)}"></textarea>
    </div>
    <div class="field">
      <label>遇到已存在的同名同老师课程</label>
      <select id="edu-mode">
        <option value="skip">跳过，不改动已有课程</option>
        <option value="update">用表格内容更新（学分 / 部门 / 类别 / 考核 / 语言，并补充新的课程代码）</option>
      </select>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm btn-primary" id="edu-preview">预演（不写入）</button>
      <button class="btn btn-sm" id="edu-commit">确认导入</button>
      <button class="btn btn-sm" id="edu-template">下载表格模板</button>
    </div>
    <div id="edu-result" style="margin-top:14px"></div>`;
}

export function eduResultHTML(res) {
  const source = SOURCE_TEXT[res.source] || res.source || '未知';
  const headerCells = (res.header || []).map((h) =>
    `<td class="${h.field ? '' : 'ignored'}">${esc(h.header)}${h.field ? ` → ${esc(EDU_FIELD_LABEL[h.field] || h.field)}` : '（忽略）'}</td>`
  ).join('');

  const rows = (res.preview || []).map((p) => `
    <tr>
      <td>${esc(p.action)}</td>
      <td>${esc(p.name)}</td>
      <td>${esc(p.teacher)}</td>
      <td>${(p.codes || []).map((c) => `<span class="code-chip">${esc(c)}</span>`).join(' ') || '-'}</td>
      <td>${esc(p.credit || '-')}</td>
      <td>${esc(p.college || '-')}</td>
      <td>${esc(p.category || '-')}</td>
      <td>${esc(p.examMode || '-')}</td>
      <td>${esc(p.language || '-')}</td>
      <td>${p.merged > 1 ? `合并 ${p.merged} 行` : '1 行'}</td>
    </tr>`).join('');
  const skipRows = (res.skipped || []).map((s) => `
    <tr><td>${esc(s.name)}</td><td>${esc(s.reason)}</td></tr>`).join('');
  const shown = (res.skipped || []).length;
  const merged = res.rows - (res.parsed || 0);

  return `
    <div class="card" style="background:#f7f9f5">
      <b>${res.dry ? '预演结果（尚未写入）' : '导入完成'}：新增 ${res.created} 门，更新 ${res.updated} 门，跳过 ${res.skippedCount} 门</b>
      <div class="small muted" style="margin-top:4px">
        来源：${esc(source)} · 表格 ${res.rows} 行 → 合并成 ${res.parsed} 门课${merged > 0 ? `（合并掉 ${merged} 行重复教学班）` : ''}
      </div>
      ${headerCells ? `
      <div class="table-wrap" style="margin-top:10px">
        <table class="edu-header-table">
          <tr><th colspan="${Math.max(1, (res.header || []).length)}">表头识别结果</th></tr>
          <tr>${headerCells}</tr>
        </table>
      </div>` : ''}
      ${rows ? `
      <div class="table-wrap" style="margin-top:10px">
        <table class="data">
          <tr><th>动作</th><th>课程名称</th><th>授课教师</th><th>课程代码</th><th>学分</th><th>开课部门</th><th>类别</th><th>考核</th><th>语言</th><th>来源行</th></tr>
          ${rows}
        </table>
        ${res.parsed > (res.preview || []).length ? `<div class="small muted">仅预览前 ${(res.preview || []).length} 条</div>` : ''}
      </div>` : ''}
      ${skipRows ? `
      <div class="table-wrap" style="margin-top:10px">
        <table class="data">
          <tr><th>跳过的课程</th><th>原因</th></tr>
          ${skipRows}
        </table>
        ${res.skippedCount > shown ? `<div class="small muted">仅显示前 ${shown} 条，共 ${res.skippedCount} 条</div>` : ''}
      </div>` : ''}
    </div>`;
}

function importTabHTML() {
  return `
    <div class="small muted" style="margin-bottom:12px;line-height:1.7">
      支持 <b>.csv / .txt / .xlsx（Excel）</b> 文件，单次最多 2000 行。
      表头支持「课程名称 / 老师 / 课程代码 / 学分 / 开课学院 / 课程类别 / 课程简介」，顺序不限；没有表头时按这个顺序读列。
      Excel 在中文 Windows 下另存的 CSV 通常是 GBK 编码，系统会自动转码，不用担心乱码。
      导入前建议先点「预演」，确认统计无误后再正式写入。
    </div>
    <div class="field">
      <label>上传文件（≤ 10MB）</label>
      <input type="file" id="import-file" accept=".csv,.txt,.xlsx" />
    </div>
    <div class="field">
      <label>或粘贴表格内容</label>
      <textarea id="import-text" style="min-height:150px;font-family:ui-monospace,Menlo,Consolas,monospace" placeholder="${esc(IMPORT_SAMPLE.trim())}"></textarea>
    </div>
    <div class="field">
      <label>遇到已存在的同名同老师课程</label>
      <select id="import-mode">
        <option value="skip">跳过，不改动已有课程</option>
        <option value="update">用表格内容更新（学分 / 学院 / 类别 / 简介）</option>
      </select>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm btn-primary" id="import-preview">预演（不写入）</button>
      <button class="btn btn-sm" id="import-commit">确认导入</button>
      <button class="btn btn-sm" id="import-template">下载模板</button>
      <button class="btn btn-sm" id="import-export">导出全部课程</button>
    </div>
    <div id="import-result" style="margin-top:14px"></div>`;
}

function importResultHTML(res) {
  const source = SOURCE_TEXT[res.source] || res.source || '未知';
  const rows = (res.preview || []).map((p) => `
    <tr>
      <td>${esc(p.action)}</td>
      <td>${esc(p.name)}</td>
      <td>${esc(p.teacher)}</td>
      <td>${esc(p.code || '-')}</td>
      <td>${esc(p.credit || '-')}</td>
      <td>${esc(p.college || '-')}</td>
      <td>${esc(p.category || '-')}</td>
    </tr>`).join('');
  const skipRows = (res.skipped || []).map((s) => `
    <tr><td>${esc(s.name)}</td><td>${esc(s.reason)}</td></tr>`).join('');
  const shown = (res.skipped || []).length;

  return `
    <div class="card" style="background:#f7f9f5">
      <b>${res.dry ? '预演结果（尚未写入）' : '导入完成'}：新增 ${res.created} 条，更新 ${res.updated} 条，跳过 ${res.skippedCount} 条</b>
      <div class="small muted" style="margin-top:4px">
        来源：${esc(source)} · 识别到 ${res.parsed} 条数据${res.total > res.parsed ? `（文件共 ${res.total} 条，超出 2000 行上限的部分已忽略）` : ''}
      </div>
      ${rows ? `
      <div class="table-wrap" style="margin-top:10px">
        <table class="data">
          <tr><th>动作</th><th>课程名称</th><th>老师</th><th>代码</th><th>学分</th><th>学院</th><th>类别</th></tr>
          ${rows}
        </table>
        ${res.parsed > (res.preview || []).length ? `<div class="small muted">仅预览前 ${(res.preview || []).length} 条</div>` : ''}
      </div>` : ''}
      ${skipRows ? `
      <div class="table-wrap" style="margin-top:10px">
        <table class="data">
          <tr><th>跳过的课程</th><th>原因</th></tr>
          ${skipRows}
        </table>
        ${res.skippedCount > shown ? `<div class="small muted">仅显示前 ${shown} 条，共 ${res.skippedCount} 条</div>` : ''}
      </div>` : ''}
    </div>`;
}

function downloadFile(name, content) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}

/** 课程管理页的筛选条件都放在 hash 里，方便刷新与分享 */
function adminCourseHash(params, patch = {}) {
  const cur = {
    q: params.get('q') || '',
    category: params.get('category') || '',
    college: params.get('college') || '',
    sort: params.get('sort') || 'newest',
    page: params.get('page') || '1',
    ...patch,
  };
  return `#/admin?tab=courses&${qsOf(cur)}`;
}

export async function renderAdmin(params) {
  if (!session.user) {
    return `<div class="card">${emptyHTML('请先登录')}<div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div></div>`;
  }
  if (!session.user.isAdmin) {
    return `<div class="card">${emptyHTML('没有访问权限', '管理后台仅限管理员进入')}<div style="text-align:center;padding-bottom:16px"><a class="btn" href="#/">回到首页</a></div></div>`;
  }

  const tab = params.get('tab') || 'verify';
  const status = params.get('status') || 'pending';
  const keyword = params.get('q') || '';
  let body = '';

  if (tab === 'settings') {
    const state = await api.adminMaintenance();
    body = `<form id="maintenance-form"><h3>闭站维护</h3>
      <p class="muted">开启后，普通访客只能看到维护提示，业务接口暂停访问。管理员仍可登录后台操作。</p>
      <div class="notice ${state.enabled ? 'pending' : 'ok'}">当前状态：${state.enabled ? '维护中' : '正常开放'}</div>
      <div class="field" style="margin-top:16px"><label><input type="checkbox" name="enabled" ${state.enabled ? 'checked' : ''}> 开启维护模式</label></div>
      <div class="field"><label for="maintenance-message">访客提示</label><textarea id="maintenance-message" name="message" maxlength="300">${esc(state.message)}</textarea></div>
      <button class="btn btn-primary" type="submit">保存设置</button><p class="small muted" role="status" id="maintenance-result"></p></form>`;
  } else if (tab === 'verify') {
    const data = await api.adminVerifications(status);
    const items = data.items || [];
    body = `
      <div class="admin-filter">
        ${['pending', 'approved', 'rejected', 'all'].map((s) => `
          <a class="chip ${status === s ? 'active' : ''}" href="#/admin?tab=verify&status=${s}">
            ${{ pending: '待审核', approved: '已通过', rejected: '未通过', all: '全部' }[s]}
            <span class="small">${s === 'all' ? '' : `(${data.counts[s] || 0})`}</span>
          </a>`).join('')}
      </div>
      ${items.length ? `<div class="admin-list">${items.map(verificationCardHTML).join('')}</div>` : emptyHTML('这个状态下没有申请')}`;
  } else if (tab === 'users') {
    const data = await api.adminUsers({ q: keyword, page: params.get('page') || 1 });
    body = `
      <form id="admin-user-search" class="admin-filter">
        <input type="search" name="q" value="${esc(keyword)}" placeholder="搜昵称 / 邮箱 / 学号" />
        <button class="btn btn-sm" type="submit">搜索</button>
        <span class="small muted">共 ${data.total} 位用户</span>
      </form>
      ${data.items.length ? `<div class="admin-list">${data.items.map(userRowHTML).join('')}</div>` : emptyHTML('没有匹配的用户')}
      ${pagerHTML(data.page, Math.ceil(data.total / data.pageSize))}`;
  } else if (tab === 'courses') {
    const category = params.get('category') || '';
    const college = params.get('college') || '';
    const sort = params.get('sort') || 'newest';
    const data = await api.adminCourses({
      q: keyword, category, college, sort, page: params.get('page') || 1,
    });
    const items = data.items || [];
    body = `
      <form id="admin-course-search" class="admin-filter">
        <input type="search" name="q" value="${esc(keyword)}" placeholder="搜课名 / 老师 / 课程代码 / 部门" />
        <button class="btn btn-sm" type="submit">搜索</button>
        <select id="filter-category">${selectOptions(data.categories, category, '全部类别')}</select>
        <select id="filter-college">${selectOptions(data.colleges, college, '全部部门')}</select>
        <select id="filter-sort">${COURSE_SORTS.map(([v, label]) => `<option value="${v}"${v === sort ? ' selected' : ''}>${esc(label)}</option>`).join('')}</select>
        <span class="small muted">共 ${data.total} 门课</span>
        <a class="btn btn-sm" href="#/new-course" style="margin-left:auto">+ 新建课程</a>
      </form>
      ${items.length
        ? `<div class="admin-list">${items.map((c) => courseManageRowHTML(c, data.categories, data.colleges)).join('')}</div>`
        : emptyHTML('没有匹配的课程')}
      ${pagerHTML(data.page, data.pageCount)}`;
  } else if (tab === 'import') {
    body = importTabHTML();
  } else if (tab === 'edu') {
    body = eduTabHTML();
  } else {
    const kind = params.get('kind') || 'posts';
    const data = kind === 'posts' ? await api.adminPosts(keyword) : await api.adminReviews(keyword);
    const items = (kind === 'posts' ? data.items : data.items) || [];
    body = `
      <div class="admin-filter">
        <a class="chip ${kind === 'posts' ? 'active' : ''}" href="#/admin?tab=content&kind=posts">帖子</a>
        <a class="chip ${kind === 'reviews' ? 'active' : ''}" href="#/admin?tab=content&kind=reviews">课程短评</a>
        <form id="admin-content-search" style="display:inline-flex;gap:6px;margin-left:auto">
          <input type="search" name="q" value="${esc(keyword)}" placeholder="搜标题 / 内容" />
          <button class="btn btn-sm" type="submit">搜索</button>
        </form>
      </div>
      ${items.length ? `<div class="admin-list">${items.map((i) => contentRowHTML(i, kind === 'posts' ? 'post' : 'review')).join('')}</div>` : emptyHTML('没有匹配的内容')}`;
  }

  let stats = null;
  try {
    stats = await api.adminStats();
  } catch {}

  return `
    <div style="max-width:820px;margin:20px auto">
      <div class="card">
        <h2 style="margin-bottom:8px">管理后台</h2>
        ${stats ? `
        <div class="stat-row">
          <span>用户 <b>${stats.users}</b></span>
          <span>已认证 <b>${stats.verified}</b></span>
          <span>待审核 <b>${stats.pending}</b></span>
          <span>课程 <b>${stats.courses}</b></span>
          <span>短评 <b>${stats.reviews}</b></span>
          <span>帖子 <b>${stats.posts}</b></span>
          <span>封禁 <b>${stats.banned}</b></span>
        </div>` : ''}
        <div class="tabs">
          <a class="tab ${tab === 'settings' ? 'active' : ''}" href="#/admin?tab=settings">网站设置</a>
          <a class="tab ${tab === 'verify' ? 'active' : ''}" href="#/admin?tab=verify">认证审核</a>
          <a class="tab ${tab === 'users' ? 'active' : ''}" href="#/admin?tab=users">用户管理</a>
          <a class="tab ${tab === 'content' ? 'active' : ''}" href="#/admin?tab=content">内容管理</a>
          <a class="tab ${tab === 'courses' ? 'active' : ''}" href="#/admin?tab=courses">课程管理</a>
          <a class="tab ${tab === 'import' ? 'active' : ''}" href="#/admin?tab=import">课程导入</a>
          <a class="tab ${tab === 'edu' ? 'active' : ''}" href="#/admin?tab=edu">教务表格导入</a>
        </div>
        <div class="divider"></div>
        ${body}
      </div>
      <div class="card" style="margin-top:14px">
        <div class="small muted">
          审核规则：材料仅管理员可见，审核通过后立即从服务器删除，系统只保留文件名、格式、大小与处理记录。
        </div>
      </div>
    </div>`;
}

export function mountAdmin(root, params) {
  root.querySelector('#maintenance-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const enabled = form.elements.enabled.checked;
    if (enabled && !window.confirm('开启维护后普通用户将无法访问网站，管理员仍可使用后台。确认保存？')) return;
    button.disabled = true;
    try {
      await api.updateMaintenance({ enabled, message: form.elements.message.value });
      toast(enabled ? '维护模式已开启' : '网站已恢复开放', 'ok');
      reload();
    } catch (err) {
      root.querySelector('#maintenance-result').textContent = err.message;
      toast(err.message, 'error');
    } finally { button.disabled = false; }
  });
  root.querySelectorAll('[data-approve]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-approve');
      if (!window.confirm('确认通过该用户的学生认证？通过后材料会被立即删除。')) return;
      try {
        await api.adminReview(id, 'approve', '');
        toast('已通过认证', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  root.querySelectorAll('[data-reject]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-reject');
      const note = window.prompt('请填写驳回原因（会发送给该用户）：', '材料不清晰，无法确认在校身份');
      if (note === null) return;
      try {
        await api.adminReview(id, 'reject', note);
        toast('已驳回', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  root.querySelectorAll('[data-toggle-admin], [data-toggle-verify], [data-toggle-ban]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-toggle-admin') || btn.getAttribute('data-toggle-verify') || btn.getAttribute('data-toggle-ban');
      const isBan = btn.hasAttribute('data-toggle-ban');
      const value = btn.getAttribute('data-value') === '1';
      const patch = isBan
        ? { banned: value }
        : btn.hasAttribute('data-toggle-admin')
          ? { isAdmin: value }
          : { verified: value };

      if (isBan && value) {
        const reason = window.prompt('封禁原因（会展示给该用户）：', '违反社区规范');
        if (reason === null) return;
        patch.banReason = reason;
      }
      if (patch.isAdmin === false && id === session.user.id) {
        toast('不能取消自己的管理员权限', 'error');
        return;
      }
      try {
        await api.adminUserUpdate(id, patch);
        toast('已更新', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  root.querySelectorAll('[data-del-id]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const type = btn.getAttribute('data-del-type');
      const id = btn.getAttribute('data-del-id');
      if (!window.confirm('确认删除这条内容？删除后不可恢复，作者会收到通知。')) return;
      try {
        await api.adminDeleteContent(type, id);
        toast('已删除', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  const userSearch = root.querySelector('#admin-user-search');
  if (userSearch) {
    userSearch.addEventListener('submit', (e) => {
      e.preventDefault();
      const q = new FormData(e.target).get('q');
      location.hash = `#/admin?tab=users&q=${encodeURIComponent(q || '')}`;
    });
  }

  const contentSearch = root.querySelector('#admin-content-search');
  if (contentSearch) {
    contentSearch.addEventListener('submit', (e) => {
      e.preventDefault();
      const q = new FormData(e.target).get('q');
      const kind = params.get('kind') || 'posts';
      location.hash = `#/admin?tab=content&kind=${kind}&q=${encodeURIComponent(q || '')}`;
    });
  }

  /* ---------- 课程管理：编辑 / 删除 / 合并 ---------- */
  const closePanels = (exceptId) => {
    root.querySelectorAll('.course-panel').forEach((p) => {
      if (p.id !== exceptId) p.hidden = true;
    });
  };

  root.querySelectorAll('[data-course-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-course-edit');
      const panel = root.querySelector(`#edit-${id}`);
      if (!panel) return;
      const willOpen = panel.hidden;
      closePanels(panel.id);
      panel.hidden = !willOpen;
      if (willOpen) panel.querySelector('[data-f="name"]')?.focus();
    });
  });

  root.querySelectorAll('[data-course-merge]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-course-merge');
      const panel = root.querySelector(`#merge-${id}`);
      if (!panel) return;
      const willOpen = panel.hidden;
      closePanels(panel.id);
      panel.hidden = !willOpen;
      if (willOpen) panel.querySelector('[data-merge-input]')?.focus();
    });
  });

  root.querySelectorAll('[data-panel-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const panel = root.querySelector(`#${btn.getAttribute('data-panel-close')}`);
      if (panel) panel.hidden = true;
    });
  });

  root.querySelectorAll('[data-course-save]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-course-save');
      const panel = root.querySelector(`#edit-${id}`);
      if (!panel) return;
      const payload = {};
      panel.querySelectorAll('[data-f]').forEach((el) => {
        payload[el.getAttribute('data-f')] = el.value;
      });
      btn.disabled = true;
      try {
        await api.adminCourseUpdate(id, payload);
        toast('课程已更新', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      }
    });
  });

  root.querySelectorAll('[data-course-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-course-del');
      const name = btn.getAttribute('data-name') || '这门课';
      const reviews = btn.getAttribute('data-reviews') || '0';
      const posts = btn.getAttribute('data-posts') || '0';
      if (!window.confirm(`确认删除「${name}」？\n该课程下的 ${reviews} 条短评与 ${posts} 条讨论会一并删除，且无法恢复。\n\n如果只是重名重复，请改用「合并到…」。`)) return;
      try {
        const res = await api.adminCourseDelete(id);
        toast(`已删除，连带 ${res.reviewCount} 条短评、${res.postCount} 条讨论`, 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  root.querySelectorAll('[data-merge-search]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-merge-search');
      const panel = root.querySelector(`#merge-${id}`);
      const input = panel && panel.querySelector('[data-merge-input]');
      const listBox = panel && panel.querySelector('[data-merge-list]');
      if (!panel || !input || !listBox) return;
      const q = input.value.trim();
      if (!q) return toast('请输入目标课程的关键词', 'error');

      btn.disabled = true;
      try {
        const res = await api.adminCourses({ q, sort: 'name', pageSize: 20 });
        const candidates = (res.items || []).filter((c) => c.id !== id);
        if (!candidates.length) {
          listBox.innerHTML = '<div class="small muted">没有找到其它匹配的课程</div>';
          return;
        }
        listBox.innerHTML = candidates.map((c) => `
          <div class="admin-row">
            <div class="admin-row-main">
              <div><b>${esc(c.name)}</b> <span class="muted">· ${esc(c.teacher)}</span></div>
              <div class="small muted">
                ${(c.codes || []).map((code) => `<span class="code-chip">${esc(code)}</span>`).join(' ') || '无代码'}
                · ${(c.stats && c.stats.reviewCount) || 0} 条短评
              </div>
            </div>
            <button class="btn btn-sm btn-primary" data-merge-go="${esc(c.id)}" data-merge-from="${esc(id)}"
              data-target-name="${esc(c.name)}">合并到这里</button>
          </div>`).join('');

        listBox.querySelectorAll('[data-merge-go]').forEach((go) => {
          go.addEventListener('click', async () => {
            const from = go.getAttribute('data-merge-from');
            const to = go.getAttribute('data-merge-go');
            const targetName = go.getAttribute('data-target-name');
            if (!window.confirm(`确认把这门课合并到「${targetName}」？\n来源课程会被删除，短评与讨论转移到目标课程。`)) return;
            try {
              const res = await api.adminCourseMerge(from, to);
              toast(`已合并：转移 ${res.movedReviews} 条短评、${res.movedPosts} 条讨论${res.droppedReviews ? `，丢弃 ${res.droppedReviews} 条重复评分` : ''}`, 'ok');
              reload();
            } catch (err) {
              toast(err.message, 'error');
            }
          });
        });
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });

  const courseSearch = root.querySelector('#admin-course-search');
  if (courseSearch) {
    courseSearch.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      location.hash = adminCourseHash(params, { q: fd.get('q') || '', page: 1 });
    });
  }
  const bindFilter = (sel, key) => {
    const el = root.querySelector(`#${sel}`);
    if (!el) return;
    el.addEventListener('change', () => {
      location.hash = adminCourseHash(params, { [key]: el.value, page: 1 });
    });
  };
  bindFilter('filter-category', 'category');
  bindFilter('filter-college', 'college');
  bindFilter('filter-sort', 'sort');

  root.querySelectorAll('.pager button[data-page]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const p = btn.getAttribute('data-page');
      if (params.get('tab') === 'courses') {
        location.hash = adminCourseHash(params, { page: p });
      } else {
        location.hash = `#/admin?tab=users&q=${encodeURIComponent(params.get('q') || '')}&page=${p}`;
      }
    });
  });

  const btnPreview = root.querySelector('#import-preview');
  const btnCommit = root.querySelector('#import-commit');
  const runImport = async (dry) => {
    const fileEl = root.querySelector('#import-file');
    const textEl = root.querySelector('#import-text');
    const modeEl = root.querySelector('#import-mode');
    const file = fileEl && fileEl.files && fileEl.files[0];
    const text = ((textEl && textEl.value) || '').trim();

    if (!file && !text) {
      return toast('请先上传文件或粘贴表格内容', 'error');
    }
    if (file && file.size > 10 * 1024 * 1024) {
      return toast('文件不能超过 10MB', 'error');
    }

    const fd = new FormData();
    if (file) {
      fd.append('file', file);
    } else {
      fd.append('csv', text);
    }

    const btn = dry ? btnPreview : btnCommit;
    if (btn) btn.disabled = true;
    try {
      const res = await api.adminImportCourses(fd, {
        dry: dry ? 1 : 0,
        mode: (modeEl && modeEl.value) || 'skip',
      });
      const box = root.querySelector('#import-result');
      if (box) box.innerHTML = importResultHTML(res);
      if (dry) {
        toast(`预演完成：新增 ${res.created}，更新 ${res.updated}，跳过 ${res.skippedCount}`, 'ok');
      } else {
        toast(`导入完成：新增 ${res.created} 条，更新 ${res.updated} 条`, 'ok');
        if (textEl) textEl.value = '';
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  if (btnPreview) btnPreview.addEventListener('click', () => runImport(true));
  if (btnCommit) {
    btnCommit.addEventListener('click', () => {
      if (!window.confirm('确认把这些课程写入数据库？建议先做一次预演确认统计无误。')) return;
      runImport(false);
    });
  }

  root.querySelector('#import-template')?.addEventListener('click', () => {
    downloadFile('课程导入模板.csv', `\uFEFF${IMPORT_SAMPLE}`);
  });

  root.querySelector('#import-export')?.addEventListener('click', async () => {
    try {
      const res = await api.exportCourses();
      downloadFile(`课程导出_${new Date().toISOString().slice(0, 10)}.csv`, res.csv || '');
      toast('已导出全部课程', 'ok');
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  /* ---------- 教务表格导入 ---------- */
  const btnEduPreview = root.querySelector('#edu-preview');
  const btnEduCommit = root.querySelector('#edu-commit');
  const runEduImport = async (dry) => {
    const fileEl = root.querySelector('#edu-file');
    const textEl = root.querySelector('#edu-text');
    const modeEl = root.querySelector('#edu-mode');
    const file = fileEl && fileEl.files && fileEl.files[0];
    const text = ((textEl && textEl.value) || '').trim();

    if (!file && !text) {
      return toast('请先上传文件或粘贴表格内容', 'error');
    }
    if (file && file.size > 10 * 1024 * 1024) {
      return toast('文件不能超过 10MB', 'error');
    }

    const fd = new FormData();
    if (file) {
      fd.append('file', file);
    } else {
      fd.append('csv', text);
    }

    const btn = dry ? btnEduPreview : btnEduCommit;
    if (btn) btn.disabled = true;
    try {
      const res = await api.adminImportEdu(fd, {
        dry: dry ? 1 : 0,
        mode: (modeEl && modeEl.value) || 'skip',
      });
      const box = root.querySelector('#edu-result');
      if (box) box.innerHTML = eduResultHTML(res);
      if (dry) {
        toast(`预演完成：新增 ${res.created}，更新 ${res.updated}，跳过 ${res.skippedCount}`, 'ok');
      } else {
        toast(`导入完成：新增 ${res.created} 门，更新 ${res.updated} 门`, 'ok');
        if (textEl) textEl.value = '';
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  if (btnEduPreview) btnEduPreview.addEventListener('click', () => runEduImport(true));
  if (btnEduCommit) {
    btnEduCommit.addEventListener('click', () => {
      if (!window.confirm('确认把这些课程写入数据库？建议先做一次预演，确认合并结果与课程代码无误。')) return;
      runEduImport(false);
    });
  }

  root.querySelector('#edu-template')?.addEventListener('click', () => {
    downloadFile('教务表格模板.csv', `\uFEFF${EDU_SAMPLE}`);
  });
}
