/*
 * Phones: data tables in the dashboard show as one card per row (styles in assets/css/mobile.css).
 * This labels every cell with its column name (data-label) and marks the table rg-cardtable,
 * also for rows added later (tables fill in after their data loads). Desktop is unaffected:
 * the card styles only apply at 640px and below. Opt out with data-no-cards on a parent.
 */
(function () {
  'use strict';

  function label(table) {
    if (table.closest('[data-no-cards], [contenteditable="true"], .ql-editor')) return;
    var heads = table.querySelectorAll('thead th');
    if (heads.length < 3) return;
    var names = Array.prototype.map.call(heads, function (th) { return th.textContent.replace(/\s+/g, ' ').trim(); });
    table.classList.add('rg-cardtable');
    // rows already labelled are skipped: the observer fires on anything the dashboard adds anywhere,
    // and re-labelling every cell of every table each time was pure repeat work
    Array.prototype.forEach.call(table.querySelectorAll('tbody tr:not([data-rg-cells])'), function (tr) {
      tr.setAttribute('data-rg-cells', '1');
      var col = 0;
      Array.prototype.forEach.call(tr.children, function (td) {
        if (td.tagName !== 'TD' && td.tagName !== 'TH') return;
        if (!td.hasAttribute('colspan') && !td.hasAttribute('data-label')) td.setAttribute('data-label', names[col] || '');
        col += parseInt(td.getAttribute('colspan') || '1', 10) || 1;
      });
    });
  }

  // Only the page being looked at, and only at the width where the card styles exist: every table of
  // all 60-odd pages was being walked again a few times a second on a desktop that never shows cards.
  function run() {
    if (window.innerWidth > 640) return;
    var page = document.querySelector('.main-content > .page.active');
    Array.prototype.forEach.call((page || document).querySelectorAll('table'), label);
  }

  var timer = null;
  function soon() {
    clearTimeout(timer);
    timer = setTimeout(run, 120);
  }

  function start() {
    run();
    // turned sideways, or the window made narrow: the open page's tables get their labels now
    window.addEventListener('resize', soon);
    var root = document.querySelector('.main-content') || document.body;
    new MutationObserver(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].addedNodes.length) { soon(); return; }
      }
    }).observe(root, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
