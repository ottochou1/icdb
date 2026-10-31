/** 前端接口封装（PHP 版：统一走 api.php?r=xxx） */

function parseQuery(str) {
  const out = {};
  if (!str) return out;
  const s = String(str).replace(/^\?/, '');
  for (const [k, v] of new URLSearchParams(s)) out[k] = v;
  return out;
}

function apiUrl(route, params = {}) {
  const url = new URL('api.php', window.location.href);
  url.searchParams.set('r', route);
  for (const [k, v] of Object.entries(params)) {
    if (v !== '' && v !== undefined && v !== null) url.searchParams.set(k, v);
  }
  return url.toString();
}

/** 统一处理响应，把后端的机器可读错误码挂到 error.code 上 */
async function unwrap(res) {
  let payload;
  try {
    payload = await res.json();
  } catch {
    throw new Error('服务器返回异常，请检查 PHP 与数据库配置');
  }
  if (!payload.ok) {
    if (payload.code === 'MAINTENANCE') window.dispatchEvent(new Event('site-maintenance'));
    const err = new Error(payload.error || '请求失败');
    err.code = payload.code || '';
    err.status = res.status;
    throw err;
  }
  return payload.data;
}

async function request(method, route, { params = {}, body = null } = {}) {
  const res = await fetch(apiUrl(route, params), {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });
  return unwrap(res);
}

/** 文件上传：交给浏览器自动设置 multipart 边界，不要手工指定 Content-Type */
async function requestForm(route, formData, params = {}) {
  const res = await fetch(apiUrl(route, params), {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  return unwrap(res);
}

export const api = {
  me: () => request('GET', 'auth.me'),
  login: (body) => request('POST', 'auth.login', { body }),
  register: (body) => request('POST', 'auth.register', { body }),
  logout: () => request('POST', 'auth.logout'),
  profile: (body) => request('POST', 'auth.profile', { body }),

  config: () => request('GET', 'config'),
  home: () => request('GET', 'home'),

  courses: (query) => request('GET', 'courses.list', { params: parseQuery(query) }),
  courseFacets: () => request('GET', 'courses.facets'),
  course: (id) => request('GET', 'courses.get', { params: { id } }),
  createCourse: (body) => request('POST', 'courses.create', { body }),
  updateCourse: (id, body) => request('POST', 'courses.update', { params: { id }, body }),
  deleteCourse: (id) => request('DELETE', 'courses.delete', { params: { id } }),
  importCourses: (csv) => request('POST', 'courses.import', { body: { csv } }),

  reviews: (courseId, query) => request('GET', 'reviews.list', { params: { ...parseQuery(query), id: courseId } }),
  createReview: (courseId, body) => request('POST', 'reviews.create', { params: { id: courseId }, body }),
  updateReview: (id, body) => request('POST', 'reviews.update', { params: { id }, body }),
  deleteReview: (id) => request('DELETE', 'reviews.delete', { params: { id } }),
  likeReview: (id) => request('POST', 'reviews.like', { params: { id } }),

  posts: (query) => request('GET', 'posts.list', { params: parseQuery(query) }),
  post: (id) => request('GET', 'posts.get', { params: { id } }),
  createPost: (body) => request('POST', 'posts.create', { body }),
  deletePost: (id) => request('DELETE', 'posts.delete', { params: { id } }),
  likePost: (id) => request('POST', 'posts.like', { params: { id } }),
  createComment: (postId, content) => request('POST', 'comments.create', { params: { id: postId }, body: { content } }),
  deleteComment: (id) => request('DELETE', 'comments.delete', { params: { id } }),
  likeComment: (id) => request('POST', 'comments.like', { params: { id } }),

  user: (id) => request('GET', 'users.get', { params: { id } }),
  myFeed: () => request('GET', 'me.feed'),

  /* 学生认证 */
  verifyStatus: () => request('GET', 'verify.status'),
  verifySubmit: (formData) => requestForm('verify.submit', formData),
  verifyCancel: () => request('POST', 'verify.cancel'),

  /* 站内信与通知 */
  unread: () => request('GET', 'messages.unread'),
  conversations: () => request('GET', 'messages.conversations'),
  thread: (id) => request('GET', 'messages.thread', { params: { id } }),
  sendMessage: (userId, content) => request('POST', 'messages.send', { body: { userId, content } }),
  readMessages: (id) => request('POST', 'messages.read', { body: id ? { id } : {} }),
  notifications: () => request('GET', 'notifications.list'),
  readNotifications: (id) => request('POST', 'notifications.read', { body: id ? { id } : {} }),

  /* 管理后台 */
  maintenanceStatus: () => request('GET', 'maintenance.status'),
  adminMaintenance: () => request('GET', 'admin.maintenance'),
  updateMaintenance: async (body) => unwrap(await fetch(apiUrl('admin.maintenance.update'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Maintenance-Request': '1' }, body: JSON.stringify(body) })),
  adminStats: () => request('GET', 'admin.stats'),
  adminVerifications: (status) => request('GET', 'admin.verifications', { params: { status } }),
  adminReview: (id, action, note) => request('POST', 'admin.verification.review', { body: { id, action, note } }),
  adminUsers: (params) => request('GET', 'admin.users', { params }),
  adminUserUpdate: (id, body) => request('POST', 'admin.user.update', { body: { id, ...body } }),
  adminPosts: (q) => request('GET', 'admin.posts', { params: { q } }),
  adminReviews: (q) => request('GET', 'admin.reviews', { params: { q } }),
  adminDeleteContent: (type, id) => request('POST', 'admin.content.delete', { body: { type, id } }),
  adminCourses: (params) => request('GET', 'admin.courses', { params }),
  adminCourseUpdate: (id, body) => request('POST', 'admin.course.update', { body: { id, ...body } }),
  adminCourseDelete: (id) => request('POST', 'admin.course.delete', { body: { id } }),
  adminCourseMerge: (from, to) => request('POST', 'admin.course.merge', { body: { from, to } }),
  adminImportCourses: (formData, params) => requestForm('admin.courses.import', formData, params),
  adminImportEdu: (formData, params) => requestForm('admin.edu.import', formData, params),
  exportCourses: () => request('GET', 'courses.export', {}),
};

export function qsOf(obj) {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(obj)) {
    if (v !== '' && v !== undefined && v !== null) p.set(k, v);
  }
  return p.toString();
}
