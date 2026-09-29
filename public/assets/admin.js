'use strict';

const $ = (id) => document.getElementById(id);
let csrfToken = null;
let loginCaptchaId = null;

const RENTAL_TEXT = { renting: '租赁中', returned: '已归还', overdue: '逾期未还' };
const DEPOSIT_TEXT = { held: '押金冻结中', deducted: '押金已部分扣除', refunding: '退款处理中', refunded: '押金已退还' };
const RESULT_TEXT = {
  success: '查询成功', fail: '查询失败', locked: '触发锁定',
  login_success: '登录成功', login_fail: '登录失败', logout: '退出',
  admin_create: '录入订单', admin_update: '更新订单',
  admin_mark_abnormal: '标记异常', admin_clear_abnormal: '解除异常',
  admin_export_logs: '导出日志',
};

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}
function yuan(fen) { return (Number(fen || 0) / 100).toFixed(2); }
function inlineAlert(id, type, msg) {
  const el = $(id);
  if (!el) return;
  el.innerHTML = msg ? `<div class="alert show ${type}">${esc(msg)}</div>` : '';
}

async function api(url, opts = {}) {
  const headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
  if (csrfToken && opts.write) headers['X-CSRF-Token'] = csrfToken;
  const res = await fetch(url, Object.assign({}, opts, { headers }));
  let body = null;
  try { body = await res.json(); } catch (_) { /* csv 等非 json 响应 */ }
  return { status: res.status, body };
}

/* ---------------- 登录 ---------------- */

async function loadLoginCaptcha() {
  $('loginCaptchaQuestion').textContent = '加载中…';
  try {
    const { body } = await api('/api/captcha.php');
    if (!body || !body.ok) throw new Error(body?.message || '验证码获取失败');
    loginCaptchaId = body.data.captcha_id;
    $('loginCaptchaQuestion').textContent = body.data.question;
  } catch (err) {
    $('loginCaptchaQuestion').textContent = '点击重试';
    inlineAlert('loginAlert', 'error', err.message);
  }
}

$('loginCaptchaBox').addEventListener('click', loadLoginCaptcha);

$('loginForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  inlineAlert('loginAlert', '', '');
  const { status, body } = await api('/api/admin/login.php', {
    method: 'POST',
    body: JSON.stringify({
      username: $('loginUser').value.trim(),
      password: $('loginPass').value,
      captcha_id: loginCaptchaId,
      captcha_answer: $('loginCaptchaAnswer').value.trim(),
    }),
  });
  if (status === 200 && body.ok) {
    csrfToken = body.data.csrf_token;
    enterApp(body.data.username);
  } else {
    inlineAlert('loginAlert', 'error', body?.message || '登录失败');
    loadLoginCaptcha();
  }
});

$('logoutBtn').addEventListener('click', async () => {
  await api('/api/admin/logout.php', { method: 'POST', write: true });
  csrfToken = null;
  location.reload();
});

async function bootstrap() {
  const { status, body } = await api('/api/admin/me.php');
  if (status === 200 && body.ok) {
    csrfToken = body.data.csrf_token;
    enterApp(body.data.username);
  } else {
    $('loginView').classList.remove('hidden');
    loadLoginCaptcha();
  }
}

function enterApp(username) {
  $('loginView').classList.add('hidden');
  $('mainView').classList.remove('hidden');
  $('currentUser').textContent = username;
  loadOrders();
}

/* ---------------- Tab 切换 ---------------- */
document.querySelectorAll('.nav-item').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.nav-item').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    const tab = btn.dataset.tab;
    $('tab-orders').classList.toggle('hidden', tab !== 'orders');
    $('tab-logs').classList.toggle('hidden', tab !== 'logs');
    if (tab === 'logs') loadLogs();
  });
});

/* ---------------- 订单列表 ---------------- */
const orderState = { page: 1, total: 0, pageSize: 20 };

