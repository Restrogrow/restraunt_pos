/*
 * Shared "how did it do" pieces for every marketing tool (WhatsApp campaigns and automatic messages,
 * My campaigns, Google posts, coupons, reviews). Styles: assets/css/rg-stats.css.
 *
 *   RGStats.funnel([{ label: 'Sent', value: 120, icon: 'send' }, …], { money: '₹', revenue: 4300, note: '…' })
 *       a row of steps, each with its number and the % of the step before it
 *   RGStats.spark([3, 5, 0, 8])          a tiny bar chart (last 14 days etc.)
 *   RGStats.badge('Best campaign', 'gold')
 *   RGStats.pct(part, whole)             "37%" or "–"
 */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(n) { n = +n || 0; return n >= 100000 ? (n / 100000).toFixed(n >= 1e6 ? 0 : 1).replace(/\.0$/, '') + 'L' : n >= 10000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : n.toLocaleString('en-IN'); }
  function pct(a, b) { a = +a || 0; b = +b || 0; if (!b) return '–'; var p = a * 100 / b; return (p >= 10 || p === 0 ? Math.round(p) : p.toFixed(1)) + '%'; }
  function money(sym, n) { return (sym || '₹') + Math.round(+n || 0).toLocaleString('en-IN'); }

  /**
   * steps: [{ label, value, icon, hint, muted }] in order (each % is of the step before it)
   * o: { money, revenue, revenueLabel, note, compact, extra: [{label, value, tone}] }
   */
  function funnel(steps, o) {
    o = o || {};
    var shown = steps.filter(function (s) { return s && !s.hide; });
    var out = '<div class="rgs-funnel' + (o.compact ? ' compact' : '') + '">';
    shown.forEach(function (s, i) {
      var prev = i ? shown[i - 1].value : null;
      var p = i ? pct(s.value, prev) : '';
      out += '<div class="rgs-step' + (s.muted ? ' muted' : '') + (s.strong ? ' strong' : '') + '" title="' + esc(s.hint || '') + '">' +
        (s.icon ? '<span class="material-symbols-rounded rgs-ic">' + esc(s.icon) + '</span>' : '') +
        '<b>' + (s.text != null ? esc(s.text) : num(s.value)) + '</b><span>' + esc(s.label) + '</span>' +
        (i && s.value != null && !s.noPct ? '<i class="rgs-pct">' + p + '</i>' : '') + '</div>';
      if (i < shown.length - 1) out += '<span class="rgs-arrow material-symbols-rounded" aria-hidden="true">chevron_right</span>';
    });
    if (o.revenue != null) out += '<div class="rgs-step rgs-rev"><span class="material-symbols-rounded rgs-ic">payments</span><b>' + money(o.money, o.revenue) + '</b><span>' + esc(o.revenueLabel || 'Sales') + '</span></div>';
    out += '</div>';
    if (o.extra && o.extra.length) out += '<div class="rgs-extra">' + o.extra.filter(Boolean).map(function (x) { return '<span class="rgs-chip ' + esc(x.tone || '') + '">' + (x.icon ? '<span class="material-symbols-rounded">' + esc(x.icon) + '</span>' : '') + esc(x.label) + (x.value != null ? ' <b>' + esc(x.value) + '</b>' : '') + '</span>'; }).join('') + '</div>';
    if (o.note) out += '<p class="rgs-note">' + o.note + '</p>';
    return out;
  }

  function spark(vals, o) {
    o = o || {};
    vals = (vals || []).map(function (v) { return +v || 0; });
    var max = Math.max.apply(null, vals.concat([1]));
    return '<span class="rgs-spark" title="' + esc(o.title || 'Last ' + vals.length + ' days') + '">' + vals.map(function (v) { return '<i style="height:' + Math.max(8, Math.round(v * 100 / max)) + '%"' + (v ? '' : ' class="z"') + '></i>'; }).join('') + '</span>';
  }

  function badge(text, tone, icon) { return '<span class="rgs-badge ' + esc(tone || '') + '">' + (icon ? '<span class="material-symbols-rounded">' + esc(icon) + '</span>' : '') + esc(text) + '</span>'; }

  /** Turns a few numbers into one plain sentence: "42 people opened it and 6 ordered (14%)". */
  function sentence(o) {
    var bits = [];
    if (o.people != null) bits.push('<b>' + num(o.people) + '</b> ' + (o.people === 1 ? 'person' : 'people') + ' ' + (o.verb || 'opened it'));
    if (o.orders != null) bits.push('<b>' + num(o.orders) + '</b> ordered' + (o.people ? ' (' + pct(o.orders, o.people) + ')' : ''));
    if (o.revenue) bits.push(money(o.money, o.revenue) + ' in sales');
    return bits.length ? bits.join(o.orders != null && bits.length === 2 ? ' and ' : ', ') : '';
  }

  window.RGStats = { funnel: funnel, spark: spark, badge: badge, pct: pct, num: num, money: money, sentence: sentence, esc: esc };
})();
