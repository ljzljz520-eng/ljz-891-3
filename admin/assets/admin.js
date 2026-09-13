(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  let CSRF = '';
  const state = {
    tab: 'orders',
    orders: { page: 1, total: 0 },
    logs: { page: 1, total: 0 },
  };

  const STATUS = {
    held: '押金占用中', refunding: '退款处理中', refunded: '押金已退还',
    deducted: '押金已扣除', abnormal: '订单异常'
  };
  const RESULT_LABEL = {
    ok: ['成功', 'ok'], bad_order: ['订单号错', 'bad'], bad_phone: ['手机号不匹配', 'bad'],
    bad_captcha: ['验证码错', 'bad'], rate_locked: ['限流', 'warn'], error: ['失败', 'bad']
  };

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function money(n) {
    return '¥' + Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2 });
  }

  async function req(method, url, data, raw) {
    const opt = {
      method: method === 'GET' ? 'GET' : 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      credentials: 'same-origin',
    };
    if (method !== 'GET') {
      // 用 _method 模拟 PUT
      opt.body = JSON.stringify(Object.assign({}, data, method === 'PUT' ? { _method: 'PUT' } : {}));
    }
    const r = await fetch(url + (method === 'GET' && data ? '?' + new URLSearchParams(data) : ''), opt);
    if (raw) return r;
    let j = {};
    try { j = await r.json(); } catch (e) { /* */ }
    if (r.status === 401) { showLogin(); throw new Error(j.error || '请重新登录'); }
    if (r.status === 419) { alert('登录已失效，请重新登录'); location.reload(); throw new Error('csrf'); }
    if (!j.ok) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }

  /* ---------------- 登录 ---------------- */
  $('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#login-btn');
    btn.disabled = true;
    const fd = new FormData(e.target);
    try {
      const r = await fetch('api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: fd.get('username'), password: fd.get('password') }),
      });
      const j = await r.json();
      if (!j.ok) {
        const m = $('#login-msg');
        m.hidden = false; m.className = 'msg err'; m.textContent = j.error;
        return;
      }
      CSRF = j.csrf;
      enterApp(j.username);
    } finally {
      btn.disabled = false;
    }
  });

  $('#btn-logout').addEventListener('click', async () => {
    await req('POST', 'api/logout.php', {}).catch(() => {});
    showLogin();
  });

  function showLogin() {
    $('#login-view').hidden = false;
    $('#app-view').hidden = true;
  }
  function enterApp(name) {
    $('#login-view').hidden = true;
    $('#app-view').hidden = false;
    $('#whoami').textContent = '👤 ' + name;
    switchTab('orders');
  }

  // 启动时探测会话
  (async function boot() {
    try {
      const j = await req('GET', 'api/me.php');
      CSRF = j.csrf;
      enterApp(j.username);
    } catch (e) { showLogin(); }
  })();

  /* ---------------- tabs ---------------- */
  $$('.tabs button').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
  function switchTab(t) {
    state.tab = t;
    $$('.tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === t));
    $('#tab-orders').hidden = t !== 'orders';
    $('#tab-abnormal').hidden = t !== 'abnormal';
    $('#tab-logs').hidden = t !== 'logs';
    if (t === 'orders') loadOrders();
    if (t === 'abnormal') loadAbnormal();
    if (t === 'logs') loadLogs();
  }

  /* ---------------- 订单列表 ---------------- */
  async function loadOrders() {
    const p = state.orders;
    const j = await req('GET', 'api/rentals.php', {
      page: p.page,
      status: $('#o-status').value,
      keyword: $('#o-kw').value.trim(),
    });
    p.total = j.total;
    $('#o-rows').innerHTML = j.rows.map((r) => `
      <tr>
        <td style="font-family:monospace">${esc(r.order_no)}</td>
        <td>${esc(r.customer_name)}</td>
        <td>${money(r.deposit_amount)}</td>
        <td><span class="badge ${r.deposit_status}">${STATUS[r.deposit_status] || r.deposit_status}</span></td>
        <td>${esc(r.rent_start)}<br><span style="color:var(--muted)">至 ${esc(r.rent_due)}</span></td>
        <td>${esc(r.returned_at || '—')}</td>
        <td>${esc(r.refund_time || '—')}</td>
        <td>${r.abnormal_flag ? '<span class="pill bad">异常</span>' : '<span class="pill ok">正常</span>'}</td>
        <td>
          <button class="btn-sm btn-ghost" data-act="view" data-id="${r.id}">详情</button>
          <button class="btn-sm btn-ghost" data-act="abn" data-id="${r.id}">异常</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="9" style="color:var(--muted)">暂无数据</td></tr>';

    renderPager('#o-pager', p, () => loadOrders());
  }

  $('#o-search').addEventListener('click', () => { state.orders.page = 1; loadOrders(); });
  $('#o-kw').addEventListener('keydown', (e) => { if (e.key === 'Enter') { state.orders.page = 1; loadOrders(); } });
  $('#o-new').addEventListener('click', openCreate);

  $('#o-rows').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-act]');
    if (!b) return;
    if (b.dataset.act === 'view') openDetail(+b.dataset.id);
    if (b.dataset.act === 'abn') openAbnormal(+b.dataset.id);
  });

  /* ---------------- 异常列表 ---------------- */
  async function loadAbnormal() {
    const j = await req('GET', 'api/rentals.php', { page: 1, abnormal: 1 });
    $('#a-rows').innerHTML = j.rows.map((r) => `
      <tr>
        <td style="font-family:monospace">${esc(r.order_no)}</td>
        <td>${esc(r.customer_name)}<br><span style="color:var(--muted)">${money(r.deposit_amount)}</span></td>
        <td>${money(r.deposit_amount)}</td>
        <td style="color:#ffb7bf;white-space:normal;max-width:320px">${esc(r.abnormal_reason)}</td>
        <td>${esc(r.updated_at)}</td>
        <td>
          <button class="btn-sm btn-ghost" data-id="${r.id}" data-a="view">处理</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="6" style="color:var(--muted)">没有异常订单 🎉</td></tr>';
  }
  $('#a-refresh').addEventListener('click', loadAbnormal);
  $('#a-rows').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-a="view"]');
    if (b) openAbnormal(+b.dataset.id);
  });

  /* ---------------- 日志 ---------------- */
  function logQuery(page) {
    return {
      page: page,
      result: $('#l-result').value,
      action: $('#l-action').value,
      order_no: $('#l-order').value.trim(),
      from: $('#l-from').value,
      to: $('#l-to').value,
    };
  }
  async function loadLogs() {
    const p = state.logs;
    const j = await req('GET', 'api/logs.php', logQuery(p.page));
    p.total = j.total;
    $('#l-rows').innerHTML = j.rows.map((r) => {
      const [text, cls] = RESULT_LABEL[r.result] || [r.result, 'warn'];
      return `<tr>
        <td>${esc(r.created_at)}</td>
        <td>${esc(r.action)}</td>
        <td><span class="pill ${cls}">${esc(text)}</span></td>
        <td style="font-family:monospace">${esc(r.order_no || '—')}</td>
        <td>${esc(r.ip)}</td>
        <td style="white-space:normal;max-width:260px">${esc(r.detail)}</td>
        <td>${esc(r.admin_name || '客户')}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="7" style="color:var(--muted)">暂无日志</td></tr>';
    renderPager('#l-pager', p, () => loadLogs());
  }
  $('#l-search').addEventListener('click', () => { state.logs.page = 1; loadLogs(); });
  $('#l-export').addEventListener('click', () => {
    const q = new URLSearchParams(Object.entries(logQuery(1))
      .filter(([, v]) => v !== '' && v != null));
    q.set('export', 'csv');
    // 带 cookie 直接跳转下载
    window.location.href = 'api/logs.php?' + q.toString();
  });

  /* ---------------- 模态 ---------------- */
  function modal(html) {
    $('#modal-root').innerHTML =
      `<div class="modal-mask"><div class="modal">${html}</div></div>`;
  }
  function closeModal() { $('#modal-root').innerHTML = ''; }
  $('#modal-root').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-mask')) closeModal();
  });

  /* 新建订单 */
  function openCreate() {
    modal(`
      <h3>录入租赁记录</h3>
      <div class="form-row">
        <label><span>客户姓名 *</span><input id="f-name" maxlength="64"></label>
        <label><span>手机号 *</span><input id="f-phone" maxlength="11" placeholder="11 位手机号"></label>
      </div>
      <div class="form-row">
        <label><span>押金金额 (¥) *</span><input id="f-amount" type="number" min="0.01" step="0.01"></label>
        <label><span>押金状态</span>
          <select id="f-status">
            <option value="held">占用中</option>
            <option value="refunding">退款处理中</option>
            <option value="refunded">已退还</option>
            <option value="deducted">已扣除</option>
          </select>
        </label>
      </div>
      <div class="form-row">
        <label><span>起租日 *</span><input id="f-start" type="date"></label>
        <label><span>应还日 *</span><input id="f-due" type="date"></label>
      </div>
      <h3>设备清单 *</h3>
      <div id="f-items"></div>
      <button type="button" class="btn-sm btn-ghost" id="f-add-item">+ 添加设备</button>
      <div style="margin-top:12px">
        <label><span>备注</span><textarea id="f-remark" maxlength="255"></textarea></label>
      </div>
      <div id="f-err" class="msg err" hidden style="margin-top:10px"></div>
      <div class="modal-foot">
        <button class="btn-sm btn-ghost" id="f-cancel">取消</button>
        <button id="f-save">保存并生成订单号</button>
      </div>`);

    const box = $('#f-items');
    function addItem(it) {
      const div = document.createElement('div');
      div.className = 'item-line';
      div.innerHTML = `
        <input placeholder="设备名称*" value="${esc(it?.name || '')}">
        <input placeholder="型号" value="${esc(it?.model || '')}">
        <input placeholder="机身编号" value="${esc(it?.sn || '')}">
        <input type="number" placeholder="数量" value="${it?.qty || 1}" min="1">
        <input type="number" placeholder="押金单价" value="${it?.unit_price || 0}" step="0.01">
        <button type="button" class="btn-sm btn-ghost">✕</button>`;
      div.querySelector('button').addEventListener('click', () => div.remove());
      box.appendChild(div);
    }
    addItem({ name: '索尼 A7M4 机身', qty: 1 });
    $('#f-add-item').addEventListener('click', () => addItem());
    $('#f-cancel').addEventListener('click', closeModal);

    $('#f-save').addEventListener('click', async () => {
      const items = $$('.item-line', box).map((d) => {
        const ins = d.querySelectorAll('input');
        return {
          name: ins[0].value.trim(), model: ins[1].value.trim(), sn: ins[2].value.trim(),
          qty: +ins[3].value || 1, unit_price: +ins[4].value || 0,
        };
      });
      const payload = {
        customer_name: $('#f-name').value.trim(),
        phone: $('#f-phone').value.trim(),
        deposit_amount: $('#f-amount').value,
        deposit_status: $('#f-status').value,
        rent_start: $('#f-start').value,
        rent_due: $('#f-due').value,
        remark: $('#f-remark').value.trim(),
        items,
      };
      try {
        const j = await req('POST', 'api/rentals.php', payload);
        closeModal();
        alert('录入成功！\n订单号：' + j.order_no + '\n（请把订单号发给客户用于查询）');
        state.orders.page = 1;
        loadOrders();
      } catch (e) {
        const m = $('#f-err');
        m.hidden = false; m.textContent = e.message;
      }
    });
  }

  /* 详情 */
  async function openDetail(id) {
    const j = await req('GET', 'api/rentals.php', { id });
    const r = j.row;
    modal(`
      <h3>订单详情 <span style="font-family:monospace;color:var(--muted);font-size:13px">${esc(r.order_no)}</span></h3>
      <div class="detail-grid">
        <div><b>客户姓名</b>${esc(r.customer_name)}</div>
        <div><b>手机号（仅后台可见）</b>${esc(r.phone)}</div>
        <div><b>押金金额</b>${money(r.deposit_amount)}</div>
        <div><b>押金状态</b>
          <select id="d-status">
            ${Object.entries(STATUS).map(([k, v]) =>
              `<option value="${k}" ${k === r.deposit_status ? 'selected' : ''}>${v}</option>`).join('')}
          </select>
        </div>
        <div><b>起租 / 应还</b>${esc(r.rent_start)} ~ ${esc(r.rent_due)}</div>
        <div><b>异常</b>${r.abnormal_flag ? '⚠️ ' + esc(r.abnormal_reason) : '正常'}</div>
        <div><b>归还时间</b><input id="d-returned" type="datetime-local" value="${toLocalInput(r.returned_at)}"></div>
        <div><b>退款时间</b><input id="d-refund" type="datetime-local" value="${toLocalInput(r.refund_time)}"></div>
      </div>
      <h3>设备清单</h3>
      <table class="adm-table">
        <thead><tr><th>设备</th><th>型号</th><th>序列号</th><th>数量</th><th>押金单价</th></tr></thead>
        <tbody>${r.items.map((it) => `<tr>
          <td>${esc(it.name)}</td><td>${esc(it.model)}</td><td>${esc(it.sn)}</td>
          <td>×${it.qty}</td><td>${money(it.unit_price)}</td></tr>`).join('')}
        </tbody>
      </table>
      <div style="margin-top:12px"><b style="color:var(--muted);font-size:12px">备注</b>
        <textarea id="d-remark" maxlength="255">${esc(r.remark)}</textarea></div>
      <div id="d-err" class="msg err" hidden style="margin-top:10px"></div>
      <div class="modal-foot">
        <button class="btn-sm btn-ghost" id="d-cancel">关闭</button>
        <button id="d-save">保存修改</button>
      </div>`);
    $('#d-cancel').addEventListener('click', closeModal);
    $('#d-save').addEventListener('click', async () => {
      try {
        await req('PUT', 'api/rentals.php', {
          id: r.id,
          deposit_status: $('#d-status').value,
          returned_at: $('#d-returned').value.replace('T', ' ') || '',
          refund_time: $('#d-refund').value.replace('T', ' ') || '',
          remark: $('#d-remark').value,
        });
        closeModal();
        loadOrders();
      } catch (e) {
        const m = $('#d-err'); m.hidden = false; m.textContent = e.message;
      }
    });
  }
  function toLocalInput(t) {
    if (!t) return '';
    return String(t).replace(' ', 'T').slice(0, 16);
  }

  /* 异常标记 */
  async function openAbnormal(id) {
    const j = await req('GET', 'api/rentals.php', { id });
    const r = j.row;
    modal(`
      <h3>异常处理 · ${esc(r.order_no)}</h3>
      <p style="color:var(--muted);font-size:13px">
        客户：${esc(r.customer_name)} ｜ 押金：${money(r.deposit_amount)} ｜
        当前：${r.abnormal_flag ? '<span style="color:#ffb7bf">已标记异常</span>' : '正常'}
      </p>
      <label><span>异常原因（标记时必填，客户仅看到“异常”提示，看不到原因）</span>
        <textarea id="ab-reason" maxlength="255" placeholder="如：归还镜头有划痕，协商扣款中">${esc(r.abnormal_reason)}</textarea>
      </label>
      <div id="ab-err" class="msg err" hidden style="margin-top:10px"></div>
      <div class="modal-foot">
        <button class="btn-sm btn-ghost" id="ab-cancel">取消</button>
        ${r.abnormal_flag ? '<button class="btn-sm btn-ghost" id="ab-clear">解除异常</button>' : ''}
        <button class="btn-sm btn-danger" id="ab-set">标记异常</button>
      </div>`);
    $('#ab-cancel').addEventListener('click', closeModal);
    const act = async (ab) => {
      try {
        await req('POST', 'api/abnormal.php', { id, abnormal: ab, reason: $('#ab-reason').value.trim() });
        closeModal();
        loadOrders();
        if (state.tab === 'abnormal') loadAbnormal();
      } catch (e) {
        const m = $('#ab-err'); m.hidden = false; m.textContent = e.message;
      }
    };
    $('#ab-set').addEventListener('click', () => act(true));
    const clr = $('#ab-clear');
    if (clr) clr.addEventListener('click', () => act(false));
  }

  /* 改密码 */
  $('#btn-pw').addEventListener('click', () => {
    modal(`
      <h3>修改登录密码</h3>
      <div class="form-row" style="grid-template-columns:1fr">
        <label><span>原密码</span><input id="pw-old" type="password"></label>
        <label><span>新密码（至少 10 位，含字母和数字）</span><input id="pw-new" type="password"></label>
      </div>
      <div id="pw-err" class="msg err" hidden></div>
      <div class="modal-foot">
        <button class="btn-sm btn-ghost" id="pw-cancel">取消</button>
        <button id="pw-save">保存</button>
      </div>`);
    $('#pw-cancel').addEventListener('click', closeModal);
    $('#pw-save').addEventListener('click', async () => {
      try {
        await req('POST', 'api/password.php', {
          old_password: $('#pw-old').value,
          new_password: $('#pw-new').value,
        });
        closeModal();
        alert('密码已修改');
      } catch (e) {
        const m = $('#pw-err'); m.hidden = false; m.textContent = e.message;
      }
    });
  });

  /* 分页 */
  function renderPager(sel, p, reload) {
    const pages = Math.max(1, Math.ceil(p.total / (sel === '#o-pager' ? 20 : 30)));
    $(sel).innerHTML = `
      <button class="btn-sm" ${p.page <= 1 ? 'disabled' : ''} id="pg-prev">上一页</button>
      <span>第 ${p.page} / ${pages} 页，共 ${p.total} 条</span>
      <button class="btn-sm" ${p.page >= pages ? 'disabled' : ''} id="pg-next">下一页</button>`;
    const prev = $('#pg-prev', $(sel));
    const next = $('#pg-next', $(sel));
    if (prev) prev.addEventListener('click', () => { p.page--; reload(); });
    if (next) next.addEventListener('click', () => { p.page++; reload(); });
  }
})();
