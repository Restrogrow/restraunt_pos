/*
 * Growth › WhatsApp ordering (data: api/wa_order_admin.php, engine: config/wa_order_engine.php).
 *
 * The switch and settings, the "Order on WhatsApp" links + QR codes (one per table), a test chat that
 * talks to the real ordering bot without WhatsApp, and the customer chats (with a reply box for chats
 * the bot handed to a person). Styles live here (wo-*), so the page needs only #woRoot.
 */
(function () {
  'use strict';
  var API = '../api/wa_order_admin.php';
  var D = null, chat = [], sending = false, real = false, openChat = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function money(n) { return (window.globalCurrencySymbol || '₹') + Math.round(+n || 0).toLocaleString('en-IN'); }
  function get(q) { return fetch(API + (q ? '?' + q : ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  // WhatsApp's own formatting (*bold*, _italic_, ~strike~) shown the same way in the test chat
  function waFmt(s) {
    return esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>').replace(/~([^~\n]+)~/g, '<s>$1</s>').replace(/\n/g, '<br>');
  }
  function ago(t) {
    var d = new Date(String(t).replace(' ', 'T')), s = (Date.now() - d) / 1000;
    if (isNaN(s)) return '';
    if (s < 60) return 'now'; if (s < 3600) return Math.round(s / 60) + ' min'; if (s < 86400) return Math.round(s / 3600) + ' h';
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  }

  function css() {
    if ($('woCss')) return;
    var st = document.createElement('style');
    st.id = 'woCss';
    st.textContent = [
      '#woRoot{--wa:#25d366;--wad:#075e54;display:grid;gap:16px}',
      '.wo-hero{display:flex;flex-wrap:wrap;align-items:center;gap:18px;padding:20px;border-radius:20px;color:#fff;background:radial-gradient(90% 140% at 100% 0%,#25d366,transparent 55%),linear-gradient(135deg,#075e54,#0b3d36);box-shadow:0 16px 34px -20px rgba(7,94,84,.8)}',
      '.wo-hero-l{flex:1;min-width:240px}.wo-hero h2{margin:0;font-size:1.25rem;font-weight:800;color:#fff}.wo-hero p{margin:6px 0 0;color:rgba(255,255,255,.82);font-size:.88rem;line-height:1.5}',
      '.wo-sw{display:inline-flex;align-items:center;gap:10px;margin-top:12px;cursor:pointer;font-weight:700}.wo-sw input{display:none}.wo-sw i{position:relative;width:46px;height:26px;border-radius:999px;background:rgba(255,255,255,.3);transition:.2s}',
      '.wo-sw i:after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:.2s}.wo-sw input:checked+i{background:#b9f6ca}.wo-sw input:checked+i:after{transform:translateX(20px);background:#075e54}',
      '.wo-kpis{display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:8px}.wo-kpis div{padding:10px 14px;border-radius:14px;background:rgba(255,255,255,.12)}.wo-kpis b{display:block;font-size:1.25rem}.wo-kpis span{font-size:.75rem;opacity:.85}',
      '.wo-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.wo-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;background:rgba(255,255,255,.14)}.wo-pill .material-symbols-rounded{font-size:15px}',
      '.wo-pill.ok{background:#dcfce7;color:#14532d}.wo-pill.warn{background:#fef3c7;color:#92400e}',
      '.wo-cols{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:16px;align-items:start}@media(max-width:1000px){.wo-cols{grid-template-columns:1fr}}',
      '.wo-card{padding:18px;border-radius:18px;background:#fff;border:1px solid #edf0f4;box-shadow:0 1px 2px rgba(16,24,40,.04)}.wo-card h3{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:1rem;font-weight:800;color:#111827}.wo-card h3 .material-symbols-rounded{color:#075e54}',
      '.wo-sub{margin:0 0 12px;color:#6b7280;font-size:.84rem;line-height:1.5}',
      '.wo-steps{display:grid;gap:8px}.wo-step{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-radius:12px;background:#f9fafb;font-size:.86rem;color:#374151}.wo-step b{color:#111827}.wo-step .material-symbols-rounded{color:#9ca3af}.wo-step.done .material-symbols-rounded{color:#16a34a}',
      '.wo-link{display:flex;gap:8px;align-items:center}.wo-link input{flex:1;min-width:0;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:10px;font:inherit;font-size:.85rem;color:#374151;background:#f9fafb}',
      '.wo-qr{display:flex;gap:14px;align-items:center;margin-top:12px}.wo-qr img{width:120px;height:120px;border-radius:12px;border:1px solid #e5e7eb;padding:6px;background:#fff}',
      '.wo-tables{display:grid;grid-template-columns:repeat(auto-fill,minmax(108px,1fr));gap:8px;margin-top:12px;max-height:260px;overflow:auto}.wo-tables button{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px;border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;font:inherit;font-weight:700;cursor:pointer}.wo-tables button:hover{border-color:#25d366}',
      '.wo-tables img{width:64px;height:64px}',
      '.wo-form{display:grid;gap:12px}.wo-types{display:flex;flex-wrap:wrap;gap:8px}.wo-types label{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:1.5px solid #e5e7eb;border-radius:999px;font-weight:600;font-size:.88rem;cursor:pointer}.wo-types input{accent-color:#075e54}',
      '.wo-form textarea{width:100%;box-sizing:border-box;min-height:70px;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:12px;font:inherit;font-size:.9rem;resize:vertical}',
      '.wo-check{display:flex;align-items:center;gap:8px;font-size:.88rem;font-weight:600;color:#374151}.wo-check input{width:18px;height:18px;accent-color:#075e54}',
      '.wo-phone{display:flex;flex-direction:column;height:560px;border-radius:22px;overflow:hidden;border:1px solid #d1d7db;background:#efeae2}',
      '.wo-ph-h{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#075e54;color:#fff}.wo-ph-h .wo-av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:#25d366;font-weight:800}.wo-ph-h b{display:block;font-size:.92rem}.wo-ph-h small{opacity:.8;font-size:.72rem}',
      '.wo-ph-h button{margin-left:auto;display:inline-flex;align-items:center;gap:4px;padding:6px 10px;border:0;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;font:inherit;font-size:.75rem;font-weight:700;cursor:pointer}',
      '.wo-msgs{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:6px}',
      '.wo-b{max-width:84%;padding:7px 10px 6px;border-radius:10px;font-size:.86rem;line-height:1.45;color:#111b21;box-shadow:0 1px .5px rgba(11,20,26,.13);word-wrap:break-word}',
      '.wo-b.in{align-self:flex-end;background:#d9fdd3;border-top-right-radius:2px}.wo-b.out{align-self:flex-start;background:#fff;border-top-left-radius:2px}.wo-b.sys{align-self:center;background:#fff3c4;font-size:.75rem;color:#54656f}',
      '.wo-typing{align-self:flex-start;padding:8px 12px;border-radius:10px;background:#fff;color:#667781;font-size:.8rem}',
      // replies with buttons / a list / a photo, drawn like WhatsApp does (config/wa_order_engine.php woBtns / woList)
      '.wo-rich{align-self:flex-start;max-width:84%;display:grid;gap:2px}.wo-rich .wo-b{max-width:none}',
      '.wo-rich img{display:block;width:100%;max-height:180px;object-fit:cover;border-radius:6px;margin:-1px 0 6px}',
      '.wo-rb{display:block;width:100%;padding:9px 10px;border:0;border-radius:10px;background:#fff;color:#027eb5;font:inherit;font-size:.86rem;font-weight:600;text-align:center;cursor:pointer;box-shadow:0 1px .5px rgba(11,20,26,.13)}',
      '.wo-rb:hover{background:#f5f6f6}.wo-rb:disabled{color:#8696a0;cursor:default}',
      '.wo-rl{display:none;background:#fff;border-radius:10px;box-shadow:0 1px .5px rgba(11,20,26,.13);overflow:hidden}.wo-rl.open{display:block}',
      '.wo-rl small{display:block;padding:8px 12px 4px;color:#008069;font-size:.72rem;font-weight:700;text-transform:uppercase}',
      '.wo-rl button{display:block;width:100%;padding:9px 12px;border:0;border-top:1px solid #f0f2f5;background:#fff;text-align:left;font:inherit;cursor:pointer}.wo-rl button:hover{background:#f5f6f6}',
      '.wo-rl b{display:block;font-size:.86rem;color:#111b21}.wo-rl span{display:block;font-size:.76rem;color:#667781;margin-top:1px}',
      '.wo-chips{display:flex;gap:6px;overflow-x:auto;padding:6px 10px;background:#f0f2f5;scrollbar-width:none}.wo-chips button{flex:none;padding:6px 11px;border:1px solid #d1d7db;border-radius:999px;background:#fff;font:inherit;font-size:.78rem;cursor:pointer;color:#111b21}',
      '.wo-in{display:flex;gap:8px;padding:8px 10px;background:#f0f2f5}.wo-in input{flex:1;min-width:0;padding:10px 14px;border:0;border-radius:999px;font:inherit;font-size:.9rem;outline:none}',
      '.wo-in button{width:42px;height:42px;flex:none;display:grid;place-items:center;border:0;border-radius:50%;background:#075e54;color:#fff;cursor:pointer}.wo-in button:disabled{opacity:.5}',
      '.wo-real{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fff;border-top:1px solid #e9edef;font-size:.76rem;color:#54656f}.wo-real input{accent-color:#075e54}',
      '.wo-chats{display:grid;gap:6px}.wo-chat{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #edf0f4;border-radius:12px;background:#fff;cursor:pointer;text-align:left;font:inherit;width:100%}.wo-chat:hover{border-color:#25d366}',
      '.wo-chat .wo-av{width:38px;height:38px;flex:none;border-radius:50%;display:grid;place-items:center;background:#e7fce3;color:#075e54;font-weight:800}.wo-chat b{display:block;font-size:.9rem;color:#111827}.wo-chat small{display:block;color:#6b7280;font-size:.78rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:320px}',
      '.wo-chat em{margin-left:auto;flex:none;font-style:normal;font-size:.7rem;font-weight:800;padding:3px 8px;border-radius:999px;background:#f3f4f6;color:#374151}.wo-chat em.hand{background:#fee2e2;color:#991b1b}.wo-chat em.placed{background:#dcfce7;color:#14532d}',
      '.wo-empty{padding:22px;text-align:center;color:#9ca3af;font-size:.88rem}',
      '.wo-note{display:flex;gap:8px;padding:10px 12px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:.82rem;line-height:1.45}.wo-note .material-symbols-rounded{font-size:18px}'
    ].join('\n');
    document.head.appendChild(st);
  }

  function qr(link, size) {
    if (!link) return '';
    if (window.MK && MK.qr) { try { return MK.qr(link).toDataURL('image/png'); } catch (e) {} }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' + (size || 240) + 'x' + (size || 240) + '&data=' + encodeURIComponent(link);
  }

  function load() {
    var root = $('woRoot');
    if (!root) return;
    css();
    if (!D) root.innerHTML = '<div class="wo-empty">Loading…</div>';
    get('').then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="wo-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
      D = d;
      draw();
    }).catch(function () { root.innerHTML = '<div class="wo-empty">No connection. Try again.</div>'; });
  }

  function draw() {
    var s = D.settings, wa = D.whatsapp, L = D.links || {};
    var steps = [
      [wa.connected, 'Connect your WhatsApp number', 'Growth › WhatsApp › Connect RepeatGrow. Customers message this number to order.'],
      [s.webhook_ready || wa.local, 'Let RestroGrow read incoming messages', 'Happens when you switch ordering on. If it asks, reconnect RepeatGrow once to allow it.'],
      [s.enabled, 'Switch WhatsApp ordering on', 'Then share the link or print the table QR codes below.']
    ];
    $('woRoot').innerHTML =
      '<div class="wo-hero"><div class="wo-hero-l"><h2>Orders on WhatsApp</h2>' +
        '<p>Customers type or say what they want ("2 butter naan, ek paneer tikka"), get the bill, confirm, and the order lands in Orders with a KOT. No app, no commission.</p>' +
        '<label class="wo-sw"><input type="checkbox" id="woOn"' + (s.enabled ? ' checked' : '') + (D.can_edit ? '' : ' disabled') + '><i></i>WhatsApp ordering is ' + (s.enabled ? 'on' : 'off') + '</label>' +
        '<div class="wo-pills"><span class="wo-pill ' + (wa.connected ? 'ok' : 'warn') + '">' + ic(wa.connected ? 'check_circle' : 'error') + (wa.connected ? 'WhatsApp connected' : 'WhatsApp not connected') + '</span>' +
          '<span class="wo-pill ' + (D.ai ? 'ok' : 'warn') + '">' + ic('auto_awesome') + (D.ai ? 'AI understanding on' : 'Simple matching (no AI key)') + '</span>' +
          '<span class="wo-pill">' + ic('restaurant_menu') + D.menu_count + ' dishes on sale now</span></div></div>' +
        '<div class="wo-kpis"><div><b>' + (+D.today.n || 0) + '</b><span>orders today</span></div><div><b>' + money(D.today.amount) + '</b><span>today</span></div>' +
          '<div><b>' + (+D.week.n || 0) + '</b><span>orders, 7 days</span></div><div><b>' + money(D.week.amount) + '</b><span>7 days</span></div></div></div>' +
      (wa.local ? '<div class="wo-note">' + ic('info') + '<div>This is a test computer: messages are not really sent on WhatsApp. Use the test chat to try everything.</div></div>' : '') +
      '<div class="wo-cols"><div style="display:grid;gap:16px">' +
        // test chat
        '<div class="wo-card"><h3>' + ic('forum') + 'Try it like a customer</h3><p class="wo-sub">This is the real ordering bot. Type the way your customers would, in English, Hindi or Hinglish.</p>' +
          '<div class="wo-phone"><div class="wo-ph-h"><span class="wo-av">' + esc((D.restaurant || 'R').charAt(0)) + '</span><div><b>Your restaurant</b><small>WhatsApp ordering · test</small></div>' +
            '<button type="button" data-wo="reset">' + ic('restart_alt') + 'New chat</button></div>' +
            '<div class="wo-msgs" id="woMsgs"></div>' +
            '<div class="wo-chips">' + ['hi', 'menu', '2 butter naan aur ek paneer tikka', 'kam spicy please', 'biryani bhi', 'bill', '1', 'T', 'talk to a person'].map(function (c) { return '<button type="button" data-wo-chip="' + esc(c) + '">' + esc(c) + '</button>'; }).join('') + '</div>' +
            '<div class="wo-in"><input id="woText" placeholder="Type a message" autocomplete="off"><button type="button" data-wo="send" id="woSend" aria-label="Send">' + ic('send') + '</button></div>' +
            '<label class="wo-real"><input type="checkbox" id="woReal"' + (real ? ' checked' : '') + '> Place a real test order at the end (shows in Orders with a KOT, on the restaurant\'s own phone number)</label>' +
          '</div></div>' +
        // chats
        '<div class="wo-card"><h3>' + ic('chat') + 'Customer chats</h3><p class="wo-sub">Latest WhatsApp orders. Chats marked “needs you” asked for a person: reply here.</p><div class="wo-chats" id="woChats">' + chatsHtml() + '</div></div>' +
      '</div><div style="display:grid;gap:16px">' +
        // setup steps
        '<div class="wo-card"><h3>' + ic('checklist') + 'Set up in 3 steps</h3><div class="wo-steps">' + steps.map(function (x, i) {
          // step 2 not done: why the last try failed (api/wa_order_admin.php woWebhookNote) and a button to try again
          var fix = i === 1 && !x[0] && wa.connected ? (s.webhook_note ? '<div style="margin-top:6px;color:#b91c1c">' + esc(s.webhook_note) + '</div>' : '') +
            (D.can_edit ? '<button type="button" class="btn btn-secondary" data-wo="link" style="margin-top:8px">' + ic('link') + 'Link now</button>' : '') : '';
          return '<div class="wo-step' + (x[0] ? ' done' : '') + '">' + ic(x[0] ? 'check_circle' : 'circle') + '<div><b>' + esc(x[1]) + '</b><br>' + esc(x[2]) + fix + '</div></div>';
        }).join('') + '</div></div>' +
        // links
        '<div class="wo-card"><h3>' + ic('qr_code_2') + 'Share it</h3>' +
          (L.order ? '<p class="wo-sub">Put this on your website, Instagram and Google profile. On a table QR, the table is filled in automatically.</p>' +
            '<div class="wo-link"><input readonly value="' + esc(L.order) + '"><button type="button" class="btn btn-secondary" data-wo-copy="' + esc(L.order) + '">' + ic('content_copy') + 'Copy</button></div>' +
            '<div class="wo-qr"><img alt="QR code to order on WhatsApp" src="' + esc(qr(L.order, 240)) + '"><div class="wo-sub" style="margin:0">Scan to order on WhatsApp.<br><a href="' + esc(qr(L.order, 600)) + '" download="whatsapp-order-qr.png" target="_blank" rel="noopener">Download QR</a></div></div>' +
            ((L.tables || []).length ? '<h3 style="margin-top:16px">' + ic('table_restaurant') + 'Table QR codes</h3><p class="wo-sub">Tap a table to open its QR to print.</p><div class="wo-tables">' + L.tables.map(function (t) {
              return '<button type="button" data-wo-table="' + esc(t.table) + '" data-link="' + esc(t.link) + '"><img alt="" src="' + esc(qr(t.link, 160)) + '">' + esc(t.table) + '</button>';
            }).join('') + '</div>' : '')
            : '<div class="wo-note">' + ic('info') + '<div>Your WhatsApp ordering link appears here once your WhatsApp number is connected (Growth › WhatsApp).</div></div>') +
        '</div>' +
        // settings
        '<div class="wo-card"><h3>' + ic('tune') + 'Settings</h3><div class="wo-form">' +
          '<div><div class="wo-sub" style="margin-bottom:6px">Order types on WhatsApp</div><div class="wo-types">' + [['dinein', 'Dine-in (table QR)'], ['takeaway', 'Takeaway'], ['delivery', 'Delivery']].map(function (t) {
            return '<label><input type="checkbox" name="woType" value="' + t[0] + '"' + (s.order_types.indexOf(t[0]) > -1 ? ' checked' : '') + '>' + t[1] + '</label>';
          }).join('') + '</div></div>' +
          '<label class="wo-check"><input type="checkbox" id="woExtras"' + (s.suggest_extras ? ' checked' : '') + '> Suggest one extra with each order (what is often ordered together)</label>' +
          '<div><div class="wo-sub" style="margin-bottom:6px">Welcome line (optional)</div><textarea id="woGreeting" maxlength="500" placeholder="Welcome to ' + esc(D.restaurant || 'our restaurant') + '! Fresh food in 20 minutes.">' + esc(s.greeting) + '</textarea></div>' +
          '<div><button type="button" class="btn btn-primary" data-wo="save"' + (D.can_edit ? '' : ' disabled') + '>' + ic('save') + 'Save</button></div>' +
        '</div></div>' +
      '</div></div>';
    drawMsgs();
  }

  function chatsHtml() {
    var list = D.chats || [];
    if (!list.length) return '<div class="wo-empty">No WhatsApp chats yet.</div>';
    return list.map(function (c) {
      var hand = c.handover_until && new Date(String(c.handover_until).replace(' ', 'T')) > new Date();
      var badge = hand ? '<em class="hand">needs you</em>' : c.state === 'placed' ? '<em class="placed">' + esc(c.order_number || 'ordered') + '</em>' : '<em>ordering</em>';
      return '<button type="button" class="wo-chat" data-wo-chat="' + esc(c.phone) + '"><span class="wo-av">' + esc((c.name || c.phone || '?').charAt(0).toUpperCase()) + '</span>' +
        '<span style="min-width:0"><b>' + esc(c.name || ('+' + c.phone)) + ' · ' + esc(ago(c.updated_at)) + '</b><small>' + esc(String(c.last || '').replace(/[*_~]/g, '').slice(0, 90)) + '</small></span>' + badge + '</button>';
    }).join('');
  }

  // the bot's photo URL points at the live site; in the dashboard the same file is served from here
  function imgSrc(u) { var i = String(u || '').indexOf('/main/website/image.php'); return i < 0 ? u : '../website/image.php' + String(u).slice(i + '/main/website/image.php'.length); }
  function plainOf(t) { return typeof t === 'string' ? t : String((t && t.text) || ''); }
  // one bot reply: text, or a WhatsApp-style message with a photo, buttons or a list to tap
  function rich(m, n) {
    var t = m.t;
    if (typeof t === 'string' || !t) return '<div class="wo-b ' + m.d + '">' + waFmt(t) + '</div>';
    var h = '<div class="wo-rich"><div class="wo-b out">' + (t.image ? '<img src="' + esc(imgSrc(t.image)) + '" alt="">' : '') + waFmt(t.text || '') + '</div>';
    (t.buttons || []).forEach(function (b) { h += '<button type="button" class="wo-rb" data-wo-tap="' + esc(b[0]) + '" data-title="' + esc(b[1]) + '">' + esc(b[1]) + '</button>'; });
    if (t.list) {
      h += '<button type="button" class="wo-rb" data-wo-list="' + n + '">☰ ' + esc(t.list.button) + '</button><div class="wo-rl" id="woRl' + n + '">' + (t.list.section ? '<small>' + esc(t.list.section) + '</small>' : '') +
        (t.list.rows || []).map(function (r) { return '<button type="button" data-wo-tap="' + esc(r[0]) + '" data-title="' + esc(r[1]) + '"><b>' + esc(r[1]) + '</b>' + (r[2] ? '<span>' + esc(r[2]) + '</span>' : '') + '</button>'; }).join('') + '</div>';
    }
    return h + '</div>';
  }

  function drawMsgs(typing) {
    var box = $('woMsgs');
    if (!box) return;
    box.innerHTML = (chat.length ? chat.map(rich).join('')
      : '<div class="wo-b sys">Say <b>hi</b>, or type an order like “2 butter naan and 1 paneer tikka”.</div>') +
      (typing ? '<div class="wo-typing">typing…</div>' : '');
    box.scrollTop = box.scrollHeight;
  }

  // text typed, or a tapped button / list row (replyId; text = its title, as WhatsApp sends it)
  function send(text, replyId) {
    text = String(text || '').trim();
    if ((!text && !replyId) || sending) return;
    sending = true;
    chat.push({ d: 'in', t: text });
    drawMsgs(true);
    var inp = $('woText'); if (inp && !replyId) inp.value = '';
    post({ action: 'try', text: text, reply_id: replyId || '', real: real ? 1 : 0 }).then(function (d) {
      sending = false;
      if (!d.success) { chat.push({ d: 'sys', t: d.message || 'Could not answer' }); drawMsgs(); return; }
      (d.replies || []).forEach(function (r) { chat.push({ d: 'out', t: r }); });
      if (!(d.replies || []).length) chat.push({ d: 'sys', t: 'The bot stays quiet here (handed to a person). Type “order” to start again.' });
      drawMsgs();
      if (real && (d.replies || []).some(function (r) { return /Order placed/.test(plainOf(r)); })) { notify('Test order placed: see Orders', 'success'); load(); }
    }).catch(function () { sending = false; chat.push({ d: 'sys', t: 'No connection.' }); drawMsgs(); });
  }

  function openChatView(phone) {
    openChat = phone;
    get('chat=' + encodeURIComponent(phone)).then(function (d) {
      var msgs = (d && d.messages) || [];
      var html = '<div class="wo-phone" style="height:520px"><div class="wo-msgs" id="woChatMsgs">' + msgs.map(function (m) {
        return '<div class="wo-b ' + (m.direction === 'in' ? 'in' : m.direction === 'out' ? 'out' : 'sys') + '">' + waFmt(m.body) + '<div style="font-size:.65rem;color:#667781;text-align:right">' + esc(ago(m.created_at)) + '</div></div>';
      }).join('') + '</div><div class="wo-in"><input id="woReplyText" placeholder="Reply as the restaurant"><button type="button" data-wo="reply" aria-label="Send">' + ic('send') + '</button></div></div>';
      if (typeof window.rgModal === 'function') {
        window.rgModal({ title: 'Chat with +' + phone, icon: 'chat', html: html, confirmText: 'Close', cancelText: null, onOpen: function () { var b = $('woChatMsgs'); if (b) b.scrollTop = b.scrollHeight; } });
      }
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-wo],[data-wo-chip],[data-wo-copy],[data-wo-table],[data-wo-chat],[data-wo-tap],[data-wo-list]');
    if (!t) return;
    if (t.hasAttribute('data-wo-chip')) { send(t.getAttribute('data-wo-chip')); return; }
    if (t.hasAttribute('data-wo-tap')) { send(t.getAttribute('data-title'), t.getAttribute('data-wo-tap')); return; }
    if (t.hasAttribute('data-wo-list')) { var l = $('woRl' + t.getAttribute('data-wo-list')); if (l) { l.classList.toggle('open'); l.scrollIntoView({ block: 'nearest' }); } return; }
    if (t.hasAttribute('data-wo-copy')) { navigator.clipboard.writeText(t.getAttribute('data-wo-copy')).then(function () { notify('Link copied', 'success'); }); return; }
    if (t.hasAttribute('data-wo-chat')) { openChatView(t.getAttribute('data-wo-chat')); return; }
    if (t.hasAttribute('data-wo-table')) {
      var link = t.getAttribute('data-link'), name = t.getAttribute('data-wo-table');
      var w = window.open('', '_blank');
      if (w) w.document.write('<title>Table ' + esc(name) + '</title><body style="font-family:system-ui;text-align:center;padding:40px"><h1 style="margin:0">Order on WhatsApp</h1><p style="font-size:20px">Table <b>' + esc(name) + '</b> · scan with your phone camera</p><img src="' + esc(qr(link, 600)) + '" style="width:340px;height:340px"><p style="color:#555">' + esc(D.restaurant || '') + '</p><script>setTimeout(function(){print()},600)<\/script></body>');
      return;
    }
    var a = t.getAttribute('data-wo');
    if (a === 'send') send(($('woText') || {}).value);
    else if (a === 'reset') { post({ action: 'try', reset: 1 }); chat = []; drawMsgs(); }
    else if (a === 'save') {
      var types = [].slice.call(document.querySelectorAll('input[name=woType]:checked')).map(function (x) { return x.value; });
      if (!types.length) { notify('Pick at least one order type', 'error'); return; }
      t.disabled = true;
      post({ action: 'save', enabled: $('woOn').checked ? 1 : 0, order_types: types, greeting: $('woGreeting').value, suggest_extras: $('woExtras').checked ? 1 : 0 }).then(function (d) {
        t.disabled = false;
        notify(d.message || (d.success ? 'Saved' : 'Could not save'), d.success ? 'success' : 'error');
        if (d.success) load();
      }).catch(function () { t.disabled = false; notify('No connection', 'error'); });
    } else if (a === 'link') {
      t.disabled = true;
      post({ action: 'link' }).then(function (d) {
        t.disabled = false;
        notify(d.message || (d.success ? 'Linked' : 'Could not link'), d.success ? 'success' : 'error');
        load();
      }).catch(function () { t.disabled = false; notify('No connection', 'error'); });
    } else if (a === 'reply') {
      var inp = $('woReplyText'), txt = inp ? inp.value.trim() : '';
      if (!txt || !openChat) return;
      post({ action: 'reply', phone: openChat, text: txt }).then(function (d) {
        notify(d.message || '', d.success ? 'success' : 'error');
        if (d.success) { var b = $('woChatMsgs'); if (b) { b.insertAdjacentHTML('beforeend', '<div class="wo-b out">' + waFmt(txt) + '</div>'); b.scrollTop = b.scrollHeight; } inp.value = ''; }
      });
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    if (e.target && e.target.id === 'woText') { e.preventDefault(); send(e.target.value); }
    if (e.target && e.target.id === 'woReplyText') { e.preventDefault(); var b = document.querySelector('[data-wo="reply"]'); if (b) b.click(); }
  });
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'woReal') real = e.target.checked;
    if (e.target && e.target.id === 'woOn') { var b = document.querySelector('[data-wo="save"]'); if (b) b.click(); }
  });

  window.loadWaOrderStudio = load;
})();
