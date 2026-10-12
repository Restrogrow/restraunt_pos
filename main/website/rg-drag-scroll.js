/*
 * Sideways rows you can drag with a mouse.
 *
 * Rows that scroll sideways hide their scrollbar, which is fine on phones
 * (swipe) but leaves a mouse with no way to move them. This lets a mouse
 * drag them, glides a little on release, snaps back to a card, and makes
 * sure the end of a drag doesn't count as a tap on a dish. Touch and
 * trackpads keep their native scrolling. Works with rows that are re-drawn
 * later (event delegation).
 *
 * Rows: Home page "Carousel" look (each category) and banner slideshows.
 */
(function () {
  'use strict';
  var ROWS = '#menuList.hc-carousel [id^="catItems"], .rg-bn-track, .tds-track, .ups-track';

  // Mouse users get a thin scrollbar too, so the row looks scrollable
  var st = document.createElement('style');
  st.textContent =
    '@media (hover:hover) and (pointer:fine){' +
      '#menuList.hc-carousel [id^="catItems"]{scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.18) transparent;cursor:grab}' +
      '#menuList.hc-carousel [id^="catItems"]::-webkit-scrollbar{display:block;height:6px}' +
      '#menuList.hc-carousel [id^="catItems"]::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:3px}' +
      '.rg-bn-track{cursor:grab}' +
    '}' +
    '.rg-dragging{cursor:grabbing!important;scroll-snap-type:none!important;scroll-behavior:auto!important;user-select:none}' +
    '.rg-dragging *{pointer-events:none}';
  document.head.appendChild(st);

  var row = null, startX = 0, startLeft = 0, moved = false, lastX = 0, lastT = 0, v = 0, suppress = false;

  document.addEventListener('pointerdown', function (e) {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    var r = e.target.closest && e.target.closest(ROWS);
    if (!r || r.scrollWidth <= r.clientWidth + 2) return;
    row = r; moved = false; v = 0;
    startX = lastX = e.clientX; startLeft = r.scrollLeft; lastT = performance.now();
  });

  document.addEventListener('pointermove', function (e) {
    if (!row) return;
    var dx = e.clientX - startX;
    if (!moved) {
      if (Math.abs(dx) < 5) return;
      moved = true;
      row.classList.add('rg-dragging');
    }
    row.scrollLeft = startLeft - dx;
    var now = performance.now(), dt = now - lastT;
    if (dt > 0) v = 0.8 * ((e.clientX - lastX) / dt) + 0.2 * v;
    lastX = e.clientX; lastT = now;
    e.preventDefault();
  });

  function end() {
    if (!row) return;
    var r = row;
    row = null;
    if (!moved) return;
    suppress = true;
    setTimeout(function () { suppress = false; }, 0);
    if (performance.now() - lastT > 80) v = 0;
    // A short glide in the drag direction, then let the row snap to a card again
    var target = r.scrollLeft - v * 220;
    r.classList.remove('rg-dragging');
    r.scrollTo({ left: target, behavior: 'smooth' });
  }
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end);
  window.addEventListener('blur', end);

  // The release after a drag is not a tap (would open the dish / copy a code)
  document.addEventListener('click', function (e) {
    if (suppress && e.target.closest && e.target.closest(ROWS)) { e.stopPropagation(); e.preventDefault(); suppress = false; }
  }, true);
  // No ghost-dragging of the photos inside the row
  document.addEventListener('dragstart', function (e) {
    if (e.target.closest && e.target.closest(ROWS)) e.preventDefault();
  });
})();
