/*
 * Growth › AI manager (data: api/owner_manager_admin.php, engine: config/owner_manager.php).
 *
 * The owner's morning WhatsApp report: on/off, numbers strip (today / yesterday / week / month vs the period
 * before), today's report with its one-tap actions (Do it / Skip), a chat that answers like the WhatsApp
 * manager (English, Hindi, Hinglish), settings, a setup checklist and earlier reports.
 * Phones get three tabs (Today · Ask · Settings); wider screens two columns. Styles live here (om-*),
 * so the page needs only #omRoot.
 * Voice: the chat's mic types what you say (you press Send). Voice mode: tap the circle, speak, check or
 * edit what it heard, press Send; the answer shows and is spoken in Gemini's natural voice (the phone's own
 * voice if that fails). It never sends or listens again by itself. Speech recognition is the browser's
 * (Web Speech API, best in Chrome on Android); the voice buttons are hidden where it's missing.
 */
(function () {
  'use strict';
  var API = '../api/owner_manager_admin.php';
  var CHAT_KEY = 'omChat';
  var D = null, busy = false, tab = 'today', reportOpen = false;
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var TTS = 'speechSynthesis' in window;
  var ANDROID = /Android/i.test(navigator.userAgent);
  function pref(k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } }
  function setPref(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  var voice = { on: false, rec: null, listening: false, speaking: false, audio: null, cache: {}, last: null,
    lang: pref('omVoiceLang', 'hi-IN'), name: pref('omVoiceName', 'Kore') };
  var chat = (function () { try { return JSON.parse(sessionStorage.getItem(CHAT_KEY) || '[]'); } catch (e) { return []; } })();

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  var SVG = {
    mic: 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z',
    speaker: 'M3 10v4h4l5 5V5L7 10H3zm13.5 2A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.06a7 7 0 0 1 0 13.48v2.06A9 9 0 0 0 14 3.2z',
    wave: 'M7 18h2V6H7v12zm4 4h2V2h-2v20zm-8-8h2v-4H3v4zm12 4h2V6h-2v12zm4-8v4h2v-4h-2z',
    spin: 'M12 2a10 10 0 1 0 10 10h-2a8 8 0 1 1-8-8V2z'
  };
  // the dashboard's self-hosted icon font has no mic / speaker glyphs, so these are drawn inline
  function svg(n, size) { return '<svg viewBox="0 0 24 24" width="' + (size || 20) + '" height="' + (size || 20) + '" aria-hidden="true" style="display:block"><path fill="currentColor" d="' + SVG[n] + '"/></svg>'; }
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
      /* voice */
      '.om-chatbox{position:relative}',
      '.om-mic{width:44px;height:44px;flex:none;display:grid;place-items:center;border:1.5px solid #ddd6fe;border-radius:50%;background:#fff;color:var(--om);cursor:pointer}',
      '.om-mic.on{background:#ef4444;border-color:#ef4444;color:#fff;animation:omPulse 1.2s infinite}',
      '@keyframes omPulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.5)}70%{box-shadow:0 0 0 12px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}',
      '.om-vbtn{display:inline-flex;align-items:center;gap:5px;margin-left:auto;padding:6px 12px;border:0;border-radius:999px;background:linear-gradient(135deg,#7c3aed,#4c1d95);color:#fff;font:inherit;font-size:.78rem;font-weight:700;cursor:pointer}',
      '.om-card h3.om-chat-h{flex-wrap:wrap}@media(max-width:760px){.om-chat-h .om-vbtn{order:3;flex:1 1 100%;justify-content:center;margin:8px 0 0;padding:10px}}',
      '.om-say{display:inline-flex;align-items:center;margin-left:6px;padding:0;border:0;background:none;color:#7c3aed;cursor:pointer;vertical-align:middle}.om-say .material-symbols-rounded{font-size:18px}',
      '.om-voice{position:absolute;inset:0;z-index:3;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:20px;text-align:center;color:#fff;background:radial-gradient(90% 70% at 50% 35%,#6d28d9,#1e1b4b)}',
      '.om-voice[hidden]{display:none}',
      '.om-orb{position:relative;width:120px;height:120px;display:grid;place-items:center;border:0;border-radius:50%;background:radial-gradient(circle at 35% 30%,#c4b5fd,#7c3aed 60%,#4c1d95);color:#fff;cursor:pointer;box-shadow:0 20px 50px -15px rgba(0,0,0,.6)}',
      '.om-orb .material-symbols-rounded{font-size:52px}',
      '.om-orb.listen{animation:omOrb 1.4s infinite ease-in-out;background:radial-gradient(circle at 35% 30%,#fca5a5,#ef4444 60%,#991b1b)}',
      '.om-orb.think{animation:omSpin 1.2s linear infinite}.om-orb.speak{animation:omOrb .9s infinite ease-in-out;background:radial-gradient(circle at 35% 30%,#86efac,#16a34a 60%,#14532d)}',
      '@keyframes omOrb{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}@keyframes omSpin{to{transform:rotate(360deg)}}',
      '.om-voice b{font-size:1.05rem}.om-vtext{min-height:3em;max-width:420px;margin:0;color:rgba(255,255,255,.85);font-size:.92rem;line-height:1.5;overflow-wrap:anywhere}',
      '.om-vrow{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}',
      '.om-vrow button{padding:8px 14px;border:1px solid rgba(255,255,255,.35);border-radius:999px;background:rgba(255,255,255,.1);color:#fff;font:inherit;font-size:.82rem;font-weight:700;cursor:pointer}',
      '.om-vrow button.on{background:#fff;color:#4c1d95;border-color:#fff}.om-vrow .om-vend{background:#ef4444;border-color:#ef4444}',
      '.om-voice{justify-content:flex-start;overflow-y:auto;padding:14px 16px 18px}',
      '.om-vtop{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;width:100%}.om-vtop button{padding:6px 11px;font-size:.76rem}',
      '.om-vgap{flex:1;min-height:6px}',
      '.om-heard{width:100%;max-width:440px;display:grid;gap:8px}.om-heard[hidden],.om-vans[hidden]{display:none}',
      '.om-heard textarea{width:100%;min-height:64px;padding:10px 12px;border:0;border-radius:14px;font:inherit;font-size:16px;line-height:1.4;color:#111827;background:#fff;resize:none}',
      '.om-vact{display:flex;gap:8px}.om-vact button{flex:1;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.12);color:#fff;font:inherit;font-weight:800;cursor:pointer}',
      '.om-vact .om-vsend{background:#22c55e;border-color:#22c55e;font-size:1rem}',
      '.om-vans{width:100%;max-width:440px;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.95);color:#1f2937;text-align:left;font-size:.88rem;line-height:1.55;max-height:38%;overflow-y:auto}',
      '.om-vans .om-replay{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:6px 12px;border:0;border-radius:999px;background:#ede9fe;color:#4c1d95;font:inherit;font-size:.78rem;font-weight:700;cursor:pointer}',
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

  function sayBtn(i) { return TTS ? '<button type="button" class="om-say" data-om-say="' + i + '" aria-label="Read aloud" title="Read aloud">' + svg('speaker', 18) + '</button>' : ''; }

  function chatHtml() {
    if (!chat.length) return '<div class="om-hello"><div style="font-size:34px">👋</div>Ask about your sales, dishes, customers or payments, in English, Hindi or Hinglish. Every number comes from your own orders.</div>';
    return chat.map(function (x, i) { return '<div class="om-q">' + esc(x.q) + '</div><div class="om-a">' + waFmt(x.a) + sayBtn(i) + '</div>'; }).join('');
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

    var ask = '<div class="om-card"><h3 class="om-chat-h">' + ic('forum') + 'Ask your manager' +
        (SR ? '<button type="button" class="om-vbtn" data-om="voice">' + svg('wave', 16) + 'Voice mode</button>' : '') +
        (chat.length ? '<button type="button" class="om-clear" data-om="clear"' + (SR ? ' style="margin-left:10px"' : '') + '>Clear</button>' : '') + '</h3>' +
      '<p class="om-sub">Same answers as on WhatsApp. Ask in English, हिंदी or Hinglish' + (SR ? ', by typing or by voice' : '') + '.</p>' +
      '<div class="om-chatbox"><div class="om-msgs" id="omMsgs">' + chatHtml() + '</div>' +
      (SR ? '<div class="om-voice" id="omVoice"' + (voice.on ? '' : ' hidden') + '>' +
        '<div class="om-vrow om-vtop"><button type="button" data-om-vlang="hi-IN"' + (voice.lang === 'hi-IN' ? ' class="on"' : '') + '>हिंदी / Hinglish</button>' +
          '<button type="button" data-om-vlang="en-IN"' + (voice.lang === 'en-IN' ? ' class="on"' : '') + '>English</button>' +
          '<button type="button" data-om-vname="Kore"' + (voice.name === 'Kore' ? ' class="on"' : '') + '>Female voice</button>' +
          '<button type="button" data-om-vname="Charon"' + (voice.name === 'Charon' ? ' class="on"' : '') + '>Male voice</button>' +
          '<button type="button" class="om-vend" data-om="voice">Close</button></div>' +
        '<div class="om-vgap"></div>' +
        '<button type="button" class="om-orb" id="omOrb" data-om="orb" aria-label="Speak">' + svg('mic', 52) + '</button>' +
        '<b id="omVState">Tap the circle and speak</b>' +
        '<div class="om-heard" id="omHeard" hidden><textarea id="omVQ" rows="2" placeholder="Your question"></textarea>' +
          '<div class="om-vact"><button type="button" data-om="vagain">Again</button><button type="button" class="om-vsend" data-om="vsend">Send</button></div></div>' +
        '<div class="om-vans" id="omVAns" hidden></div>' +
        '<div class="om-vgap"></div></div>' : '') +
      '<div class="om-chips">' + ['aaj kitna bika?', 'compare this week with last week', 'top dishes this month', 'how many Paneer Tikka sold this week', 'busiest hours', 'cash vs UPI this week', 'least sold dishes', 'new customers this month', 'what should I do to increase sales?', 'कल कितने ऑर्डर कैंसिल हुए?']
        .map(function (q) { return '<button type="button" data-om-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') + '</div>' +
      '<form class="om-ask" id="omAskForm"><input id="omAsk" placeholder="Ask anything… e.g. sales this week" maxlength="300" autocomplete="off" enterkeyhint="send">' +
      (SR ? '<button type="button" class="om-mic" id="omMic" data-om="mic" aria-label="Speak your question" title="Speak">' + svg('mic', 22) + '</button>' : '') +
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

  function ask(q, opts) {
    q = String(q || '').trim();
    opts = opts || {};
    if (!q || busy) return Promise.resolve(null);
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
    return post({ action: 'ask', text: q, voice: opts.voice ? 1 : 0 }).then(function (d) {
      var a = d.success ? d.answer : (d.message || 'Could not answer. Try again.');
      if (d.success) { chat.push({ q: q, a: a, s: d.speech || '' }); saveChat(); }
      var w = $('omWait'); if (w) { w.removeAttribute('id'); w.innerHTML = waFmt(a) + (d.success ? sayBtn(chat.length - 1) : ''); }
      return { answer: a, speech: d.speech || a, ok: !!d.success };
    }).catch(function () {
      var w = $('omWait'); if (w) { w.removeAttribute('id'); w.textContent = 'No connection. Try again.'; }
      return { answer: 'No connection. Try again.', speech: '', ok: false };
    }).finally(function () {
      busy = false;
      var b = document.querySelector('.om-send'); if (b) b.disabled = false;
      scrollChat();
    });
  }

  // ── voice ──
  var VSTATE = { idle: 'Tap the circle and speak', listen: 'Listening… tap the circle when you\'re done', heard: 'Check your question, then press Send',
    think: 'Thinking…', speak: 'Speaking… tap the circle to stop' };
  function vState(state) {
    voice.state = state;
    var o = $('omOrb'), st = $('omVState');
    if (o) { o.className = 'om-orb' + ({ listen: ' listen', think: ' think', speak: ' speak' }[state] || ''); o.innerHTML = svg(state === 'speak' ? 'wave' : state === 'think' ? 'spin' : 'mic', 52); }
    if (st) st.textContent = VSTATE[state] || VSTATE.idle;
    var h = $('omHeard'); if (h) h.hidden = !(state === 'listen' || state === 'heard');
    var q = $('omVQ'); if (q) q.readOnly = state === 'listen';
  }

  // what the voice should say: no emoji or symbols, ₹ read as rupees
  function speechText(t, hindi) {
    return String(t).replace(/\p{Extended_Pictographic}|\uFE0F|\u20E3|[▲▼•*_]/gu, '').replace(/—|–/g, ', ')
      .replace(/₹\s?/g, hindi ? 'रुपये ' : 'rupees ').replace(/\n+/g, '. ').replace(/\s+/g, ' ').replace(/\.\s*\./g, '.').replace(/:\s*\./g, ':').replace(/\s+,/g, ',').trim();
  }

  function pickVoice(lang) {
    var vs = window.speechSynthesis.getVoices() || [], l = lang.toLowerCase();
    var norm = function (v) { return String(v.lang || '').replace('_', '-').toLowerCase(); };
    return vs.filter(function (v) { return norm(v) === l; })[0] || vs.filter(function (v) { return norm(v).indexOf(l.slice(0, 2)) === 0; })[0] || null;
  }

  // the phone's own voice (fallback)
  function phoneVoice(text) {
    if (!TTS) return Promise.resolve();
    return new Promise(function (done) {
      var hindi = /[\u0900-\u097F]/.test(text), lang = hindi ? 'hi-IN' : 'en-IN';
      window.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(speechText(text, hindi));
      u.lang = lang; u.rate = 1; var v = pickVoice(lang); if (v) u.voice = v;
      u.onend = done; u.onerror = done;
      window.speechSynthesis.speak(u);
    });
  }

  // a phone only plays sound a page starts after a tap: play silence on the tap (Send) so the answer can play later
  function unlockAudio() {
    if (!voice.audio) voice.audio = new Audio();
    try { voice.audio.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA='; var p = voice.audio.play(); if (p && p.catch) p.catch(function () {}); } catch (e) { /* ignore */ }
  }

  function stopSpeaking() {
    if (voice.audio) { try { voice.audio.pause(); } catch (e) { /* ignore */ } }
    if (TTS) window.speechSynthesis.cancel();
    voice.speaking = false;
  }

  // say it in Gemini's natural voice; the phone's own voice if that isn't available
  function speak(text) {
    text = String(text || '').trim();
    if (!text) return Promise.resolve();
    stopSpeaking();
    voice.speaking = true;
    if (voice.on) vState('speak');
    var key = voice.name + '|' + text;
    var getAudio = voice.cache[key] ? Promise.resolve(voice.cache[key])
      : post({ action: 'tts', text: speechText(text, /[\u0900-\u097F]/.test(text)), voice_name: voice.name }).then(function (d) { if (d.success && d.audio) { voice.cache[key] = d.audio; return d.audio; } return null; }).catch(function () { return null; });
    return getAudio.then(function (src) {
      if (!voice.speaking) return;   // stopped while it was loading
      if (!src) return phoneVoice(text);
      return new Promise(function (done) {
        if (!voice.audio) voice.audio = new Audio();
        var a = voice.audio;
        a.onended = done; a.onerror = function () { phoneVoice(text).then(done); };
        a.src = src;
        var p = a.play();
        if (p && p.catch) p.catch(function () { phoneVoice(text).then(done); });
      });
    }).then(function () {
      voice.speaking = false;
      if (voice.on && voice.state === 'speak') vState('idle');
    });
  }

  function stopListening(keep) {
    try { if (voice.rec) { if (keep) voice.rec.stop(); else voice.rec.abort(); } } catch (e) { /* already stopped */ }
    if (!keep) { voice.rec = null; voice.listening = false; }
    var m = $('omMic'); if (m && !keep) m.classList.remove('on');
  }

  // speech → text. target 'input' fills the chat box; 'voice' fills the voice screen. Nothing is sent: you press Send.
  function listen(target) {
    if (!SR) { notify('Voice needs Chrome on Android or a computer', 'error'); return; }
    if (voice.listening) { stopListening(true); return; }   // tap again = done talking
    stopSpeaking();
    var rec = new SR(), heard = '';
    voice.rec = rec; voice.listening = true;
    rec.lang = voice.lang; rec.interimResults = true; rec.maxAlternatives = 1; rec.continuous = false;
    var out = function (txt) { if (target === 'voice') { var q = $('omVQ'); if (q) q.value = txt; } else { var inp = $('omAsk'); if (inp) inp.value = txt; } };
    if (target === 'voice') { out(''); vState('listen'); } else { var mic = $('omMic'); if (mic) mic.classList.add('on'); }
    rec.onresult = function (e) {
      // Android Chrome repeats earlier words in every result ("aaj aaj kitna aaj kitna bika"): use only the latest one there
      var txt = '';
      if (ANDROID) txt = e.results[e.results.length - 1][0].transcript;
      else for (var i = 0; i < e.results.length; i++) txt += e.results[i][0].transcript;
      heard = txt.replace(/\s+/g, ' ').trim();
      out(heard);
    };
    rec.onerror = function (e) {
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') notify('Allow the microphone for this site (tap the lock icon next to the address)', 'error');
      else if (e.error === 'network') notify('Voice needs an internet connection', 'error');
      else if (e.error === 'language-not-supported') notify('This phone can\'t listen in that language. Try the other one.', 'error');
    };
    rec.onend = function () {
      voice.listening = false; voice.rec = null;
      var m = $('omMic'); if (m) m.classList.remove('on');
      if (target === 'voice') {
        if (heard) { vState('heard'); } else { vState('idle'); var st = $('omVState'); if (st) st.textContent = 'Didn\'t catch that. Tap the circle and try again.'; }
      } else {
        var inp = $('omAsk'); if (inp) { inp.focus(); if (!heard) notify('Didn\'t catch that. Tap the mic and try again.', 'error'); }
      }
    };
    try { rec.start(); } catch (e) { voice.listening = false; notify('The microphone is busy. Try again.', 'error'); }
  }

  // Send in voice mode: ask, show the answer, speak it. Then wait for the next tap.
  function voiceSend() {
    var q = ($('omVQ') || {}).value || '';
    q = q.trim();
    if (!q) { listen('voice'); return; }
    unlockAudio();
    vState('think');
    var box = $('omVAns'); if (box) { box.hidden = true; box.innerHTML = ''; }
    ask(q, { voice: true }).then(function (r) {
      if (!r) { vState('idle'); return; }
      voice.last = r;
      if (box) { box.innerHTML = waFmt(r.answer) + (r.ok ? '<br><button type="button" class="om-replay" data-om="vreplay">' + svg('speaker', 16) + 'Play again</button>' : ''); box.hidden = false; box.scrollTop = 0; }
      var q2 = $('omVQ'); if (q2) q2.value = '';
      if (r.ok && r.speech) return speak(r.speech);
      vState('idle');
    });
  }

  function toggleVoiceMode() {
    voice.on = !voice.on;
    var ov = $('omVoice');
    if (!voice.on) { stopListening(false); stopSpeaking(); if (ov) ov.hidden = true; return; }
    if (ov) ov.hidden = false;
    var box = $('omVAns'); if (box) box.hidden = true;
    vState('idle');
  }

  function save(extra) {
    var b = document.querySelector('[data-om="save"]');
    if (b) b.disabled = true;
    return post(Object.assign({ action: 'save', enabled: $('omOn').checked ? 1 : 0, send_time: $('omTime').value, language: $('omLang').value, phone: $('omPhone').value, email_on: $('omEmail').checked ? 1 : 0 }, extra || {}))
      .then(function (d) { notify(d.message || (d.success ? 'Saved' : 'Could not save'), d.success ? 'success' : 'error'); if (d.success) load(); else if (b) b.disabled = false; })
      .catch(function () { notify('No connection', 'error'); if (b) b.disabled = false; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-om],[data-om-q],[data-om-run],[data-om-skip],[data-om-hist],[data-om-tab],[data-om-say],[data-om-vlang],[data-om-vname]');
    if (!t || !D || !$('omRoot') || !$('omRoot').contains(t) && !t.closest('.rg-modal, [role="dialog"]')) return;
    if (t.hasAttribute('data-om-tab')) { if (voice.on) toggleVoiceMode(); tab = t.getAttribute('data-om-tab'); draw(); return; }
    if (voice.on && voice.state === 'heard' && t.closest('#omVoice') === null && t.hasAttribute('data-om-q')) toggleVoiceMode();
    if (t.hasAttribute('data-om-say')) { var x = chat[+t.getAttribute('data-om-say')]; if (x) { if (voice.speaking) stopSpeaking(); else { unlockAudio(); speak(x.s || x.a); } } return; }
    if (t.hasAttribute('data-om-vlang')) {
      voice.lang = t.getAttribute('data-om-vlang'); setPref('omVoiceLang', voice.lang);
      document.querySelectorAll('[data-om-vlang]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-om-vlang') === voice.lang); });
      if (voice.listening) { stopListening(false); listen('voice'); }
      return;
    }
    if (t.hasAttribute('data-om-vname')) {
      voice.name = t.getAttribute('data-om-vname'); setPref('omVoiceName', voice.name);
      document.querySelectorAll('[data-om-vname]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-om-vname') === voice.name); });
      return;
    }
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
    else if (a === 'voice') toggleVoiceMode();
    else if (a === 'orb') { if (voice.speaking) { stopSpeaking(); vState('idle'); } else if (voice.state !== 'think') listen('voice'); }
    else if (a === 'vagain') listen('voice');
    else if (a === 'vsend') voiceSend();
    else if (a === 'vreplay') { if (voice.last) { unlockAudio(); speak(voice.last.speech || voice.last.answer); } }
    else if (a === 'mic') listen('input');
    else if (a === 'preview' || a === 'send') {
      t.disabled = true;
      var old = t.innerHTML; t.innerHTML = svg('spin', 18) + (a === 'send' ? 'Sending…' : 'Making it…');
      post({ action: a }).then(function (d) {
        t.disabled = false; t.innerHTML = old;
        notify(d.message || (d.success ? 'Report ready' : 'Could not make the report'), d.success ? 'success' : 'error');
        if (d.success) { reportOpen = true; load(); }
      }).catch(function () { t.disabled = false; t.innerHTML = old; notify('No connection', 'error'); });
    } else if (a === 'save') save();
  });
  document.addEventListener('submit', function (e) { if (e.target && e.target.id === 'omAskForm') { e.preventDefault(); ask(($('omAsk') || {}).value); } });
  document.addEventListener('change', function (e) { if (e.target && e.target.id === 'omOn') save(); });

  if (TTS) { window.speechSynthesis.getVoices(); window.speechSynthesis.onvoiceschanged = function () { window.speechSynthesis.getVoices(); }; }
  // leaving the page stops the microphone and the voice
  document.addEventListener('visibilitychange', function () { if (document.hidden && (voice.listening || voice.speaking)) { stopListening(false); stopSpeaking(); if (voice.on) vState('idle'); } });

  window.loadOwnerManagerStudio = load;
})();
