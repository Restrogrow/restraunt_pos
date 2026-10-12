/*
 * Shared helpers for the redesigned dashboard screens (Areas, Tables, Table map,
 * Loyalty Studio, WhatsApp). One place for: escaping, money, each area's icon and
 * colour, "busy for 45m", and the menu link a table's QR code opens.
 * Styles live in assets/css/studio-ui.css.
 */
(function () {
  'use strict';
  var PALETTE = ['#7c3aed', '#0ea5e9', '#f97316', '#10b981', '#e11d48', '#eab308', '#6366f1', '#14b8a6', '#8b5cf6', '#475569'];
  var RULES = [[/roof|terrace top/, 'roofing'], [/outdoor|terrace|patio|deck|lawn/, 'deck'], [/garden|park/, 'park'], [/bar|pub/, 'local_bar'], [/\bac\b|air/, 'ac_unit'],
    [/private|vip|cabin/, 'meeting_room'], [/party|banquet|event/, 'celebration'], [/lounge|sofa/, 'weekend'], [/family/, 'family_restroom'], [/caf|coffee/, 'coffee'],
    [/first|second|upper|floor 1|1st|2nd|mezz/, 'stairs'], [/ground|main|hall/, 'storefront'], [/pool/, 'pool'], [/wine/, 'wine_bar']];

  var SX = {
    palette: PALETTE,
    esc: function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); },
    money: function (n, cur) { return (cur || '₹') + Math.round(n || 0).toLocaleString('en-IN'); },
    guessIcon: function (name) { var n = String(name || '').toLowerCase(); for (var i = 0; i < RULES.length; i++) if (RULES[i][0].test(n)) return RULES[i][1]; return 'restaurant'; },
    /** An area's icon and colour: the owner's choice, else guessed from its name / position. */
    look: function (area, index) { return { icon: (area && area.icon) || SX.guessIcon(area && (area.name || area.area_name)), color: (area && area.color) || PALETTE[(index || 0) % PALETTE.length] }; },
    /** Minutes between two "Y-m-d H:i:s" times (server clock, so the browser's clock doesn't matter). */
    minsBetween: function (since, now) { if (!since || !now) return 0; return Math.max(0, Math.round((new Date(now.replace(' ', 'T')) - new Date(since.replace(' ', 'T'))) / 60000)); },
    fmtMins: function (m) { return m < 60 ? m + 'm' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; },
    /** The menu link a table's QR code opens (same rules as Tables › QR codes). */
    tableUrl: function (tableNumber) {
      var rid = (document.querySelector('meta[name="restaurant-id"]') || {}).content || window.restaurant_id || '';
      var base;
      if (window.restaurantCustomDomain && window.restaurantEmbedEnabled) base = window.location.protocol + '//' + window.restaurantCustomDomain.replace(/^https?:\/\//, '') + '/menu';
      else if (window.restaurantWebsiteSlug) base = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/')) + '/' + encodeURIComponent(window.restaurantWebsiteSlug) + '/menu';
      else base = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/') + 6) + 'website/menu.php?restaurant_id=' + encodeURIComponent(rid);
      return base + (base.indexOf('?') > -1 ? '&' : '?') + 'table=' + encodeURIComponent(tableNumber);
    },
    qrImage: function (tableNumber, size) { return 'https://api.qrserver.com/v1/create-qr-code/?size=' + (size || 300) + 'x' + (size || 300) + '&margin=8&data=' + encodeURIComponent(SX.tableUrl(tableNumber)); },
    notify: function (m, t) { if (typeof window.showNotification === 'function') window.showNotification(m, t); },
    goTo: function (pageId) { var n = document.querySelector('[data-page="' + pageId + '"]'); if (n) n.click(); },
    isActive: function (pageId) { var p = document.getElementById(pageId); return !!(p && p.classList.contains('active')) && !document.hidden; }
  };
  window.SX = SX;
})();
