/*
 * Growth › AI manager (data: api/owner_manager_admin.php, engine: config/owner_manager.php).
 *
 * The owner's morning WhatsApp report: on/off, numbers strip (today / yesterday / week / month vs the period
 * before), today's report with its one-tap actions (Do it / Skip), a chat that answers like the WhatsApp
 * manager (English, Hindi, Hinglish), settings, a setup checklist and earlier reports.
 * Phones get three tabs (Today · Ask · Settings); wider screens two columns. Styles live here (om-*),
 * so the page needs only #omRoot.
 */
(function () {
  'use strict';
  var API = '../api/owner_manager_admin.php';
  var CHAT_KEY = 'omChat';
  var D = null, busy = false, tab = 'today', reportOpen = false;
  var chat = (function () { try { return JSON.parse(sessionStorage.getItem(CHAT_KEY) || '[]'); } catch (e) { return []; } })();

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (m && typeof window.showNotification === 'function') window.showNotification(m, t); }
  function get() { return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function waFmt(s) { return esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>').replace(/\n/g, '<br>'); }
  function day(d) { var t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }
  function saveChat() { try { sessionStorage.setItem(CHAT_KEY, JSON.stringify(chat.slice(-30))); } catch (e) { /* private mode */ } }

  function css() {
    if ($('omCss')) return;
    var st = document.createElement('style');
    st.id = 'omCss';
    st.textContent = [
      '#omRoot{display:grid;gap:14px;--om:#6d28d9;--om-d:#4c1d95;--om-l:#f5f3ff}',
      '#omRoot *{box-sizing:border-box}',
      /* hero */
      '.om-hero{position:relative;overflow:hidden;display:grid;gap:12px;padding:18px;border-radius:20px;color:#fff;background:radial-gradient(80% 140% at 100% 0%,#8b5cf6,transparent 60%),linear-gradient(135deg,#1e1b4b,#312e81);box-shadow:0 16px 34px -20px rgba(49,46,129,.9)}',
      '.om-hero-top{display:flex;align-items:center;gap:12px}.om-bot{width:46px;height:46px;flex:none;display:grid;place-items:center;border-radius:14px;background:rgba(255,255,255,.14);font-size:24px}',
      '.om-hero h2{margin:0;color:#fff;font-size:1.15rem;font-weight:800;line-height:1.2}.om-hero p{margin:3px 0 0;color:rgba(255,255,255,.8);font-size:.84rem;line-height:1.45}',
      '.om-sw{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.1);cursor:pointer}.om-sw input{display:none}',
      '.om-sw i{position:relative;width:48px;height:28px;flex:none;border-radius:999px;background:rgba(255,255,255,.3);transition:.2s}.om-sw i:after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:.2s}',
      '.om-sw input:checked+i{background:#a7f3d0}.om-sw input:checked+i:after{transform:translateX(20px);background:#065f46}',
      '.om-sw b{display:block;font-size:.95rem}.om-sw small{display:block;color:rgba(255,255,255,.75);font-size:.78rem;margin-top:1px}',
      '.om-pills{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 -2px;padding:0 2px}.om-pills::-webkit-scrollbar{display:none}',
      '.om-pill{flex:none;display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:999px;font-size:.74rem;font-weight:700;background:rgba(255,255,255,.14);white-space:nowrap}.om-pill .material-symbols-rounded{font-size:15px}',
      '.om-pill.ok .material-symbols-rounded{color:#86efac}.om-pill.warn .material-symbols-rounded{color:#fcd34d}',
      /* numbers */
      '.om-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}@media(max-width:760px){.om-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}',
      '.om-kpi{padding:12px 14px;border-radius:16px;background:#fff;border:1px solid #edf0f4}.om-kpi small{display:block;color:#6b7280;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em}',
      '.om-kpi b{display:block;margin-top:4px;font-size:1.25rem;font-weight:800;color:#111827;letter-spacing:-.01em}.om-kpi span{display:block;color:#6b7280;font-size:.76rem;margin-top:2px}',
      '.om-chg{display:inline-flex;align-items:center;margin-top:6px;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:800}.om-chg.up{background:#dcfce7;color:#15803d}.om-chg.down{background:#fee2e2;color:#b91c1c}.om-chg.flat{background:#f3f4f6;color:#6b7280}',
      /* tabs (phones) */
      '.om-tabs{display:none}@media(max-width:760px){.om-tabs{position:sticky;top:0;z-index:5;display:grid;grid-template-columns:repeat(3,1fr);gap:4px;padding:4px;border-radius:14px;background:#ede9fe}',
      '.om-tabs button{display:flex;align-items:center;justify-content:center;gap:5px;padding:10px 6px;border:0;border-radius:11px;background:transparent;color:#4c1d95;font:inherit;font-size:.86rem;font-weight:700;cursor:pointer}',
      '.om-tabs button .material-symbols-rounded{font-size:18px}.om-tabs button.on{background:#fff;box-shadow:0 2px 6px -2px rgba(76,29,149,.35)}',
      '#omRoot [data-tab]:not(.on){display:none}}',
      '.om-cols{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:14px;align-items:start}.om-col{display:grid;gap:14px;min-width:0}@media(max-width:1000px){.om-cols{grid-template-columns:1fr}}',
      /* cards */
      '.om-card{padding:16px;border-radius:18px;background:#fff;border:1px solid #edf0f4;box-shadow:0 1px 2px rgba(16,24,40,.04);min-width:0}',
      '.om-card h3{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:1rem;font-weight:800;color:#111827}.om-card h3 .material-symbols-rounded{color:var(--om)}',
      '.om-sub{margin:0 0 12px;color:#6b7280;font-size:.83rem;line-height:1.5}',
      /* report bubble */
      '.om-wa{position:relative;padding:12px;border-radius:16px;background:#efeae2}',
      '.om-bub{max-width:560px;padding:10px 12px;border-radius:12px;border-top-left-radius:3px;background:#fff;color:#111b21;font-size:.9rem;line-height:1.55;box-shadow:0 1px .5px rgba(11,20,26,.13);overflow-wrap:anywhere}',
      '.om-bub small{display:block;margin-top:6px;color:#667781;font-size:.7rem;text-align:right}',
      '.om-wa.clip .om-bub{max-height:260px;overflow:hidden;-webkit-mask-image:linear-gradient(#000 70%,transparent);mask-image:linear-gradient(#000 70%,transparent)}',
      '.om-more{display:block;margin:8px auto 0;padding:6px 14px;border:0;border-radius:999px;background:#fff;color:var(--om-d);font:inherit;font-size:.8rem;font-weight:700;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.1)}',
      /* actions */
      '.om-acts{display:grid;gap:8px;margin-top:12px}',
      '.om-act{display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px;align-items:start;padding:12px;border-radius:14px;border:1.5px solid #ede9fe;background:#faf5ff}',
      '.om-act .om-n{width:28px;height:28px;display:grid;place-items:center;border-radius:50%;background:var(--om);color:#fff;font-weight:800;font-size:.85rem}',
      '.om-act b{display:block;font-size:.88rem;color:#1f2937;font-weight:600;line-height:1.45}.om-act .om-res{display:block;margin-top:4px;color:#15803d;font-size:.8rem;font-weight:600}',
      '.om-act-btns{grid-column:2;display:flex;gap:8px;flex-wrap:wrap}.om-act-btns .btn{min-height:38px}',
      '.om-act.done{background:#f0fdf4;border-color:#bbf7d0}.om-act.done .om-n{background:#16a34a}.om-act.declined,.om-act.expired{opacity:.6}',
      '.om-tag{font-size:.76rem;font-weight:800;color:#6b7280}.om-act.done .om-tag{color:#15803d}',
      '.om-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.om-btns .btn{flex:1 1 180px;justify-content:center;min-height:42px}',
      /* chat */
      '.om-chatbox{display:flex;flex-direction:column;height:min(62vh,520px);border-radius:16px;background:#f8f7fc;border:1px solid #ede9fe;overflow:hidden}',
      '.om-msgs{flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:12px;scroll-behavior:smooth}',
      '.om-q{align-self:flex-end;max-width:85%;padding:9px 12px;border-radius:14px 14px 4px 14px;background:var(--om);color:#fff;font-size:.88rem;line-height:1.45;overflow-wrap:anywhere}',
      '.om-a{align-self:flex-start;max-width:92%;padding:10px 12px;border-radius:14px 14px 14px 4px;background:#fff;border:1px solid #ede9fe;color:#1f2937;font-size:.88rem;line-height:1.55;overflow-wrap:anywhere}',
      '.om-hello{align-self:center;max-width:320px;margin:auto;text-align:center;color:#6b7280;font-size:.85rem;line-height:1.5}.om-hello .material-symbols-rounded{font-size:34px;color:#a78bfa}',
      '.om-dots{display:inline-flex;gap:4px}.om-dots i{width:7px;height:7px;border-radius:50%;background:#a78bfa;animation:omDot 1s infinite ease-in-out}.om-dots i:nth-child(2){animation-delay:.15s}.om-dots i:nth-child(3){animation-delay:.3s}',
      '@keyframes omDot{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}',
      '.om-chips{display:flex;gap:6px;overflow-x:auto;padding:8px 10px;border-top:1px solid #ede9fe;background:#fff;scrollbar-width:none}.om-chips::-webkit-scrollbar{display:none}',
      '.om-chips button{flex:none;padding:7px 12px;border:1px solid #ddd6fe;border-radius:999px;background:var(--om-l);color:var(--om-d);font:inherit;font-size:.78rem;font-weight:600;cursor:pointer;white-space:nowrap}',
      '.om-ask{display:flex;gap:8px;padding:10px;background:#fff;border-top:1px solid #ede9fe}',
      '.om-ask input{flex:1;min-width:0;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:999px;font:inherit;font-size:16px;outline:none}.om-ask input:focus{border-color:#a78bfa}',
      '.om-send{width:44px;height:44px;flex:none;display:grid;place-items:center;border:0;border-radius:50%;background:var(--om);color:#fff;cursor:pointer}.om-send:disabled{opacity:.5}',
      '.om-clear{border:0;background:none;color:#6b7280;font:inherit;font-size:.78rem;font-weight:600;cursor:pointer;padding:0;margin-left:auto}',
      /* settings + setup */
      '.om-form{display:grid;gap:12px}.om-form label{display:grid;gap:5px;font-size:.82rem;font-weight:700;color:#374151}',
      '.om-form input,.om-form select{width:100%;padding:11px 12px;border:1.5px solid #e5e7eb;border-radius:12px;font:inherit;font-size:16px;background:#fff}',
      '.om-form small{font-weight:500;color:#6b7280;font-size:.78rem;line-height:1.4}',
      '.om-check{display:flex!important;grid-template-columns:none;align-items:center;gap:10px;font-weight:600!important}.om-check input{width:20px;height:20px;flex:none}',
      '.om-setup{display:grid;gap:8px;margin:0;padding:0;list-style:none}.om-setup li{display:flex;gap:10px;align-items:flex-start;font-size:.85rem;line-height:1.45;color:#374151}',
      '.om-setup .material-symbols-rounded{font-size:20px;flex:none}.om-setup .ok{color:#16a34a}.om-setup .no{color:#d97706}.om-setup small{display:block;color:#6b7280;font-size:.78rem}',
      '.om-hist{display:grid;gap:6px}.om-h{width:100%;text-align:left;padding:10px 12px;border:1px solid #edf0f4;border-radius:12px;background:#fff;font:inherit;cursor:pointer}',
      '.om-h b{display:block;font-size:.86rem;color:#111827}.om-h small{display:block;color:#6b7280;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.om-empty{padding:20px;text-align:center;color:#9ca3af;font-size:.88rem}',
      '@media(max-width:760px){.om-hero{padding:16px;border-radius:18px}.om-card{padding:14px;border-radius:16px}.om-chatbox{height:min(66vh,560px)}}'
    ].join('\n');
    document.head.appendChild(st);
  }

  function load() {
    var root = $('omRoot');
    if (!root) return;
    css();
    if (!D) root.innerHTML = '<div class="om-empty">Loading…</div>';
    get().then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="om-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
      D = d;
      if (d.sent_now) notify('Today\'s report was sent to your WhatsApp', 'success');
      draw();
    }).catch(function () { root.innerHTML = '<div class="om-empty">No connection. <button type="button" class="btn btn-secondary btn-sm" onclick="loadOwnerManagerStudio()">Try again</button></div>'; });
  }

  function chg(k) {
    if (k.change === null || k.change === undefined) return '<span class="om-chg flat">New</span>';
    var c = k.change > 0 ? 'up' : (k.change < 0 ? 'down' : 'flat');
    return '<span class="om-chg ' + c + '" title="vs ' + esc(k.vs) + '">' + (k.change > 0 ? '▲ +' : k.change < 0 ? '▼ ' : '') + k.change + '%</span>';
  }

  function actsHtml(acts, live) {
    if (!acts || !acts.length) return '';
    return '<div class="om-acts">' + acts.map(function (a) {
      var st = live ? a.status : (a.status === 'pending' ? 'expired' : a.status);
      var right = st === 'pending' && D.can_edit
        ? '<div class="om-act-btns"><button type="button" class="btn btn-primary btn-sm" data-om-run="' + a.id + '">' + ic('bolt') + 'Do it</button><button type="button" class="btn btn-secondary btn-sm" data-om-skip="' + a.id + '">Skip</button></div>'
        : '<div class="om-act-btns"><span class="om-tag">' + esc(st === 'done' ? '✓ Done' : st === 'declined' ? 'Skipped' : st === 'expired' ? 'Expired' : '') + '</span></div>';
      return '<div class="om-act ' + esc(st) + '"><span class="om-n">' + (st === 'done' ? '✓' : a.code) + '</span><div><b>' + esc(a.label) + '</b>' + (a.result ? '<span class="om-res">' + esc(a.result) + '</span>' : '') + '</div>' + right + '</div>';
    }).join('') + '</div>';
  }

  function chatHtml() {
    if (!chat.length) return '<div class="om-hello">' + ic('waving_hand') + '<br>Ask about your sales, dishes, customers or payments, in English, Hindi or Hinglish. Every number comes from your own orders.</div>';
    return chat.map(function (x) { return '<div class="om-q">' + esc(x.q) + '</div><div class="om-a">' + waFmt(x.a) + '</div>'; }).join('');
  }

  function setupHtml(s, st) {
    var item = function (ok, title, sub) { return '<li>' + ic(ok ? 'check_circle' : 'error') .replace('rounded"', 'rounded ' + (ok ? 'ok' : 'no') + '"') + '<div>' + title + (sub ? '<small>' + sub + '</small>' : '') + '</div></li>'; };
    return '<ul class="om-setup">' +
      item(s.enabled, s.enabled ? 'Morning report is on (' + esc(s.send_time) + ')' : 'Morning report is off', s.enabled ? '' : 'Switch it on at the top.') +
      item(!!s.phone, s.phone ? 'Goes to ' + esc(s.phone_intl || s.phone) : 'No WhatsApp number', s.phone ? '' : 'Add your number in Settings.') +
      item(st.whatsapp && !st.whatsapp_test, st.whatsapp_test ? 'WhatsApp test mode on this computer' : (st.whatsapp ? 'WhatsApp sending is connected' : 'WhatsApp is not connected'),
        st.whatsapp_test ? 'Nothing is really sent here; on restrogrow.com it goes to your phone.' : (st.whatsapp ? 'Until the "daily report" template is approved, send any message to the restaurant\'s WhatsApp number once a day so the report can reach you.' : 'Growth › WhatsApp, or ask RestroGrow support.')) +
      item(st.cron === 'running', st.cron === 'running' ? 'Daily sending is running' : 'Daily sending is not running yet', st.cron === 'running' ? '' : 'RestroGrow switches this on for you. Meanwhile the report also goes out when you open this page after your time.') +
      item(D.ai, D.ai ? 'AI writing is on' : 'AI writing is off', D.ai ? 'Gemini writes the report in your language; every number is checked against your data.' : 'The plain report still works.') +
      '</ul>';
  }

  function draw() {
    var s = D.settings, st = D.status || {}, latest = (D.briefs || [])[0];
    var tabBtn = function (k, icon, label) { return '<button type="button" class="' + (tab === k ? 'on' : '') + '" data-om-tab="' + k + '">' + ic(icon) + label + '</button>'; };
    var on = function (k) { return ' data-tab="' + k + '" class="' + (tab === k ? 'on ' : '') + 'om-col"'; };

    var report = '<div class="om-card"><h3>' + ic('summarize') + 'Today\'s report</h3><p class="om-sub">Exactly what arrives on WhatsApp. Every number comes from your orders.</p>' +
      (latest ? '<div class="om-wa' + (reportOpen ? '' : ' clip') + '" id="omWa"><div class="om-bub">' + waFmt(latest.text) + '<small>' + esc(day(latest.day)) + (latest.sent_at ? ' · sent ✓✓' : ' · preview') + '</small></div>' +
        '<button type="button" class="om-more" data-om="more">' + (reportOpen ? 'Show less' : 'Show the full report') + '</button></div>' + actsHtml(latest.actions, true)
        : '<div class="om-empty">No report yet. Tap “Make today\'s report”.</div>') +
      '<div class="om-btns"><button type="button" class="btn btn-secondary" data-om="preview">' + ic('refresh') + 'Make today\'s report</button>' +
      (D.can_edit ? '<button type="button" class="btn btn-primary" data-om="send"' + (s.phone ? '' : ' disabled title="Add your WhatsApp number in Settings"') + '>' + ic('send') + 'Send to my WhatsApp</button>' : '') + '</div></div>';

    var ask = '<div class="om-card"><h3>' + ic('forum') + 'Ask your manager' + (chat.length ? '<button type="button" class="om-clear" data-om="clear">Clear</button>' : '') + '</h3>' +
      '<p class="om-sub">Same answers as on WhatsApp. Ask in English, हिंदी or Hinglish.</p>' +
      '<div class="om-chatbox"><div class="om-msgs" id="omMsgs">' + chatHtml() + '</div>' +
      '<div class="om-chips">' + ['aaj kitna bika?', 'compare this week with last week', 'top dishes this month', 'how many Paneer Tikka sold this week', 'busiest hours', 'cash vs UPI this week', 'least sold dishes', 'new customers this month', 'what should I do to increase sales?', 'कल कितने ऑर्डर कैंसिल हुए?']
        .map(function (q) { return '<button type="button" data-om-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') + '</div>' +
      '<form class="om-ask" id="omAskForm"><input id="omAsk" placeholder="Ask anything… e.g. sales this week" maxlength="300" autocomplete="off" enterkeyhint="send">' +
      '<button type="submit" class="om-send" aria-label="Send"' + (busy ? ' disabled' : '') + '>' + ic('send') + '</button></form></div></div>';

    var settings = '<div class="om-card"><h3>' + ic('tune') + 'Settings</h3><div class="om-form">' +
        '<label>Send every day at<input type="time" id="omTime" value="' + esc(s.send_time) + '"><small>Your restaurant\'s time (' + esc(s.tz || '') + ').</small></label>' +
        '<label>Report language<select id="omLang">' + [['en', 'English'], ['hinglish', 'Hinglish'], ['hi', 'हिंदी (Hindi)']].map(function (l) { return '<option value="' + l[0] + '"' + (s.language === l[0] ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select><small>Questions are answered in the language you ask in.</small></label>' +
        '<label>WhatsApp number for the report<input id="omPhone" inputmode="tel" placeholder="' + esc(s.phone || 'Your number') + '" value="' + esc(s.phone_override || '') + '">' +
          '<small>Empty = the restaurant\'s phone' + (s.phone_intl ? ' (' + esc(s.phone_intl) + ')' : '') + '. Messages from this number go to your AI manager, not to ordering.</small></label>' +
        '<label class="om-check"><input type="checkbox" id="omEmail"' + (s.email_on ? ' checked' : '') + '>Also email me the report' + (st.email ? '' : ' <small>(email is not switched on yet)</small>') + '</label>' +
        '<div><button type="button" class="btn btn-primary" data-om="save"' + (D.can_edit ? '' : ' disabled') + '>' + ic('save') + 'Save</button></div></div></div>' +
      '<div class="om-card"><h3>' + ic('checklist') + 'Ready to send?</h3>' + setupHtml(s, st) + '</div>' +
      '<div class="om-card"><h3>' + ic('history') + 'Earlier reports</h3><div class="om-hist">' + ((D.briefs || []).slice(1).map(function (b) {
        var done = (b.actions || []).filter(function (a) { return a.status === 'done'; }).length;
        return '<button type="button" class="om-h" data-om-hist="' + b.id + '"><b>' + esc(day(b.day)) + (b.sent_at ? ' · sent' : '') + (done ? ' · ' + done + ' action' + (done > 1 ? 's' : '') + ' done' : '') + '</b><small>' + esc((String(b.text).split('\n').filter(function (l) { return l.trim(); })[1] || '').replace(/[*_]/g, '')) + '</small></button>';
      }).join('') || '<div class="om-empty">Earlier reports appear here.</div>') + '</div></div>';

    $('omRoot').innerHTML =
      '<div class="om-hero"><div class="om-hero-top"><div class="om-bot">🤖</div><div><h2>Your AI manager</h2><p>A morning report on WhatsApp, one-tap fixes, and answers any time you ask.</p></div></div>' +
        '<label class="om-sw"><input type="checkbox" id="omOn"' + (s.enabled ? ' checked' : '') + (D.can_edit ? '' : ' disabled') + '><i></i><div><b>Morning report ' + (s.enabled ? 'on' : 'off') + '</b><small>' + (s.enabled ? 'Every day at ' + esc(s.send_time) + (s.phone_intl ? ' to ' + esc(s.phone_intl) : '') : 'Switch on to get it every morning') + '</small></div></label>' +
        '<div class="om-pills">' +
          '<span class="om-pill ' + (D.ai ? 'ok' : 'warn') + '">' + ic('auto_awesome') + (D.ai ? 'AI on, numbers checked' : 'Plain report (no AI)') + '</span>' +
          '<span class="om-pill ' + (st.whatsapp ? 'ok' : 'warn') + '">' + ic('chat') + (st.whatsapp_test ? 'WhatsApp test mode' : st.whatsapp ? 'WhatsApp connected' : 'WhatsApp not connected') + '</span>' +
          (s.last_sent_on ? '<span class="om-pill ok">' + ic('done_all') + 'Last sent ' + esc(day(s.last_sent_on)) + '</span>' : '') + '</div></div>' +
      '<div class="om-kpis">' + (D.kpis || []).map(function (k) { return '<div class="om-kpi"><small>' + esc(k.title) + '</small><b>' + esc(k.amount) + '</b><span>' + k.orders + ' order' + (k.orders === 1 ? '' : 's') + '</span>' + chg(k) + '</div>'; }).join('') + '</div>' +
      '<div class="om-tabs" role="tablist">' + tabBtn('today', 'summarize', 'Today') + tabBtn('ask', 'forum', 'Ask') + tabBtn('settings', 'tune', 'Settings') + '</div>' +
      '<div class="om-cols"><div class="om-col">' +
        '<div' + on('today') + '>' + report + '</div>' +
        '<div' + on('ask') + '>' + ask + '</div>' +
      '</div><div' + on('settings') + '>' + settings + '</div></div>';
    scrollChat();
  }

  function scrollChat() { var m = $('omMsgs'); if (m) m.scrollTop = m.scrollHeight; }

  function ask(q) {
    q = String(q || '').trim();
    if (!q || busy) return;
    busy = true;
    if (tab !== 'ask' && window.innerWidth <= 760) { tab = 'ask'; draw(); }
    var box = $('omMsgs');
    if (box) {
      var hello = box.querySelector('.om-hello'); if (hello) hello.remove();
      box.insertAdjacentHTML('beforeend', '<div class="om-q">' + esc(q) + '</div><div class="om-a" id="omWait"><span class="om-dots"><i></i><i></i><i></i></span></div>');
      scrollChat();
    }
    var input = $('omAsk'); if (input) input.value = '';
    var sendBtn = document.querySelector('.om-send'); if (sendBtn) sendBtn.disabled = true;
    post({ action: 'ask', text: q }).then(function (d) {
      var a = d.success ? d.answer : (d.message || 'Could not answer. Try again.');
      if (d.success) { chat.push({ q: q, a: a }); saveChat(); }
      var w = $('omWait'); if (w) { w.removeAttribute('id'); w.innerHTML = waFmt(a); }
    }).catch(function () {
      var w = $('omWait'); if (w) { w.removeAttribute('id'); w.textContent = 'No connection. Try again.'; }
    }).finally(function () {
      busy = false;
      var b = document.querySelector('.om-send'); if (b) b.disabled = false;
      scrollChat();
    });
  }

  function save(extra) {
    var b = document.querySelector('[data-om="save"]');
    if (b) b.disabled = true;
    return post(Object.assign({ action: 'save', enabled: $('omOn').checked ? 1 : 0, send_time: $('omTime').value, language: $('omLang').value, phone: $('omPhone').value, email_on: $('omEmail').checked ? 1 : 0 }, extra || {}))
      .then(function (d) { notify(d.message || (d.success ? 'Saved' : 'Could not save'), d.success ? 'success' : 'error'); if (d.success) load(); else if (b) b.disabled = false; })
      .catch(function () { notify('No connection', 'error'); if (b) b.disabled = false; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-om],[data-om-q],[data-om-run],[data-om-skip],[data-om-hist],[data-om-tab]');
    if (!t || !D || !$('omRoot') || !$('omRoot').contains(t) && !t.closest('.rg-modal, [role="dialog"]')) return;
    if (t.hasAttribute('data-om-tab')) { tab = t.getAttribute('data-om-tab'); draw(); return; }
    if (t.hasAttribute('data-om-q')) { ask(t.getAttribute('data-om-q')); return; }
    if (t.hasAttribute('data-om-run') || t.hasAttribute('data-om-skip')) {
      var run = t.hasAttribute('data-om-run');
      t.disabled = true;
      post({ action: run ? 'run' : 'decline', id: +(t.getAttribute('data-om-run') || t.getAttribute('data-om-skip')) }).then(function (d) {
        notify(d.message || '', d.success ? 'success' : 'error'); load();
      }).catch(function () { t.disabled = false; notify('No connection', 'error'); });
      return;
    }
    if (t.hasAttribute('data-om-hist')) {
      var b = (D.briefs || []).filter(function (x) { return String(x.id) === t.getAttribute('data-om-hist'); })[0];
      if (b && typeof window.rgModal === 'function') window.rgModal({ title: 'Report for ' + day(b.day), icon: 'summarize', html: '<div class="om-wa"><div class="om-bub">' + waFmt(b.text) + '</div></div>' + actsHtml(b.actions, false), confirmText: 'Close', cancelText: null });
      return;
    }
    var a = t.getAttribute('data-om');
    if (a === 'more') { reportOpen = !reportOpen; var w = $('omWa'); if (w) w.classList.toggle('clip', !reportOpen); t.textContent = reportOpen ? 'Show less' : 'Show the full report'; }
    else if (a === 'clear') { chat = []; saveChat(); draw(); }
    else if (a === 'preview' || a === 'send') {
      t.disabled = true;
      var old = t.innerHTML; t.innerHTML = ic('progress_activity') + (a === 'send' ? 'Sending…' : 'Making it…');
      post({ action: a }).then(function (d) {
        t.disabled = false; t.innerHTML = old;
        notify(d.message || (d.success ? 'Report ready' : 'Could not make the report'), d.success ? 'success' : 'error');
        if (d.success) { reportOpen = true; load(); }
      }).catch(function () { t.disabled = false; t.innerHTML = old; notify('No connection', 'error'); });
    } else if (a === 'save') save();
  });
  document.addEventListener('submit', function (e) { if (e.target && e.target.id === 'omAskForm') { e.preventDefault(); ask(($('omAsk') || {}).value); } });
  document.addEventListener('change', function (e) { if (e.target && e.target.id === 'omOn') save(); });

  window.loadOwnerManagerStudio = load;
})();
