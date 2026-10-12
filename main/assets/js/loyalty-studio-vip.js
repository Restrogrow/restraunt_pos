/*
 * Loyalty Studio > VIP membership tab: a paid club with plans (price, length)
 * and perks (% off every order, free delivery, faster earning, welcome gift).
 * Customers join on the rewards page with PhonePe or their wallet; perks apply
 * at checkout. Draws through window.lsDraw_vip (see loyalty-studio.js).
 */
(function () {
  'use strict';

  var LENGTHS = [[30, 'month'], [90, '3 months'], [180, '6 months'], [365, 'year']];
  var TEMPLATES = [
    { name: 'Monthly', price: 199, duration_days: 30, discount_pct: 10, discount_max: 100, free_delivery: 1, free_delivery_min: 0, earn_boost: 1.5, gift_kind: null, gift_value: 0, highlight: 0 },
    { name: 'Quarterly', price: 499, duration_days: 90, discount_pct: 10, discount_max: 150, free_delivery: 1, free_delivery_min: 0, earn_boost: 2, gift_kind: 'flat', gift_value: 100, highlight: 1 },
    { name: 'Yearly', price: 1499, duration_days: 365, discount_pct: 15, discount_max: 200, free_delivery: 1, free_delivery_min: 0, earn_boost: 2, gift_kind: 'item', gift_value: 0, highlight: 0 }
  ];
  var D = null, edit = null, confirmDel = null, S = null;

  function L() { return window.lsApi; }
  function esc(s) { return L().esc(s); }
  function money(n) { return L().money(n); }
  function cur() { return (D && D.currency) || '₹'; }
  function dish(id) { return (D.items || []).filter(function (i) { return i.id === +id; })[0]; }

  function per(p) {
    var l = LENGTHS.filter(function (x) { return x[0] === +p.duration_days; })[0];
    return l ? l[1] : p.duration_days + ' days';
  }
  function perMonth(p) { return p.duration_days > 31 ? money(Math.round(p.price / (p.duration_days / 30))) + '/month' : ''; }
  function giftText(p) {
    if (!p.gift_kind) return '';
    if (p.gift_kind === 'item') { var it = dish(p.gift_item_id); return it ? 'Free ' + it.name : 'A free dish'; }
    if (p.gift_kind === 'percent') return (+p.gift_value) + '% off';
    return money(+p.gift_value) + ' off';
  }
  function perks(p) {
    var out = [], prog = D.settings.program_type;
    if (+p.discount_pct > 0) out.push(['percent', (+p.discount_pct) + '% off every order' + (+p.discount_max > 0 ? ' (up to ' + money(p.discount_max) + ')' : '')]);
    if (+p.free_delivery) out.push(['local_shipping', 'Free delivery' + (+p.free_delivery_min > 0 ? ' above ' + money(p.free_delivery_min) : '')]);
    if (+p.earn_boost > 1 && prog !== 'stamps') out.push(['bolt', (+p.earn_boost) + 'x ' + (prog === 'cashback' ? 'cashback' : 'points') + ' on every order']);
    if (p.gift_kind && giftText(p)) out.push(['redeem', 'Welcome gift: ' + giftText(p)]);
    return out;
  }
  function planCard(p, preview) {
    return '<div class="lsv-plan' + (+p.highlight ? ' hl' : '') + (p.active === 0 ? ' off' : '') + '">' +
      (+p.highlight ? '<span class="lsv-badge">Most popular</span>' : '') +
      '<div class="lsv-plan-h"><span class="material-symbols-rounded">diamond</span><b>' + esc(p.name || 'Plan') + '</b></div>' +
      '<div class="lsv-price"><b>' + money(+p.price || 0) + '</b><span>/ ' + esc(per(p)) + '</span></div>' +
      (perMonth(p) ? '<small class="lsv-pm">Works out to ' + perMonth(p) + '</small>' : '<small class="lsv-pm">&nbsp;</small>') +
      '<ul>' + perks(p).map(function (x) { return '<li><span class="material-symbols-rounded">' + x[0] + '</span>' + esc(x[1]) + '</li>'; }).join('') + '</ul>' +
      (preview ? '<span class="lsv-cta">Join ' + esc(S.name || 'VIP Club') + '</span>' :
        '<div class="lsv-stats"><div><b>' + p.members + '</b><span>Active</span></div><div><b>' + p.sold + '</b><span>Sold</span></div><div><b>' + money(p.revenue) + '</b><span>Revenue</span></div></div>' +
        '<div class="lsv-acts"><label class="up-switch" title="Show on the rewards page"><input type="checkbox" data-lsv-toggle="' + p.id + '"' + (p.active ? ' checked' : '') + '><span></span> ' + (p.active ? 'On sale' : 'Hidden') + '</label>' +
          '<button type="button" class="lsa-edit" data-lsv-edit="' + p.id + '">Edit<span class="material-symbols-rounded">tune</span></button>' +
          '<button type="button" class="lsa-edit lsg-del' + (confirmDel === p.id ? ' sure' : '') + '" data-lsv-del="' + p.id + '">' + (confirmDel === p.id ? (p.sold ? 'Hide it?' : 'Remove?') : '<span class="material-symbols-rounded">delete</span>') + '</button></div>') +
    '</div>';
  }

  function editorHtml() {
    var p = edit;
    var opt = '<option value="">Pick a dish</option>' + (D.items || []).map(function (it) { return '<option value="' + it.id + '"' + (+p.gift_item_id === it.id ? ' selected' : '') + '>' + esc(it.name) + ' · ' + money(it.price) + '</option>'; }).join('');
    var prog = D.settings.program_type;
    return '<div class="lsg-ed lsv-ed"><div class="lsg-ed-h"><b>' + (p.id ? 'Edit plan' : 'New plan') + '</b><button type="button" class="lsa-edit" data-lsv-cancel>Cancel</button></div>' +
      '<div class="lsg-ed-body"><div>' +
        '<div class="ls-grid">' +
          '<label>Plan name<input type="text" data-v="name" maxlength="40" value="' + esc(p.name) + '" placeholder="Monthly"></label>' +
          '<label>Price (' + esc(cur()) + ')<input type="number" data-v="price" min="1" value="' + (p.price || '') + '"></label>' +
          '<label>Runs for<select data-v="duration_days">' + LENGTHS.map(function (l) { return '<option value="' + l[0] + '"' + (+p.duration_days === l[0] ? ' selected' : '') + '>' + (/^\d/.test(l[1]) ? l[1] : '1 ' + l[1]) + '</option>'; }).join('') + '</select></label>' +
        '</div>' +
        '<h4 class="lsv-h4">Perks</h4>' +
        '<div class="ls-grid">' +
          '<label>Discount on every order (%)<input type="number" data-v="discount_pct" min="0" max="50" value="' + (p.discount_pct || '') + '" placeholder="None"></label>' +
          '<label>Most off per order (' + esc(cur()) + ')<input type="number" data-v="discount_max" min="0" value="' + (p.discount_max || '') + '" placeholder="No limit"></label>' +
          (prog !== 'stamps' ? '<label>Earn faster<select data-v="earn_boost">' + [[1, 'No boost'], [1.5, '1.5x ' + (prog === 'cashback' ? 'cashback' : 'points')], [2, '2x ' + (prog === 'cashback' ? 'cashback' : 'points')], [3, '3x ' + (prog === 'cashback' ? 'cashback' : 'points')]].map(function (o) { return '<option value="' + o[0] + '"' + (+p.earn_boost === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>' : '') +
        '</div>' +
        '<div class="ls-grid" style="margin-top:12px">' +
          '<label class="lsv-check"><span><input type="checkbox" data-v="free_delivery"' + (+p.free_delivery ? ' checked' : '') + '> Free delivery</span></label>' +
          (+p.free_delivery ? '<label>Free delivery above (' + esc(cur()) + ')<input type="number" data-v="free_delivery_min" min="0" value="' + (p.free_delivery_min || '') + '" placeholder="Any order"></label>' : '') +
        '</div>' +
        '<div class="ls-grid" style="margin-top:12px">' +
          '<label>Welcome gift<select data-v="gift_kind"><option value="">None</option><option value="flat"' + (p.gift_kind === 'flat' ? ' selected' : '') + '>' + esc(cur()) + ' off</option><option value="percent"' + (p.gift_kind === 'percent' ? ' selected' : '') + '>% off</option><option value="item"' + (p.gift_kind === 'item' ? ' selected' : '') + '>A free dish</option></select></label>' +
          (p.gift_kind === 'item' ? '<label>Dish<select data-v="gift_item_id">' + opt + '</select></label>' : p.gift_kind ? '<label>' + (p.gift_kind === 'percent' ? 'Percent off' : 'Amount off') + '<input type="number" data-v="gift_value" min="1" value="' + (p.gift_value || '') + '"></label>' : '') +
          '<label class="lsv-check"><span><input type="checkbox" data-v="highlight"' + (+p.highlight ? ' checked' : '') + '> Mark as most popular</span></label>' +
        '</div>' +
      '</div>' +
      '<div class="lsg-prev lsv-prev"><span class="lsa-bubble-h" style="color:#6d28d9">On the rewards page</span>' + planCard(p, true) + '</div></div>' +
      '<div class="lsa-foot"><span class="material-symbols-rounded">info</span><span>Discounts and free delivery apply to website orders when the member is logged in. Faster earning applies to every completed order with their phone number. Renewing early adds the time on.</span>' +
        '<button type="button" class="btn btn-primary" data-lsv-save><span class="material-symbols-rounded">save</span> ' + (p.id ? 'Save plan' : 'Add plan') + '</button></div>' +
    '</div>';
  }

  function draw(el) {
    if (!D) { el.innerHTML = '<div class="up-empty">Loading…</div>'; return; }
    S = S || Object.assign({}, D.settings);
    var k = D.kpis, plans = D.plans || [];
    var pay = [];
    if (!D.phonepe_ready) pay.push('PhonePe is not set up yet, so customers can only join with their wallet' + (D.wallet_enabled ? '' : ' (also off: turn it on in Offers > Wallet)') + '.');
    el.innerHTML =
      '<div class="ls-hero lsv-hero"><div><label class="up-switch" style="margin:0"><input type="checkbox" id="lsvOn"' + (S.enabled ? ' checked' : '') + '><span></span> <b>Membership is ' + (S.enabled ? 'on' : 'off') + '</b></label>' +
        '<p>Regulars pay a small fee and get perks on every order. You get money up front and customers who keep choosing you.</p></div>' +
        '<div class="ls-kpis"><div><b>' + k.members + '</b><span>Members</span></div><div><b>' + money(k.revenue_month) + '</b><span>Sold this month</span></div><div><b>' + money(k.given) + '</b><span>Discounts given</span></div></div></div>' +
      '<div class="ls-box lsv-club"><div class="ls-grid">' +
        '<label>Club name<input type="text" id="lsvName" maxlength="40" value="' + esc(S.name) + '" placeholder="VIP Club"></label>' +
        '<label style="grid-column:span 2">Tagline <input type="text" id="lsvTag" maxlength="120" value="' + esc(S.tagline) + '" placeholder="Save on every order, all year"></label>' +
      '</div><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap">' +
        '<span class="lsa-fine" style="margin:0">' + (k.renewing_soon ? k.renewing_soon + ' membership' + (k.renewing_soon > 1 ? 's end' : ' ends') + ' in the next 7 days · ' : '') + money(k.revenue_total) + ' earned from memberships so far</span>' +
        '<button type="button" class="btn btn-primary" data-lsv-settings><span class="material-symbols-rounded">save</span> Save</button></div>' +
      (pay.length ? '<div class="lsa-note" style="margin-top:12px">' + esc(pay.join(' ')) + '</div>' : '') + '</div>' +
      '<div class="lsg-sec" style="margin-top:22px"><div class="lsg-sec-h"><div><h3 class="ls-h" style="margin:0">Plans</h3><p>Up to 6. Customers see them side by side and pick one.</p></div></div>' +
        (edit ? editorHtml() : '<div class="lsg-tpls"><span>Start from</span>' + TEMPLATES.map(function (t, i) { return '<button type="button" data-lsv-tpl="' + i + '"><span class="material-symbols-rounded">add</span>' + t.name + ' · ' + money(t.price) + '</button>'; }).join('') + '<button type="button" data-lsv-tpl="-1"><span class="material-symbols-rounded">add</span>Blank plan</button></div>') +
        (plans.length ? '<div class="lsv-plans">' + plans.map(function (p) { return planCard(p, false); }).join('') + '</div>'
                      : (edit ? '' : '<div class="lsa-empty" style="margin-top:12px"><span class="material-symbols-rounded">diamond</span><b>No plans yet</b><span>Start from a template above. A quarterly plan with a welcome gift sells best.</span></div>')) +
      '</div>' +
      '<div class="lsg-sec"><h3 class="ls-h">Latest members</h3>' + (D.recent.length ? '<div class="lsa-recent">' + D.recent.map(function (r) {
        return '<div class="lsa-row"><span class="lsa-ric material-symbols-rounded">diamond</span><div><b>' + esc(r.name) + ' <small style="font-weight:500">' + esc(r.phone) + '</small></b><small>' + esc(r.plan) + ' · ' + money(r.amount) + ' via ' + (r.via === 'wallet' ? 'wallet' : 'PhonePe') + ' · until ' + esc(String(r.ends_at).slice(0, 10)) + '</small></div></div>';
      }).join('') + '</div>' : '<div class="lsa-empty"><span class="material-symbols-rounded">group</span><b>No members yet</b><span>Once a plan is on sale, customers join from the rewards page.</span></div>') + '</div>';
  }
  function redraw() { draw(document.getElementById('lsPanel')); }

  function readEditor() {
    var box = document.querySelector('.lsv-ed');
    if (!box || !edit) return;
    box.querySelectorAll('[data-v]').forEach(function (f) {
      var k = f.getAttribute('data-v');
      if (f.type === 'checkbox') edit[k] = f.checked ? 1 : 0;
      else if (k === 'name' || k === 'gift_kind') edit[k] = f.value || (k === 'gift_kind' ? null : '');
      else edit[k] = f.value === '' ? 0 : +f.value;
    });
  }
  function readClub() { var n = document.getElementById('lsvName'), t = document.getElementById('lsvTag'), o = document.getElementById('lsvOn'); if (n) S.name = n.value; if (t) S.tagline = t.value; if (o) S.enabled = o.checked ? 1 : 0; }
  function saveClub() {
    readClub();
    return L().post({ section: 'vip', action: 'settings', enabled: S.enabled, name: S.name, tagline: S.tagline }).then(function (d) {
      L().notify(d.success ? 'Membership saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error');
    });
  }

  window.lsDraw_vip = function (el, data) { if (data && data.success) { D = data; S = Object.assign({}, D.settings); } draw(el); };

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D || !document.querySelector('.lsv-club')) return;
    var t;
    if (e.target.closest('[data-lsv-settings]')) { saveClub(); return; }
    if ((t = e.target.closest('[data-lsv-tpl]'))) {
      var i = +t.getAttribute('data-lsv-tpl');
      readClub();
      edit = Object.assign({ id: 0, gift_item_id: null }, i < 0 ? { name: '', price: 0, duration_days: 30, discount_pct: 10, discount_max: 0, free_delivery: 0, free_delivery_min: 0, earn_boost: 1, gift_kind: null, gift_value: 0, highlight: 0 } : TEMPLATES[i]);
      confirmDel = null; redraw(); return;
    }
    if (e.target.closest('[data-lsv-cancel]')) { edit = null; redraw(); return; }
    if ((t = e.target.closest('[data-lsv-edit]'))) {
      var id = +t.getAttribute('data-lsv-edit'); readClub();
      edit = Object.assign({}, D.plans.filter(function (p) { return p.id === id; })[0]); confirmDel = null; redraw();
      var ed = document.querySelector('.lsv-ed'); if (ed) ed.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if ((t = e.target.closest('[data-lsv-del]'))) {
      var did = +t.getAttribute('data-lsv-del');
      if (confirmDel !== did) { confirmDel = did; readClub(); redraw(); return; }
      L().post({ section: 'vip', action: 'delete', id: did }).then(function (d) {
        L().notify(d.success ? (d.hidden ? 'Plan hidden. Its members keep their perks until it ends.' : 'Plan removed') : (d.message || 'Could not remove'), d.success ? 'success' : 'error');
        confirmDel = null; L().reload();
      });
      return;
    }
    if ((t = e.target.closest('[data-lsv-save]'))) {
      readEditor(); t.disabled = true;
      L().post(Object.assign({ section: 'vip', action: 'save' }, edit)).then(function (d) {
        t.disabled = false;
        L().notify(d.success ? (edit.id ? 'Plan saved' : 'Plan added') + (S.enabled ? '' : '. Turn membership on to start selling.') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        if (d.success) { edit = null; L().reload(); }
      }).catch(function () { t.disabled = false; L().notify('Network error. Please try again.', 'error'); });
    }
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D || !document.querySelector('.lsv-club')) return;
    var t = e.target;
    if (t.id === 'lsvOn') { if (edit) readEditor(); saveClub().then(function () { redraw(); }); return; }
    if (t.hasAttribute('data-lsv-toggle')) {
      var id = +t.getAttribute('data-lsv-toggle'), on = t.checked;
      L().post({ section: 'vip', action: 'toggle', id: id, active: on ? 1 : 0 }).then(function (d) {
        L().notify(d.success ? (on ? 'Plan is on sale' : 'Plan hidden') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        L().reload();
      });
      return;
    }
    if (t.closest('.lsv-ed')) { readClub(); readEditor(); redraw(); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.closest || !e.target.closest('.lsv-ed') || !edit) return;
    readEditor();
    var prev = document.querySelector('.lsv-prev .lsv-plan');
    if (prev) prev.outerHTML = planCard(edit, true);
  });
})();
