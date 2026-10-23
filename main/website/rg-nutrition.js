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
    '.rgn{margin:8px 0 16px;padding:14px;border-radius:20px;background:linear-gradient(180deg,#f7fbf8,#fff);border:1px solid #e6f2ea}' +
    '.rgn-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:10px}' +
    '.rgn-head b{font-size:16px;font-weight:800;color:#111827}' +
    '.rgn-head b.caps{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#6b7280}' +
    '.rgn-head span{font-size:11.5px;color:#9ca3af}' +
    '.rgn-tags{display:flex;flex-wrap:wrap;gap:6px;margin:-2px 0 12px}' +
    '.rgn-tag{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11.5px;font-weight:700}' +
    '.rgn-tag svg{width:12px;height:12px}' +
    '.rgn-tag.diet{background:#eef2ff;color:#3730a3}' +
    '.rgn-row{display:flex;gap:8px;align-items:stretch}' +
    '.rgn-kcal{flex:0 0 auto;min-width:78px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;padding:10px 12px;border-radius:16px;background:#111827;color:#fff}' +
    '.rgn-kcal svg{width:15px;height:15px;color:#fb923c;margin-bottom:2px}' +
    '.rgn-kcal b{font-size:20px;font-weight:800;line-height:1.1}' +
    '.rgn-kcal span{font-size:11.5px;color:#9ca3af}' +
    '.rgn-mac{flex:1;min-width:0;display:flex;align-items:stretch;padding:8px 2px;border-radius:16px;background:#fff;border:1px solid #e5efe8}' +
    '.rgn-mac>div{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2px 4px;text-align:center}' +
    '.rgn-mac>div+div{border-left:1.5px solid #eef2ef}' +
    // Phones: the one line gets too tight, so calories and each nutrient become 5 equal boxes, 3 + 2
    '@media (max-width:600px){' +
      '.rgn-row{flex-wrap:wrap;justify-content:center}' +
      '.rgn-mac{display:contents}' +
      '.rgn-kcal,.rgn-mac>div{flex:0 0 calc((100% - 16px) / 3);min-width:0;min-height:76px;padding:10px 4px;border-radius:16px;box-sizing:border-box}' +
      '.rgn-mac>div{background:#fff;border:1px solid #e5efe8}' +
      '.rgn-mac>div+div{border-left:1px solid #e5efe8}' +
      '.rgn-kcal b,.rgn-mac b{font-size:16px}' +
    '}' +
    '.rgn-mac i{display:block;width:8px;height:8px;border-radius:50%;margin-bottom:4px}' +
    '.rgn-mac b{font-size:15.5px;font-weight:800;color:#111827;line-height:1.2}' +
    '.rgn-mac span{font-size:11.5px;color:#6b7280}' +
    '.rgn-split{margin-top:12px}' +
    '.rgn-split .bar{display:flex;height:8px;border-radius:999px;overflow:hidden;background:#eef0f3}' +
    '.rgn-split .bar i{display:block;height:100%}' +
    '.rgn-split .lg{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:6px;font-size:11.5px;color:#6b7280}' +
    '.rgn-split .lg em{font-style:normal;display:inline-flex;align-items:center;gap:5px}' +
    '.rgn-split .lg em::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--c)}' +
    '.rgn-ing{margin:4px 0 16px}' +
    '.rgn-all{border:0;background:none;padding:0;font:600 12.5px inherit;color:#4b5563;text-decoration:underline dashed;text-underline-offset:4px;cursor:pointer}' +
    '.rgn-ing ul{list-style:none;margin:0;padding:0}' +
    '.rgn-ing li{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px dashed #eef0f3}' +
    '.rgn-ing li:last-child{border-bottom:0}' +
    '.rgn-ing li.has{cursor:pointer}' +
    '.rgn-ing .ck{flex-shrink:0;width:20px;height:20px;margin-top:1px;padding:4px;border-radius:50%;background:#dcfce7;color:#16a34a}' +
    '.rgn-ing li>div{flex:1;min-width:0;font-size:14px;line-height:1.45;color:#374151}' +
    '.rgn-ing li b{font-weight:700;color:#15803d}' +
    '.rgn-ing .dash{color:#9ca3af}' +
    '.rgn-ing li.has:not(.open)>div{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}' +
    '.rgn-ing .chev{flex-shrink:0;width:18px;height:18px;margin-top:2px;color:#9ca3af;transition:transform .25s}' +
    '.rgn-ing li.open .chev{transform:rotate(180deg)}' +
    '.rgn-note{margin:8px 0 0;font-size:11px;color:#9ca3af}';

  // Ticked health tags (keys as stored) and their labels
  var TAGS = { high_protein: 'High protein', low_carb: 'Low carb', low_fat: 'Low fat', high_fibre: 'High fibre', low_calorie: 'Low calorie', low_sugar: 'Low sugar',
    keto: 'Keto friendly', vegan: 'Vegan', gluten_free: 'Gluten free', dairy_free: 'Dairy free', jain: 'Jain' };
  var DIET = { keto: 1, vegan: 1, gluten_free: 1, dairy_free: 1, jain: 1 };
  var MAC_COL = { protein: '#22c55e', carbs: '#f59e0b', fat: '#ef4444', fibre: '#8b5cf6' };
  var SVG = {
    check: '<svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>',
    chev: '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
    bolt: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>',
    flame: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c1 3.5 5 5.4 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5.5 1-8.5z"/></svg>'
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
    var macros = [[p, 'protein'], [c, 'carbs'], [f, 'fat'], [fi, 'fibre']].filter(function (m) { return m[0] !== null; });
    var tags = String(item.health_tags || '').split(',').filter(function (t) { return TAGS[t]; });
    if (kcal || macros.length || tags.length) {
      out += '<div class="rgn"><div class="rgn-head"><b>Nutritional info</b><span>per serving</span></div>';
      if (tags.length) {
        out += '<div class="rgn-tags">' + tags.map(function (t) { return '<span class="rgn-tag' + (DIET[t] ? ' diet' : '') + '">' + (DIET[t] ? '' : SVG.bolt) + TAGS[t] + '</span>'; }).join('') + '</div>';
      }
      if (kcal || macros.length) {
        out += '<div class="rgn-row">';
        if (kcal) out += '<div class="rgn-kcal">' + SVG.flame + '<b>' + Math.round(kcal) + '</b><span>kcal</span></div>';
        if (macros.length) out += '<div class="rgn-mac">' + macros.map(function (m) { return '<div><i style="background:' + MAC_COL[m[1]] + '"></i><b>' + m[0] + 'g</b><span>' + m[1] + '</span></div>'; }).join('') + '</div>';
        out += '</div>';
        // Where the energy comes from (protein & carbs 4 kcal/g, fat 9 kcal/g)
        var e = [[p, 4, 'protein'], [c, 4, 'carbs'], [f, 9, 'fat']].filter(function (x) { return x[0]; });
        var tot = e.reduce(function (s, x) { return s + x[0] * x[1]; }, 0);
        if (e.length >= 2 && tot > 0) {
          out += '<div class="rgn-split"><div class="bar">' + e.map(function (x) { return '<i style="width:' + (x[0] * x[1] / tot * 100).toFixed(1) + '%;background:' + MAC_COL[x[2]] + '"></i>'; }).join('') + '</div>' +
            '<div class="lg">' + e.map(function (x) { return '<em style="--c:' + MAC_COL[x[2]] + '">' + Math.round(x[0] * x[1] / tot * 100) + '% from ' + x[2] + '</em>'; }).join('') + '</div></div>';
        }
      }
      out += '</div>';
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
