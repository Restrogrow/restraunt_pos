/**
 * Profile (dashboard › Profile), the part below the header card: contact details with copy buttons,
 * show / hide and a strength meter on the password fields, and the person's latest sign-ins
 * (api/profile_security.php). The forms and their ids stay as they are (script.js handles saving).
 * Styles: prf-* in studio-ui.css.
 */
(function () {
  'use strict';
  var esc = function (s) { return window.SX ? SX.esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ic = function (n) { return '<span class="material-symbols-rounded">' + n + '</span>'; };
  var notify = function (m, t) { if (window.SX) SX.notify(m, t); };

  function enhance() {
    var page = document.getElementById('profilePage');
    if (!page || page._prf) return;
    page._prf = true;
    page.classList.add('prf');

    // copy buttons on phone and email
    ['profilePhoneValue', 'profileEmailValue'].forEach(function (id) {
      var v = document.getElementById(id); if (!v) return;
      var row = v.closest('.profile-contact-row'); if (!row) return;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'sx-icon-btn prf-copy'; b.title = 'Copy'; b.innerHTML = ic('content_copy');
      b.addEventListener('click', function () {
        var t = (v.textContent || '').trim();
        if (!t || /^(Not added|Add your)/.test(t)) return;
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(function () { b.innerHTML = ic('check'); setTimeout(function () { b.innerHTML = ic('content_copy'); }, 1200); });
      });
      row.appendChild(b);
    });

    // show / hide on every password field
    page.querySelectorAll('input[type=password]').forEach(function (inp) {
      var wrap = document.createElement('div'); wrap.className = 'prf-pw';
      inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
      var eye = document.createElement('button'); eye.type = 'button'; eye.className = 'prf-eye'; eye.setAttribute('aria-label', 'Show password'); eye.innerHTML = ic('visibility');
      eye.addEventListener('click', function () { var show = inp.type === 'password'; inp.type = show ? 'text' : 'password'; eye.innerHTML = ic(show ? 'visibility_off' : 'visibility'); eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); });
      wrap.appendChild(eye);
    });

    // strength meter under the new password
    var np = document.getElementById('newPassword');
    if (np) {
      var m = document.createElement('div'); m.className = 'prf-meter'; m.innerHTML = '<i></i><i></i><i></i><i></i><small></small>';
      np.closest('.prf-pw').after(m);
      np.addEventListener('input', function () {
        var v = np.value, s = 0;
        if (v.length >= 6) s++; if (v.length >= 10) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
        if (!v) s = 0;
        m.className = 'prf-meter s' + s;
        m.querySelector('small').textContent = v ? ['Too short', 'Weak', 'OK', 'Good', 'Strong'][s] : '';
      });
    }

    // sign-in activity, as a card in the grid
    var grid = page.querySelector('.profile-content-grid');
    if (grid) {
      var card = document.createElement('div'); card.className = 'profile-card-modern prf-logins'; card.id = 'prfLogins';
      card.innerHTML = '<div class="profile-card-header"><h3>' + ic('devices') + 'Sign-in activity</h3><p class="card-description">Your latest logins. Not you? Change your password.</p></div><div class="profile-card-body"><div class="cbx-loading"><span></span><span></span><span></span></div></div>';
      grid.appendChild(card);
    }
  }

  function loadLogins() {
    var body = document.querySelector('#prfLogins .profile-card-body');
    if (!body) return;
    fetch('../api/profile_security.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      var rows = (d && d.logins) || [];
      body.innerHTML = rows.length ? '<ul class="prf-login-list">' + rows.map(function (r, i) {
        var t = new Date(String(r.at).replace(' ', 'T'));
        return '<li class="' + (r.ok ? 'ok' : 'bad') + '"><span class="prf-li-ic">' + ic(r.ok ? (/(android|iphone|mobile)/i.test(r.device) ? 'smartphone' : 'desktop_windows') : 'gpp_maybe') + '</span>' +
          '<span class="prf-li-tx"><b>' + esc(r.device) + (i === 0 && r.ok ? ' <em>this session</em>' : '') + (r.new_device ? ' <em class="new">new device</em>' : '') + '</b><small>' + (r.ok ? 'Signed in' : 'Wrong password / blocked') + ' · ' + esc(r.ip) + '</small></span>' +
          '<span class="prf-li-at">' + t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + '<small>' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</small></span></li>';
      }).join('') + '</ul>' : '<p class="sx-muted">No sign-ins recorded yet.</p>';
    }).catch(function () { body.innerHTML = '<p class="sx-muted">Could not load sign-ins.</p>'; });
  }

  function open() { enhance(); loadLogins(); }
  document.addEventListener('click', function (e) { if (e.target.closest('.nav-link[data-page="profilePage"]')) setTimeout(open, 50); });
  document.addEventListener('DOMContentLoaded', function () { enhance(); if (window.SX && SX.isActive('profilePage')) loadLogins(); });
})();
