/*
 * Loyalty Studio > Games tab: the after-checkout game's style (scratch card or
 * spin wheel, same prizes and budget as Offers > Scratch card) and challenges
 * customers complete by ordering (visits, spend, try a dish, weekly streaks).
 * Draws through window.lsDraw_games (see loyalty-studio.js).
 */
(function () {
  'use strict';

  var KINDS = {
    visits: ['repeat', 'Visits', 'Order N times in a month', 'Orders this month', 3],
    spend:  ['payments', 'Spend', 'Spend an amount in a month', 'Amount to spend', 2000],
    dish:   ['restaurant', 'Try a dish', 'Order a chosen dish', 'Times to order it', 1],
    streak: ['local_fire_department', 'Weekly streak', 'Order every week, N weeks in a row', 'Weeks in a row', 3]
  };
  var TEMPLATES = [
    ['visits', 'Visit 3 times', { target: 3, reward_kind: 'flat', reward_value: 100, min_order: 299 }],
    ['spend', 'Spend 2,000', { target: 2000, reward_kind: 'percent', reward_value: 15 }],
    ['dish', 'Try a new dish', { target: 1, reward_kind: 'flat', reward_value: 50, min_order: 199 }],
    ['streak', '3-week streak', { target: 3, reward_kind: 'item' }]
  ];
  var D = null, edit = null, confirmDel = null;

  function L() { return window.lsApi; }
  function esc(s) { return L().esc(s); }
  function money(n) { return L().money(n); }
  function cur() { return (D && D.currency) || '₹'; }
  function dish(id) { return (D.items || []).filter(function (i) { return i.id === +id; })[0]; }

  function rewardText(c) {
    if (c.reward_kind === 'percent') return (+c.reward_value || 0) + '% off';
    if (c.reward_kind === 'item') { var it = dish(c.reward_item_id); return it ? 'Free ' + it.name : 'A free dish'; }
    return money(+c.reward_value || 0) + ' off';
  }
  function autoTitle(c) {
    var n = +c.target || 0, it = dish(c.item_id);
    if (c.kind === 'visits') return 'Order ' + n + ' times this month';
    if (c.kind === 'spend') return 'Spend ' + money(n) + ' this month';
    if (c.kind === 'dish') return n > 1 ? 'Order ' + (it ? it.name : 'the dish') + ' ' + n + ' times this month' : 'Try our ' + (it ? it.name : 'special');
    return 'Order ' + n + ' weeks in a row';
  }

  /* ── The after-checkout game ── */
  function wheelArt() {
    var seg = ['#7c3aed', '#fde68a', '#a78bfa', '#fef3c7', '#6d28d9', '#fcd34d', '#c4b5fd', '#fffbeb'];
    var stops = seg.map(function (c, i) { return c + ' ' + (i * 45) + 'deg ' + ((i + 1) * 45) + 'deg'; }).join(',');
    return '<div class="lsg-wheel"><i style="background:conic-gradient(' + stops + ')"></i><b></b><em></em></div>';
  }
  function scratchArt() {
    return '<div class="lsg-scratch"><span>₹50 OFF</span><i></i></div>';
  }
  function gameHtml() {
    var g = D.game, prizes = g.prizes || [];
    var styles = [['scratch', 'Scratch card', 'Rub the foil to reveal the prize. Quick and familiar.', scratchArt()],
                  ['wheel', 'Spin the wheel', 'Tap to spin, the wheel lands on the prize. Big, fun moment.', wheelArt()]];
    return '<div class="lsg-sec"><div class="lsg-sec-h"><div><h3 class="ls-h" style="margin:0">After-checkout game</h3><p>Shown on the order tracking page once the restaurant accepts the order. Same prizes, chances and monthly budget for both styles.</p></div>' +
        '<button type="button" class="btn btn-secondary" data-lsg-prizes><span class="material-symbols-rounded">tune</span> Prizes and budget</button></div>' +
      '<div class="lsg-styles">' + styles.map(function (s) {
        var on = g.style === s[0];
        return '<button type="button" class="lsg-style' + (on ? ' on' : '') + '" data-lsg-style="' + s[0] + '">' + s[3] +
          '<div><b>' + s[1] + (on ? ' <em>In use</em>' : '') + '</b><small>' + s[2] + '</small></div></button>';
      }).join('') + '</div>' +
      '<div class="lsg-status' + (g.enabled ? '' : ' off') + '"><span class="material-symbols-rounded">' + (g.enabled ? 'check_circle' : 'pause_circle') + '</span>' +
        (g.enabled ? '<span><b>On</b> · ' + prizes.length + ' prizes · ' + money(g.spent) + (g.budget > 0 ? ' of ' + money(g.budget) : '') + ' given this month · ' + g.played + ' played, ' + g.won + ' won</span>'
                   : '<span><b>Off.</b> Turn it on and set the prizes in Prizes and budget.</span>') + '</div>' +
    '</div>';
  }

  /* ── Challenges ── */
  function challengeCard(c) {
    var k = KINDS[c.kind], st = c.stats || {};
    var del = confirmDel === c.id;
    return '<div class="lsg-ch' + (c.active ? ' on' : '') + '">' +
      '<div class="lsa-top"><span class="lsa-ic material-symbols-rounded">' + k[0] + '</span>' +
        '<div class="lsa-t"><b>' + esc(c.display_title) + '</b><small>' + k[1] + ' · <em>' + esc(c.reward) + (c.min_order > 0 ? ' above ' + money(c.min_order) : '') + '</em> · ' + (c.kind === 'streak' ? 'every ' + (+c.target) + ' weeks' : 'resets monthly') + '</small></div>' +
        '<label class="up-switch lsa-sw"><input type="checkbox" data-lsg-toggle="' + c.id + '"' + (c.active ? ' checked' : '') + '><span></span></label></div>' +
      '<div class="lsa-nums"><div><b>' + (st.taking_part || 0) + '</b><span>Customers active</span></div><div><b>' + (st.completed || 0) + '</b><span>Completed</span></div><div><b>' + (st.used || 0) + '</b><span>Rewards used</span></div>' +
        '<button type="button" class="lsa-edit" data-lsg-edit="' + c.id + '">Edit<span class="material-symbols-rounded">tune</span></button>' +
        '<button type="button" class="lsa-edit lsg-del' + (del ? ' sure' : '') + '" data-lsg-del="' + c.id + '" title="Remove">' + (del ? 'Remove?' : '<span class="material-symbols-rounded">delete</span>') + '</button></div>' +
    '</div>';
  }

  function editorHtml() {
    var c = edit, k = KINDS[c.kind];
    var opt = function (sel) { return '<option value="">Pick a dish</option>' + (D.items || []).map(function (it) { return '<option value="' + it.id + '"' + (+sel === it.id ? ' selected' : '') + '>' + esc(it.name) + ' · ' + money(it.price) + '</option>'; }).join(''); };
    var t = +c.target || 0, prog = c.kind === 'spend' ? Math.round(t * 0.6) : Math.max(0, Math.min(t - 1, Math.ceil(t * 0.6)));
    var pct = t ? Math.round(prog / t * 100) : 0;
    var progLbl = c.kind === 'spend' ? money(prog) + ' of ' + money(t) : c.kind === 'streak' ? prog + ' of ' + t + ' weeks' : prog + ' of ' + t;
    return '<div class="lsg-ed">' +
      '<div class="lsg-ed-h"><b>' + (c.id ? 'Edit challenge' : 'New challenge') + '</b><button type="button" class="lsa-edit" data-lsg-cancel>Cancel</button></div>' +
      '<div class="lsg-kinds">' + Object.keys(KINDS).map(function (key) {
        return '<button type="button" class="lsg-kind' + (c.kind === key ? ' on' : '') + '" data-lsg-kind="' + key + '"><span class="material-symbols-rounded">' + KINDS[key][0] + '</span><b>' + KINDS[key][1] + '</b><small>' + KINDS[key][2] + '</small></button>';
      }).join('') + '</div>' +
      '<div class="lsg-ed-body"><div>' +
        '<div class="ls-grid">' +
          '<label>' + k[3] + (c.kind === 'spend' ? ' (' + esc(cur()) + ')' : '') + '<input type="number" data-g="target" min="1" value="' + (c.target || '') + '"></label>' +
          (c.kind === 'dish' ? '<label>Dish<select data-g="item_id">' + opt(c.item_id) + '</select></label>' : '') +
          '<label>Reward<select data-g="reward_kind"><option value="flat"' + (c.reward_kind === 'flat' ? ' selected' : '') + '>' + esc(cur()) + ' off</option><option value="percent"' + (c.reward_kind === 'percent' ? ' selected' : '') + '>% off</option><option value="item"' + (c.reward_kind === 'item' ? ' selected' : '') + '>A free dish</option></select></label>' +
          (c.reward_kind === 'item' ? '<label>Free dish<select data-g="reward_item_id">' + opt(c.reward_item_id) + '</select></label>'
                                    : '<label>' + (c.reward_kind === 'percent' ? 'Percent off' : 'Amount off') + '<input type="number" data-g="reward_value" min="1" value="' + (c.reward_value || '') + '"></label>') +
          '<label>Reward on orders above<input type="number" data-g="min_order" min="0" value="' + (c.min_order || '') + '" placeholder="Any order"></label>' +
          '<label>Reward valid for<select data-g="valid_days">' + [7, 14, 30, 60].map(function (n) { return '<option value="' + n + '"' + (n === (+c.valid_days || 30) ? ' selected' : '') + '>' + n + ' days</option>'; }).join('') + '</select></label>' +
        '</div>' +
        '<label class="lsg-title"><span>Title customers see <i>optional</i></span><input type="text" data-g="title" maxlength="80" value="' + esc(c.title || '') + '" placeholder="' + esc(autoTitle(c)) + '"></label>' +
      '</div>' +
      '<div class="lsg-prev"><span class="lsa-bubble-h" style="color:#6d28d9">On the rewards page</span>' +
        '<div class="lsg-prev-card"><span class="lsg-prev-ic material-symbols-rounded">' + k[0] + '</span><div><b>' + esc(c.title || autoTitle(c)) + '</b><small>Reward: ' + esc(rewardText(c)) + '</small>' +
        '<div class="lsg-bar"><i style="width:' + pct + '%"></i></div><small>' + esc(progLbl) + (c.kind === 'streak' ? ' · keep it going this week' : ' · ends ' + new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })) + '</small></div></div>' +
      '</div></div>' +
      '<div class="lsa-foot"><span class="material-symbols-rounded">info</span><span>Every completed order counts: website, POS and table orders with the customer’s phone number. The reward arrives in <b>Your gifts</b> the moment the goal is reached.</span>' +
        '<button type="button" class="btn btn-primary" data-lsg-save><span class="material-symbols-rounded">save</span> ' + (c.id ? 'Save' : 'Start challenge') + '</button></div>' +
    '</div>';
  }

  function challengesHtml() {
    var list = D.challenges || [];
    return '<div class="lsg-sec"><div class="lsg-sec-h"><div><h3 class="ls-h" style="margin:0">Challenges</h3><p>Goals customers reach by ordering, with a reward at the end. Progress shows on their rewards page.</p></div></div>' +
      (edit ? editorHtml() : '<div class="lsg-tpls"><span>Start from</span>' + TEMPLATES.map(function (t, i) {
        return '<button type="button" data-lsg-tpl="' + i + '"><span class="material-symbols-rounded">' + KINDS[t[0]][0] + '</span>' + (t[0] === 'spend' ? 'Spend ' + money(2000) : t[1]) + '</button>';
      }).join('') + '</div>') +
      (list.length ? '<div class="lsa-grid" style="margin-top:12px">' + list.map(challengeCard).join('') + '</div>'
                   : (edit ? '' : '<div class="lsa-empty" style="margin-top:12px"><span class="material-symbols-rounded">flag</span><b>No challenges yet</b><span>Pick a starting point above. You can change every detail before it goes live.</span></div>')) +
    '</div>';
  }

  function draw(el) {
    if (!D) { el.innerHTML = '<div class="up-empty">Loading…</div>'; return; }
    var active = (D.challenges || []).filter(function (c) { return c.active; }).length;
    var done = (D.challenges || []).reduce(function (n, c) { return n + ((c.stats || {}).completed || 0); }, 0);
    el.innerHTML =
      '<div class="ls-hero"><div><b style="font-size:1.1rem">Games that bring them back</b><p>A prize after every order, and goals worth coming back for.' + (D.loyalty_enabled ? '' : ' <b style="color:#fde68a">The loyalty program is off, so challenges pause.</b>') + '</p></div>' +
        '<div class="ls-kpis"><div><b>' + active + '</b><span>Live challenges</span></div><div><b>' + done + '</b><span>Completed</span></div><div><b>' + (D.game.played || 0) + '</b><span>Games played (month)</span></div></div></div>' +
      gameHtml() + challengesHtml();
  }
  function redraw() { draw(document.getElementById('lsPanel')); }

  function readEditor() {
    var box = document.querySelector('.lsg-ed');
    if (!box || !edit) return;
    box.querySelectorAll('[data-g]').forEach(function (f) {
      var k = f.getAttribute('data-g');
      edit[k] = (k === 'title' || k === 'reward_kind') ? f.value : (f.value === '' ? 0 : +f.value);
    });
  }

  window.lsDraw_games = function (el, data) { if (data && data.success) D = data; draw(el); };

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D || !document.querySelector('.lsg-sec')) return;
    var t;
    if ((t = e.target.closest('[data-lsg-style]'))) {
      var style = t.getAttribute('data-lsg-style');
      if (style === D.game.style) return;
      D.game.style = style; redraw();
      L().post({ section: 'games', action: 'style', style: style }).then(function (d) {
        L().notify(d.success ? (style === 'wheel' ? 'Customers now spin the wheel' : 'Customers now scratch a card') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
      });
      return;
    }
    if (e.target.closest('[data-lsg-prizes]')) { var nav = document.querySelector('[data-page="scratchPage"]'); if (nav) nav.click(); return; }
    if ((t = e.target.closest('[data-lsg-tpl]'))) {
      var tp = TEMPLATES[+t.getAttribute('data-lsg-tpl')];
      edit = Object.assign({ id: 0, kind: tp[0], title: '', item_id: null, reward_item_id: null, reward_value: 0, min_order: 0, valid_days: 30 }, tp[2]);
      confirmDel = null; redraw(); return;
    }
    if ((t = e.target.closest('[data-lsg-kind]'))) { readEditor(); edit.kind = t.getAttribute('data-lsg-kind'); edit.target = KINDS[edit.kind][4]; redraw(); return; }
    if (e.target.closest('[data-lsg-cancel]')) { edit = null; redraw(); return; }
    if ((t = e.target.closest('[data-lsg-edit]'))) {
      var id = +t.getAttribute('data-lsg-edit');
      edit = Object.assign({}, D.challenges.filter(function (c) { return c.id === id; })[0]);
      confirmDel = null; redraw();
      var ed = document.querySelector('.lsg-ed'); if (ed) ed.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if ((t = e.target.closest('[data-lsg-del]'))) {
      var did = +t.getAttribute('data-lsg-del');
      if (confirmDel !== did) { confirmDel = did; redraw(); return; }
      L().post({ section: 'games', action: 'delete', id: did }).then(function (d) {
        L().notify(d.success ? 'Challenge removed' : (d.message || 'Could not remove'), d.success ? 'success' : 'error');
        confirmDel = null; L().reload();
      });
      return;
    }
    if ((t = e.target.closest('[data-lsg-save]'))) {
      readEditor(); t.disabled = true;
      L().post(Object.assign({ section: 'games', action: 'save' }, edit)).then(function (d) {
        t.disabled = false;
        L().notify(d.success ? (edit.id ? 'Challenge saved' : 'Challenge is live') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        if (d.success) { edit = null; L().reload(); }
      }).catch(function () { t.disabled = false; L().notify('Network error. Please try again.', 'error'); });
    }
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D) return;
    var t = e.target;
    if (t.hasAttribute('data-lsg-toggle')) {
      var id = +t.getAttribute('data-lsg-toggle'), on = t.checked;
      L().post({ section: 'games', action: 'toggle', id: id, active: on ? 1 : 0 }).then(function (d) {
        L().notify(d.success ? (on ? 'Challenge is live' : 'Challenge paused') : (d.message || 'Could not save'), d.success ? 'success' : 'error');
        L().reload();
      });
      return;
    }
    if (t.closest('.lsg-ed')) { readEditor(); redraw(); }
  });
  document.addEventListener('input', function (e) {
    if (!e.target.closest || !e.target.closest('.lsg-ed') || !edit) return;
    if (e.target.getAttribute('data-g') !== 'title') return;
    edit.title = e.target.value;
    var b = document.querySelector('.lsg-prev-card b'); if (b) b.textContent = edit.title || autoTitle(edit);
  });
})();
