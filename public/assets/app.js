'use strict';

const $ = (id) => document.getElementById(id);
let captchaId = null;

function showAlert(type, msg) {
  const box = $('alertBox');
  box.className = 'alert show ' + type;
  box.textContent = msg;
}
function clearAlert() {
  $('alertBox').className = 'alert';
  $('alertBox').textContent = '';
}

async function loadCaptcha() {
  $('captchaQuestion').textContent = '加载中…';
  try {
    const res = await fetch('/api/captcha.php');
    const body = await res.json();
    if (!body.ok) throw new Error(body.message || '验证码获取失败');
    captchaId = body.data.captcha_id;
    $('captchaQuestion').textContent = body.data.question;
    $('captcha_answer').value = '';
  } catch (err) {
    $('captchaQuestion').textContent = '点击重试';
    showAlert('error', err.message);
  }
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function badgeClass(status) {
  return status;
}

function renderResult(d) {
  $('rOrderNo').textContent = d.order_no;
  $('rDeposit').textContent = d.deposit_amount;

  const ds = $('rDepositStatus');
  ds.textContent = d.deposit_status_text;
  ds.className = 'badge ' + badgeClass(d.deposit_status);

  const rs = $('rRentalStatus');
  rs.textContent = d.rental_status_text;
  rs.className = 'badge ' + badgeClass(d.rental_status);

  $('rRefundTime').textContent = d.refund_time
    ? ('已于 ' + esc(d.refund_time) + ' 发起退款')
    : (d.deposit_status === 'refunding' ? '退款处理中' : '归还验收后退款');

  $('rItems').innerHTML = d.equipment.map((it) => `
    <tr>
      <td>${esc(it.name)}</td>
      <td>${esc(it.model)}</td>
      <td>${it.qty}</td>
      <td>${esc(it.daily_rent)}</td>
    </tr>`).join('');

  $('rPeriod').innerHTML = `
    <tr><th style="width:120px">起租时间</th><td>${esc(d.rental_period.start)}</td></tr>
    <tr><th>实际归还</th><td>${d.return_time ? esc(d.return_time) : (esc(d.rental_period.end || '') + ' 前应归还')}</td></tr>`;

  $('rRefundNote').textContent = d.refund_note;
  const notice = $('rNotice');
  if (d.notice) {
    notice.textContent = '📝 ' + d.notice;
    notice.classList.remove('hidden');
  } else {
    notice.classList.add('hidden');
  }

  $('resultCard').classList.remove('hidden');
  $('resultCard').scrollIntoView({ behavior: 'smooth' });
}

$('captchaBox').addEventListener('click', loadCaptcha);

$('queryForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  clearAlert();

  const payload = {
    order_no: $('order_no').value.trim().toUpperCase(),
    phone: $('phone').value.trim(),
    captcha_id: captchaId,
    captcha_answer: $('captcha_answer').value.trim(),
  };

  $('submitBtn').disabled = true;
  $('submitBtn').textContent = '查询中…';
  try {
    const res = await fetch('/api/query.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await res.json();

    if (res.status === 429) {
      showAlert('error', body.message || '尝试过于频繁，请稍后再试');
    } else if (!body.ok) {
      showAlert('error', body.message || '查询失败，请稍后重试');
    } else {
      showAlert('success', '查询成功');
      renderResult(body.data);
    }
  } catch (err) {
    showAlert('error', '网络异常，请检查连接后重试');
  } finally {
    $('submitBtn').disabled = false;
    $('submitBtn').textContent = '查 询';
    // 验证码一次性，无论成败都换新
    loadCaptcha();
  }
});

loadCaptcha();
