/*
 * Dish details: "Nutritional info" (kcal + protein / fat / carbs / fibre) and
 * "What's inside" (each ingredient with a tick, its name and an optional note,
 * expandable). Used by the dish sheet on the Home and Menu pages.
 *
 * Ingredients come as one per line, "Paneer - excellent source of protein"
 * (a note after " - " or ":"), or the older comma-separated list.
 */
(function () {
  'use strict';

  var CSS = '' +
    '.rgn{margin:6px 0 14px}' +
    '.rgn-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:10px}' +
    '.rgn-head b{font-size:16px;font-weight:800;color:#111827}' +
    '.rgn-head b.caps{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#6b7280}' +
    '.rgn-head span{font-size:11.5px;color:#9ca3af}' +
    '.rgn-row{display:flex;gap:8px;align-items:stretch}' +
    '.rgn-kcal{flex:0 0 auto;min-width:74px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px 12px;border-radius:18px;background:#f3f4f6}' +
    '.rgn-kcal b{font-size:19px;font-weight:800;color:#111827;line-height:1.1}' +
    '.rgn-kcal span{font-size:12px;color:#6b7280}' +
    '.rgn-mac{flex:1;min-width:0;display:flex;align-items:center;padding:8px 4px;border-radius:18px;background:#effaf2;border:1px solid #cfeedd}' +
    '.rgn-mac>div{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2px 4px;text-align:center}' +
    '.rgn-mac>div+div{border-left:1.5px solid #d7ecdf}' +
    '.rgn-mac b{font-size:15.5px;font-weight:800;color:#14532d;line-height:1.2}' +
    '.rgn-mac span{font-size:11.5px;color:#4b6355}' +
    '.rgn-mac .rgn-high svg{width:20px;height:20px;color:#16a34a}' +
    '.rgn-mac .rgn-high span{font-weight:700;color:#15803d}' +
    '.rgn-ing{margin:4px 0 16px;padding-top:12px;border-top:1px dashed #e5e7eb}' +
    '.rgn-all{border:0;background:none;padding:0;font:600 12.5px inherit;color:#4b5563;text-decoration:underline dashed;text-underline-offset:4px;cursor:pointer}' +
    '.rgn-ing ul{list-style:none;margin:0;padding:0}' +
    '.rgn-ing li{display:flex;align-items:flex-start;gap:10px;padding:9px 0}' +
    '.rgn-ing li.has{cursor:pointer}' +
    '.rgn-ing .ck{flex-shrink:0;width:18px;height:18px;margin-top:2px;color:#16a34a}' +
    '.rgn-ing li>div{flex:1;min-width:0;font-size:14px;line-height:1.45;color:#374151}' +
    '.rgn-ing li b{font-weight:700;color:#15803d}' +
    '.rgn-ing .dash{color:#9ca3af}' +
    '.rgn-ing li.has:not(.open)>div{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}' +
    '.rgn-ing .chev{flex-shrink:0;width:18px;height:18px;margin-top:2px;color:#9ca3af;transition:transform .25s}' +
    '.rgn-ing li.open .chev{transform:rotate(180deg)}' +
    '.rgn-note{margin:6px 0 0;font-size:11px;color:#9ca3af}';

  var SVG = {
    check: '<svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>',
    chev: '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
    bolt: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>'
  };

  function ensureCss() {
    if (document.getElementById('rgNutritionCss')) return;
    var st = document.createElement('style');
    st.id = 'rgNutritionCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function num(v) {
    var n = parseFloat(v);
    return isFinite(n) && n > 0 ? Math.round(n * 10) / 10 : null;
  }
  // "Paneer - protein and calcium" / "Paneer: ..." / "Paneer"; one per line, or comma-separated
  function parseIngredients(raw) {
    raw = String(raw || '');
    var parts = /\n/.test(raw) ? raw.split(/\r?\n/) : raw.split(',');
    return parts.map(function (t) { return t.trim(); }).filter(Boolean).map(function (t) {
      var m = t.match(/^(.+?)\s+[-–—]\s+(.+)$/) || t.match(/^([^:]+):\s*(.+)$/);
      return m ? { name: m[1].trim(), note: m[2].trim() } : { name: t, note: '' };
    });
  }

  function html(item) {
    ensureCss();
    item = item || {};
    var kcal = num(item.calories), p = num(item.protein_g), f = num(item.fat_g), c = num(item.carbs_g), fi = num(item.fibre_g);
    var out = '';
    var macros = [[p, 'protein'], [f, 'fat'], [c, 'carbs'], [fi, 'fibre']].filter(function (m) { return m[0] !== null; });
    if (kcal || macros.length) {
      out += '<div class="rgn"><div class="rgn-head"><b>Nutritional info</b><span>per serving</span></div><div class="rgn-row">';
      if (kcal) out += '<div class="rgn-kcal"><b>' + kcal + '</b><span>kcal</span></div>';
      if (macros.length) {
        out += '<div class="rgn-mac">' +
          (p !== null && p >= 20 ? '<div class="rgn-high">' + SVG.bolt + '<span>High protein</span></div>' : '') +
          macros.map(function (m) { return '<div><b>' + m[0] + 'g</b><span>' + m[1] + '</span></div>'; }).join('') + '</div>';
      }
      out += '</div></div>';
    }
    var list = parseIngredients(item.ingredients);
    if (list.length) {
      var notes = list.some(function (x) { return x.note; });
      out += '<div class="rgn-ing"><div class="rgn-head"><b class="caps">What\'s inside</b>' +
        (notes ? '<button type="button" class="rgn-all" data-rgn-all>Expand all</button>' : '<span>' + list.length + ' ingredient' + (list.length > 1 ? 's' : '') + '</span>') + '</div><ul>';
      list.forEach(function (x) {
        out += '<li' + (x.note ? ' class="has" role="button" tabindex="0" aria-expanded="false"' : '') + '>' + SVG.check +
          '<div><b>' + esc(x.name) + '</b>' + (x.note ? '<span class="dash"> - </span>' + esc(x.note) : '') + '</div>' +
          (x.note ? SVG.chev : '') + '</li>';
      });
      out += '</ul><p class="rgn-note">Ask the restaurant if you have an allergy.</p></div>';
    }
    return out;
  }

  // Tap an ingredient to open its note; "Expand all" opens / closes every one
  function toggle(li, open) {
    li.classList.toggle('open', open);
    li.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    var all = e.target.closest && e.target.closest('[data-rgn-all]');
    if (all) {
      var box = all.closest('.rgn-ing'), items = box.querySelectorAll('li.has');
      var open = Array.prototype.some.call(items, function (li) { return !li.classList.contains('open'); });
      items.forEach(function (li) { toggle(li, open); });
      all.textContent = open ? 'Collapse all' : 'Expand all';
      return;
    }
    var li = e.target.closest && e.target.closest('.rgn-ing li.has');
    if (li) toggle(li, !li.classList.contains('open'));
  }, true); // capture: the dish sheet stops clicks from bubbling up
  document.addEventListener('keydown', function (e) {
    var li = e.target.closest && e.target.closest('.rgn-ing li.has');
    if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(li, !li.classList.contains('open')); }
  }, true);

  window.RGNutrition = { html: html, parseIngredients: parseIngredients };
})();