async function loadOrders() {
  const params = new URLSearchParams({
    page: String(orderState.page),
    page_size: String(orderState.pageSize),
  });
  if ($('orderQ').value.trim()) params.set('q', $('orderQ').value.trim());
  if ($('filterRental').value) params.set('rental_status', $('filterRental').value);
  if ($('filterDeposit').value) params.set('deposit_status', $('filterDeposit').value);
  if ($('filterAbnormal').value) params.set('abnormal', $('filterAbnormal').value);

  const { status, body } = await api('/api/admin/orders.php?' + params.toString());
  if (status !== 200) {
    inlineAlert('orderAlert', 'error', body?.message || '加载失败');
    return;
  }
  orderState.total = body.data.total;
  $('orderTbody').innerHTML = body.data.list.map((o) => `
    <tr>
      <td><strong>${esc(o.order_no)}</strong></td>
      <td>${esc(o.customer_name)}<br><span class="muted">${esc(o.customer_phone)}</span></td>
      <td>${yuan(o.deposit_amount)}</td>
      <td>
        <span class="badge ${o.rental_status}">${RENTAL_TEXT[o.rental_status] || o.rental_status}</span><br>
        <span class="badge ${o.deposit_status}">${DEPOSIT_TEXT[o.deposit_status] || o.deposit_status}</span>
      </td>
      <td>
        <span class="muted">归还：</span>${o.return_time ? esc(o.return_time) : '—'}<br>
        <span class="muted">退款：</span>${o.refund_time ? esc(o.refund_time) : '—'}
      </td>
      <td>${Number(o.is_abnormal) ? '<span class="badge abnormal">异常</span>' : '<span class="muted">正常</span>'}</td>
      <td>
        <button class="btn secondary small" onclick="editOrder('${esc(o.order_no)}')">编辑</button>
        <button class="btn ${Number(o.is_abnormal) ? 'secondary' : 'danger'} small"
                onclick="markAbnormal('${esc(o.order_no)}', ${Number(o.is_abnormal)}, ${JSON.stringify(esc(o.abnormal_note))})">
          ${Number(o.is_abnormal) ? '解除' : '异常'}
        </button>
      </td>
    </tr>`).join('') || '<tr><td colspan="7" class="muted">暂无数据</td></tr>';

  const pages = Math.max(1, Math.ceil(orderState.total / orderState.pageSize));
  $('orderPageInfo').textContent = `第 ${orderState.page} / ${pages} 页（共 ${orderState.total} 条）`;
  $('orderPrev').disabled = orderState.page <= 1;
  $('orderNext').disabled = orderState.page >= pages;
}

$('orderSearchBtn').addEventListener('click', () => { orderState.page = 1; loadOrders(); });
$('orderPrev').addEventListener('click', () => { orderState.page--; loadOrders(); });
$('orderNext').addEventListener('click', () => { orderState.page++; loadOrders(); });

/* ---------------- 录入 / 编辑订单 ---------------- */
function itemLine(it = {}) {
  const row = document.createElement('div');
  row.className = 'item-line';
  row.innerHTML = `
    <input type="text" class="it-name" placeholder="设备名称" maxlength="128" value="${esc(it.name || '')}">
    <input type="text" class="it-model" placeholder="型号" maxlength="128" value="${esc(it.model || '')}">
    <input type="number" class="it-qty" placeholder="数量" min="1" max="999" value="${it.qty || 1}">
    <input type="text" class="it-price" placeholder="日租金(元)" inputmode="decimal" value="${it.unit_price != null ? yuan(it.unit_price) : ''}">
    <button type="button" class="del">×</button>`;
  row.querySelector('.del').addEventListener('click', () => row.remove());
  return row;
}

function resetOrderForm() {
  $('orderForm').reset();
  $('of_order_no').value = '';
  $('itemsEditor').innerHTML = '';
  $('itemsEditor').appendChild(itemLine());
  inlineAlert('orderFormAlert', '', '');
}

