'use strict';
const $ = (s, r=document) => r.querySelector(s);
const $$ = (s, r=document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const TokenStore = {
  get: () => localStorage.getItem('admin_token') || '',
  set: (t) => localStorage.setItem('admin_token', t),
  clear: () => localStorage.removeItem('admin_token'),
};

async function call(url, opts = {}) {
  const headers = Object.assign({'Content-Type': 'application/json'}, opts.headers || {});
  const token = TokenStore.get();
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(url, Object.assign({}, opts, {headers}));
  let data = {};
  try { data = await res.json(); } catch (_) {}
  if (res.status === 401) {
    TokenStore.clear();
    location.reload();
    throw new Error('unauthorized');
  }
  return {status: res.status, data};
}
const post = (u, b) => call(u, {method: 'POST', body: JSON.stringify(b || {})});
const put = (u, b) => call(u, {method: 'PUT', body: JSON.stringify(b || {})});

const STATUS_CN = {active:'租赁中', returned:'已归还', abnormal:'异常', closed:'已关闭'};
const REFUND_CN = {unpaid:'待退款', processing:'退款中', refunded:'已退款', deducted:'部分扣除'};
const RESULT_CN = {success:'成功', not_found:'未找到', invalid:'参数错误'};

/* ---------- 登录 / 退出 ---------- */
$('#login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('#lg-msg').innerHTML = '';
  const {status, data} = await post('/api/admin/login.php', {
    username: $('#lg-user').value.trim(),
    password: $('#lg-pass').value,
  });
  if (data.ok) {
    TokenStore.set(data.data.token);
    enterApp(data.data.admin);
  } else {
    $('#lg-msg').innerHTML = `<div class="error">${esc(data.error || '登录失败')}（${status}）</div>`;
  }
});
$('#logout-btn').addEventListener('click', async () => {
  await post('/api/admin/logout.php');
  TokenStore.clear();
  location.reload();
});

function enterApp(admin) {
  $('#login-view').classList.add('hide');
  $('#app-view').classList.remove('hide');
  $('#who').textContent = `${admin.display_name || admin.username}（${admin.username}）`;
  loadOrders(1);
}

/* ---------- tabs ---------- */
$$('.tabs button').forEach((btn) => btn.addEventListener('click', () => {
  $$('.tabs button').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  ['orders','logs','audit'].forEach(t => $('#tab-' + t).classList.toggle('hide', t !== btn.dataset.tab));
  if (btn.dataset.tab === 'logs') loadLogs(1);
  if (btn.dataset.tab === 'audit') loadAudit(1);
}));

/* ---------- 弹窗 ---------- */
function modal(html) {
  $('#modal-box').innerHTML = html +
    '<div style="margin-top:18px;text-align:right"><button class="ghost" id="m-cancel">关闭</button></div>';
  $('#modal-mask').classList.add('show');
  $('#m-cancel').onclick = closeModal;
}
function closeModal() { $('#modal-mask').classList.remove('show'); }
$('#modal-mask').addEventListener('click', (e) => { if (e.target.id === 'modal-mask') closeModal(); });
function mAlert(kind, text) { $('#m-msg').innerHTML = `<div class="${kind==='err'?'error':'ok-msg'}">${esc(text)}</div>`; }

