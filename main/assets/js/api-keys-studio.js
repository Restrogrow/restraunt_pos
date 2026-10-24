/*
 * Dashboard › API keys (data: api/api_keys_admin.php; the keys: config/api_keys.php; the API: api/v1, docs at /developers).
 *
 * The owner makes a key, ticks what it may do (menu, orders, reports …) and copies it once. The key works on the
 * REST API and on /mcp, so Claude Code, Cursor and other agents can use it. Keys list with Revoke (goes with Undo
 * for a few seconds, assets/js/rg-undo.js). Styles live here (ak-*), so the page needs only #akRoot.
 */
(function () {
  'use strict';
  var API = '../api/api_keys_admin.php';
  var D = null, fresh = null, useTab = 'claude', picked = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function when(s) {
    if (!s) return 'never';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  var CSS = [
    '#akRoot{max-width:900px}',
    '.ak-hero{display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:20px;border-radius:18px;margin-bottom:16px;color:#fff;background:radial-gradient(90% 140% at 100% 0%,#ff8a5c,transparent 55%),linear-gradient(135deg,#1f2937,#111827)}',
    '.ak-hero h2{margin:0 0 6px;font-size:1.2rem}.ak-hero p{margin:0;opacity:.85;font-size:.9rem;line-height:1.5}.ak-hero-l{flex:1;min-width:220px}',
    '.ak-hero a{color:#fff}.ak-hero .ak-btn{background:#fff;color:#111827;text-decoration:none}',
    '.ak-card{background:#fff;border:1px solid #eef0f3;border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}',
    '.ak-card h3{margin:0 0 12px;font-size:1rem;display:flex;align-items:center;gap:8px}.ak-card h3 .material-symbols-rounded{color:#ff6b35;font-size:20px}',
    '.ak-note{display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:.85rem;line-height:1.45;margin-bottom:14px}.ak-note .material-symbols-rounded{font-size:18px}',
    '.ak-ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}',
    '.ak-small{font-size:.8rem;color:#6b7280}',
    '.ak-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}.ak-row label{display:flex;flex-direction:column;gap:5px;font-size:.82rem;font-weight:600;color:#374151;flex:1;min-width:180px}',
    '.ak-row input,.ak-row select{padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:10px;font:inherit;font-size:.9rem;color:#111827;background:#fff}',
    '.ak-perms{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;margin:6px 0 12px}',
    '.ak-perm{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1.5px solid #eef0f3;border-radius:12px;cursor:pointer;font-size:.86rem;color:#374151;line-height:1.4}',
    '.ak-perm.on{border-color:#ff6b35;background:#fff7f3}.ak-perm input{margin-top:3px;accent-color:#ff6b35}.ak-perm b{display:block;color:#111827}.ak-perm code{font-size:.72rem;color:#9ca3af}',
    '.ak-presets{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:.8rem;color:#6b7280}',
    '.ak-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;padding:9px 14px;font:inherit;font-weight:600;font-size:.86rem;cursor:pointer;background:#ff6b35;color:#fff}.ak-btn .material-symbols-rounded{font-size:18px}',
    '.ak-btn.ghost{background:#f3f4f6;color:#374151}.ak-btn.danger{background:#fef2f2;color:#b91c1c}.ak-btn.sm{padding:5px 10px;font-size:.78rem}.ak-btn[disabled]{opacity:.6;cursor:default}',
    '.ak-code{display:flex;gap:8px;align-items:flex-start;margin:6px 0;flex-wrap:wrap}',
    '.ak-code pre{flex:1;min-width:0;margin:0;white-space:pre-wrap;overflow-wrap:anywhere;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:11px 13px;font-size:.8rem;line-height:1.5}',
    '.ak-key pre{background:#f9fafb;color:#111827;border:1.5px dashed #ff6b35;font-size:.9rem}',
    '.ak-k{display:flex;flex-wrap:wrap;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #f3f4f6}.ak-k:first-child{border-top:0;padding-top:0}',
    '.ak-k-ic{width:40px;height:40px;border-radius:12px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#111827;flex:none}',
    '.ak-k-t{flex:1;min-width:0}.ak-k-t>div:first-child{font-weight:600;color:#111827}.ak-k.off{opacity:.55}',
    '.ak-chips{display:flex;flex-wrap:wrap;gap:4px;margin:5px 0 3px}.ak-chip{background:#f3f4f6;border-radius:999px;padding:2px 8px;font-size:.72rem;color:#374151}.ak-chip.w{background:#fff1ea;color:#c2410c}',
    '.ak-tabs{display:inline-flex;flex-wrap:wrap;background:#f3f4f6;border-radius:12px;padding:4px;margin-bottom:12px}',
    '.ak-tab{border:0;background:none;padding:7px 14px;border-radius:9px;font:inherit;font-weight:600;font-size:.86rem;color:#4b5563;cursor:pointer}.ak-tab.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.1)}',
    '.ak-empty{color:#6b7280;font-size:.9rem;padding:4px 0}',
    '.ak-safe{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}.ak-safe div{display:flex;gap:8px;font-size:.86rem;color:#374151;line-height:1.45}.ak-safe .material-symbols-rounded{color:#16a34a;font-size:19px}'
  ].join('');

  function codeBox(text, id) {
    return '<div class="ak-code"><pre id="' + id + '">' + esc(text) + '</pre><button class="ak-btn ghost sm" type="button" data-ak-copy="' + id + '">' + ic('content_copy') + 'Copy</button></div>';
  }

  function snippets(key) {
    var k = key || 'rg_live_YOUR_KEY';
    if (useTab === 'claude') {
      return '<p class="ak-small">Run this once in a terminal. Then ask Claude Code things like “switch off Paneer Tikka” or “what sold best this week?”. It can only use the permissions ticked on the key.</p>'
        + codeBox('claude mcp add --transport http restrogrow ' + D.mcp_url + ' --header "Authorization: Bearer ' + k + '"', 'akSnip');
    }
    if (useTab === 'mcp') {
      return '<p class="ak-small">Cursor, Windsurf, VS Code and other MCP apps: add this server to their MCP settings (for Cursor: <b>.cursor/mcp.json</b>).</p>'
        + codeBox(JSON.stringify({ mcpServers: { restrogrow: { url: D.mcp_url, headers: { Authorization: 'Bearer ' + k } } } }, null, 2), 'akSnip');
    }
    if (useTab === 'js') {
      return '<p class="ak-small">From your own server (keep the key secret: never put it in a public web page or app).</p>'
        + codeBox("const res = await fetch('" + D.base_url + "/menu?search=paneer', {\n  headers: { Authorization: 'Bearer " + k + "' }\n});\nconst { data } = await res.json();\nconsole.log(data.dishes);", 'akSnip');
    }
    return '<p class="ak-small">Check the key works, then see every endpoint in the <a href="' + esc(D.docs_url) + '" target="_blank" rel="noopener">API docs</a>.</p>'
      + codeBox('curl ' + D.base_url + '/me \\\n  -H "Authorization: Bearer ' + k + '"', 'akSnip');
  }

  function permChips(perms) {
    return perms.map(function (p) { return '<span class="ak-chip' + (/:write$/.test(p) ? ' w' : '') + '">' + esc(p) + '</span>'; }).join('');
  }

  function keysList() {
    if (!D.keys.length) return '<div class="ak-empty">No keys yet.' + (D.is_owner ? ' Create one above.' : '') + '</div>';
    return D.keys.map(function (k) {
      var live = k.status === 'active';
      return '<div class="ak-k' + (live ? '' : ' off') + '"><div class="ak-k-ic">' + ic('key') + '</div><div class="ak-k-t"><div>' + esc(k.name) + (live ? '' : ' · ' + esc(k.status)) + '</div>'
        + '<div class="ak-small"><code>' + esc(k.key_hint) + '</code></div><div class="ak-chips">' + permChips(k.perms) + '</div>'
        + '<div class="ak-small">Created ' + esc(when(k.created_at)) + ' · last used ' + esc(when(k.last_used_at)) + (k.expires_at ? ' · ' + (live ? 'expires ' : 'expired ') + esc(when(k.expires_at)) : '') + '</div></div>'
        + (D.is_owner && live ? '<button class="ak-btn danger" type="button" data-ak-revoke="' + k.id + '">' + ic('block') + 'Revoke</button>' : '') + '</div>';
    }).join('');
  }

  function createCard() {
    if (!D.is_owner) return '<div class="ak-note">' + ic('lock') + '<div>Only the restaurant <b>owner</b> can create or revoke API keys.</div></div>';
    if (fresh) {
      return '<div class="ak-card"><h3>' + ic('check_circle') + 'Key created: ' + esc(fresh.row ? fresh.row.name : '') + '</h3>'
        + '<div class="ak-note">' + ic('warning') + '<div><b>Copy it now.</b> For your safety it is shown only this once. Lost it? Revoke it and create a new one.</div></div>'
        + '<div class="ak-key">' + codeBox(fresh.key, 'akNewKey') + '</div>'
        + '<div class="ak-chips" style="margin:8px 0 14px">' + permChips(fresh.row ? fresh.row.perms : []) + '</div>'
        + '<button class="ak-btn ghost" type="button" data-ak-done>' + ic('done') + 'I copied it</button></div>';
    }
    var sel = picked || ['restaurant:read', 'menu:read', 'orders:read', 'reports:read'];
    return '<div class="ak-card"><h3>' + ic('add') + 'Create a key</h3>'
      + '<div class="ak-row"><label>Name<input id="akName" maxlength="80" placeholder="e.g. Claude Code, Stock sync, My developer"></label>'
      + '<label>Works for<select id="akDays"><option value="0">Until I revoke it</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">1 year</option></select></label></div>'
      + '<div class="ak-presets">Permissions: <button class="ak-btn ghost sm" type="button" data-ak-preset="read">Read only</button><button class="ak-btn ghost sm" type="button" data-ak-preset="all">Everything</button></div>'
      + '<div class="ak-perms">' + D.perms.map(function (p) {
        var on = sel.indexOf(p.id) >= 0;
        return '<label class="ak-perm' + (on ? ' on' : '') + '"><input type="checkbox" value="' + esc(p.id) + '"' + (on ? ' checked' : '') + '><span><b>' + esc(p.label) + '</b>' + esc(p.what) + '<br><code>' + esc(p.id) + '</code></span></label>';
      }).join('') + '</div>'
      + '<button class="ak-btn" type="button" data-ak-create>' + ic('key') + 'Create key</button></div>';
  }

  function draw() {
    var tabs = [['claude', 'Claude Code'], ['mcp', 'Cursor & MCP apps'], ['curl', 'curl'], ['js', 'JavaScript']];
    $('akRoot').innerHTML = '<style>' + CSS + '</style>'
      + '<div class="ak-hero"><div class="ak-hero-l"><h2>Connect your own code and AI agents</h2><p>An API key lets a developer, Claude Code, Cursor or any other agent read and change your menu, orders and reports. You choose exactly what each key may do.</p></div>'
      + '<a class="ak-btn" href="' + esc(D.docs_url) + '" target="_blank" rel="noopener">' + ic('menu_book') + 'API docs</a></div>'
      + createCard()
      + '<div class="ak-card"><h3>' + ic('key') + 'Your keys</h3><div id="akKeys">' + keysList() + '</div></div>'
      + '<div class="ak-card"><h3>' + ic('code') + 'Use a key</h3><div class="ak-tabs">' + tabs.map(function (t) {
        return '<button class="ak-tab' + (useTab === t[0] ? ' on' : '') + '" type="button" data-ak-tab="' + t[0] + '">' + t[1] + '</button>';
      }).join('') + '</div>' + snippets(fresh && fresh.key)
      + '<p class="ak-small" style="margin:10px 0 0">REST API: <code>' + esc(D.base_url) + '</code> · MCP: <code>' + esc(D.mcp_url) + '</code></p></div>'
      + '<div class="ak-card"><h3>' + ic('shield') + 'Safe by design</h3><div class="ak-safe">'
      + '<div>' + ic('check_circle') + 'A key only sees and changes this restaurant.</div>'
      + '<div>' + ic('check_circle') + 'It can do only what you ticked. Read-only keys cannot change anything.</div>'
      + '<div>' + ic('check_circle') + 'Every change shows in your Activity log with the key\'s name.</div>'
      + '<div>' + ic('check_circle') + 'Revoke a key and it stops working at once.</div>'
      + '</div></div>';
  }

  function load() {
    var root = $('akRoot');
    if (!root) return;
    if (!D) root.innerHTML = '<div class="ak-empty">Loading…</div>';
    fetch(API, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success) { root.innerHTML = '<div class="ak-empty">' + esc(d.message || 'Could not load.') + '</div>'; return; }
      D = d;
      draw();
    }).catch(function () { root.innerHTML = '<div class="ak-empty">Could not load. Check your internet and try again.</div>'; });
  }

  function currentPicks() {
    return Array.prototype.map.call(document.querySelectorAll('#akRoot .ak-perm input:checked'), function (i) { return i.value; });
  }

  function copy(id) {
    var el = $(id);
    if (!el) return;
    var done = function () { notify('Copied', 'success'); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(el.textContent).then(done, function () { notify('Copy failed: select the text and copy it', 'error'); });
    else { var r = document.createRange(); r.selectNodeContents(el); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); try { document.execCommand('copy'); done(); } catch (x) { /* selected for the owner to copy */ } }
  }

  document.addEventListener('change', function (e) {
    var box = e.target.closest && e.target.closest('#akRoot .ak-perm');
    if (box) { box.classList.toggle('on', e.target.checked); picked = currentPicks(); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-ak-tab],[data-ak-copy],[data-ak-revoke],[data-ak-create],[data-ak-preset],[data-ak-done]');
    if (!t || !$('akRoot') || !$('akRoot').contains(t)) return;
    if (t.hasAttribute('data-ak-tab')) { useTab = t.getAttribute('data-ak-tab'); draw(); return; }
    if (t.hasAttribute('data-ak-copy')) { copy(t.getAttribute('data-ak-copy')); return; }
    if (t.hasAttribute('data-ak-done')) { fresh = null; draw(); return; }
    if (t.hasAttribute('data-ak-preset')) {
      var all = t.getAttribute('data-ak-preset') === 'all';
      picked = D.perms.map(function (p) { return p.id; }).filter(function (id) { return all || /:read$/.test(id); });
      var name = $('akName') ? $('akName').value : '', days = $('akDays') ? $('akDays').value : '0';
      draw();
      $('akName').value = name; $('akDays').value = days;
      return;
    }
    if (t.hasAttribute('data-ak-create')) {
      var perms = currentPicks();
      if (!$('akName').value.trim()) { notify('Give the key a name', 'error'); $('akName').focus(); return; }
      if (!perms.length) { notify('Tick at least one permission', 'error'); return; }
      t.disabled = true;
      fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', name: $('akName').value, perms: perms, days: parseInt($('akDays').value, 10) || 0 }) })
        .then(function (r) { return r.json(); }).then(function (d) {
          t.disabled = false;
          if (!d.success) { notify(d.message || 'Could not create the key', 'error'); return; }
          fresh = { key: d.key, row: d.row };
          picked = null;
          if (d.row) D.keys.unshift(d.row);
          draw();
          notify('Key created. Copy it now.', 'success');
        }).catch(function () { t.disabled = false; notify('Could not create the key. Check your internet.', 'error'); });
      return;
    }
    if (t.hasAttribute('data-ak-revoke')) {
      var id = parseInt(t.getAttribute('data-ak-revoke'), 10);
      var k = D.keys.filter(function (x) { return x.id === id; })[0];
      rgUndo({
        html: rgUndo.label('Revoked', k ? k.name : 'key'),
        hide: t.closest('.ak-k'),
        request: { url: API, json: { action: 'revoke', id: id } },
        onDone: function () { load(); },
        onError: function (msg) { notify(msg, 'error'); load(); }
      });
    }
  });

  window.loadApiKeys = load;
})();