$('newOrderBtn').addEventListener('click', () => {
  $('orderDialogTitle').textContent = '录入租赁记录';
  resetOrderForm();
  $('of_rental_start').value = new Date().toISOString().slice(0, 16);
  $('orderDialog').classList.remove('hidden');
});

$('addItemBtn').addEventListener('click', () => $('itemsEditor').appendChild(itemLine()));
$('orderDialogCancel').addEventListener('click', () => $('orderDialog').classList.add('hidden'));

window.editOrder = async function (orderNo) {
  $('orderDialogTitle').textContent = '编辑租赁记录 · ' + orderNo;
  inlineAlert('orderFormAlert', '', '');
  const { status, body } = await api('/api/admin/orders.php?order_no=' + encodeURIComponent(orderNo));
  if (status !== 200) { inlineAlert('orderFormAlert', 'error', body?.message || '加载订单失败'); return; }
  const o = body.data.order;

  $('of_order_no').value = o.order_no;
  $('of_name').value = o.customer_name;
  $('of_phone').value = o.customer_phone;
  $('of_deposit').value = yuan(o.deposit_amount);
  $('of_rental_status').value = o.rental_status;
  $('of_deposit_status').value = o.deposit_status;
  $('of_rental_start').value = (o.rental_start || '').replace(' ', 'T').slice(0, 16);
  $('of_rental_end').value = (o.rental_end || '').replace(' ', 'T').slice(0, 16);
  $('of_return_time').value = (o.return_time || '').replace(' ', 'T').slice(0, 16);
  $('of_refund_time').value = (o.refund_time || '').replace(' ', 'T').slice(0, 16);
  $('of_customer_note').value = o.customer_note || '';

  $('itemsEditor').innerHTML = '';
  (o.items || []).forEach((it) => $('itemsEditor').appendChild(itemLine(it)));
  if (!o.items || !o.items.length) $('itemsEditor').appendChild(itemLine());

  $('orderDialog').classList.remove('hidden');
};

$('orderForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  inlineAlert('orderFormAlert', '', '');

  const items = [...document.querySelectorAll('#itemsEditor .item-line')].map((row) => ({
    name: row.querySelector('.it-name').value.trim(),
    model: row.querySelector('.it-model').value.trim(),
    qty: parseInt(row.querySelector('.it-qty').value, 10) || 0,
    unit_price: row.querySelector('.it-price').value.trim(),
  }));

  const payload = {
    name: $('of_name').value.trim(),
    phone: $('of_phone').value.trim(),
    deposit_amount: $('of_deposit').value.trim(),
    rental_status: $('of_rental_status').value,
    deposit_status: $('of_deposit_status').value,
    rental_start: $('of_rental_start').value,
    rental_end: $('of_rental_end').value,
    return_time: $('of_return_time').value,
    refund_time: $('of_refund_time').value,
    customer_note: $('of_customer_note').value.trim(),
    items,
  };

  const orderNo = $('of_order_no').value;
  const url = orderNo
    ? '/api/admin/orders.php?order_no=' + encodeURIComponent(orderNo)
    : '/api/admin/orders.php';
  const { status, body } = await api(url, {
    method: orderNo ? 'PUT' : 'POST',
    write: true,
    body: JSON.stringify(payload),
  });

  if (status === 200 && body.ok) {
    $('orderDialog').classList.add('hidden');
    inlineAlert('orderAlert', 'success', body.message + (body.data.order_no ? '（订单号：' + body.data.order_no + '）' : ''));
    loadOrders();
  } else {
    inlineAlert('orderFormAlert', 'error', body?.message || '保存失败');
  }
});

