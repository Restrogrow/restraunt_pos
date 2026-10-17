/*
 * Loyalty Studio > Autopilot tab. Gifts that send themselves: each automation
 * has an on/off switch, a reward, a message (kept for WhatsApp sending) and its
 * own numbers. Draws through window.lsDraw_autopilot (see loyalty-studio.js).
 */
(function () {
  'use strict';

  var META = {
    welcome:     ['waving_hand', 'Welcome gift', 'After a customer’s first completed order', 'Turns a first visit into a second one.', null],
    birthday:    ['cake', 'Birthday treat', 'Days before the birthday', 'The highest-redeeming gift there is.', ['Days before', 0, 30]],
    anniversary: ['favorite', 'Anniversary', 'Days before the anniversary', 'Couples celebrate out: a natural big order.', ['Days before', 0, 30]],
    winback:     ['history', 'We miss you', 'When a regular goes quiet', 'Brings back customers before they forget you.', ['Quiet for (days)', 7, 365]],
    tier_up:     ['military_tech', 'Level up', 'When a customer reaches a new level', 'Celebrates progress so they keep climbing.', null],
    milestone:   ['emoji_events', 'Order milestone', 'Every Nth completed order', 'A thank-you for your most loyal regulars.', ['Every N orders', 2, 100]],
    referral:    ['group_add', 'Referral gift', 'When an invited friend completes a first order', 'A gift for both friends.', null]
  };
  var ORDER = ['welcome', 'birthday', 'winback', 'milestone', 'tier_up', 'referral', 'anniversary'];
  var VARS = ['{name}', '{reward}', '{code}', '{restaurant}'];
  var open = null, D = null;

  function L() { return window.lsApi; }
  function esc(s) { return L().esc(s); }
  function money(n) { return L().money(n); }
  function ordinal(n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function auto(type) { return D.automations.filter(function (a) { return a.type === type; })[0]; }

  function rewardText(a) {
    if (a.reward_kind === 'percent') return (+a.reward_value || 0) + '% off';
    if (a.reward_kind === 'item') { var it = (D.items || []).filter(function (i) { return i.id === +a.reward_item_id; })[0]; return it ? 'Free ' + it.name : 'A free dish'; }
    return money(+a.reward_value || 0) + ' off';
  }
  function trigger(a) {
    var m = META[a.type];
    if (a.type === 'birthday' || a.type === 'anniversary') return a.param ? a.param + ' days before the ' + (a.type === 'birthday' ? 'birthday' : 'anniversary') : 'On the day';
    if (a.type === 'winback') return 'No order for ' + a.param + ' days';
    if (a.type === 'milestone') return 'Every ' + ordinal(a.param) + ' order';
    return m[2];
  }
  function preview(a) {
    var rest = window.restaurantName || (document.querySelector('.restaurant-name') || {}).textContent || 'your restaurant';
    return esc(a.message).replace(/\{name\}/g, '<b>Priya</b>').replace(/\{reward\}/g, '<b>' + esc(rewardText(a)) + '</b>').replace(/\{code\}/g, '<b>GIFT7K2Q9M</b>')
      .replace(/\{restaurant\}/g, esc(String(rest).trim())).replace(/\{tier\}/g, '<b>Gold</b>').replace(/\{count\}/g, '<b>' + (a.param || 10) + '</b>')
      .replace(/\{valid_till\}/g, '<b>' + new Date(Date.now() + (+a.valid_days || 30) * 864e5).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + '</b>')
      .replace(/\{min_order\}/g, '<b>' + (a.min_order > 0 ? money(a.min_order) : 'any order') + '</b>').replace(/\{link\}/g, '<b>restrogrow.com/…/loyalty</b>');
  }

  function card(type) {
    var a = auto(type), m = META[type], st = (D.stats || {})[type] || { issued: 0, used: 0, revenue: 0 };
    var note = '';
    if (type === 'referral' && D.program_type === 'points') note = 'Your program is Points, so referrals earn points (Program tab). This gift applies to Cashback and Stamp card programs.';
    else if (type === 'referral' && !D.referral_enabled) note = 'Referrals are off. Turn them on in the Program tab.';
    else if (type === 'birthday') note = plural(D.upcoming.birthdays_known, 'customer has', 'customers have') + ' shared a birthday · ' + D.upcoming.birthday + ' coming up in the next 30 days';
    else if (type === 'anniversary') note = plural(D.upcoming.anniversary, 'anniversary', 'anniversaries') + ' in the next 30 days';
    else if (type === 'winback') note = plural(D.upcoming.winback, 'customer has', 'customers have') + ' not ordered for ' + a.param + '+ days';
    else if (type === 'tier_up' && D.program_type !== 'points') note = 'Levels follow total spend, whatever the program type.';
    var isOpen = open === type;
    var conv = st.issued ? Math.round(st.used * 100 / st.issued) : 0;
    return '<div class="lsa-card' + (a.enabled ? ' on' : '') + (isOpen ? ' open' : '') + '" data-lsa="' + type + '">' +
      '<div class="lsa-top">' +
        '<span class="lsa-ic material-symbols-rounded">' + m[0] + '</span>' +
        '<div class="lsa-t"><b>' + m[1] + '</b><small>' + esc(trigger(a)) + ' · <em>' + esc(rewardText(a)) + (a.min_order > 0 ? ' above ' + money(a.min_order) : '') + '</em></small></div>' +
        '<label class="up-switch lsa-sw" title="' + (a.enabled ? 'On' : 'Off') + '"><input type="checkbox" data-lsa-toggle="' + type + '"' + (a.enabled ? ' checked' : '') + '><span></span></label>' +
      '</div>' +
      '<div class="lsa-nums"><div><b>' + st.issued + '</b><span>Sent</span></div><div><b>' + st.used + '</b><span>Used' + (st.issued ? ' · ' + conv + '%' : '') + '</span></div><div><b>' + money(st.revenue) + '</b><span>Orders brought in</span></div>' +
        '<button type="button" class="lsa-edit" data-lsa-open="' + type + '">' + (isOpen ? 'Close' : 'Edit') + '<span class="material-symbols-rounded">' + (isOpen ? 'expand_less' : 'tune') + '</span></button></div>' +
      (note ? '<p class="lsa-note">' + esc(note) + '</p>' : '') +
      (isOpen ? editor(a) : '') +
    '</div>';
  }

  function editor(a) {
    var m = META[a.type];
    var itemOpts = '<option value="">Pick a dish</option>' + (D.items || []).map(function (it) { return '<option value="' + it.id + '"' + (+a.reward_item_id === it.id ? ' selected' : '') + '>' + esc(it.name) + ' · ' + money(it.price) + '</option>'; }).join('');
    var vars = VARS.concat(a.type === 'tier_up' ? ['{tier}'] : a.type === 'milestone' ? ['{count}'] : []);
    return '<div class="lsa-ed">' +
      '<div class="ls-grid">' +
        (m[4] ? '<label>' + m[4][0] + '<input type="number" data-f="param" min="' + m[4][1] + '" max="' + m[4][2] + '" value="' + a.param + '"></label>' : '') +
        '<label>Gift<select data-f="reward_kind"><option value="flat"' + (a.reward_kind === 'flat' ? ' selected' : '') + '>' + esc((D.currency || '₹')) + ' off</option><option value="percent"' + (a.reward_kind === 'percent' ? ' selected' : '') + '>% off</option><option value="item"' + (a.reward_kind === 'item' ? ' selected' : '') + '>A free dish</option></select></label>' +
        (a.reward_kind === 'item' ? '<label>Free dish<select data-f="reward_item_id">' + itemOpts + '</select></label>'
                                  : '<label>' + (a.reward_kind === 'percent' ? 'Percent off' : 'Amount off') + '<input type="number" data-f="reward_value" min="1" max="' + (a.reward_kind === 'percent' ? 100 : 100000) + '" value="' + (a.reward_value || '') + '"></label>') +
        '<label>On orders above<input type="number" data-f="min_order" min="0" value="' + (a.min_order || '') + '" placeholder="Any order"></label>' +
        '<label>Valid for<select data-f="valid_days">' + [7, 14, 30, 60, 90].map(function (n) { return '<option value="' + n + '"' + (n === +a.valid_days ? ' selected' : '') + '>' + n + ' days</option>'; }).join('') + '</select></label>' +
      '</div>' +
      '<div class="lsa-msg"><label>Message<textarea data-f="message" rows="3" maxlength="500">' + esc(a.message) + '</textarea></label>' +
        '<div class="lsa-vars">' + vars.map(function (v) { return '<button type="button" data-lsa-var="' + v + '">' + v + '</button>'; }).join('') + '</div>' +
        '<div class="lsa-bubble"><span class="lsa-bubble-h">Preview</span><p>' + preview(a) + '</p></div>' +
      '</div>' +
      '<div class="lsa-foot"><span class="material-symbols-rounded">info</span><span>The gift lands in the customer’s <b>Your gifts</b> on the rewards page and applies at checkout. With WhatsApp connected (Growth › WhatsApp) the message also goes on WhatsApp; customers without WhatsApp get it by email.</span>' +
        '<button type="button" class="btn btn-primary" data-lsa-save="' + a.type + '"><span class="material-symbols-rounded">save</span> Save</button></div>' +
    '</div>';
  }

  function recent() {
    if (!D.recent.length) return '<div class="lsa-empty"><span class="material-symbols-rounded">redeem</span><b>No gifts yet</b><span>Turn on an automation and gifts appear here as customers qualify.</span></div>';
    var name = function (s) { return (META[s] || [null, s === 'stamps' ? 'Stamp card' : s === 'scratch' ? 'Scratch card' : s])[1]; };
    return '<div class="lsa-recent">' + D.recent.map(function (r) {
      return '<div class="lsa-row"><span class="lsa-ric material-symbols-rounded">' + ((META[r.source] || ['redeem'])[0]) + '</span>' +
        '<div><b>' + esc(r.name) + '</b><small>' + esc(name(r.source)) + ' · ' + esc(r.label) + '</small></div>' +
        '<code>' + esc(r.code) + '</code>' +
        '<span class="lsa-pill ' + (r.used ? 'used' : 'ready') + '">' + (r.used ? 'Used' : 'Ready') + '</span></div>';
    }).join('') + '</div>';
  }

  function draw(el) {
    if (!D) { el.innerHTML = '<div class="up-empty">Loading…</div>'; return; }
    var on = D.automations.filter(function (a) { return a.enabled; }).length;
    var tot = { issued: 0, used: 0, revenue: 0 };
    Object.keys(D.stats || {}).forEach(function (k) { if (META[k]) { tot.issued += D.stats[k].issued; tot.used += D.stats[k].used; tot.revenue += D.stats[k].revenue; } });
    el.innerHTML =
      '<div class="ls-hero"><div><b style="font-size:1.1rem">Rewards on autopilot</b><p>Set each gift once. It goes out to the right customer at the right moment, on every channel.' + (D.loyalty_enabled ? '' : ' <b style="color:#fde68a">The loyalty program is off, so nothing is sent.</b>') + '</p></div>' +
        '<div class="ls-kpis"><div><b>' + on + '/7</b><span>Running</span></div><div><b>' + tot.issued + '</b><span>Gifts (90 days)</span></div><div><b>' + money(tot.revenue) + '</b><span>Orders brought in</span></div></div></div>' +
      '<div class="lsa-grid">' + ORDER.map(card).join('') + '</div>' +
      '<div class="lsa-side-h"><h3 class="ls-h" style="margin:0">Latest gifts</h3><button type="button" class="btn btn-secondary" data-lsa-run><span class="material-symbols-rounded">refresh</span> Check birthdays and quiet customers now</button></div>' +
      recent() +
      '<p class="lsa-fine">Each customer gets a gift once per occasion: one welcome, one birthday a year, one win-back per quiet spell. Codes are personal: only that phone number can use them, once. Birthdays and quiet customers are checked every hour while your rewards page or this dashboard is in use' + (D.last_run ? ' (last: ' + esc(D.last_run.slice(0, 16)) + ')' : '') + '.</p>';
  }

  function readEditor(card) {
    var a = auto(card.getAttribute('data-lsa'));
    card.querySelectorAll('[data-f]').forEach(function (f) {
      var k = f.getAttribute('data-f');
      a[k] = (k === 'message' || k === 'reward_kind') ? f.value : (f.value === '' ? 0 : +f.value);
    });
    return a;
  }
  function save(a, btn) {
    if (btn) btn.disabled = true;
    return L().post(Object.assign({ section: 'autopilot' }, a)).then(function (d) {
      if (btn) btn.disabled = false;
      L().notify(d.success ? META[a.type][1] + (a.enabled ? ' is on' : ' saved') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
      return d.success;
    }).catch(function () { if (btn) btn.disabled = false; L().notify('Network error. Please try again.', 'error'); return false; });
  }
  function redraw() { draw(document.getElementById('lsPanel')); }

  window.lsDraw_autopilot = function (el, data) { if (data && data.success) D = data; draw(el); };

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D) return;
    var t;
    if ((t = e.target.closest('[data-lsa-open]'))) { var ty = t.getAttribute('data-lsa-open'); if (open && open !== ty) readEditor(document.querySelector('[data-lsa="' + open + '"]')); open = open === ty ? null : ty; redraw(); return; }
    if ((t = e.target.closest('[data-lsa-var]'))) {
      var ta = t.closest('.lsa-ed').querySelector('textarea'), v = t.getAttribute('data-lsa-var'), p = ta.selectionStart || ta.value.length;
      ta.value = ta.value.slice(0, p) + v + ta.value.slice(ta.selectionEnd || p); ta.focus(); ta.selectionStart = ta.selectionEnd = p + v.length;
      ta.dispatchEvent(new Event('input', { bubbles: true })); return;
    }
    if ((t = e.target.closest('[data-lsa-save]'))) { var a = readEditor(t.closest('[data-lsa]')); save(a, t).then(function (ok) { if (ok) { open = null; L().reload(); } }); return; }
    if ((t = e.target.closest('[data-lsa-run]'))) {
      t.disabled = true;
      L().post({ section: 'autopilot', action: 'run' }).then(function (d) {
        L().notify(d.success ? (d.issued ? d.issued + ' new gift' + (d.issued > 1 ? 's' : '') + ' sent' : 'All caught up: no new gifts due') : (d.message || 'Could not run'), d.success ? 'success' : 'error');
        L().reload();
      }).catch(function () { t.disabled = false; });
    }
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D) return;
    var t = e.target;
    if (t.hasAttribute('data-lsa-toggle')) {
      var c = t.closest('[data-lsa]'), a = c.classList.contains('open') ? readEditor(c) : auto(t.getAttribute('data-lsa-toggle'));
      a.enabled = t.checked ? 1 : 0;
      save(a).then(function (ok) { if (!ok) { a.enabled = a.enabled ? 0 : 1; if (a.enabled === 0 && a.reward_kind === 'item' && !a.reward_item_id) open = a.type; } else a.saved = true; redraw(); });
      return;
    }
    if (t.getAttribute('data-f') === 'reward_kind' || t.getAttribute('data-f') === 'param' || t.getAttribute('data-f') === 'reward_item_id') { readEditor(t.closest('[data-lsa]')); redraw(); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.closest || !e.target.closest('.lsa-ed') || !D) return;
    var c = e.target.closest('[data-lsa]'), a = readEditor(c), b = c.querySelector('.lsa-bubble p');
    if (b) b.innerHTML = preview(a);
  });
})();
