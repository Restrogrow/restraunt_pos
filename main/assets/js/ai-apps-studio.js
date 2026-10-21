/*
 * Growth › ChatGPT & Claude (data: api/ai_apps_admin.php; the app itself: main/mcp, config/mcp_oauth.php).
 *
 * How to connect RestroGrow inside ChatGPT or Claude (steps with the address to copy), what to ask, and the
 * apps connected to this restaurant with Disconnect (goes with Undo for a few seconds, assets/js/rg-undo.js).
 * Styles live here (aia-*), so the page needs only #aiaRoot.
 */
(function () {
  'use strict';
  var API = '../api/ai_apps_admin.php';
  var D = null, tab = 'chatgpt';

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function when(s) {
    if (!s) return 'not yet';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  var CSS = [
    '#aiaRoot{max-width:860px}',
    '.aia-hero{display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:20px;border-radius:18px;margin-bottom:16px;color:#fff;background:radial-gradient(90% 140% at 100% 0%,#ff8a5c,transparent 55%),linear-gradient(135deg,#1f2937,#111827)}',
    '.aia-hero h2{margin:0 0 6px;font-size:1.2rem}.aia-hero p{margin:0;opacity:.85;font-size:.9rem;line-height:1.5}.aia-hero-l{flex:1;min-width:220px}',
    '.aia-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}.aia-chip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:5px 11px;font-size:.8rem}',
    '.aia-card{background:#fff;border:1px solid #eef0f3;border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}',
    '.aia-card h3{margin:0 0 12px;font-size:1rem;display:flex;align-items:center;gap:8px}.aia-card h3 .material-symbols-rounded{color:#ff6b35;font-size:20px}',
    '.aia-tabs{display:inline-flex;background:#f3f4f6;border-radius:12px;padding:4px;margin-bottom:14px}',
    '.aia-tab{border:0;background:none;padding:8px 18px;border-radius:9px;font:inherit;font-weight:600;font-size:.9rem;color:#4b5563;cursor:pointer}.aia-tab.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.1)}',
    '.aia-note{display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:.85rem;line-height:1.45;margin-bottom:14px}.aia-note .material-symbols-rounded{font-size:18px}',
    '.aia-steps{list-style:none;margin:0;padding:0;counter-reset:s}',
    '.aia-steps li{position:relative;padding:0 0 14px 40px;font-size:.92rem;line-height:1.55;color:#374151;counter-increment:s}',
    '.aia-steps li:before{content:counter(s);position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;background:#ff6b35;color:#fff;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center}',
    '.aia-steps b{color:#111827}.aia-small{font-size:.8rem;color:#6b7280}',
    '.aia-url{display:flex;gap:8px;align-items:center;margin:8px 0 4px;flex-wrap:wrap}',
    '.aia-url code{flex:1;min-width:0;overflow-wrap:anywhere;background:#f9fafb;border:1.5px dashed #d1d5db;border-radius:10px;padding:9px 12px;font-size:.9rem;color:#111827}',
    '.aia-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;padding:9px 14px;font:inherit;font-weight:600;font-size:.86rem;cursor:pointer;background:#ff6b35;color:#fff}.aia-btn .material-symbols-rounded{font-size:18px}',
    '.aia-btn.ghost{background:#f3f4f6;color:#374151}.aia-btn.danger{background:#fef2f2;color:#b91c1c}',
    '.aia-asks{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}',
    '.aia-ask{background:#f9fafb;border:1px solid #eef0f3;border-radius:12px;padding:10px 12px;font-size:.88rem;color:#374151}.aia-ask b{display:block;font-size:.75rem;color:#9ca3af;font-weight:600;margin-bottom:2px;text-transform:uppercase;letter-spacing:.03em}',
    '.aia-conn{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #f3f4f6}.aia-conn:first-child{border-top:0;padding-top:0}',
    '.aia-conn-ic{width:40px;height:40px;border-radius:12px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-weight:800;color:#111827;flex:none}',
    '.aia-conn-t{flex:1;min-width:0}.aia-conn-t div:first-child{font-weight:600;color:#111827}',
    '.aia-empty{color:#6b7280;font-size:.9rem;padding:4px 0}',
    '.aia-safe{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}.aia-safe div{display:flex;gap:8px;font-size:.86rem;color:#374151;line-height:1.45}.aia-safe .material-symbols-rounded{color:#16a34a;font-size:19px}'
  ].join('');

  function urlBox() {
    return '<div class="aia-url"><code id="aiaUrl">' + esc(D.url) + '</code><button class="aia-btn" type="button" data-aia-copy>' + ic('content_copy') + 'Copy</button></div>';
  }

  function steps() {
    if (tab === 'claude') {
      return '<div class="aia-note">' + ic('info') + '<div>Works on claude.ai and the Claude apps. Custom connectors may need a paid Claude plan.</div></div>'
        + '<ol class="aia-steps">'
        + '<li>Open <b>Claude</b> › <b>Settings</b> › <b>Connectors</b> › <b>Add custom connector</b>.</li>'
        + '<li>Name: <b>RestroGrow</b>. Remote MCP server URL:' + urlBox() + '<span class="aia-small">Leave the advanced settings empty.</span> Press <b>Add</b>, then <b>Connect</b>.</li>'
        + '<li>Claude opens RestroGrow: log in with your <b>owner</b> account and press <b>Allow</b>.</li>'
        + '<li>In a chat, open the tools menu and make sure <b>RestroGrow</b> is switched on. Then just ask.</li>'
        + '</ol>';
    }
    return '<div class="aia-note">' + ic('info') + '<div>Needs a <b>paid ChatGPT plan</b> (Plus, Pro, Business, Enterprise or Edu): Developer mode is not on the free plan. ChatGPT renames its menus now and then; if a name below looks different, look for <b>Apps</b> or <b>Connectors</b>.</div></div>'
      + '<ol class="aia-steps">'
      + '<li>Open <b>ChatGPT</b> › <b>Settings</b> › <b>Apps &amp; Connectors</b>. At the bottom, open <b>Advanced settings</b> and turn on <b>Developer mode</b>.</li>'
      + '<li>Back in Apps &amp; Connectors press <b>Create</b>. Name: <b>RestroGrow</b>. MCP server URL:' + urlBox() + 'Authentication: <b>OAuth</b>. Tick <b>I trust this application</b> and press <b>Create</b>.</li>'
      + '<li>ChatGPT opens RestroGrow: log in with your <b>owner</b> account and press <b>Allow</b>.</li>'
      + '<li>Start a new chat, press <b>+</b> › <b>More</b> (or <b>Developer mode</b>) › <b>RestroGrow</b>. Then just ask.</li>'
      + '</ol>';
  }

  function connections() {
    if (!D.connections.length) return '<div class="aia-empty">Nothing connected yet. Follow the steps above, then come back here: the app shows up after you press Allow.</div>';
    return D.connections.map(function (c) {
      return '<div class="aia-conn"><div class="aia-conn-ic">' + esc(c.label.charAt(0)) + '</div><div class="aia-conn-t"><div>' + esc(c.label) + '</div>'
        + '<div class="aia-small">Connected ' + esc(when(c.since)) + ' · last used ' + esc(when(c.last_used)) + '</div></div>'
        + (D.is_owner ? '<button class="aia-btn danger" type="button" data-aia-off="' + esc(c.client_id) + '">' + ic('link_off') + 'Disconnect</button>' : '') + '</div>';
    }).join('');
  }

  function draw() {
    var asks = [['Sales', 'How were sales this week compared to last week?'], ['Dishes', 'What are my top 5 dishes this month?'], ['One dish', 'How many Paneer Tikka did I sell this month?'],
      ['Orders', "Show today's open orders"], ['Menu', 'Switch off Paneer Tikka, we ran out'], ['New dish', 'Add Mango Lassi for 129 to Beverages'],
      ['Order', 'Accept the newest order, ready in 20 minutes'], ['Advice', 'What should I do to increase sales?']];
    $('aiaRoot').innerHTML = '<style>' + CSS + '</style>'
      + '<div class="aia-hero"><div class="aia-hero-l"><h2>Talk to your restaurant</h2><p>Connect RestroGrow to ChatGPT or Claude once. Then ask about sales, change the menu or handle orders in plain words, in English, Hindi or Hinglish.</p>'
      + '<div class="aia-chips"><span class="aia-chip">“aaj kitna bika?”</span><span class="aia-chip">“switch off Chilli Paneer”</span><span class="aia-chip">“top dishes this month”</span></div></div></div>'
      + (D.is_owner ? '' : '<div class="aia-note">' + ic('lock') + '<div>Only the restaurant <b>owner</b> can connect: ChatGPT asks for the owner login.</div></div>')
      + '<div class="aia-card"><h3>' + ic('add_link') + 'Connect</h3><div class="aia-tabs"><button class="aia-tab' + (tab === 'chatgpt' ? ' on' : '') + '" type="button" data-aia-tab="chatgpt">ChatGPT</button>'
      + '<button class="aia-tab' + (tab === 'claude' ? ' on' : '') + '" type="button" data-aia-tab="claude">Claude</button></div>' + steps() + '</div>'
      + '<div class="aia-card"><h3>' + ic('forum') + 'Try asking</h3><div class="aia-asks">' + asks.map(function (a) { return '<div class="aia-ask"><b>' + esc(a[0]) + '</b>' + esc(a[1]) + '</div>'; }).join('') + '</div></div>'
      + '<div class="aia-card"><h3>' + ic('link') + 'Connected apps</h3><div id="aiaConns">' + connections() + '</div></div>'
      + '<div class="aia-card"><h3>' + ic('shield') + 'Safe by design</h3><div class="aia-safe">'
      + '<div>' + ic('check_circle') + 'It only sees and changes this restaurant.</div>'
      + '<div>' + ic('check_circle') + 'ChatGPT and Claude ask you before changing a price, a dish or an order.</div>'
      + '<div>' + ic('check_circle') + 'Every change shows in your Activity log with the app\'s name.</div>'
      + '<div>' + ic('check_circle') + 'Changing your password disconnects every app at once.</div>'
      + '</div></div>';
  }

  function load() {
    var root = $('aiaRoot');
    if (!root) return;
    if (!D) root.innerHTML = '<div class="aia-empty">Loading…</div>';
    fetch(API, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="aia-empty">' + esc(d.message || 'Could not load.') + '</div>'; return; }
      D = d;
      draw();
    }).catch(function () { root.innerHTML = '<div class="aia-empty">Could not load. Check your internet and try again.</div>'; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-aia-tab],[data-aia-copy],[data-aia-off]');
    if (!t || !$('aiaRoot') || !$('aiaRoot').contains(t)) return;
    if (t.hasAttribute('data-aia-tab')) { tab = t.getAttribute('data-aia-tab'); draw(); return; }
    if (t.hasAttribute('data-aia-copy')) {
      var done = function () { notify('Address copied', 'success'); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(D.url).then(done, function () { notify('Copy failed: select the address and copy it', 'error'); });
      else { var r = document.createRange(); r.selectNodeContents($('aiaUrl')); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); try { document.execCommand('copy'); done(); } catch (x) { /* selected for the owner to copy */ } }
      return;
    }
    if (t.hasAttribute('data-aia-off')) {
      var id = t.getAttribute('data-aia-off');
      var c = D.connections.filter(function (k) { return k.client_id === id; })[0];
      rgUndo({
        html: rgUndo.label('Disconnected', c ? c.label : 'app'),
        hide: t.closest('.aia-conn'),
        request: { url: API, json: { action: 'disconnect', client_id: id } },
        onDone: function () { load(); },
        onError: function (msg) { notify(msg, 'error'); load(); }
      });
    }
  });

  window.loadAiApps = load;
})();
