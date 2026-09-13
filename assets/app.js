(function () {
  'use strict';

  const $ = (s) => document.querySelector(s);
  const STATUS_TEXT = {
    held: '押金占用中', refunding: '退款处理中',
    refunded: '押金已退还', deducted: '押金已扣除', abnormal: '订单异常'
  };

  async function api(url, data) {
    const opt = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data || {}),
    };
    const r = await fetch(url, opt);
    let j = null;
    try { j = await r.json(); } catch (e) { /* non-json */ }
    if (!j) throw new Error('HTTP ' + r.status);
    if (r.status === 429) throw new Error(j.error || '操作过于频繁，请稍后再试');
    return j;
  }

  function showMsg(text, isErr) {
    const m = $('#msg');
    m.hidden = false;
    m.className = 'msg ' + (isErr ? 'err' : 'ok');
    m.textContent = text;
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function fmtMoney(n) {
    return Number(n).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtTime(t) {
    if (!t) return '<span style="color:var(--muted)">—</span>';
    return esc(String(t).replace('T', ' '));
  }

  const form = $('#qform');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('#qbtn');
      btn.disabled = true;
      btn.textContent = '查询中…';
      $('#msg').hidden = true;
      $('#result').hidden = true;
      try {
        const fd = new FormData(form);
        const j = await api('api/query.php', {
          order_no: (fd.get('order_no') || '').toString().trim().toUpperCase(),
          phone: (fd.get('phone') || '').toString().trim(),
        });
        if (!j.ok) {
          showMsg(j.error || '查询失败，请稍后重试', true);
          return;
        }
        render(j.data);
      } catch (err) {
        showMsg(err.message || '网络异常，请稍后重试', true);
      } finally {
        btn.disabled = false;
        btn.textContent = '查询押金';
      }
    });
  }

  function render(d) {
    $('#r-order').textContent = d.order_no;
    const badge = $('#r-status');
    badge.textContent = d.deposit_status_text || STATUS_TEXT[d.deposit_status];
    badge.className = 'badge ' + d.deposit_status;
    $('#r-status-text').textContent = d.deposit_status_text || STATUS_TEXT[d.deposit_status];
    $('#r-amount').textContent = fmtMoney(d.deposit_amount);
    $('#r-start').textContent = d.rent_start;
    $('#r-due').textContent = d.rent_due;
    $('#r-returned').innerHTML = d.returned
      ? '<span style="color:var(--ok)">已归还 · ' + fmtTime(d.returned_at) + '</span>'
      : '<span style="color:var(--warn)">未归还</span>';
    $('#r-refund').innerHTML = d.refund_time
      ? fmtTime(d.refund_time)
      : (d.deposit_status === 'refunded' ? '近期到账' : '<span style="color:var(--muted)">待退款</span>');
    $('#r-abnormal').hidden = !d.abnormal;

    const tb = $('#r-items');
    tb.innerHTML = d.items.map((it) =>
      '<tr><td>' + esc(it.name) + '</td><td>' + esc(it.model) +
      '</td><td>' + esc(it.sn_masked) + '</td><td class="num">×' + it.qty + '</td></tr>'
    ).join('') || '<tr><td colspan="4" style="color:var(--muted)">无设备记录</td></tr>';

    $('#result').hidden = false;
    $('#result').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
})();