/* ---------- 租赁列表 ---------- */
let orderPage = 1;
async function loadOrders(page) {
  orderPage = page;
  const qs = new URLSearchParams({
    page: String(page),
    q: $('#f-q').value.trim(),
    status: $('#f-status').value,
    refund_status: $('#f-refund').value,
    phone: $('#f-phone').value.trim(),
  });
  const {data} = await call('/api/admin/rentals.php?' + qs.toString());
  if (!data.ok) return;
  const d = data.data;
  $('#orders-body').innerHTML = d.list.map(r => `
    <tr>
      <td><code>${esc(r.order_no)}</code></td>
      <td>${esc(r.customer_name || '-')}</td>
      <td class="tag-phone">${esc(r.phone)}</td>
      <td>¥${esc(r.deposit)}</td>
      <td><span class="badge ${esc(r.status)}">${esc(r.status_label)}</span></td>
      <td><span class="badge ${esc(r.refund_status)}">${esc(r.refund_status_label)}</span>
          ${r.refund_amount ? '<br><small>¥' + esc(r.refund_amount) + '</small>' : ''}</td>
      <td><small>${esc(r.rented_at)}<br>${r.returned_at ? '还:'+esc(r.returned_at) : '未归还'}</small></td>
      <td style="white-space:nowrap">
        <button class="sm" data-act="view" data-id="${r.id}">详情</button>
        <button class="sm ghost" data-act="edit" data-id="${r.id}">编辑</button>
        ${r.status==='active'||r.status==='abnormal' ? `<button class="sm ghost" data-act="return" data-id="${r.id}">归还</button>` : ''}
        ${r.status!=='abnormal' ? `<button class="sm danger" data-act="exc" data-id="${r.id}">异常</button>` : ''}
        ${r.status==='returned'||r.status==='abnormal' ? `<button class="sm ghost" data-act="refund" data-id="${r.id}">退款</button>` : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="8" class="muted">暂无数据</td></tr>';
  $('#o-pageinfo').textContent = `第 ${d.page} / ${Math.max(1, Math.ceil(d.total/d.page_size))} 页，共 ${d.total} 条`;
  $$('#orders-body button').forEach(b => b.onclick = () => orderAction(b.dataset.act, Number(b.dataset.id)));
}
$('#f-search').onclick = () => loadOrders(1);
$('#f-reset').onclick = () => { ['f-q','f-phone'].forEach(id=>$('#'+id).value=''); $('#f-status').value=''; $('#f-refund').value=''; loadOrders(1); };
$('#o-prev').onclick = () => { if (orderPage>1) loadOrders(orderPage-1); };
$('#o-next').onclick = () => loadOrders(orderPage+1);
$('#btn-create').onclick = () => rentalForm(null);

async function orderAction(act, id) {
  const {data} = await call('/api/admin/rental.php?id=' + id);
  if (!data.ok) return;
  const r = data.data;
  if (act === 'view') return viewRental(r);
  if (act === 'edit') return rentalForm(r);
  if (act === 'return') return returnForm(r);
  if (act === 'exc') return exceptionForm(r);
  if (act === 'refund') return refundForm(r);
}

function viewRental(r) {
  const items = r.items.map(i => `<tr><td>${esc(i.name)}</td><td>${esc(i.model||'-')}</td>
    <td>${esc(i.sn||'-')}</td><td>${i.qty} ${esc(i.unit)}</td><td>${i.unit_deposit?('¥'+esc(i.unit_deposit)):'-'}</td></tr>`).join('');
  modal(`<h3>订单 ${esc(r.order_no)} <span class="badge ${esc(r.status)}">${esc(r.status_label)}</span>
      <span class="badge ${esc(r.refund_status)}">${esc(r.refund_status_label)}</span></h3>
    <dl class="kv">
      <dt>客户</dt><dd>${esc(r.customer_name||'-')} / ${esc(r.phone)}</dd>
      <dt>押金</dt><dd>¥${esc(r.deposit)}</dd>
      <dt>起租</dt><dd>${esc(r.rented_at)}（应还 ${esc(r.due_at||'不限')}）</dd>
      <dt>归还</dt><dd>${esc(r.returned_at||'未归还')}</dd>
      <dt>退款</dt><dd>${esc(r.refund_status_label)} ${r.refund_amount?('¥'+esc(r.refund_amount)):''} ${r.refunded_at?esc(r.refunded_at):''}</dd>
      <dt>异常说明</dt><dd style="color:${r.exception_note?'#e8590c':'inherit'}">${esc(r.exception_note||'无')}</dd>
      <dt>客户可见备注</dt><dd>${esc(r.public_note||'无')}</dd>
    </dl>
    <table><thead><tr><th>设备</th><th>型号</th><th>序列号</th><th>数量</th><th>单件押金</th></tr></thead>
    <tbody>${items||'<tr><td colspan=5>无</td></tr>'}</tbody></table>`);
}

/* ---------- 录入 / 编辑 ---------- */
function dtLocal(s) {
  if (!s) return '';
  return s.replace(' ', 'T').slice(0, 16);
}
let itemSeq = 0;
function itemLine(it = {}) {
  itemSeq++;
  return `<div class="item-line" data-seq="${itemSeq}">
    <input class="i-name" placeholder="设备名称*" value="${esc(it.name||'')}">
    <input class="i-model" placeholder="型号" value="${esc(it.model||'')}">
    <input class="i-sn" placeholder="序列号(内部)" value="${esc(it.sn||'')}">
    <input class="i-qty" type="number" min="1" value="${it.qty||1}" style="max-width:80px" title="数量">
    <input class="i-dep" placeholder="单件押金(元)" value="${esc(it.unit_deposit||'0')}" style="max-width:130px">
    <button type="button" class="ghost sm i-del">删</button>
  </div>`;
}
function bindItemLines() {
  $$('.i-del').forEach(b => b.onclick = () => b.closest('.item-line').remove());
}
function collectItems() {
  return $$('.item-line').map(line => ({
    name: $('.i-name', line).value, model: $('.i-model', line).value, sn: $('.i-sn', line).value,
    qty: Number($('.i-qty', line).value), unit_deposit: $('.i-dep', line).value, unit: '件',
  }));
}

function rentalForm(r) {
  itemSeq = 0;
  const items = (r ? r.items : [{name:'',model:'',sn:'',qty:1,unit_deposit:'0'}]).map(itemLine).join('');
  modal(`<h3>${r ? '编辑租赁 ' + esc(r.order_no) : '录入新租赁'}</h3>
    <form id="r-form">
      <div class="row">
        <div><label>客户姓名</label><input id="r-name" value="${esc(r?.customer_name||'')}"></div>
        <div><label>手机号*</label><input id="r-phone" inputmode="numeric" maxlength="11" value="${esc(r?.phone||'')}"></div>
      </div>
      <label>设备清单* <button type="button" class="ghost sm" id="r-add">＋ 增加一行</button></label>
      <div id="r-items">${items}</div>
      <div class="row">
        <div><label>押金总额(元)*</label><input id="r-deposit" value="${esc(r?.deposit||'0')}"></div>
        <div><label>起租时间*</label><input id="r-start" type="datetime-local" value="${dtLocal(r?.rented_at)}"></div>
        <div><label>应还时间</label><input id="r-due" type="datetime-local" value="${dtLocal(r?.due_at)}"></div>
      </div>
      <label>客户可见备注</label><textarea id="r-note" rows="2">${esc(r?.public_note||'')}</textarea>
      <div id="m-msg"></div>
      <div style="margin-top:14px;text-align:right">
        <button type="submit">${r ? '保存修改' : '创建租赁单'}</button></div>
    </form>`);
  $('#r-add').onclick = () => { $('#r-items').insertAdjacentHTML('beforeend', itemLine()); bindItemLines(); };
  bindItemLines();
  $('#r-form').onsubmit = async (e) => {
    e.preventDefault();
    const body = {
      customer_name: $('#r-name').value.trim(),
      phone: $('#r-phone').value.trim(),
      items: collectItems(),
      deposit: $('#r-deposit').value,
      rented_at: $('#r-start').value,
      due_at: $('#r-due').value,
      public_note: $('#r-note').value.trim(),
    };
    const url = r ? '/api/admin/rental-update.php' : '/api/admin/rental-create.php';
    if (r) body.id = r.id;
    const {status, data} = r ? await put(url, body) : await post(url, body);
    if (data.ok) { closeModal(); loadOrders(orderPage); }
    else mAlert('err', data.error || ('操作失败(' + status + ')'));
  };
}

/* ---------- 归还 / 异常 / 退款 ---------- */
function returnForm(r) {
  modal(`<h3>标记归还 · ${esc(r.order_no)}</h3>
    <label>实际归还时间</label><input id="x-time" type="datetime-local" value="${dtLocal(r.returned_at)}">
    <label>客户可见备注（可选）</label><textarea id="x-note" rows="2">${esc(r.public_note||'')}</textarea>
    <div id="m-msg"></div>
    <div style="margin-top:14px;text-align:right">
      <button id="x-ok">确认归还</button></div>`);
  $('#x-ok').onclick = async () => {
    const {status, data} = await post('/api/admin/return.php', {
      id: r.id, returned_at: $('#x-time').value, public_note: $('#x-note').value.trim(),
    });
    if (data.ok) { closeModal(); loadOrders(orderPage); } else mAlert('err', data.error||('失败('+status+')'));
  };
}
function exceptionForm(r) {
  modal(`<h3>标记异常 · ${esc(r.order_no)}</h3>
    <label>异常说明*（内部留存，客户不可见）</label>
    <textarea id="e-note" rows="4" placeholder="如：镜头卡口损坏、配件缺失、逾期未还…">${esc(r.exception_note||'')}</textarea>
    <label>客户可见提示（可选，留空则使用默认提示）</label><input id="e-pub" value="${esc(r.public_note||'')}">
    <div id="m-msg"></div>
    <div style="margin-top:14px;text-align:right"><button class="danger" id="e-ok">确认标记异常</button></div>`);
  $('#e-ok').onclick = async () => {
    const {status, data} = await post('/api/admin/exception.php', {
      id: r.id, exception_note: $('#e-note').value, public_note: $('#e-pub').value.trim(),
    });
    if (data.ok) { closeModal(); loadOrders(orderPage); } else mAlert('err', data.error||('失败('+status+')'));
  };
}
function refundForm(r) {
  modal(`<h3>登记退款 · ${esc(r.order_no)}</h3>
    <p class="muted">押金 ¥${esc(r.deposit)}，当前：${esc(r.status_label)} / ${esc(r.refund_status_label)}</p>
    <label>退款状态</label>
    <select id="rf-status">
      <option value="processing">退款中</option>
      <option value="refunded">已退款（需填金额与时间）</option>
      <option value="deducted">部分扣除（保留异常状态）</option>
    </select>
    <div class="row">
      <div><label>实际退款金额(元)</label><input id="rf-amt" value="${esc(r.deposit)}"></div>
      <div><label>退款到账时间</label><input id="rf-time" type="datetime-local" value="${dtLocal(r.refunded_at)}"></div>
    </div>
    <div id="m-msg"></div>
    <div style="margin-top:14px;text-align:right"><button id="rf-ok">保存</button></div>`);
  $('#rf-ok').onclick = async () => {
    const st = $('#rf-status').value;
    const body = {id: r.id, refund_status: st};
    if (st === 'refunded') {
      body.refund_amount = $('#rf-amt').value;
      body.refunded_at = $('#rf-time').value;
    }
    const {status, data} = await post('/api/admin/refund.php', body);
    if (data.ok) { closeModal(); loadOrders(orderPage); } else mAlert('err', data.error||('失败('+status+')'));
  };
}

/* ---------- 日志 ---------- */
let logPage = 1;
function logQuery(page) {
  return new URLSearchParams({
    page: String(page), result: $('#l-result').value,
    order_no: $('#l-order').value.trim(),
    from: $('#l-from').value, to: $('#l-to').value,
  }).toString();
}
async function loadLogs(page) {
  logPage = page;
  const {data} = await call('/api/admin/logs.php?' + logQuery(page));
  if (!data.ok) return;
  const d = data.data;
  $('#logs-body').innerHTML = d.list.map(l => `
    <tr><td>${l.id}</td><td>${esc(l.created_at)}</td><td><code>${esc(l.order_input||'-')}</code></td>
    <td>${esc(l.phone_input||'-')}</td>
    <td><span class="badge ${l.result==='success'?'refunded':l.result==='not_found'?'processing':'unpaid'}">${esc(RESULT_CN[l.result]||l.result)}</span></td>
    <td>${l.http_status}</td><td><code>${esc(l.ip_hash.slice(0,12))}</code></td>
    <td><small>${esc((l.user_agent||'').slice(0,60))}</small></td></tr>`).join('')
    || '<tr><td colspan="8" class="muted">暂无日志</td></tr>';
  $('#l-pageinfo').textContent = `第 ${d.page} / ${Math.max(1,Math.ceil(d.total/d.page_size))} 页，共 ${d.total} 条`;
}
$('#l-search').onclick = () => loadLogs(1);
$('#l-prev').onclick = () => { if (logPage>1) loadLogs(logPage-1); };
$('#l-next').onclick = () => loadLogs(logPage+1);
$('#l-export').onclick = async (e) => {
  e.preventDefault();
  const qs = logQuery(1);
  // 带鉴权头拿 CSV 再触发下载
  const res = await fetch('/api/admin/logs-export.php?' + qs, {headers:{Authorization:'Bearer '+TokenStore.get()}});
  if (!res.ok) { alert('导出失败(' + res.status + ')'); return; }
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'query-logs.csv';
  a.click();
  URL.revokeObjectURL(a.href);
};

/* ---------- 审计 ---------- */
let auditPage = 1;
async function loadAudit(page) {
  auditPage = page;
  const qs = new URLSearchParams({page: String(page), action: $('#a-q').value.trim()}).toString();
  const {data} = await call('/api/admin/audits.php?' + qs);
  if (!data.ok) return;
  const d = data.data;
  $('#audit-body').innerHTML = d.list.map(a => `
    <tr><td>${a.id}</td><td>${esc(a.created_at)}</td><td>${esc(a.username||'-')}</td>
    <td><code>${esc(a.action)}</code></td><td>${esc(a.target)}</td>
    <td>${esc(a.detail)}</td><td>${esc(a.ip)}</td></tr>`).join('')
    || '<tr><td colspan="7" class="muted">暂无记录</td></tr>';
  $('#a-pageinfo').textContent = `第 ${d.page} / ${Math.max(1,Math.ceil(d.total/d.page_size))} 页，共 ${d.total} 条`;
}
$('#a-search').onclick = () => loadAudit(1);
$('#a-prev').onclick = () => { if (auditPage>1) loadAudit(auditPage-1); };
$('#a-next').onclick = () => loadAudit(auditPage+1);

/* ---------- 启动：已登录则恢复会话 ---------- */
(async () => {
  if (!TokenStore.get()) return;
  const {data} = await call('/api/admin/me.php');
  if (data.ok) enterApp(data.data);
})();
