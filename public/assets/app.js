'use strict';
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function showMsg(kind, text) {
  $('#msg').innerHTML = `<div class="${kind === 'err' ? 'error' : 'ok-msg'}">${esc(text)}</div>`;
}

async function api(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(body),
  });
  let data = {};
  try { data = await res.json(); } catch (_) {}
  return {status: res.status, data};
}

function badgeClass(d) {
  if (d.return_status === 'returned') return 'returned';
  if (d.return_status === 'abnormal') return 'abnormal';
  return 'active';
}
function returnText(d) {
  return {renting: '未归还（租赁中）', returned: '已归还', abnormal: '异常处理中'}[d.return_status] || d.status_label;
}

function renderResult(d) {
  const items = d.items.map((it) => `
    <tr>
      <td>${esc(it.name)}</td>
      <td>${esc(it.model || '-')}</td>
      <td>${it.qty} ${esc(it.unit)}</td>
      <td>${it.deposit ? '¥' + esc(it.deposit) : '-'}</td>
    </tr>`).join('');

  let refund = '';
  if (d.refund_status === 'refunded') {
    refund = `<p>退款金额：<strong>¥${esc(d.refund_amount || '0.00')}</strong>，退款到账时间：${esc(d.refunded_at || '-')}</p>`;
  } else if (d.refund_status === 'processing') {
    refund = `<p>退款处理中，我们会在设备验收后尽快原路退回。</p>`;
  } else if (d.status === 'abnormal') {
    refund = `<p>订单异常，退款待核算，请联系门店处理。</p>`;
  } else if (d.return_status === 'renting') {
    refund = `<p>设备归还并验收通过后安排退款。</p>`;
  } else {
    refund = `<p>已归还，等待门店登记退款。</p>`;
  }

  $('#result').innerHTML = `
    <div class="card">
      <h2>订单 ${esc(d.order_no)}
        <span class="badge ${badgeClass(d)}">${esc(returnText(d))}</span>
        <span class="badge ${esc(d.refund_status)}">${esc(d.refund_status_label)}</span>
      </h2>
      <p class="amount">¥${esc(d.deposit)} <small>押金总额</small></p>
      <dl class="kv">
        <dt>起租时间</dt><dd>${esc(d.rented_at || '-')}</dd>
        <dt>应还时间</dt><dd>${esc(d.due_at || '-')}</dd>
        <dt>实际归还</dt><dd>${esc(d.returned_at || '未归还')}</dd>
        <dt>退款状态</dt><dd>${esc(d.refund_status_label)}${d.refunded_at ? '（' + esc(d.refunded_at) + '）' : ''}</dd>
      </dl>
    </div>
    <div class="card">
      <h2>设备清单</h2>
      <table>
        <thead><tr><th>设备名称</th><th>型号</th><th>数量</th><th>单件押金</th></tr></thead>
        <tbody>${items || '<tr><td colspan="4">无</td></tr>'}</tbody>
      </table>
    </div>
    <div class="card">
      <h2>退款信息</h2>
      ${refund}
      ${d.exception_notice ? `<div class="error">${esc(d.exception_notice)}</div>` : ''}
      ${d.public_note ? `<p class="muted">门店备注：${esc(d.public_note)}</p>` : ''}
    </div>`;
  $('#result').classList.remove('hide');
}

$('#q-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('#msg').innerHTML = '';
  $('#result').classList.add('hide');
  const btn = $('#q-btn');
  btn.disabled = true; btn.textContent = '查询中…';
  try {
    const {status, data} = await api('/api/deposit.php', {
      order_no: $('#order_no').value.trim(),
      phone: $('#phone').value.trim(),
    });
    if (data.ok) {
      renderResult(data.data);
    } else if (status === 429) {
      showMsg('err', data.error || '操作过于频繁，请稍后再试');
    } else {
      showMsg('err', data.error || '查询失败，请核对信息');
    }
  } catch (err) {
    showMsg('err', '网络异常，请稍后再试');
  } finally {
    btn.disabled = false; btn.textContent = '查询押金';
  }
});
