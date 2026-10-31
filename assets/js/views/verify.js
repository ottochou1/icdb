import { api } from '../api.js';
import { esc, timeAgo, toast, emptyHTML } from '../util.js';
import { session, reload, refreshUnread } from '../session.js';

const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

function fmtSize(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1048576).toFixed(1)} MB`;
}

function extOf(name) {
  return String(name || '').split('.').pop().toLowerCase();
}

export async function renderVerify() {
  if (!session.user) {
    return `
      <div style="max-width:520px;margin:20px auto">
        <div class="card">
          ${emptyHTML('请先登录后再进行学生认证')}
          <div style="text-align:center;padding-bottom:16px"><a class="btn btn-primary" href="#/login">去登录</a></div>
        </div>
      </div>`;
  }

  const st = await api.verifyStatus();
  const app = st.application;
  let body = '';

  if (st.banned) {
    body = `
      <div class="notice error">
        <b>账号已被限制发言</b>
        <div>${esc(st.banReason || '如有疑问请通过站内信联系管理员')}</div>
      </div>`;
  } else if (st.verified) {
    body = `
      <div class="notice ok">
        <b>你已通过学生认证</b>${st.verifiedAt ? `<span class="small muted">（${timeAgo(st.verifiedAt)}）</span>` : ''}
        <div class="small" style="margin-top:6px">
          现在可以发布课程评价、参与讨论、给短评点赞，也可以给其他同学发私信。
          ${st.isAdmin ? '<br>你是管理员，享有豁免与后台管理权限。' : ''}
        </div>
      </div>`;
  } else if (app && app.status === 'pending') {
    body = `
      <div class="notice pending">
        <b>认证申请待审核</b>
        <div class="small">提交于 ${timeAgo(app.createdAt)} · ${esc(app.fileName)}（${fmtSize(app.fileSize)}）</div>
        <div class="small" style="margin-top:6px">管理员审核后会通过站内信通知你。审核期间你仍然可以浏览和搜索课程。</div>
      </div>
      <div style="margin-top:12px"><button class="btn btn-sm" id="cancel-verify">撤回申请</button></div>`;
  } else {
    const rejected = app && app.status === 'rejected';
    body = `
      ${rejected ? `
      <div class="notice error">
        <b>上次认证未通过</b>
        <div>${esc(app.adminNote || '材料不清晰或无法确认在校身份')}</div>
        <div class="small">原文件已删除，你可以重新提交一份清晰的证明材料。</div>
      </div>` : ''}
      <form id="verify-form">
        <div class="field">
          <label>上传证明材料 *</label>
          <input type="file" name="file" id="verify-file" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" required />
          <div class="hint">支持 PDF 或图片（JPG / PNG / GIF / WebP），大小不超过 10MB</div>
          <div id="file-preview"></div>
        </div>
        <div class="field">
          <label>补充说明（选填）</label>
          <textarea name="note" style="min-height:64px" placeholder="例如：学生证内页，姓名学号清晰可见"></textarea>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%">提交认证申请</button>
      </form>`;
  }

  return `
    <div style="max-width:560px;margin:20px auto">
      <div class="card">
        <h2 style="margin-bottom:4px">学生认证</h2>
        <p class="muted small">为保证社区内容都来自在校同学，发帖、评分和评论需要先通过在读认证。</p>
        <div class="divider"></div>
        ${body}
      </div>
      <div class="card" style="margin-top:14px">
        <h3 style="margin-bottom:8px">关于你的证明材料</h3>
        <ul class="small muted" style="padding-left:18px;line-height:1.9">
          <li>请上传学生证、校园卡或在读证明等能证明在校身份的材料</li>
          <li>建议先裁掉或遮挡与在校身份无关的敏感信息</li>
          <li><b>审核完成后（无论通过与否）文件都会立刻从服务器删除</b>，系统只保留文件名、格式、大小等记录用于追溯，不会保存证件照片</li>
          <li>审核结果会通过站内信通知你</li>
        </ul>
      </div>
    </div>`;
}

export function mountVerify(root) {
  const form = root.querySelector('#verify-form');
  if (form) {
    const input = form.querySelector('#verify-file');
    const preview = root.querySelector('#file-preview');

    input.addEventListener('change', () => {
      const f = input.files[0];
      if (!f) {
        preview.innerHTML = '';
        return;
      }
      const okExt = ALLOWED.includes(extOf(f.name));
      const okSize = f.size <= MAX_BYTES;
      const problems = [];
      if (!okExt) problems.push('格式不支持');
      if (!okSize) problems.push('超过 10MB');
      preview.innerHTML = `
        <div class="file-chip ${problems.length ? 'bad' : ''}">
          <span>${esc(f.name)} · ${fmtSize(f.size)}</span>
          ${problems.length ? `<span class="warn">${problems.join(' / ')}</span>` : '<span class="ok-tag">可以上传</span>'}
        </div>`;
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = input.files[0];
      if (!f) {
        toast('请选择要上传的证明材料', 'error');
        return;
      }
      if (!ALLOWED.includes(extOf(f.name))) {
        toast('只支持 PDF 或图片格式（jpg / png / gif / webp）', 'error');
        return;
      }
      if (f.size > MAX_BYTES) {
        toast(`文件不能超过 10MB，当前 ${fmtSize(f.size)}`, 'error');
        return;
      }
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      btn.textContent = '上传中…';
      try {
        await api.verifySubmit(new FormData(form));
        toast('已提交，请等待管理员审核', 'ok');
        await refreshUnread();
        reload();
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
        btn.textContent = '提交认证申请';
      }
    });
  }

  const cancel = root.querySelector('#cancel-verify');
  if (cancel) {
    cancel.addEventListener('click', async () => {
      if (!window.confirm('确定撤回这份认证申请吗？撤回后可以重新提交。')) return;
      try {
        await api.verifyCancel();
        toast('已撤回申请', 'ok');
        reload();
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }
}
