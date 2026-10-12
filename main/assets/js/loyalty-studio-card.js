/*
 * Loyalty Studio > Phone card tab: the rewards card customers add to Apple
 * Wallet or Google Wallet. Their balance, level and gifts update on the card
 * by themselves. The owner switches it on and picks the colours; the wallets
 * themselves are set up once for all restaurants by the RestroGrow team.
 * Draws through window.lsDraw_card (see loyalty-studio.js).
 */
(function () {
  'use strict';

  var SWATCHES = [['#1c1230', '#ffffff'], ['#0f172a', '#ffffff'], ['#7f1d1d', '#ffffff'], ['#14532d', '#ffffff'], ['#1e3a8a', '#ffffff'], ['#78350f', '#ffffff'], ['#fef3c7', '#3b2604'], ['#ffffff', '#111827']];
  var D = null;

  function L() { return window.lsApi; }
  function esc(s) { return L().esc(s); }
  function qr() {
    // a decorative QR-like pattern for the preview (the real card carries the member's code)
    var cells = '', seed = 7;
    for (var i = 0; i < 121; i++) { seed = (seed * 9301 + 49297) % 233280; var x = i % 11, y = Math.floor(i / 11);
      var finder = (x < 3 && y < 3) || (x > 7 && y < 3) || (x < 3 && y > 7);
      cells += '<i' + ((finder ? !(x === 1 && y === 1) && !(x === 9 && y === 1) && !(x === 1 && y === 9) : seed / 233280 > 0.5) ? ' class="on"' : '') + '></i>'; }
    return '<div class="lsc-qr">' + cells + '</div>';
  }
  function cards() {
    var p = D.preview, bg = D.color, fg = D.text_color;
    // an empty balance looks broken in a preview: show a typical one instead
    var empty = /^\D*0( \/|$)/.test(p.balance), goal = (p.balance.split(' / ')[1] || '6');
    var bal = !empty ? p.balance : p.program === 'stamps' ? '3 / ' + goal : p.program === 'cashback' ? D.currency + '120' : '240';
    var logo = p.logo_url ? '<img src="' + esc(p.logo_url) + '" alt="">' : '<span>' + esc(p.restaurant.charAt(0)) + '</span>';
    var apple = '<div class="lsc-device"><div class="lsc-cap">Apple Wallet</div><div class="lsc-apple" style="background:' + bg + ';color:' + fg + '">' +
      '<div class="lsc-a-top"><span class="lsc-logo">' + logo + '</span><b>' + esc(p.restaurant) + '</b><div class="lsc-a-bal"><small>' + esc(p.balance_label.toUpperCase()) + '</small><b>' + esc(bal) + '</b></div></div>' +
      '<div class="lsc-a-mem"><small>MEMBER</small><b>' + esc(p.name) + '</b></div>' +
      '<div class="lsc-a-row">' + (p.tier ? '<div><small>LEVEL</small><b>' + esc(p.tier) + '</b></div>' : '') + '<div><small>GIFTS READY</small><b>2</b></div></div>' +
      '<div class="lsc-a-code">' + qr() + '<small>' + esc(p.barcode_alt) + '</small></div></div></div>';
    var google = '<div class="lsc-device"><div class="lsc-cap">Google Wallet</div><div class="lsc-google" style="background:' + bg + ';color:' + fg + '">' +
      '<div class="lsc-g-top"><span class="lsc-logo round">' + logo + '</span><b>' + esc(p.restaurant) + '</b></div>' +
      '<div class="lsc-g-prog">' + esc(p.restaurant) + ' Rewards</div>' +
      '<div class="lsc-g-row"><div><small>' + esc(p.balance_label) + '</small><b>' + esc(bal) + '</b></div>' + (p.tier ? '<div><small>Level</small><b>' + esc(p.tier) + '</b></div>' : '') + '</div>' +
      '<div class="lsc-g-name">' + esc(p.name) + '</div>' +
      '<div class="lsc-g-code">' + qr() + '<small>' + esc(p.barcode_alt) + '</small></div></div></div>';
    return apple + google;
  }
  function draw(el) {
    if (!D) { el.innerHTML = '<div class="up-empty">Loading…</div>'; return; }
    var pf = D.platforms, any = pf.apple || pf.google;
    el.innerHTML =
      '<div class="ls-hero lsc-hero"><div><label class="up-switch" style="margin:0"><input type="checkbox" id="lscOn"' + (D.enabled ? ' checked' : '') + '><span></span> <b>Phone card is ' + (D.enabled ? 'on' : 'off') + '</b></label>' +
        '<p>Members add your rewards card to Apple Wallet or Google Wallet in one tap. Their ' + esc(D.preview.balance_label.toLowerCase()) + ', level and gifts update on it by themselves, and your logo sits on their phone.</p></div>' +
        '<div class="ls-kpis"><div><b>' + D.counts.apple + '</b><span>Apple Wallet</span></div><div><b>' + D.counts.google + '</b><span>Google Wallet</span></div></div></div>' +
      '<div class="lsc-wrap"><div class="lsc-side">' +
        '<h3 class="ls-h">Card colour</h3><div class="lsc-sw">' + SWATCHES.map(function (s) {
          return '<button type="button" class="' + (s[0] === D.color ? 'on' : '') + '" data-lsc-sw="' + s[0] + '|' + s[1] + '" style="background:' + s[0] + ';color:' + s[1] + '">Aa</button>'; }).join('') +
          '<label class="lsc-custom"><input type="color" id="lscColor" value="' + esc(D.color) + '"><span>Custom</span></label></div>' +
        '<div class="ls-grid" style="margin-top:12px"><label>Text colour<select id="lscText"><option value="#ffffff"' + (D.text_color === '#ffffff' ? ' selected' : '') + '>Light</option><option value="#111827"' + (D.text_color === '#111827' ? ' selected' : '') + '>Dark</option><option value="#3b2604"' + (D.text_color === '#3b2604' ? ' selected' : '') + '>Warm dark</option></select></label></div>' +
        '<div class="lsc-status">' +
          '<div class="' + (pf.apple ? 'ok' : '') + '"><span class="material-symbols-rounded">' + (pf.apple ? 'check_circle' : 'schedule') + '</span><div><b>Apple Wallet</b><small>' + (pf.apple ? 'Ready' : 'Being set up by the RestroGrow team') + '</small></div></div>' +
          '<div class="' + (pf.google ? 'ok' : '') + '"><span class="material-symbols-rounded">' + (pf.google ? 'check_circle' : 'schedule') + '</span><div><b>Google Wallet</b><small>' + (pf.google ? 'Ready' : 'Being set up by the RestroGrow team') + '</small></div></div></div>' +
        '<p class="lsa-fine">' + (any ? 'The “Add to Wallet” buttons show on the rewards page for logged-in members' + (D.enabled ? '.' : ' once you turn the card on.') : 'Turn it on now: the buttons appear on your rewards page as soon as the wallets are ready.') +
          ' The card shows the member’s phone as a QR code, so staff can find them at the counter.</p>' +
        '<div style="display:flex;justify-content:flex-end"><button type="button" class="btn btn-primary" id="lscSave"><span class="material-symbols-rounded">save</span> Save card</button></div>' +
      '</div><div class="lsc-prev">' + cards() + '</div></div>';
  }
  function save() {
    var b = document.getElementById('lscSave'); if (b) b.disabled = true;
    L().post({ section: 'card', enabled: D.enabled, color: D.color, text_color: D.text_color }).then(function (d) {
      if (b) b.disabled = false;
      L().notify(d.success ? 'Phone card saved' : (d.message || 'Could not save'), d.success ? 'success' : 'error');
    });
  }
  window.lsDraw_card = function (el, data) { if (data && data.success) D = data; draw(el); };

  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D || !document.querySelector('.lsc-wrap')) return;
    var t;
    if ((t = e.target.closest('[data-lsc-sw]'))) { var v = t.getAttribute('data-lsc-sw').split('|'); D.color = v[0]; D.text_color = v[1]; draw(document.getElementById('lsPanel')); return; }
    if (e.target.closest('#lscSave')) save();
  });
  document.addEventListener('change', function (e) {
    if (!e.target.closest || !e.target.closest('#lsPanel') || !D || !document.querySelector('.lsc-wrap')) return;
    if (e.target.id === 'lscOn') { D.enabled = e.target.checked ? 1 : 0; save(); draw(document.getElementById('lsPanel')); }
    if (e.target.id === 'lscText') { D.text_color = e.target.value; draw(document.getElementById('lsPanel')); }
  });
  document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'lscColor' && D) { D.color = e.target.value; var p = document.querySelector('.lsc-prev'); if (p) p.innerHTML = cards(); }
  });
})();
