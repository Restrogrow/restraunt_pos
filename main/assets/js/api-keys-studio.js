/*
 * Dashboard › API keys (data: api/api_keys_admin.php; the keys: config/api_keys.php; the API: api/v1, docs at /developers).
 *
 * The owner makes a key: name, permissions, valid until (never, 7 / 30 / 90 days, 1 year or a date) and optional
 * allowed IPs, then copies it once. Each key can be edited (same secret), rotated (new secret; the old one ends now,
 * in 1 hour or in 24 hours), paused / resumed and revoked; ended keys can be deleted. Revoke and delete go with Undo
 * for a few seconds (assets/js/rg-undo.js). Styles live here (ak-*), so the page needs only #akRoot.
 */
(function () {
  'use strict';
  var API = '../api/api_keys_admin.php';
  var D = null;
  var fresh = null;       // {key, row, rotated} shown once
  var form = null;        // {mode:'create'|'edit', id, name, perms[], exp:'never'|'7'|'30'|'90'|'365'|'date', date, ips}
  var rotating = null;    // key id with the rotate panel open
  var grace = 0;
  var showEnded = false, useTab = 'claude', busy = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n) { return '<span class="material-symbols-rounded">' + n + '</span>'; }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function day(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function ago(s) {
    if (!s) return 'never';
    var d = new Date(String(s).replace(' ', 'T')), m = Math.round((Date.now() - d) / 60000);
    if (isNaN(m)) return s;
    if (m < 2) return 'just now';
    if (m < 60) return m + ' min ago';
    if (m < 1440) return Math.round(m / 60) + ' h ago';
    return day(s);
  }
  function inDays(n) { var d = new Date(); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function isEnded(k) { return k.status === 'revoked' || k.status === 'expired'; }

  var CSS = [
    '#akRoot{max-width:920px}',
    '.ak-hero{display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:20px;border-radius:18px;margin-bottom:16px;color:#fff;background:radial-gradient(90% 140% at 100% 0%,#ff8a5c,transparent 55%),linear-gradient(135deg,#1f2937,#111827)}',
    '.ak-hero h2{margin:0 0 6px;font-size:1.2rem}.ak-hero p{margin:0;opacity:.85;font-size:.9rem;line-height:1.5}.ak-hero-l{flex:1;min-width:220px}',
    '.ak-hero .ak-btn{background:#fff;color:#111827;text-decoration:none}',
    '.ak-card{background:#fff;border:1px solid #eef0f3;border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.03)}',
    '.ak-card h3{margin:0 0 12px;font-size:1rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ak-card h3 .material-symbols-rounded{color:#ff6b35;font-size:20px}',
    '.ak-card h3 .ak-count{margin-left:auto;font-size:.78rem;font-weight:600;color:#6b7280}',
    '.ak-note{display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:.85rem;line-height:1.45;margin-bottom:14px}.ak-note .material-symbols-rounded{font-size:18px}',
    '.ak-small{font-size:.8rem;color:#6b7280}',
    '.ak-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}.ak-row label{display:flex;flex-direction:column;gap:5px;font-size:.82rem;font-weight:600;color:#374151;flex:1;min-width:180px}',
    '.ak-row input,.ak-row select{padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:10px;font:inherit;font-size:.9rem;color:#111827;background:#fff;width:100%;box-sizing:border-box}',
    '.ak-row .ak-hint{font-weight:400;color:#6b7280;font-size:.76rem}',
    '.ak-perms{border:1px solid #eef0f3;border-radius:12px;margin:6px 0 12px;overflow:hidden}',
    '.ak-prow{display:flex;align-items:center;gap:12px;padding:10px 12px;border-top:1px solid #f3f4f6}.ak-prow:first-child{border-top:0}',
    '.ak-prow-t{flex:1;min-width:0}.ak-prow-t b{display:block;font-size:.88rem;color:#111827}.ak-prow-t span{font-size:.76rem;color:#6b7280}',
    '.ak-perm{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1.5px solid #e5e7eb;border-radius:999px;cursor:pointer;font-size:.8rem;font-weight:600;color:#4b5563;background:#fff;white-space:nowrap}',
    '.ak-perm.on{border-color:#ff6b35;background:#fff7f3;color:#c2410c}.ak-perm input{margin:0;accent-color:#ff6b35}.ak-perm.na{visibility:hidden}',
    '.ak-pcount{font-size:.76rem;color:#6b7280;margin-left:auto}',
    '.ak-presets{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:.8rem;color:#6b7280}',
    '.ak-btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:10px;padding:9px 14px;font:inherit;font-weight:600;font-size:.86rem;cursor:pointer;background:#ff6b35;color:#fff;white-space:nowrap}.ak-btn .material-symbols-rounded{font-size:18px}',
    '.ak-btn.ghost{background:#f3f4f6;color:#374151}.ak-btn.danger{background:#fef2f2;color:#b91c1c}.ak-btn.sm{padding:6px 10px;font-size:.78rem}.ak-btn.sm .material-symbols-rounded{font-size:16px}.ak-btn[disabled]{opacity:.6;cursor:default}',
    '.ak-btns{display:flex;flex-wrap:wrap;gap:6px}',
    '.ak-code{display:flex;gap:8px;align-items:flex-start;margin:6px 0;flex-wrap:wrap}',
    '.ak-code pre{flex:1;min-width:0;margin:0;white-space:pre-wrap;overflow-wrap:anywhere;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:11px 13px;font-size:.8rem;line-height:1.5}',
    '.ak-key pre{background:#f9fafb;color:#111827;border:1.5px dashed #ff6b35;font-size:.9rem}',
    '.ak-k{padding:14px 0;border-top:1px solid #f3f4f6}.ak-k:first-child{border-top:0;padding-top:0}',
    '.ak-k-top{display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px}',
    '.ak-k-ic{width:40px;height:40px;border-radius:12px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#111827;flex:none}',
    '.ak-k-t{flex:1;min-width:200px}.ak-k-name{font-weight:600;color:#111827;display:flex;flex-wrap:wrap;gap:6px;align-items:center}.ak-k.ended .ak-k-t{opacity:.6}',
    '.ak-badge{font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534}',
    '.ak-badge.paused{background:#f3f4f6;color:#4b5563}.ak-badge.soon{background:#fef3c7;color:#92400e}.ak-badge.ended{background:#fee2e2;color:#991b1b}',
    '.ak-chips{display:flex;flex-wrap:wrap;gap:4px;margin:5px 0 3px}.ak-chip{background:#f3f4f6;border-radius:999px;padding:2px 8px;font-size:.72rem;color:#374151}.ak-chip.w{background:#fff1ea;color:#c2410c}',
    '.ak-meta{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.78rem;color:#6b7280;margin-top:3px}.ak-meta span{display:inline-flex;align-items:center;gap:3px}.ak-meta .material-symbols-rounded{font-size:15px}',
    '.ak-panel{margin:12px 0 0 52px;padding:14px;border-radius:12px;background:#fafafa;border:1px solid #eef0f3}',
    '.ak-choice{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 12px}.ak-choice button{border:1.5px solid #e5e7eb;background:#fff;border-radius:10px;padding:8px 12px;font:inherit;font-size:.84rem;cursor:pointer;color:#374151}.ak-choice button.on{border-color:#ff6b35;background:#fff7f3;color:#c2410c;font-weight:600}',
    '.ak-tabs{display:inline-flex;flex-wrap:wrap;background:#f3f4f6;border-radius:12px;padding:4px;margin-bottom:12px}',
    '.ak-tab{border:0;background:none;padding:7px 14px;border-radius:9px;font:inherit;font-weight:600;font-size:.86rem;color:#4b5563;cursor:pointer}.ak-tab.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.1)}',
    '.ak-empty{color:#6b7280;font-size:.9rem;padding:4px 0}',
    '.ak-more{display:block;margin-top:12px;border:0;background:none;color:#6b7280;font:inherit;font-size:.84rem;font-weight:600;cursor:pointer;padding:0}',
    '.ak-safe{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}.ak-safe div{display:flex;gap:8px;font-size:.86rem;color:#374151;line-height:1.45}.ak-safe .material-symbols-rounded{color:#16a34a;font-size:19px}',
    '@media (max-width:640px){.ak-panel{margin-left:0}}'
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

  // one tag per area: "Menu · change" (orange) or "Menu · see"
  function permChips(perms) {
    if (D.perms.every(function (p) { return perms.indexOf(p.id) >= 0; })) return '<span class="ak-chip w">Full access: see and change everything</span>';
    var seen = {}, out = [];
    D.perms.forEach(function (p) {
      if (seen[p.area]) return;
      var w = perms.indexOf(p.area && D.perms.filter(function (x) { return x.area === p.area && /:write$/.test(x.id); }).map(function (x) { return x.id; })[0]) >= 0;
      var r = D.perms.some(function (x) { return x.area === p.area && /:read$/.test(x.id) && perms.indexOf(x.id) >= 0; });
      if (!w && !r) return;
      seen[p.area] = true;
      out.push('<span class="ak-chip' + (w ? ' w' : '') + '">' + esc(p.area) + ' · ' + (w ? 'change' : 'see') + '</span>');
    });
    return out.join('');
  }

  function left(m) {
    if (m < 60) return Math.max(1, m) + ' min';
    if (m < 2880) return Math.round(m / 60) + ' h';
    var d = Math.round(m / 1440);
    return d + (d === 1 ? ' day' : ' days');
  }

  function badge(k) {
    if (k.status === 'revoked') return '<span class="ak-badge ended">Revoked</span>';
    if (k.status === 'expired') return '<span class="ak-badge ended">Expired</span>';
    if (k.status === 'paused') return '<span class="ak-badge paused">Paused</span>';
    var m = k.expires_in_minutes;
    if (k.replaced) return '<span class="ak-badge soon">Old key · stops in ' + left(m) + '</span>';
    if (m !== null && m < 7 * 1440) return '<span class="ak-badge soon">Ends in ' + left(m) + '</span>';
    return '<span class="ak-badge">Active</span>';
  }

  // ---- the create / edit form ----
  function newForm() { return { mode: 'create', id: 0, name: '', perms: ['restaurant:read', 'menu:read', 'orders:read', 'reports:read'], exp: 'never', date: inDays(30), ips: '' }; }
  function editForm(k) {
    return { mode: 'edit', id: k.id, name: k.name, perms: k.perms.slice(), exp: k.expires_at ? 'date' : 'never', date: k.expires_at ? String(k.expires_at).slice(0, 10) : inDays(30), ips: k.allowed_ips.join(', ') };
  }
  function readForm() {
    if (!form || !$('akName')) return;
    form.name = $('akName').value;
    form.exp = $('akExp').value;
    if ($('akDate')) form.date = $('akDate').value;
    form.ips = $('akIps').value;
    form.perms = Array.prototype.map.call(document.querySelectorAll('#akForm .ak-perm input:checked'), function (i) { return i.value; });
  }
  function expiresValue() {
    if (form.exp === 'never') return 'never';
    if (form.exp === 'date') return form.date;
    return inDays(parseInt(form.exp, 10));
  }
  // one row per area: See (…:read) and Change (…:write) side by side
  function permRows(sel) {
    var areas = [], byArea = {};
    D.perms.forEach(function (p) {
      if (!byArea[p.area]) { byArea[p.area] = {}; areas.push(p.area); }
      byArea[p.area][/:write$/.test(p.id) ? 'w' : 'r'] = p;
    });
    var box = function (p, label) {
      if (!p) return '<label class="ak-perm na"><input type="checkbox" disabled>' + label + '</label>';
      var on = sel.indexOf(p.id) >= 0;
      return '<label class="ak-perm' + (on ? ' on' : '') + '" title="' + esc(p.id) + ': ' + esc(p.what) + '"><input type="checkbox" value="' + esc(p.id) + '"' + (on ? ' checked' : '') + '>' + label + '</label>';
    };
    return areas.map(function (a) {
      var r = byArea[a].r, w = byArea[a].w;
      return '<div class="ak-prow"><div class="ak-prow-t"><b>' + esc(a) + '</b><span>' + esc((w || r).what) + '</span></div>' + box(r, 'See') + box(w, 'Change') + '</div>';
    }).join('');
  }

  function formHtml() {
    var f = form, opts = [['never', 'Never (until I revoke it)'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['365', '1 year'], ['date', 'Pick a date…']];
    return '<div id="akForm">'
      + '<div class="ak-row"><label>Name<input id="akName" maxlength="80" value="' + esc(f.name) + '" placeholder="e.g. Claude Code, Stock sync, My developer"></label>'
      + '<label>Valid until<select id="akExp">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (f.exp === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>'
      + (f.exp === 'date' ? '<label>Last day it works<input id="akDate" type="date" min="' + esc(D.today) + '" value="' + esc(f.date) + '"></label>' : '') + '</div>'
      + '<div class="ak-presets">Permissions: <button class="ak-btn ghost sm" type="button" data-ak-preset="read">Read only</button><button class="ak-btn ghost sm" type="button" data-ak-preset="all">Everything</button><button class="ak-btn ghost sm" type="button" data-ak-preset="none">Clear</button></div>'
      + '<div class="ak-perms">' + permRows(f.perms) + '</div>'
      + '<div class="ak-row"><label>Allowed IP addresses <span class="ak-hint">Optional. Leave empty to allow any address. Separate with commas.</span>'
      + '<input id="akIps" value="' + esc(f.ips) + '" placeholder="e.g. 203.0.113.7, 198.51.100.20"></label></div>'
      + (D.your_ip ? '<p class="ak-small" style="margin:-6px 0 12px">Your address right now: <code>' + esc(D.your_ip) + '</code> <button class="ak-btn ghost sm" type="button" data-ak-myip>' + ic('add') + 'Add it</button></p>' : '')
      + '<div class="ak-btns">' + (f.mode === 'create'
        ? '<button class="ak-btn" type="button" data-ak-save>' + ic('key') + 'Create key</button><button class="ak-btn ghost" type="button" data-ak-cancel>Cancel</button>'
        : '<button class="ak-btn" type="button" data-ak-save>' + ic('save') + 'Save changes</button><button class="ak-btn ghost" type="button" data-ak-cancel>Cancel</button>')
      + '</div></div>';
  }

  function createCard() {
    if (!D.is_owner) return '<div class="ak-note">' + ic('lock') + '<div>Only the restaurant <b>owner</b> can create or change API keys.</div></div>';
    if (fresh) {
      return '<div class="ak-card"><h3>' + ic('check_circle') + (fresh.rotated ? 'New key for ' : 'Key created: ') + esc(fresh.row ? fresh.row.name : '') + '</h3>'
        + '<div class="ak-note">' + ic('warning') + '<div><b>Copy it now.</b> For your safety it is shown only this once. Lost it? Rotate the key to get a new one.'
        + (fresh.rotated ? ' ' + esc(fresh.rotated) : '') + '</div></div>'
        + '<div class="ak-key">' + codeBox(fresh.key, 'akNewKey') + '</div>'
        + '<div class="ak-chips" style="margin:8px 0 14px">' + permChips(fresh.row ? fresh.row.perms : []) + '</div>'
        + '<button class="ak-btn ghost" type="button" data-ak-done>' + ic('done') + 'I copied it</button></div>';
    }
    if (!form || form.mode !== 'create') return '<div class="ak-card"><h3>' + ic('add') + 'Create a key</h3><button class="ak-btn" type="button" data-ak-new>' + ic('add') + 'New API key</button></div>';
    return '<div class="ak-card"><h3>' + ic('add') + 'Create a key</h3>' + formHtml() + '</div>';
  }

  function rotatePanel(k) {
    var opts = [[0, 'Right away'], [60, 'In 1 hour'], [1440, 'In 24 hours']];
    return '<div class="ak-panel"><b style="font-size:.9rem;color:#111827">Rotate “' + esc(k.name) + '”</b>'
      + '<p class="ak-small" style="margin:4px 0 0">You get a new key with the same name, permissions and dates. Put the new key in your code or agent. When should the old key stop working?</p>'
      + '<div class="ak-choice">' + opts.map(function (o) { return '<button type="button" data-ak-grace="' + o[0] + '" class="' + (grace === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>'
      + '<div class="ak-btns"><button class="ak-btn" type="button" data-ak-dorotate="' + k.id + '">' + ic('autorenew') + 'Rotate key</button><button class="ak-btn ghost" type="button" data-ak-cancel>Cancel</button></div></div>';
  }

  function keyRow(k) {
    var ended = isEnded(k), owner = D.is_owner;
    var until = k.expires_at ? (k.status === 'expired' ? 'Ended ' : 'Valid until ') + day(k.expires_at) + (k.replaced && !ended ? ', replaced by a rotated key' : '') : 'Never expires';
    var btns = '';
    if (owner && !ended && k.replaced) {
      btns = '<button class="ak-btn danger sm" type="button" data-ak-revoke="' + k.id + '">' + ic('block') + 'Stop now</button>';
    } else if (owner && !ended) {
      btns = '<button class="ak-btn ghost sm" type="button" data-ak-edit="' + k.id + '">' + ic('edit') + 'Edit</button>'
        + '<button class="ak-btn ghost sm" type="button" data-ak-rotate="' + k.id + '">' + ic('autorenew') + 'Rotate</button>'
        + (k.status === 'paused'
          ? '<button class="ak-btn ghost sm" type="button" data-ak-pause="resume" data-id="' + k.id + '">' + ic('play_arrow') + 'Resume</button>'
          : '<button class="ak-btn ghost sm" type="button" data-ak-pause="pause" data-id="' + k.id + '">' + ic('pause') + 'Pause</button>')
        + '<button class="ak-btn danger sm" type="button" data-ak-revoke="' + k.id + '">' + ic('block') + 'Revoke</button>';
    } else if (owner) {
      btns = '<button class="ak-btn ghost sm" type="button" data-ak-delete="' + k.id + '">' + ic('delete') + 'Delete</button>';
    }
    var panel = '';
    if (form && form.mode === 'edit' && form.id === k.id) panel = '<div class="ak-panel">' + formHtml() + '</div>';
    else if (rotating === k.id) panel = rotatePanel(k);
    return '<div class="ak-k' + (ended ? ' ended' : '') + '"><div class="ak-k-top"><div class="ak-k-ic">' + ic('key') + '</div><div class="ak-k-t">'
      + '<div class="ak-k-name">' + esc(k.name) + ' ' + badge(k) + '</div>'
      + '<div class="ak-small"><code>' + esc(k.key_hint) + '</code>' + (k.rotated_from ? ' · rotated' : '') + '</div>'
      + '<div class="ak-chips">' + permChips(k.perms) + '</div>'
      + '<div class="ak-meta"><span>' + ic('event') + esc(until) + '</span><span>' + ic('schedule') + 'Last used ' + esc(ago(k.last_used_at)) + '</span>'
      + '<span>' + ic('bar_chart') + k.use_count + ' call' + (k.use_count === 1 ? '' : 's') + '</span>'
      + '<span>' + ic('public') + (k.allowed_ips.length ? esc(k.allowed_ips.join(', ')) : 'Any IP address') + '</span>'
      + '<span>' + ic('add_circle') + 'Created ' + esc(day(k.created_at)) + '</span></div>'
      + '</div><div class="ak-btns">' + btns + '</div></div>' + panel + '</div>';
  }

  function keysList() {
    var live = D.keys.filter(function (k) { return !isEnded(k); }), ended = D.keys.filter(isEnded);
    var html = live.length ? live.map(keyRow).join('') : '<div class="ak-empty">No working keys.' + (D.is_owner ? ' Create one above.' : '') + '</div>';
    if (ended.length) {
      html += '<button class="ak-more" type="button" data-ak-ended>' + (showEnded ? 'Hide' : 'Show') + ' ended keys (' + ended.length + ')</button>';
      if (showEnded) html += '<div style="margin-top:12px">' + ended.map(keyRow).join('') + '</div>';
    }
    return html;
  }

  function draw() {
    var tabs = [['claude', 'Claude Code'], ['mcp', 'Cursor & MCP apps'], ['curl', 'curl'], ['js', 'JavaScript']];
    var live = D.keys.filter(function (k) { return !isEnded(k); }).length;
    $('akRoot').innerHTML = '<style>' + CSS + '</style>'
      + '<div class="ak-hero"><div class="ak-hero-l"><h2>Connect your own code and AI agents</h2><p>An API key lets a developer, Claude Code, Cursor or any other agent read and change your menu, orders and reports. You choose exactly what each key may do and for how long.</p></div>'
      + '<a class="ak-btn" href="' + esc(D.docs_url) + '" target="_blank" rel="noopener">' + ic('menu_book') + 'API docs</a></div>'
      + createCard()
      + '<div class="ak-card"><h3>' + ic('key') + 'Your keys<span class="ak-count">' + live + ' of ' + D.max_keys + ' in use</span></h3><div id="akKeys">' + keysList() + '</div></div>'
      + '<div class="ak-card"><h3>' + ic('code') + 'Use a key</h3><div class="ak-tabs">' + tabs.map(function (t) {
        return '<button class="ak-tab' + (useTab === t[0] ? ' on' : '') + '" type="button" data-ak-tab="' + t[0] + '">' + t[1] + '</button>';
      }).join('') + '</div>' + snippets(fresh && fresh.key)
      + '<p class="ak-small" style="margin:10px 0 0">REST API: <code>' + esc(D.base_url) + '</code> · MCP: <code>' + esc(D.mcp_url) + '</code></p></div>'
      + '<div class="ak-card"><h3>' + ic('shield') + 'Safe by design</h3><div class="ak-safe">'
      + '<div>' + ic('check_circle') + 'A key only sees and changes this restaurant.</div>'
      + '<div>' + ic('check_circle') + 'It can do only what you ticked. Read-only keys cannot change anything.</div>'
      + '<div>' + ic('check_circle') + 'Set an end date, limit it to your IP addresses, or pause it any time.</div>'
      + '<div>' + ic('check_circle') + 'Leaked? Rotate it: the old key stops and you get a new one.</div>'
      + '<div>' + ic('check_circle') + 'Every change shows in your Activity log with the key\'s name.</div>'
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

  function post(body, btn, done) {
    if (busy) return;
    busy = true;
    if (btn) btn.disabled = true;
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (d) {
        busy = false;
        if (btn) btn.disabled = false;
        if (!d.success) { notify(d.message || 'Could not do that', 'error'); return; }
        done(d);
      }).catch(function () { busy = false; if (btn) btn.disabled = false; notify('Could not reach RestroGrow. Check your internet.', 'error'); });
  }

  function replaceRow(row) {
    for (var i = 0; i < D.keys.length; i++) if (D.keys[i].id === row.id) { D.keys[i] = row; return; }
    D.keys.unshift(row);
  }

  function copy(id) {
    var el = $(id);
    if (!el) return;
    var done = function () { notify('Copied', 'success'); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(el.textContent).then(done, function () { notify('Copy failed: select the text and copy it', 'error'); });
    else { var r = document.createRange(); r.selectNodeContents(el); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); try { document.execCommand('copy'); done(); } catch (x) { /* selected for the owner to copy */ } }
  }

  document.addEventListener('change', function (e) {
    if (!$('akRoot') || !$('akRoot').contains(e.target)) return;
    var box = e.target.closest('.ak-perm');
    if (box) {
      box.classList.toggle('on', e.target.checked);
      var v = e.target.value, row = box.parentNode, pair;
      // changing something needs seeing it: Change on turns See on, See off turns Change off
      if (/:write$/.test(v) && e.target.checked) pair = row.querySelector('input[value="' + v.replace(':write', ':read') + '"]');
      if (/:read$/.test(v) && !e.target.checked) pair = row.querySelector('input[value="' + v.replace(':read', ':write') + '"]');
      if (pair && pair.checked !== e.target.checked) { pair.checked = e.target.checked; pair.parentNode.classList.toggle('on', pair.checked); }
      return;
    }
    if (e.target.id === 'akExp') { readForm(); draw(); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-ak-tab],[data-ak-copy],[data-ak-revoke],[data-ak-delete],[data-ak-save],[data-ak-preset],[data-ak-done],[data-ak-new],[data-ak-edit],[data-ak-cancel],[data-ak-rotate],[data-ak-grace],[data-ak-dorotate],[data-ak-pause],[data-ak-ended],[data-ak-myip]');
    if (!t || !$('akRoot') || !$('akRoot').contains(t)) return;
    var id;
    if (t.hasAttribute('data-ak-tab')) { readForm(); useTab = t.getAttribute('data-ak-tab'); draw(); return; }
    if (t.hasAttribute('data-ak-copy')) { copy(t.getAttribute('data-ak-copy')); return; }
    if (t.hasAttribute('data-ak-done')) { fresh = null; draw(); return; }
    if (t.hasAttribute('data-ak-ended')) { readForm(); showEnded = !showEnded; draw(); return; }
    if (t.hasAttribute('data-ak-new')) { form = newForm(); rotating = null; draw(); $('akName').focus(); return; }
    if (t.hasAttribute('data-ak-edit')) {
      id = parseInt(t.getAttribute('data-ak-edit'), 10);
      form = editForm(D.keys.filter(function (k) { return k.id === id; })[0]); rotating = null; fresh = null; draw(); return;
    }
    if (t.hasAttribute('data-ak-cancel')) { form = null; rotating = null; draw(); return; }
    if (t.hasAttribute('data-ak-rotate')) { rotating = parseInt(t.getAttribute('data-ak-rotate'), 10); grace = 0; form = null; draw(); return; }
    if (t.hasAttribute('data-ak-grace')) { grace = parseInt(t.getAttribute('data-ak-grace'), 10); draw(); return; }
    if (t.hasAttribute('data-ak-myip')) {
      readForm();
      var list = form.ips.split(/[\s,;]+/).filter(Boolean);
      if (list.indexOf(D.your_ip) < 0) list.push(D.your_ip);
      form.ips = list.join(', ');
      draw(); return;
    }
    if (t.hasAttribute('data-ak-preset')) {
      readForm();
      var p = t.getAttribute('data-ak-preset');
      form.perms = p === 'none' ? [] : D.perms.map(function (x) { return x.id; }).filter(function (x) { return p === 'all' || /:read$/.test(x); });
      draw(); return;
    }
    if (t.hasAttribute('data-ak-save')) {
      readForm();
      if (form.name.trim().length < 2) { notify('Give the key a name', 'error'); $('akName').focus(); return; }
      if (!form.perms.length) { notify('Tick at least one permission', 'error'); return; }
      if (form.exp === 'date' && !form.date) { notify('Pick the last day the key works', 'error'); return; }
      var body = { action: form.mode === 'create' ? 'create' : 'update', id: form.id, name: form.name, perms: form.perms, expires: expiresValue(), ips: form.ips };
      post(body, t, function (d) {
        if (body.action === 'create') { fresh = { key: d.key, row: d.row }; notify('Key created. Copy it now.', 'success'); }
        else notify('Key saved', 'success');
        if (d.row) replaceRow(d.row);
        form = null;
        draw();
      });
      return;
    }
    if (t.hasAttribute('data-ak-dorotate')) {
      id = parseInt(t.getAttribute('data-ak-dorotate'), 10);
      post({ action: 'rotate', id: id, grace: grace }, t, function (d) {
        fresh = { key: d.key, row: d.row, rotated: grace ? 'The old key keeps working for ' + (grace === 60 ? '1 more hour' : '24 more hours') + ', then stops.' : 'The old key has stopped working.' };
        rotating = null;
        notify('Key rotated. Copy the new key now.', 'success');
        load();
        setTimeout(function () { window.scrollTo({ top: $('akRoot').getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' }); }, 400);
      });
      return;
    }
    if (t.hasAttribute('data-ak-pause')) {
      id = parseInt(t.getAttribute('data-id'), 10);
      var act = t.getAttribute('data-ak-pause');
      post({ action: act, id: id }, t, function (d) {
        if (d.row) replaceRow(d.row);
        notify(act === 'pause' ? 'Key paused: every call is refused until you resume it' : 'Key working again', 'success');
        draw();
      });
      return;
    }
    if (t.hasAttribute('data-ak-revoke') || t.hasAttribute('data-ak-delete')) {
      var del = t.hasAttribute('data-ak-delete');
      id = parseInt(t.getAttribute(del ? 'data-ak-delete' : 'data-ak-revoke'), 10);
      var k = D.keys.filter(function (x) { return x.id === id; })[0];
      rgUndo({
        html: rgUndo.label(del ? 'Deleted' : 'Revoked', k ? k.name : 'key'),
        hide: t.closest('.ak-k'),
        request: { url: API, json: { action: del ? 'delete' : 'revoke', id: id } },
        onDone: function () { load(); },
        onError: function (msg) { notify(msg, 'error'); load(); }
      });
    }
  });

  window.loadApiKeys = load;
})();
