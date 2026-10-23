<?php
/*
 * One cart per restaurant. The cart lives in shared browser storage
 * (dvaniCart + dish notes, add-ons and the note for the restaurant), so a
 * customer who hops between restaurants or branches would see one place's
 * dishes counted on another's site. When the restaurant changes, the old
 * restaurant's cart is put aside (rgCart_<id>) and this restaurant's own cart
 * comes back. Included right after the restaurant-id meta tag, before any
 * script reads the cart.
 */
?>
<script>
// The table from a QR scan (sessionStorage qrTable, set by cart.php) belongs to
// one restaurant and lasts a few hours (header.php's QR_TABLE_KEEP_SECONDS): all
// restaurants share this browser storage, so another restaurant's table, or an
// old one, must not turn this visit into a dine-in order (or show Call waiter).
(function () {
  try {
    var m = document.querySelector('meta[name="restaurant-id"]');
    var rid = m && m.content;
    if (!rid || !sessionStorage.getItem('qrTable')) return;
    var at = parseInt(sessionStorage.getItem('qrTableAt') || '0', 10);
    if (sessionStorage.getItem('qrTableRid') !== rid || !at || Date.now() - at > 4 * 3600 * 1000) {
      ['qrTable', 'qrTableRid', 'qrTableAt'].forEach(function (k) { sessionStorage.removeItem(k); });
    }
  } catch (e) {}
})();
(function () {
  try {
    var m = document.querySelector('meta[name="restaurant-id"]');
    var rid = m && m.content;
    if (!rid) return;
    var KEYS = ['dvaniCart', '_itemNotes', '_itemAddons', 'dvaniInstruction'];
    var owner = localStorage.getItem('dvaniCartRid');
    if (owner === rid) return;
    if (!owner) { localStorage.setItem('dvaniCartRid', rid); return; } // cart from before this check: keep it here
    var bag = {};
    KEYS.forEach(function (k) { var v = localStorage.getItem(k); if (v !== null) bag[k] = v; });
    if (Object.keys(bag).length) localStorage.setItem('rgCart_' + owner, JSON.stringify(bag));
    var mine = {};
    try { mine = JSON.parse(localStorage.getItem('rgCart_' + rid) || '{}') || {}; } catch (e) {}
    KEYS.forEach(function (k) { if (mine[k] != null) localStorage.setItem(k, mine[k]); else localStorage.removeItem(k); });
    localStorage.removeItem('rgCart_' + rid);
    localStorage.setItem('dvaniCartRid', rid);
  } catch (e) {}
})();
</script>
