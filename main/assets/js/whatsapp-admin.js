/*
 * Growth › WhatsApp (api/whatsapp_admin.php).
 *
 * Tabs: Automatic messages (every gift code the loyalty engine issues, sent on
 * WhatsApp to customers who opted in), Campaigns (one-off messages to an
 * audience, optionally with a personal gift code each), Message log, Settings
 * (connection, quiet hours, weekly limit, test number).
 *
 * Also the sender heartbeat: while any dashboard page is open, api/wa_dispatch.php
 * is called once a minute so queued messages go out.
 */
(function () {
  'use strict';

  var API = '../api/whatsapp_admin.php';
  var D = null, tab = 'auto', open = null, draft = null, logFilter = 'all', pendingDisconnect = false, pollT = null;
  // ready campaigns: [name, audience, message, gift kind, gift value, icon, what it is for, min order, valid days]
  var PRESETS = [
    ['We miss you', 'lapsed30', 'Hi {name}, it has been a while! Come back to {restaurant} this week for {reward}. Use code {code} before {valid_till}. Order here: {link}', 'flat', 75, 'favorite', 'Bring back customers who have not ordered for a month. Works best of all.', 299, 7],
    ['Weekend treat', 'recent', 'Hi {name}, your weekend treat from {restaurant}: {reward} on orders above {min_order}. Use code {code} before {valid_till}. Order here: {link}', 'percent', 15, 'celebration', 'A Friday nudge to people who ordered recently.', 399, 3],
    ['New on the menu', 'all', 'Hi {name}, something new just landed at {restaurant}! Try it with {reward}. Use code {code} before {valid_till}. Order here: {link}', 'flat', 50, 'restaurant_menu', 'Tell everyone about a new dish, with a small reason to try it.', 299, 7],
    ['Thank our regulars', 'regulars', 'Hi {name}, thank you for being one of our regulars at {restaurant}. Here is {reward} on us. Use code {code} before {valid_till}. Order here: {link}', 'item', 0, 'workspace_premium', 'A free dish for people with 5+ orders. Keeps your best customers loyal.', 0, 14],
    ['Festival offer', 'all', 'Hi {name}, happy festival from all of us at {restaurant}! Celebrate with {reward} on orders above {min_order}. Use code {code} before {valid_till}. Order here: {link}', 'percent', 10, 'auto_awesome', 'Diwali, Holi, Eid, Christmas: a festive offer for everyone.', 499, 5],
    ['Birthday month', 'birthdays', 'Hi {name}, it is your birthday month! {restaurant} has {reward} waiting for you. Use code {code} before {valid_till}. Order here: {link}', 'flat', 100, 'cake', 'A treat for customers with a birthday this month.', 0, 14],
    ['Come back for a 2nd order', 'new', 'Hi {name}, thanks for trying {restaurant}! Here is {reward} on your next order. Use code {code} before {valid_till}. Order here: {link}', 'flat', 50, 'replay', 'Turn first-time customers into regulars.', 249, 7],
    ['VIP members', 'vip', 'Hi {name}, an extra thank-you for our VIP members at {restaurant}: {reward}. Use code {code} before {valid_till}. Order here: {link}', 'percent', 15, 'diamond', 'Something special for your paying members.', 0, 7]
  ];

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function notify(m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); }
  function cur() { return (D && D.currency) || '₹'; }
  function money(n) { return cur() + Math.round(n || 0).toLocaleString('en-IN'); }
  function get(q) { return fetch(API + '?' + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function slug() { var s = String(D.restaurant || 'shop').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); if (s.length > 30) s = s.slice(0, 30).replace(/_[^_]*$/, ''); return s || 'shop'; }
  function sample() {
    return { name: 'Priya', reward: cur() + '50 off', code: 'GIFT7K2Q9M', valid_till: new Date(Date.now() + 14 * 864e5).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
      restaurant: D.restaurant, min_order: cur() + '199', link: 'restrogrow.com/' + slug().replace(/_/g, '-') + '/go/Ab3xK9q', tier: 'Gold', count: '10' };
  }
  function fill(msg, over) { var v = Object.assign(sample(), over || {}); return esc(msg).replace(/\{([a-z_]+)\}/g, function (m, k) { return v[k] != null ? '<b>' + esc(v[k]) + '</b>' : m; }); }
  function toTemplate(msg) { var n = 0, vars = [], known = D.vars; var t = String(msg).replace(/\{([a-z_]+)\}/g, function (m, k) { if (!known[k]) return m; vars.push(k); return '{{' + (++n) + '}}'; }); return [t, vars]; }
  function load() {
    var el = $('waRoot'); if (!el) return;
    if (!D) el.innerHTML = '<div class="up-empty">Loading…</div>';
    get('view=all').then(function (d) {
      if (!d.success) { el.innerHTML = '<div class="up-empty">' + esc(d.message || 'Could not load') + '</div>'; return; }
      D = d; draw();
      // templates waiting for WhatsApp: check again every 30 s while this page is open
      clearTimeout(pollT);
      var waiting = d.settings.connected_via === 'connect' && (d.auto.some(function (a) { return a.template_status === 'pending' || a.pending_template; }) || d.campaigns.some(function (c) { return c.template_status === 'pending' || c.template_status === 'queued'; }));
      if (waiting) pollT = setTimeout(function () { var pg = $('whatsappPage'); if (pg && pg.classList.contains('active') && !open && !draft) post({ action: 'templates_sync' }).then(load); else load(); }, 30000);
    });
  }

  /* ── pieces ── */
  function phone(msgHtml, title) {
    var t = new Date(); var hh = t.getHours() % 12 || 12, mm = ('0' + t.getMinutes()).slice(-2);
    return '<div class="wa-phone"><div class="wa-phone-top"><span class="wa-av">' + esc((D.restaurant || 'R').charAt(0)) + '</span><div><b>' + esc(title || D.restaurant) + '</b><small>Business account</small></div></div>' +
      '<div class="wa-chat"><div class="wa-bubble">' + msgHtml.replace(/\n/g, '<br>') + '<span class="wa-time">' + hh + ':' + mm + (t.getHours() >= 12 ? ' pm' : ' am') + '</span></div></div></div>';
  }
  function pill(st) {
    var m = { sent: ['ok', 'Sent'], queued: ['wait', 'Queued'], failed: ['bad', 'Failed'], skipped: ['muted', 'Skipped'], scheduled: ['wait', 'Scheduled'], sending: ['wait', 'Sending'], done: ['ok', 'Done'], cancelled: ['muted', 'Cancelled'] }[st] || ['muted', st];
    return '<span class="wa-pill ' + m[0] + '">' + m[1] + '</span>';
  }
  function templateBox(name, msg) {
    var tv = toTemplate(msg), v = sample();
    return '<div class="wa-tpl"><div class="wa-tpl-h"><span class="material-symbols-rounded">description</span><b>Template to create in RepeatGrow</b><button type="button" class="lsa-edit" data-wa-copy="' + esc(tv[0]) + '">Copy text<span class="material-symbols-rounded">content_copy</span></button></div>' +
      '<div class="wa-tpl-grid"><span>Name</span><code>' + esc(name) + '</code><span>Category</span><code>Marketing</code><span>Language</span><code>English</code></div>' +
      '<pre>' + esc(tv[0]) + '</pre>' +
      (tv[1].length ? '<div class="wa-tpl-vars">' + tv[1].map(function (k, i) { return '<span><b>{{' + (i + 1) + '}}</b> ' + esc(D.vars[k]) + ' <i>e.g. ' + esc(v[k]) + '</i></span>'; }).join('') + '</div>' : '') +
      '<small>Create it in RepeatGrow › Templates with exactly this text. When Meta approves it (usually within minutes), type its name here.</small></div>';
  }

  /* ── automatic messages ── */
  function autoSample(a) { return a.sample_reward ? { reward: a.sample_reward } : {}; }
  function linked() { return D.settings.connected_via === 'connect'; }
  /** Template state chip: approved / waiting for Meta / turned down / new wording in review */
  function tplChip(a) {
    if (D.settings.mode === 'platform') return D.platform.available ? '<span class="wa-chip ok"><span class="material-symbols-rounded">check_circle</span>Ready-made template</span>' : '<span class="wa-chip muted">Template coming soon</span>';
    if (!linked()) return a.template_name ? '<span class="wa-chip ok"><span class="material-symbols-rounded">check_circle</span>' + esc(a.template_name) + '</span>' : '<span class="wa-chip warn"><span class="material-symbols-rounded">pending</span>Needs a template</span>';
    var out = '';
    if (!a.template_status) out = '<span class="wa-chip warn"><span class="material-symbols-rounded">add_circle</span>Not created yet</span>';
    else if (a.template_status === 'approved') out = '<span class="wa-chip ok"><span class="material-symbols-rounded">verified</span>Approved by WhatsApp</span>';
    else if (a.template_status === 'pending') out = '<span class="wa-chip wait"><span class="material-symbols-rounded wa-spin">progress_activity</span>Waiting for approval</span>';
    else out = '<span class="wa-chip bad" title="' + esc(a.template_reason || '') + '"><span class="material-symbols-rounded">error</span>Turned down</span>';
    if (a.pending_template) out += ' <span class="wa-chip wait"><span class="material-symbols-rounded wa-spin">progress_activity</span>New wording in review</span>';
    else if (a.needs_update) out += ' <span class="wa-chip warn">Wording changed</span>';
    return out;
  }
  /** Sent > Delivered > Read > Clicked > Ordered > Sales, with failed / waiting chips and one plain sentence. */
  function results(n, compact, when) {
    var receipts = D.kpis.receipts || D.dry_run;
    var steps = [];
    if (n.reached != null) steps.push({ label: 'Reached', value: n.reached, icon: 'groups', hint: 'Customers in the audience who opted in' });
    steps.push({ label: 'Sent', value: n.sent, icon: 'send' });
    steps.push({ label: 'Delivered', value: n.delivered || 0, icon: 'done_all', muted: !receipts, hint: receipts ? 'Reached the customer\u2019s phone' : 'Shows once RepeatGrow sends delivery receipts' });
    steps.push({ label: 'Read', value: n.read || 0, icon: 'visibility', muted: !receipts, hint: receipts ? 'Customer opened the message (if they allow read receipts)' : 'Shows once RepeatGrow sends read receipts' });
    steps.push({ label: 'Clicked', value: n.clicked || 0, icon: 'touch_app', hint: 'Opened the link in the message ({link})' });
    steps.push({ label: 'Ordered', value: n.orders || 0, icon: 'shopping_bag', strong: true, hint: 'Orders that used the gift code from this message' });
    var extra = [];
    if (n.failed) extra.push({ label: 'Failed', value: n.failed, tone: 'bad', icon: 'error' });
    if (n.queued) extra.push({ label: 'Waiting to send', value: n.queued, tone: 'info', icon: 'schedule' });
    if (n.sent && n.orders) extra.push({ label: 'Order rate', value: RGStats.pct(n.orders, n.sent), tone: 'good', icon: 'trending_up' });
    if (when) extra.push({ label: when });
    var line = n.sent ? RGStats.sentence({ people: n.clicked || 0, verb: 'tapped the link', orders: n.orders || 0, revenue: n.revenue, money: cur() }) : '';
    return RGStats.funnel(steps, { money: cur(), revenue: n.revenue || 0, compact: compact, extra: extra, note: line });
  }
  function autoCard(a) {
    var st = a.stats || {}, platform = D.settings.mode === 'platform';
    var isOpen = open === a.kind;
    var head = '<div class="wa-card' + (a.enabled ? ' on' : '') + (isOpen ? ' open' : '') + '" data-wa-kind="' + a.kind + '">' +
      '<div class="wa-card-top"><span class="wa-ic material-symbols-rounded">' + a.icon + '</span><div class="wa-card-t"><b>' + esc(a.label) + '</b>' +
        '<small>' + tplChip(a) +
        (a.autopilot_on === 0 ? ' <span class="wa-chip muted">Gift is off in Autopilot</span>' : '') + '</small></div>' +
        '<label class="up-switch"><input type="checkbox" data-wa-auto-toggle="' + a.kind + '"' + (a.enabled ? ' checked' : '') + '><span></span></label></div>' +
      '<p class="wa-card-msg">' + fill(a.message, autoSample(a)) + '</p>' +
      results({ sent: st.sent || 0, failed: st.failed || 0, delivered: (a.funnel || {}).delivered, read: (a.funnel || {}).read, clicked: (a.funnel || {}).clicked, orders: a.orders.orders, revenue: a.orders.revenue }, true, 'Last 30 days') +
      '<div class="wa-card-acts"><button type="button" class="lsa-edit" data-wa-open="' + a.kind + '">' + (isOpen ? 'Close' : 'Edit') + '<span class="material-symbols-rounded">' + (isOpen ? 'expand_less' : 'tune') + '</span></button></div>';
    if (!isOpen) return head + '</div>';
    var tplName = a.template_name || (slug() + '_' + a.kind + '_gift');
    return head + '<div class="wa-ed"><div class="wa-ed-l">' +
      (platform ? '<div class="lsa-note" style="margin:0 0 12px">You are using RestroGrow’s number, so every gift uses one ready-made message. Connect your own number in Settings to write your own.</div>'
        : '<label class="wa-lbl">Message<textarea data-wa-f="message" rows="4" maxlength="700">' + esc(a.message) + '</textarea></label>' +
          '<div class="wa-varbar">' + Object.keys(D.vars).filter(function (k) { return (k !== 'tier' || a.kind === 'tier_up') && (k !== 'count' || a.kind === 'milestone'); }).map(function (k) { return '<button type="button" data-wa-var="{' + k + '}" title="' + esc(D.vars[k]) + '">{' + k + '}</button>'; }).join('') + '</div>' +
          (linked() ? linkedBox(a)
            : '<div class="ls-grid" style="margin-top:12px"><label>Approved template name<input data-wa-f="template_name" value="' + esc(a.template_name) + '" placeholder="' + esc(tplName) + '"></label>' +
              '<label>Language<select data-wa-f="language">' + [['en', 'English'], ['en_US', 'English (US)'], ['hi', 'Hindi']].map(function (l) { return '<option value="' + l[0] + '"' + (a.language === l[0] ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select></label></div>' +
              templateBox(tplName, a.message))) +
      '</div><div class="wa-ed-r">' + phone(platform ? fill(D.platform.text.replace(/\{\{1\}\}/, '{name}').replace(/\{\{2\}\}/, '{restaurant}').replace(/\{\{3\}\}/, '{reward}').replace(/\{\{4\}\}/, '{code}').replace(/\{\{5\}\}/, '{valid_till}')) : fill(a.message, autoSample(a))) +
        '<div class="wa-ed-acts"><button type="button" class="btn btn-secondary" data-wa-test="' + a.kind + '"><span class="material-symbols-rounded">send</span> Send me a test</button>' +
        (platform ? '' : '<button type="button" class="btn btn-primary" data-wa-save="' + a.kind + '"><span class="material-symbols-rounded">save</span> Save</button>') + '</div></div></div></div>';
  }
  function linkedBox(a) {
    var st = a.template_status;
    var line = !st ? 'Save, and we send this wording to WhatsApp for approval for you.'
      : st === 'approved' ? (a.pending_template ? 'Your new wording is waiting for approval. Until then the approved one keeps sending.' : 'Approved. Change the wording and Save: we send the new one for approval, and the approved one keeps sending meanwhile.')
      : st === 'pending' ? 'Sent to WhatsApp for approval, usually a few minutes. Gifts earned meanwhile go out as soon as it is approved.'
      : 'WhatsApp turned this wording down' + (a.template_reason ? ' (' + esc(a.template_reason) + ')' : '') + '. Change it a little (keep it friendly, no all-caps) and Save to try again.';
    if (st === 'approved' && a.template_reason) line += ' ' + esc(a.template_reason);
    return '<div class="wa-linked ' + (st || 'none') + '"><span class="material-symbols-rounded">' + (st === 'approved' ? 'verified' : st === 'pending' ? 'hourglass_top' : st ? 'error' : 'auto_awesome') + '</span><div><b>' +
      (st === 'approved' ? 'Approved by WhatsApp' : st === 'pending' ? 'Waiting for WhatsApp approval' : st ? 'Turned down by WhatsApp' : 'We create the template for you') + '</b><small>' + line + '</small></div></div>';
  }
  function autoTab() {
    var A = D.auto, ready = A.filter(function (a) { return D.settings.mode === 'platform' ? D.platform.available : linked() ? a.template_status === 'approved' : a.template_name; }).length;
    var missing = A.filter(function (a) { return a.enabled && (!a.template_status || a.needs_update); }).length;
    var waiting = A.filter(function (a) { return a.template_status === 'pending' || a.pending_template; }).length;
    return '<div class="wa-intro"><span class="material-symbols-rounded">bolt</span><div><b>Every gift code goes out on WhatsApp by itself.</b> Welcome, birthday, win-back, stamp cards, challenges and more: the moment a customer earns a gift, they get it on WhatsApp with their code. ' +
      (D.settings.mode === 'own' ? ready + ' of ' + A.length + ' messages are approved by WhatsApp.' : '') + '</div></div>' +
      (D.settings.mode === 'own' && !D.settings.key_last4 ? connectBanner() : '') +
      (linked() && (missing || waiting) ? '<div class="wa-tplbar"><span class="material-symbols-rounded">' + (missing ? 'auto_awesome' : 'hourglass_top') + '</span><div><b>' +
        (missing ? missing + ' message' + (missing > 1 ? 's need' : ' needs') + ' a WhatsApp template' : waiting + ' waiting for WhatsApp approval') + '</b><small>' +
        (missing ? 'One click creates them in RepeatGrow and sends them to WhatsApp for approval.' : 'Usually a few minutes. This page checks by itself.') + '</small></div>' +
        (missing ? '<button type="button" class="btn btn-primary" data-wa-tpl-create><span class="material-symbols-rounded">auto_awesome</span> Create ' + (missing > 1 ? 'all ' + missing : 'it') + '</button>'
                 : '<button type="button" class="btn btn-secondary" data-wa-tpl-sync><span class="material-symbols-rounded">refresh</span> Check now</button>') + '</div>' : '') +
      '<div class="wa-grid">' + A.map(autoCard).join('') + '</div>';
  }
  function connectBanner() {
    // RepeatGrow stopped accepting the connection (the owner removed RestroGrow there)
    var lost = D.settings.connection_lost;
    if (!D.connect.available) return lost ? '<div class="sx-note warn"><span class="material-symbols-rounded">link_off</span><span>' + esc(D.settings.lost_message) + '</span></div>' : '';
    if (lost) return '<div class="wa-connect wa-connect-lost"><div class="wa-connect-logo"><span class="material-symbols-rounded">link_off</span></div><div><b>' + esc(D.settings.lost_message) + '</b><small>Messages are paused and waiting. Log in to RepeatGrow and click Allow to pick up where you left off.</small></div>' +
      '<a class="btn btn-primary wa-connect-btn" href="' + esc(D.connect.url) + '"><span class="material-symbols-rounded">link</span> Connect again</a></div>';
    return '<div class="wa-connect"><div class="wa-connect-logo"><span class="material-symbols-rounded">forum</span></div><div><b>Connect your RepeatGrow account</b><small>Log in to RepeatGrow and click Allow. We set up every WhatsApp template for you, no copying.</small></div>' +
      '<a class="btn btn-primary wa-connect-btn" href="' + esc(D.connect.url) + '"><span class="material-symbols-rounded">link</span> Connect RepeatGrow</a></div>';
  }

  /* ── campaigns ── */
  function giftSample(c) {
    if (!c.gift_kind) return {};
    var it = c.gift_kind === 'item' ? D.items.filter(function (i) { return i.id === +c.gift_item_id; })[0] : null;
    return { reward: c.gift_kind === 'percent' ? (+c.gift_value || 0) + '% off' : c.gift_kind === 'item' ? 'Free ' + (it ? it.name : 'dish') : cur() + (+c.gift_value || 0) + ' off',
      min_order: +c.gift_min_order > 0 ? cur() + (+c.gift_min_order) : 'any order',
      valid_till: new Date(Date.now() + (+c.gift_valid_days || 14) * 864e5).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) };
  }
  function goalPicker() {
    return '<div class="wa-compose"><div class="lsg-ed-h"><b>What do you want this campaign to do?</b><button type="button" class="lsa-edit" data-wa-cancel-draft>Cancel</button></div>' +
      '<div class="wa-steps"><span class="on">1 · Goal</span><span>2 · Check message and gift</span><span>3 · Send</span></div>' +
      '<div class="wa-goals">' + PRESETS.map(function (p, i) {
        var a = D.audiences.filter(function (x) { return x.key === p[1]; })[0] || { count: 0, label: '' };
        return '<button type="button" class="wa-goal" data-wa-goal="' + i + '"><span class="material-symbols-rounded">' + p[5] + '</span><b>' + esc(p[0]) + '</b><small>' + esc(p[6]) + '</small>' +
          '<em' + (a.count ? '' : ' class="zero"') + '>' + a.count + ' customer' + (a.count === 1 ? '' : 's') + ' · ' + esc(a.label) + '</em></button>';
      }).join('') +
      '<button type="button" class="wa-goal" data-wa-goal="blank"><span class="material-symbols-rounded">edit_note</span><b>Write my own</b><small>Start from an empty message and pick everything yourself.</small><em class="zero">Any audience</em></button></div></div>';
  }
  function campaignForm() {
    var c = draft, aud = D.audiences.filter(function (a) { return a.key === c.audience; })[0] || {};
    var tplName = c.template_name || (slug() + '_' + String(c.name || 'campaign').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 30));
    var opt = '<option value="">Pick a dish</option>' + D.items.map(function (i) { return '<option value="' + i.id + '"' + (+c.gift_item_id === i.id ? ' selected' : '') + '>' + esc(i.name) + '</option>'; }).join('');
    return '<div class="wa-compose"><div class="lsg-ed-h"><b>New campaign</b><span style="display:flex;gap:6px"><button type="button" class="lsa-edit" data-wa-back-goal>Change goal</button><button type="button" class="lsa-edit" data-wa-cancel-draft>Cancel</button></span></div>' +
      '<div class="wa-steps"><span>1 · Goal</span><span class="on">2 · Check message and gift</span><span>3 · Send</span></div>' +
      (String(c.message).indexOf('{link}') < 0 ? '<div class="sx-note warn" style="margin:0 0 10px"><span class="material-symbols-rounded">link</span><span>Add <b>{link}</b> to the message to see who tapped it. It opens your menu with their gift already applied.</span></div>' : '') +
      '<div class="wa-ed"><div class="wa-ed-l">' +
        '<div class="ls-grid"><label>Campaign name<input data-wc="name" maxlength="80" value="' + esc(c.name) + '" placeholder="Weekend treat"></label></div>' +
        '<label class="wa-lbl" style="margin-top:12px">Who gets it</label><div class="wa-aud">' + D.audiences.map(function (a) {
          return '<button type="button" class="' + (a.key === c.audience ? 'on' : '') + '" data-wa-aud="' + a.key + '"><b>' + a.count + '</b><span>' + esc(a.label) + '</span></button>'; }).join('') + '</div>' +
        '<label class="wa-lbl" style="margin-top:12px">Message<textarea data-wc="message" rows="4" maxlength="700">' + esc(c.message) + '</textarea></label>' +
        '<div class="wa-varbar">' + ['name', 'restaurant', 'reward', 'code', 'valid_till', 'min_order', 'link'].map(function (k) { return '<button type="button" data-wa-var="{' + k + '}">{' + k + '}</button>'; }).join('') + '</div>' +
        '<div class="ls-grid" style="margin-top:12px">' +
          '<label>Gift for each customer<select data-wc="gift_kind"><option value="">No gift</option><option value="flat"' + (c.gift_kind === 'flat' ? ' selected' : '') + '>' + esc(cur()) + ' off</option><option value="percent"' + (c.gift_kind === 'percent' ? ' selected' : '') + '>% off</option><option value="item"' + (c.gift_kind === 'item' ? ' selected' : '') + '>A free dish</option></select></label>' +
          (c.gift_kind === 'item' ? '<label>Dish<select data-wc="gift_item_id">' + opt + '</select></label>' : c.gift_kind ? '<label>' + (c.gift_kind === 'percent' ? 'Percent off' : 'Amount off') + '<input type="number" data-wc="gift_value" min="1" value="' + (c.gift_value || '') + '"></label>' : '') +
          (c.gift_kind ? '<label>On orders above<input type="number" data-wc="gift_min_order" min="0" value="' + (c.gift_min_order || '') + '" placeholder="Any order"></label><label>Valid for<select data-wc="gift_valid_days">' + [3, 7, 14, 30].map(function (n) { return '<option value="' + n + '"' + (+c.gift_valid_days === n ? ' selected' : '') + '>' + n + ' days</option>'; }).join('') + '</select></label>' : '') +
        '</div>' +
        '<div class="ls-grid" style="margin-top:12px"><label>When<select data-wc="when"><option value="now"' + (c.when === 'now' ? ' selected' : '') + '>Send now</option><option value="later"' + (c.when === 'later' ? ' selected' : '') + '>Schedule</option></select></label>' +
          (c.when === 'later' ? '<label>Date and time<input type="datetime-local" data-wc="scheduled_at" value="' + esc(c.scheduled_at || '') + '"></label>' : '') +
          (linked() ? '' : '<label>Approved template name<input data-wc="template_name" value="' + esc(c.template_name) + '" placeholder="' + esc(tplName) + '"></label>') + '</div>' +
        (linked() ? '<div class="wa-linked none" style="margin-top:12px"><span class="material-symbols-rounded">auto_awesome</span><div><b>We create the template for you</b><small>WhatsApp approves new wording first, usually within minutes. The campaign starts by itself as soon as it is approved. Same wording as an earlier campaign? It goes out right away.</small></div></div>'
                  : templateBox(tplName, c.message)) +
      '</div><div class="wa-ed-r">' + phone(fill(c.message, giftSample(c))) +
        '<div class="wa-reach"><b>' + (aud.count || 0) + '</b> customer' + (aud.count === 1 ? '' : 's') + ' will get this' + (c.gift_kind ? ', each with their own one-time code' : '') + '. Messages pause during quiet hours and each customer gets at most ' + D.settings.weekly_cap + ' campaign' + (D.settings.weekly_cap === 1 ? '' : 's') + ' a week.</div>' +
        '<div class="wa-ed-acts"><button type="button" class="btn btn-secondary" data-wa-test-campaign><span class="material-symbols-rounded">send</span> Send me a test</button>' +
        '<button type="button" class="btn btn-primary" data-wa-launch><span class="material-symbols-rounded">rocket_launch</span> ' + (c.when === 'later' ? 'Schedule' : 'Send to ' + (aud.count || 0)) + '</button></div></div></div></div>';
  }
  function bestId(C) { var b = null; C.forEach(function (c) { if (c.revenue > 0 && (!b || c.revenue > b.revenue)) b = c; }); return b ? b.id : null; }
  function campSummary(C) {
    var t = C.reduce(function (s, c) { s.sent += c.sent; s.read += c.read; s.clicked += c.clicked; s.orders += c.orders; s.revenue += c.revenue; return s; }, { sent: 0, read: 0, clicked: 0, orders: 0, revenue: 0 });
    if (!t.sent) return '';
    return '<div class="wa-sum"><b>All campaigns</b>' + RGStats.funnel([
      { label: 'Messages sent', value: t.sent, icon: 'send' }, { label: 'Read', value: t.read, icon: 'visibility', muted: !(D.kpis.receipts || D.dry_run) },
      { label: 'Link taps', value: t.clicked, icon: 'touch_app' }, { label: 'Orders', value: t.orders, icon: 'shopping_bag', strong: true }], { money: cur(), revenue: t.revenue, compact: true }) + '</div>';
  }
  function campTab() {
    if (D.settings.mode === 'own' && !D.settings.key_last4 && D.connect.available) return connectBanner();
    if (D.settings.mode !== 'own') return '<div class="wa-lock"><span class="material-symbols-rounded">lock</span><b>Campaigns need your own WhatsApp number</b><p>Connect your RepeatGrow account in Settings. Then send offers to the customers you pick, with your own name and messages.</p><button type="button" class="btn btn-primary" data-wa-tab="settings">Open Settings</button></div>';
    var C = D.campaigns;
    return (draft ? (draft.step === 'goal' ? goalPicker() : campaignForm()) : '<div class="wa-new"><div><b>Send a campaign</b><span>Pick a goal, check the message, send. Every message has its own tracked link, so you see who read it, tapped it and ordered.</span></div><button type="button" class="btn btn-primary" data-wa-new><span class="material-symbols-rounded">add</span> New campaign</button></div>') +
      (C.length ? campSummary(C) + '<div class="wa-camps">' + C.map(function (c) {
        var aud = D.audiences.filter(function (a) { return a.key === c.audience; })[0];
        var best = bestId(C) === c.id;
        return '<div class="wa-camp"><div class="wa-camp-h"><b>' + esc(c.name) + (best ? ' ' + RGStats.badge('Best campaign', 'gold', 'emoji_events') : '') + '</b>' + (c.status === 'scheduled' && (c.template_status === 'pending' || c.template_status === 'queued') ? '<span class="wa-pill wait">Waiting for approval</span>' : pill(c.status)) + '</div><small>' + esc(aud ? aud.label : c.audience) + ' · ' + esc(String(c.scheduled_at).slice(0, 16).replace('T', ' ')) + (c.gift_kind ? ' · with gift' : '') + '</small>' +
          results({ reached: c.audience_count, sent: c.sent, failed: c.failed, queued: c.queued, delivered: c.delivered, read: c.read, clicked: c.clicked, orders: c.orders, revenue: c.revenue }) +
          '<div class="wa-card-acts">' + (c.status === 'scheduled' || c.status === 'sending' ? '<button type="button" class="lsa-edit" data-wa-cancel="' + c.id + '">Stop</button>' : '') +
          '<button type="button" class="lsa-edit" data-wa-again="' + c.id + '">Send again<span class="material-symbols-rounded">content_copy</span></button></div></div>';
      }).join('') + '</div>' : (draft ? '' : '<div class="lsa-empty" style="margin-top:12px"><span class="material-symbols-rounded">campaign</span><b>No campaigns yet</b><span>Start from a ready idea: “We miss you” brings back quiet customers best.</span></div>'));
  }

  /* ── log ── */
  function logTab() {
    var L = D.log.filter(function (l) { return logFilter === 'all' || l.status === logFilter; });
    var names = {}; D.auto.forEach(function (a) { names[a.kind] = a.label; }); names.campaign = 'Campaign';
    return '<div class="wa-logbar"><div class="lsp-range">' + [['all', 'All'], ['sent', 'Sent'], ['queued', 'Queued'], ['failed', 'Failed'], ['skipped', 'Skipped']].map(function (f) { return '<button type="button" data-wa-filter="' + f[0] + '"' + (f[0] === logFilter ? ' class="on"' : '') + '>' + f[1] + '</button>'; }).join('') + '</div>' +
      '<div style="display:flex;gap:8px"><button type="button" class="btn btn-secondary" data-wa-dispatch><span class="material-symbols-rounded">sync</span> Send waiting now</button>' + (D.kpis.failed ? '<button type="button" class="btn btn-secondary" data-wa-retry><span class="material-symbols-rounded">replay</span> Retry failed</button>' : '') + '</div></div>' +
      (L.length ? '<div class="wa-log">' + L.map(function (l) {
        var ticks = l.status === 'sent' ? '<span class="wa-ticks"><span class="' + (l.delivered_at ? 'on' : '') + '">Delivered</span><span class="' + (l.read_at ? 'on' : '') + '">Read</span>' + (l.clicked_at ? '<span class="click">Tapped link</span>' : '') + '</span>' : '';
        return '<div class="wa-log-row"><div class="wa-log-who"><b>' + esc(l.name || 'Customer') + '</b><small>' + esc(l.phone) + ' · ' + esc(names[l.kind] || l.kind) + '</small>' + ticks + '</div><p>' + esc(l.body || '') + (l.error ? '<em>' + esc(l.error) + '</em>' : '') + '</p>' +
          '<div class="wa-log-st">' + pill(l.status) + '<small>' + esc(String(l.at || '').slice(5, 16)) + '</small></div></div>';
      }).join('') + '</div>' : '<div class="lsa-empty"><span class="material-symbols-rounded">forum</span><b>No messages yet</b><span>Messages appear here as gifts are earned and campaigns go out.</span></div>');
  }

  /* ── settings ── */
  function setTab() {
    var s = D.settings, hours = []; for (var h = 0; h < 24; h++) hours.push(h);
    var hl = function (h) { return (h % 12 || 12) + (h < 12 ? ' am' : ' pm'); };
    return '<div class="wa-modes">' +
      '<button type="button" class="wa-mode' + (s.mode === 'platform' ? ' on' : '') + '" data-wa-mode="platform"><span class="material-symbols-rounded">rocket_launch</span><b>Quick start</b><small>Gift messages from RestroGrow’s WhatsApp number with a ready-made template. Nothing to set up.</small>' + (D.platform.available ? '<i class="ok">Available</i>' : '<i>Coming soon</i>') + '</button>' +
      '<button type="button" class="wa-mode' + (s.mode === 'own' ? ' on' : '') + '" data-wa-mode="own"><span class="material-symbols-rounded">verified</span><b>Your own number</b><small>Messages come from your restaurant’s WhatsApp through RepeatGrow. Your words, your campaigns.</small><i class="ok">Recommended</i></button></div>' +
      (s.mode === 'own' && linked() ? '<div class="wa-connected"><span class="material-symbols-rounded">verified</span><div><b>Connected to RepeatGrow</b><small>' + esc(s.account_name || 'Your account') + (s.rg_phone ? ' · ' + esc(s.rg_phone) : '') + (s.connected_at ? ' · since ' + esc(String(s.connected_at).slice(0, 10)) : '') + '</small></div>' +
        '<button type="button" class="btn btn-secondary" data-wa-disconnect' + (pendingDisconnect ? ' style="color:#b91c1c;border-color:#fecaca"' : '') + '>' + (pendingDisconnect ? 'Disconnect? Click again' : 'Disconnect') + '</button></div>' : '') +
      (s.mode === 'own' && !linked() && D.connect.available ? connectBanner() : '') +
      (s.mode === 'own' && !linked() ? (D.connect.available ? '<details class="wa-manual"' + (s.key_last4 ? ' open' : '') + '><summary>Or paste an API key instead</summary>' : '') + '<div class="ls-box" style="margin-top:12px"><div class="ls-grid"><label style="grid-column:span 2">RepeatGrow API key' +
        '<input type="password" id="waKey" autocomplete="off" placeholder="' + (s.key_last4 ? 'Saved: ••••' + esc(s.key_last4) + '. Paste a new key to replace it' : 'wacrm_live_...') + '"></label></div>' +
        '<p class="lsa-fine" style="margin:8px 0 0">In RepeatGrow: Settings › API keys › New key with the “messages:send” permission.' + (s.account_name ? ' Connected to <b>' + esc(s.account_name) + '</b>.' : '') + '</p></div>' + (D.connect.available ? '</details>' : '') : '') +
      '<div class="ls-box" style="margin-top:12px"><div class="ls-grid">' +
        '<label>Don’t send after<select id="waQs">' + hours.map(function (h) { return '<option value="' + h + '"' + (h === s.quiet_start ? ' selected' : '') + '>' + hl(h) + '</option>'; }).join('') + '</select></label>' +
        '<label>Start again at<select id="waQe">' + hours.map(function (h) { return '<option value="' + h + '"' + (h === s.quiet_end ? ' selected' : '') + '>' + hl(h) + '</option>'; }).join('') + '</select></label>' +
        '<label>Campaigns per customer per week<select id="waCap">' + [1, 2, 3, 5, 0].map(function (n) { return '<option value="' + n + '"' + (n === s.weekly_cap ? ' selected' : '') + '>' + (n ? n : 'No limit') + '</option>'; }).join('') + '</select></label>' +
        '<label>Your phone (for tests)<input id="waTest" inputmode="tel" value="' + esc(s.test_phone) + '" placeholder="98XXXXXXXX"></label></div>' +
        '<p class="lsa-fine" style="margin:8px 0 0">Only customers who ticked “Send my rewards and offers on WhatsApp” get messages. Gifts earned at night wait for the morning.</p></div>' +
      '<div style="display:flex;justify-content:flex-end;margin-top:12px"><button type="button" class="btn btn-primary" data-wa-save-settings><span class="material-symbols-rounded">save</span> Save settings</button></div>';
  }

  function draw() {
    var el = $('waRoot'); if (!el || !D) return;
    var s = D.settings, k = D.kpis, pct = k.customers ? Math.round(k.optin * 100 / k.customers) : 0;
    var conn = s.mode === 'own' ? (linked() ? 'Connected to RepeatGrow: ' + esc(s.account_name || '') + (s.rg_phone ? ' (' + esc(s.rg_phone) + ')' : '') : s.key_last4 ? 'Your number' + (s.account_name ? ' (' + esc(s.account_name) + ')' : '') : 'Your number: not connected yet') : (D.platform.available ? 'RestroGrow’s number' : 'Not connected');
    el.innerHTML =
      (D.dry_run ? '<div class="wa-dry"><span class="material-symbols-rounded">science</span>Test mode on this computer: messages are prepared and logged, but nothing is sent to anyone.</div>' : '') +
      '<div class="wa-hero"><div class="wa-hero-l"><label class="up-switch" style="margin:0"><input type="checkbox" id="waOn"' + (s.enabled ? ' checked' : '') + '><span></span> <b>WhatsApp messages are ' + (s.enabled ? 'on' : 'off') + '</b></label>' +
        '<p><span class="material-symbols-rounded">link</span>' + conn + (s.last_dispatch ? ' · last checked ' + esc(String(s.last_dispatch).slice(11, 16)) : '') + '</p></div>' +
        '<div class="wa-ring" style="--p:' + pct + '"><div><b>' + pct + '%</b><span>reachable</span></div></div>' +
        '<div class="ls-kpis"><div><b>' + k.optin + '</b><span>Opted in of ' + k.customers + '</span></div><div><b>' + k.sent + '</b><span>Sent (30 days)</span></div>' +
          (k.receipts || D.dry_run ? '<div><b>' + RGStats.pct(k.read, k.sent) + '</b><span>Read</span></div>' : '') + '<div><b>' + (k.clicked || 0) + '</b><span>Link taps</span></div><div><b>' + money(k.revenue) + '</b><span>Orders from messages</span></div></div></div>' +
      '<div class="ls-tabs">' + [['auto', 'Automatic messages', 'bolt'], ['camp', 'Campaigns', 'campaign'], ['log', 'Message log', 'forum'], ['settings', 'Settings', 'settings']].map(function (t) {
        return '<button type="button" class="ls-tab' + (t[0] === tab ? ' on' : '') + '" data-wa-tab="' + t[0] + '"><span class="material-symbols-rounded">' + t[2] + '</span>' + t[1] + (t[0] === 'log' && k.failed ? ' <em class="wa-badge">' + k.failed + '</em>' : '') + '</button>';
      }).join('') + '</div>' +
      '<div id="waTab">' + (tab === 'auto' ? autoTab() : tab === 'camp' ? campTab() : tab === 'log' ? logTab() : setTab()) + '</div>';
  }

  function readAuto(card) { var a = D.auto.filter(function (x) { return x.kind === card.getAttribute('data-wa-kind'); })[0]; card.querySelectorAll('[data-wa-f]').forEach(function (f) { a[f.getAttribute('data-wa-f')] = f.value; }); return a; }
  function readDraft() { var box = document.querySelector('.wa-compose'); if (!box) return; box.querySelectorAll('[data-wc]').forEach(function (f) { draft[f.getAttribute('data-wc')] = f.value; }); }
  function saveAuto(a, btn, extra) {
    if (btn) btn.disabled = true;
    var body = { action: 'auto', kind: a.kind, enabled: a.enabled };
    if (!linked()) { body.template_name = a.template_name; body.language = a.language; }
    if (extra) body.message = a.message;
    return post(body).then(function (d) {
      if (btn) btn.disabled = false;
      notify(!d.success ? (d.message || 'Could not save') : d.warning ? d.warning : d.submitted ? a.label + ' saved and sent to WhatsApp for approval' : a.label + ' saved', !d.success ? 'error' : d.warning ? 'warning' : 'success');
      return d.success;
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#waRoot') || !D) return;
    var t;
    if ((t = e.target.closest('[data-wa-tab]'))) { tab = t.getAttribute('data-wa-tab'); open = null; draw(); return; }
    if ((t = e.target.closest('[data-wa-open]'))) { var k = t.getAttribute('data-wa-open'); open = open === k ? null : k; draw(); var c = document.querySelector('[data-wa-kind="' + k + '"]'); if (c && open) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    if ((t = e.target.closest('[data-wa-var]'))) {
      var ta = t.closest('.wa-ed-l').querySelector('textarea'), v = t.getAttribute('data-wa-var'), p = ta.selectionStart || ta.value.length;
      ta.value = ta.value.slice(0, p) + v + ta.value.slice(ta.selectionEnd || p); ta.focus(); ta.selectionStart = ta.selectionEnd = p + v.length; ta.dispatchEvent(new Event('input', { bubbles: true })); return;
    }
    if ((t = e.target.closest('[data-wa-copy]'))) { var txt = t.getAttribute('data-wa-copy'); (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { notify('Template text copied', 'success'); }, function () { notify('Select the text and copy it', 'info'); }); return; }
    if ((t = e.target.closest('[data-wa-save]'))) { var a = readAuto(t.closest('[data-wa-kind]')); saveAuto(a, t, true).then(function (ok) { if (ok) { open = null; load(); } }); return; }
    if ((t = e.target.closest('[data-wa-test]'))) {
      var card = t.closest('[data-wa-kind]'); if (card.querySelector('[data-wa-f]')) { var a2 = readAuto(card); t.disabled = true; saveAuto(a2, null, true).then(function () { test({ action: 'test', kind: a2.kind }, t); }); } else test({ action: 'test', kind: card.getAttribute('data-wa-kind') }, t);
      return;
    }
    if (e.target.closest('[data-wa-new]')) { draft = { step: 'goal', name: '', audience: 'all', message: PRESETS[0][2], gift_kind: 'flat', gift_value: 75, gift_min_order: 299, gift_valid_days: 7, when: 'now', template_name: '' }; draw(); return; }
    if ((t = e.target.closest('[data-wa-goal]'))) {
      var gi = t.getAttribute('data-wa-goal'), g = PRESETS[+gi];
      var stamp = new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
      if (gi === 'blank') Object.assign(draft, { step: 'form', name: 'Campaign ' + stamp, audience: 'all', message: 'Hi {name}, \n\nOrder here: {link}', gift_kind: '', gift_value: 0 });
      else Object.assign(draft, { step: 'form', name: g[0] + ' · ' + stamp, audience: g[1], message: g[2], gift_kind: g[3], gift_value: g[4], gift_min_order: g[7], gift_valid_days: g[8], gift_item_id: g[3] === 'item' && D.items[0] ? D.items[0].id : '' });
      draw(); var box = document.querySelector('.wa-compose'); if (box) box.scrollIntoView({ behavior: 'smooth', block: 'start' }); return;
    }
    if (e.target.closest('[data-wa-back-goal]')) { readDraft(); draft.step = 'goal'; draw(); return; }
    if ((t = e.target.closest('[data-wa-again]'))) {
      var old = D.campaigns.filter(function (x) { return x.id === +t.getAttribute('data-wa-again'); })[0]; if (!old) return;
      draft = { step: 'form', name: old.name.replace(/ · .*$/, '') + ' · ' + new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'short' }), audience: old.audience, message: old.message, gift_kind: old.gift_kind || '', gift_value: old.gift_value,
        gift_item_id: old.gift_item_id || '', gift_min_order: old.gift_min_order, gift_valid_days: old.gift_valid_days, when: 'now', template_name: old.template_name };
      draw(); var bx = document.querySelector('.wa-compose'); if (bx) bx.scrollIntoView({ behavior: 'smooth', block: 'start' }); return;
    }
    if (e.target.closest('[data-wa-cancel-draft]')) { draft = null; draw(); return; }
    if ((t = e.target.closest('[data-wa-aud]'))) { readDraft(); draft.audience = t.getAttribute('data-wa-aud'); draw(); return; }
    if (e.target.closest('[data-wa-test-campaign]')) { readDraft(); test({ action: 'test', campaign: draft }, e.target.closest('button')); return; }
    if ((t = e.target.closest('[data-wa-launch]'))) {
      readDraft(); t.disabled = true;
      post(Object.assign({ action: 'campaign', send_now: draft.when !== 'later' }, draft)).then(function (d) {
        t.disabled = false;
        if (!d.success) { notify(d.message || 'Could not start', 'error'); return; }
        notify(d.waiting_approval ? 'Campaign saved. It starts by itself once WhatsApp approves the wording (usually minutes).' : draft.when === 'later' ? 'Campaign scheduled' : 'Campaign started' + (d.dispatched ? ': ' + d.dispatched.sent + ' sent so far' : ''), 'success');
        draft = null; load();
      });
      return;
    }
    if ((t = e.target.closest('[data-wa-cancel]'))) { post({ action: 'cancel', id: +t.getAttribute('data-wa-cancel') }).then(function () { notify('Campaign stopped', 'success'); load(); }); return; }
    if ((t = e.target.closest('[data-wa-filter]'))) { logFilter = t.getAttribute('data-wa-filter'); draw(); return; }
    if ((t = e.target.closest('[data-wa-dispatch]'))) { t.disabled = true; post({ action: 'dispatch' }).then(function (d) { notify(d.success ? (d.dispatched.sent + ' sent' + (d.dispatched.failed ? ', ' + d.dispatched.failed + ' failed' : '')) : 'Could not send', d.success ? 'success' : 'error'); load(); }); return; }
    if ((t = e.target.closest('[data-wa-retry]'))) { t.disabled = true; post({ action: 'retry' }).then(function (d) { notify(d.success ? d.requeued + ' queued again' : 'Could not retry', d.success ? 'success' : 'error'); load(); }); return; }
    if ((t = e.target.closest('[data-wa-mode]'))) { D.settings.mode = t.getAttribute('data-wa-mode'); draw(); return; }
    if ((t = e.target.closest('[data-wa-save-settings]'))) saveSettings(t);
    if ((t = e.target.closest('[data-wa-tpl-create]'))) {
      t.disabled = true; t.innerHTML = '<span class="material-symbols-rounded wa-spin">progress_activity</span> Creating…';
      post({ action: 'templates_create' }).then(function (d) {
        var errs = d.errors ? Object.keys(d.errors).length : 0;
        if (d.success && d.paused === 'rate_limited') { notify((d.created ? d.created + ' sent to WhatsApp for approval. ' : '') + d.message, 'warning'); load(); return; }
        notify(!d.success ? (d.message || 'Could not create') : d.created + ' sent to WhatsApp for approval' + (errs ? ', ' + errs + ' had a problem' : '') + '. Usually a few minutes.', !d.success || errs ? 'warning' : 'success');
        load();
      });
      return;
    }
    if ((t = e.target.closest('[data-wa-tpl-sync]'))) { t.disabled = true; post({ action: 'templates_sync' }).then(function () { notify('Checked with WhatsApp', 'success'); load(); }); return; }
    if ((t = e.target.closest('[data-wa-disconnect]'))) {
      if (!pendingDisconnect) { pendingDisconnect = true; draw(); setTimeout(function () { pendingDisconnect = false; }, 6000); return; }
      pendingDisconnect = false; t.disabled = true;
      post({ action: 'disconnect' }).then(function (d) { notify(d.success ? 'RepeatGrow disconnected. WhatsApp messages are off.' : 'Could not disconnect', d.success ? 'success' : 'error'); load(); });
    }
  });
  function test(body, btn) {
    if (btn) btn.disabled = true;
    post(body).then(function (d) { if (btn) btn.disabled = false; notify(d.message || (d.success ? 'Sent' : 'Could not send'), d.success ? 'success' : 'error'); });
  }
  function saveSettings(btn, onlyToggle) {
    var s = D.settings, v = function (id) { var x = $(id); return x ? x.value : null; };
    var body = { action: 'settings', enabled: $('waOn') && $('waOn').checked ? 1 : 0, mode: s.mode,
      quiet_start: v('waQs') != null ? +v('waQs') : s.quiet_start, quiet_end: v('waQe') != null ? +v('waQe') : s.quiet_end,
      weekly_cap: v('waCap') != null ? +v('waCap') : s.weekly_cap, test_phone: v('waTest') != null ? v('waTest') : s.test_phone };
    if (v('waKey')) body.api_key = v('waKey');
    if (btn) btn.disabled = true;
    post(body).then(function (d) {
      if (btn) btn.disabled = false;
      notify(d.success ? (onlyToggle ? (body.enabled ? 'WhatsApp messages are on' : 'WhatsApp messages are off') : 'Settings saved') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
      load();
    });
  }
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#waRoot') || !D) return;
    var t = e.target;
    if (t.id === 'waOn') { saveSettings(null, true); return; }
    if (t.hasAttribute('data-wa-auto-toggle')) {
      var a = D.auto.filter(function (x) { return x.kind === t.getAttribute('data-wa-auto-toggle'); })[0]; a.enabled = t.checked ? 1 : 0;
      saveAuto(a).then(function () { draw(); }); return;
    }
    if (t.closest('.wa-compose')) { readDraft(); draw(); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.closest || !D) return;
    var ed = e.target.closest('.wa-ed'); if (!ed || e.target.tagName !== 'TEXTAREA') return;
    var b = ed.querySelector('.wa-bubble'); if (b) b.innerHTML = fill(e.target.value, draft && ed.closest('.wa-compose') ? giftSample(draft) : null).replace(/\n/g, '<br>') + b.querySelector('.wa-time').outerHTML;
    var tpl = ed.querySelector('.wa-tpl pre'); if (tpl) tpl.textContent = toTemplate(e.target.value)[0];
  });

  window.loadWhatsappAdmin = function () { load(); };

  // Back from RepeatGrow's Connect page: say how it went, open WhatsApp, tidy the address
  (function () {
    var q = new URLSearchParams(location.search), r = q.get('wa');
    if (!r) return;
    var msg = { connected: ['RepeatGrow connected. Next: create your WhatsApp templates in one click.', 'success'], cancelled: ['Connecting was cancelled. Nothing changed.', 'info'],
                unavailable: [q.get('wa_msg') || 'Connecting is not available yet.', 'warning'], failed: [q.get('wa_msg') || 'Could not connect RepeatGrow. Please try again.', 'error'] }[r];
    q.delete('wa'); q.delete('wa_msg');
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '') + location.hash);
    var show = function () {
      var nav = document.querySelector('[data-page="whatsappPage"]');
      if (nav && !(document.getElementById('whatsappPage') || {}).classList.contains('active')) nav.click(); else load();
      if (msg) setTimeout(function () { notify(msg[0], msg[1]); }, 600);
      if (r === 'connected') { tab = 'auto'; }
    };
    if (document.readyState === 'complete') setTimeout(show, 800); else window.addEventListener('load', function () { setTimeout(show, 800); });
  })();

  // Sender heartbeat: queued gift messages and campaigns go out while the dashboard is open
  var beatDelay = 60000;
  function beat() {
    fetch('../api/wa_dispatch.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (d) { beatDelay = d && d.enabled ? 60000 : 600000; }).catch(function () {})
      .then(function () { setTimeout(beat, beatDelay); });
  }
  setTimeout(beat, 15000);
})();