/* ---------------- 异常标记 ---------------- */
window.markAbnormal = function (orderNo, isAbnormal, note) {
  $('abn_order_no').value = orderNo;
  $('abn_view_no').value = orderNo;
  $('abn_note').value = isAbnormal ? note : '';
  $('abn_deposit_status').value = '';
  inlineAlert('abnAlert', '', '');
  $('abnSave').textContent = isAbnormal ? '解除异常' : '确认标记异常';
  $('abnSave').className = isAbnormal ? 'btn secondary' : 'btn danger';
  $('abnDialog').classList.remove('hidden');
};
$('abnCancel').addEventListener('click', () => $('abnDialog').classList.add('hidden'));

$('abnSave').addEventListener('click', async () => {
  const orderNo = $('abn_order_no').value;
  const isMark = $('abnSave').textContent.includes('确认');
  const payload = {
    is_abnormal: isMark ? 1 : 0,
    note: $('abn_note').value.trim(),
    deposit_status: $('abn_deposit_status').value,
  };
  const { status, body } = await api('/api/admin/abnormal.php?order_no=' + encodeURIComponent(orderNo), {
    method: 'POST', write: true, body: JSON.stringify(payload),
  });
  if (status === 200 && body.ok) {
    $('abnDialog').classList.add('hidden');
    inlineAlert('orderAlert', 'success', body.message);
    loadOrders();
  } else {
    inlineAlert('abnAlert', 'error', body?.message || '操作失败');
  }
});

/* ---------------- 查询日志 ---------------- */
const logState = { page: 1, total: 0, pageSize: 30 };

function logFilterParams(extra = {}) {
  const params = new URLSearchParams(Object.assign({
    page: String(logState.page),
    page_size: String(logState.pageSize),
  }, extra));
  if ($('filterResult').value) params.set('result', $('filterResult').value);
  if ($('logOrderQ').value.trim()) params.set('q', $('logOrderQ').value.trim());
  if ($('logStart').value) params.set('start', $('logStart').value);
  if ($('logEnd').value) params.set('end', $('logEnd').value);
  return params;
}

async function loadLogs() {
  const { status, body } = await api('/api/admin/logs.php?' + logFilterParams().toString());
  if (status !== 200) {
    inlineAlert('logAlert', 'error', body?.message || '加载失败');
    return;
  }
  logState.total = body.data.total;
  $('logTbody').innerHTML = body.data.list.map((l) => {
    const okish = ['success', 'login_success', 'admin_create', 'admin_update',
      'admin_mark_abnormal', 'admin_clear_abnormal', 'admin_export_logs', 'logout', 'refunded'].includes(l.result);
    const bad = ['fail', 'locked', 'login_fail'].includes(l.result);
    const cls = bad ? 'deducted' : (okish ? 'returned' : 'renting');
    return `<tr>
      <td>${esc(l.created_at)}</td>
      <td>${esc(l.ip)}</td>
      <td>${esc(l.order_no) || '<span class="muted">—</span>'}</td>
      <td>${esc(l.phone_last4) || '<span class="muted">—</span>'}</td>
      <td><span class="badge ${cls}">${esc(RESULT_TEXT[l.result] || l.result)}</span></td>
      <td class="muted">${esc(l.code)}</td>
      <td>${esc(l.admin_username || '—')}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="7" class="muted">暂无日志</td></tr>';

  const pages = Math.max(1, Math.ceil(logState.total / logState.pageSize));
  $('logPageInfo').textContent = `第 ${logState.page} / ${pages} 页（共 ${logState.total} 条）`;
  $('logPrev').disabled = logState.page <= 1;
  $('logNext').disabled = logState.page >= pages;
}

$('logSearchBtn').addEventListener('click', () => { logState.page = 1; loadLogs(); });
$('logPrev').addEventListener('click', () => { logState.page--; loadLogs(); });
$('logNext').addEventListener('click', () => { logState.page++; loadLogs(); });

$('exportCsvBtn').addEventListener('click', () => {
  // 依赖已有的会话 Cookie；CSRF 只用于状态变更，GET 导出无需携带
  window.location.href = '/api/admin/logs.php?' + logFilterParams({ format: 'csv' }).toString();
});

bootstrap();
