/*
 * Growth › AI manager (data: api/owner_manager_admin.php, engine: config/owner_manager.php).
 *
 * The owner's morning WhatsApp report: switch, time, language and number; today's report with its
 * one-tap actions (Run / Skip); "ask me anything" with the same answers as on WhatsApp; the last 14
 * reports. Styles live here (om-*), so the page needs only #omRoot.
 */
(function () {
  'use strict';
  var API = '../api/owner_manager_admin.php';
  var D = null, busy = false, answers = [];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function get() { return fetch(API, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function waFmt(s) { return esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>').replace(/\n/g, '<br>'); }
  function day(d) { var t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }

  function css() {
    if ($('omCss')) return;
    var st = document.createElement('style');
    st.id = 'omCss';
    st.textContent = [
      '#omRoot{display:grid;gap:16px}',
      '.om-hero{display:flex;flex-wrap:wrap;gap:18px;align-items:center;padding:20px;border-radius:20px;color:#fff;background:radial-gradient(80% 140% at 100% 0%,#8b5cf6,transparent 60%),linear-gradient(135deg,#1e1b4b,#312e81);box-shadow:0 16px 34px -20px rgba(49,46,129,.9)}',
      '.om-hero-l{flex:1;min-width:240px}.om-hero h2{margin:0;color:#fff;font-size:1.25rem;font-weight:800}.om-hero p{margin:6px 0 0;color:rgba(255,255,255,.85);font-size:.88rem;line-height:1.5}',
      '.om-sw{display:inline-flex;align-items:center;gap:10px;margin-top:12px;cursor:pointer;font-weight:700}.om-sw input{display:none}.om-sw i{position:relative;width:46px;height:26px;border-radius:999px;background:rgba(255,255,255,.3);transition:.2s}',
      '.om-sw i:after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:.2s}.om-sw input:checked+i{background:#c4b5fd}.om-sw input:checked+i:after{transform:translateX(20px);background:#312e81}',
      '.om-pills{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.om-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;background:rgba(255,255,255,.14)}.om-pill .material-symbols-rounded{font-size:15px}',
      '.om-cols{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px;align-items:start}@media(max-width:1000px){.om-cols{grid-template-columns:1fr}}',
      '.om-card{padding:18px;border-radius:18px;background:#fff;border:1px solid #edf0f4;box-shadow:0 1px 2px rgba(16,24,40,.04)}.om-card h3{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:1rem;font-weight:800;color:#111827}.om-card h3 .material-symbols-rounded{color:#6d28d9}',
      '.om-sub{margin:0 0 12px;color:#6b7280;font-size:.84rem;line-height:1.5}',
      '.om-wa{padding:14px;border-radius:16px;background:#efeae2}.om-bub{max-width:560px;padding:10px 12px;border-radius:12px;border-top-left-radius:3px;background:#fff;color:#111b21;font-size:.9rem;line-height:1.55;box-shadow:0 1px .5px rgba(11,20,26,.13)}',
      '.om-bub small{display:block;margin-top:6px;color:#667781;font-size:.7rem;text-align:right}',
      '.om-acts{display:grid;gap:8px;margin-top:12px}.om-act{display:flex;align-items:center;gap:10px;padding:12px;border-radius:14px;border:1.5px solid #ede9fe;background:#faf5ff}',
      '.om-act b{flex:1;font-size:.88rem;color:#1f2937;font-weight:600;line-height:1.45}.om-act .om-n{width:28px;height:28px;flex:none;display:grid;place-items:center;border-radius:50%;background:#6d28d9;color:#fff;font-weight:800;font-size:.85rem}',
      '.om-act.done{background:#f0fdf4;border-color:#bbf7d0}.om-act.done .om-n{background:#16a34a}.om-act.declined,.om-act.expired{opacity:.55}',
      '.om-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
      '.om-ask{display:flex;gap:8px}.om-ask input{flex:1;min-width:0;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:12px;font:inherit;font-size:.9rem}',
      '.om-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.om-chips button{padding:6px 11px;border:1px solid #ddd6fe;border-radius:999px;background:#f5f3ff;color:#4c1d95;font:inherit;font-size:.78rem;font-weight:600;cursor:pointer}',
      '.om-ans{display:grid;gap:8px;margin-top:12px}.om-q{justify-self:end;max-width:85%;padding:8px 12px;border-radius:12px;background:#ede9fe;font-size:.86rem}.om-a{max-width:92%;padding:10px 12px;border-radius:12px;background:#f9fafb;border:1px solid #edf0f4;font-size:.88rem;line-height:1.5}',
      '.om-form{display:grid;gap:12px}.om-form label{display:grid;gap:5px;font-size:.82rem;font-weight:700;color:#374151}.om-form input,.om-form select{padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:10px;font:inherit;font-size:.9rem}',
      '.om-hist{display:grid;gap:6px}.om-h{width:100%;text-align:left;padding:10px 12px;border:1px solid #edf0f4;border-radius:12px;background:#fff;font:inherit;cursor:pointer}.om-h b{display:block;font-size:.86rem;color:#111827}.om-h small{display:block;color:#6b7280;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.om-empty{padding:20px;text-align:center;color:#9ca3af;font-size:.88rem}',
      '.om-note{display:flex;gap:8px;padding:10px 12px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:.82rem;line-height:1.45}.om-note .material-symbols-rounded{font-size:18px}'
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
    }).catch(function () { root.innerHTML = '<div class="om-empty">No connection. Try again.</div>'; });
  }

  function actsHtml(acts) {
    if (!acts || !acts.length) return '';
    return '<div class="om-acts">' + acts.map(function (a) {
      var btns = a.status === 'pending' && D.can_edit
        ? '<button type="button" class="btn btn-primary btn-sm" data-om-run="' + a.id + '">Do it</button><button type="button" class="btn btn-secondary btn-sm" data-om-skip="' + a.id + '">Skip</button>'
        : '<span style="font-size:.78rem;font-weight:700;color:' + (a.status === 'done' ? '#15803d' : '#6b7280') + '">' + esc(a.status === 'done' ? 'Done' : a.status === 'declined' ? 'Skipped' : a.status === 'expired' ? 'Expired' : '') + '</span>';
      return '<div class="om-act ' + esc(a.status) + '"><span class="om-n">' + (a.status === 'done' ? '✓' : a.code) + '</span><b>' + esc(a.label) + (a.result ? '<br><small style="font-weight:500;color:#15803d">' + esc(a.result) + '</small>' : '') + '</b>' + btns + '</div>';
    }).join('') + '</div>';
  }

  function draw() {
    var s = D.settings, latest = (D.briefs || [])[0];
    $('omRoot').innerHTML =
      '<div class="om-hero"><div class="om-hero-l"><h2>Your AI manager</h2>' +
        '<p>Every morning on WhatsApp: yesterday\'s sales and what changed, what needs attention, and one-tap fixes ("Reply 1 to switch Paneer Tikka back on"). Ask it anything, like "aaj kitna bika?".</p>' +
        '<label class="om-sw"><input type="checkbox" id="omOn"' + (s.enabled ? ' checked' : '') + (D.can_edit ? '' : ' disabled') + '><i></i>Morning report is ' + (s.enabled ? 'on, at ' + esc(s.send_time) : 'off') + '</label>' +
        '<div class="om-pills"><span class="om-pill">' + ic('auto_awesome') + (D.ai ? 'Written by AI (Gemini), numbers checked' : 'Plain report (no AI key)') + '</span>' +
          '<span class="om-pill">' + ic('phone') + (s.phone ? '+' + esc(s.phone) : 'No number yet') + '</span>' +
          (s.last_sent_on ? '<span class="om-pill">' + ic('done_all') + 'Last sent ' + esc(day(s.last_sent_on)) + '</span>' : '') + '</div></div></div>' +
      '<div class="om-cols"><div style="display:grid;gap:16px">' +
        '<div class="om-card"><h3>' + ic('summarize') + 'Today\'s report</h3><p class="om-sub">This is exactly what arrives on WhatsApp. Every number comes from your sales data.</p>' +
          (latest ? '<div class="om-wa"><div class="om-bub">' + waFmt(latest.text) + '<small>' + esc(day(latest.day)) + (latest.sent_at ? ' · sent' : ' · preview') + '</small></div></div>' + actsHtml(latest.actions)
            : '<div class="om-empty">No report yet. Tap “Make today\'s report”.</div>') +
          '<div class="om-btns"><button type="button" class="btn btn-secondary" data-om="preview">' + ic('refresh') + 'Make today\'s report</button>' +
          (D.can_edit ? '<button type="button" class="btn btn-primary" data-om="send">' + ic('send') + 'Send to my WhatsApp now</button>' : '') + '</div></div>' +
        '<div class="om-card"><h3>' + ic('forum') + 'Ask me anything</h3><p class="om-sub">Same answers as on WhatsApp. English, Hindi or Hinglish.</p>' +
          '<div class="om-ask"><input id="omAsk" placeholder="e.g. sales this week, aaj kitna bika, top dishes this month" maxlength="300"><button type="button" class="btn btn-primary" data-om="ask">Ask</button></div>' +
          '<div class="om-chips">' + ['aaj kitna bika?', 'sales this week', 'top dishes this month', 'least sold dishes', 'WhatsApp vs website orders', 'new customers this month', 'cancelled orders yesterday'].map(function (q) { return '<button type="button" data-om-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') + '</div>' +
          '<div class="om-ans" id="omAns">' + answers.map(function (x) { return '<div class="om-q">' + esc(x.q) + '</div><div class="om-a">' + waFmt(x.a) + '</div>'; }).join('') + '</div></div>' +
      '</div><div style="display:grid;gap:16px">' +
        '<div class="om-card"><h3>' + ic('tune') + 'Settings</h3><div class="om-form">' +
          '<label>Send every day at<input type="time" id="omTime" value="' + esc(s.send_time) + '"></label>' +
          '<label>Language<select id="omLang">' + [['en', 'English'], ['hinglish', 'Hinglish'], ['hi', 'हिंदी (Hindi)']].map(function (l) { return '<option value="' + l[0] + '"' + (s.language === l[0] ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select></label>' +
          '<label>WhatsApp number<input id="omPhone" inputmode="tel" placeholder="' + esc(s.phone || 'Your number') + '" value="' + esc(s.phone_override || '') + '"><small style="font-weight:500;color:#6b7280">Empty = the restaurant\'s phone' + (s.phone ? ' (+' + esc(s.phone) + ')' : '') + '. Replies from this number reach the AI manager.</small></label>' +
          '<div><button type="button" class="btn btn-primary" data-om="save"' + (D.can_edit ? '' : ' disabled') + '>' + ic('save') + 'Save</button></div></div></div>' +
        '<div class="om-note">' + ic('info') + '<div>On the live site the report goes out from a scheduled job every 15 minutes (cron/owner_brief.php). WhatsApp only delivers a message the owner didn\'t start if it uses an approved template, so until the "daily report" template is approved the owner should message the restaurant number once a day.</div></div>' +
        '<div class="om-card"><h3>' + ic('history') + 'Earlier reports</h3><div class="om-hist">' + ((D.briefs || []).slice(1).map(function (b) {
          var done = (b.actions || []).filter(function (a) { return a.status === 'done'; }).length;
          return '<button type="button" class="om-h" data-om-hist="' + b.id + '"><b>' + esc(day(b.day)) + (done ? ' · ' + done + ' action' + (done > 1 ? 's' : '') + ' done' : '') + '</b><small>' + esc((String(b.text).split('\n').filter(function (l) { return l.trim(); })[1] || '').replace(/[*_]/g, '')) + '</small></button>';
        }).join('') || '<div class="om-empty">Earlier reports appear here.</div>') + '</div></div>' +
      '</div></div>';
  }

  function ask(q) {
    q = String(q || '').trim();
    if (!q || busy) return;
    busy = true;
    var box = $('omAns');
    if (box) box.insertAdjacentHTML('beforeend', '<div class="om-q">' + esc(q) + '</div><div class="om-a" id="omWait">Thinking…</div>');
    post({ action: 'ask', text: q }).then(function (d) {
      busy = false;
      var a = d.success ? d.answer : (d.message || 'Could not answer');
      answers.push({ q: q, a: a });
      var w = $('omWait'); if (w) { w.removeAttribute('id'); w.innerHTML = waFmt(a); }
      var i = $('omAsk'); if (i) i.value = '';
    }).catch(function () { busy = false; var w = $('omWait'); if (w) { w.removeAttribute('id'); w.textContent = 'No connection.'; } });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-om],[data-om-q],[data-om-run],[data-om-skip],[data-om-hist]');
    if (!t || !D) return;
    if (t.hasAttribute('data-om-q')) { ask(t.getAttribute('data-om-q')); return; }
    if (t.hasAttribute('data-om-run') || t.hasAttribute('data-om-skip')) {
      var run = t.hasAttribute('data-om-run');
      t.disabled = true;
      post({ action: run ? 'run' : 'decline', id: +(t.getAttribute('data-om-run') || t.getAttribute('data-om-skip')) }).then(function (d) {
        notify(d.message || '', d.success ? 'success' : 'error'); load();
      });
      return;
    }
    if (t.hasAttribute('data-om-hist')) {
      var b = (D.briefs || []).filter(function (x) { return String(x.id) === t.getAttribute('data-om-hist'); })[0];
      if (b && typeof window.rgModal === 'function') window.rgModal({ title: 'Report for ' + day(b.day), icon: 'summarize', html: '<div class="om-wa"><div class="om-bub">' + waFmt(b.text) + '</div></div>' + actsHtml((b.actions || []).map(function (a) { return Object.assign({}, a, { status: a.status === 'pending' ? 'expired' : a.status }); })), confirmText: 'Close', cancelText: null });
      return;
    }
    var a = t.getAttribute('data-om');
    if (a === 'ask') ask(($('omAsk') || {}).value);
    else if (a === 'preview' || a === 'send') {
      t.disabled = true;
      var old = t.innerHTML; t.innerHTML = ic('progress_activity') + 'Working…';
      post({ action: a }).then(function (d) {
        t.disabled = false; t.innerHTML = old;
        if (d.message) notify(d.message, d.success ? 'success' : 'error');
        if (d.success) load();
      }).catch(function () { t.disabled = false; t.innerHTML = old; notify('No connection', 'error'); });
    } else if (a === 'save') {
      t.disabled = true;
      post({ action: 'save', enabled: $('omOn').checked ? 1 : 0, send_time: $('omTime').value, language: $('omLang').value, phone: $('omPhone').value }).then(function (d) {
        t.disabled = false; notify(d.message || (d.success ? 'Saved' : 'Could not save'), d.success ? 'success' : 'error'); if (d.success) load();
      });
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target && e.target.id === 'omAsk') { e.preventDefault(); ask(e.target.value); } });
  document.addEventListener('change', function (e) { if (e.target && e.target.id === 'omOn') { var b = document.querySelector('[data-om="save"]'); if (b) b.click(); } });

  window.loadOwnerManagerStudio = load;
})();
