/**
 * Combos & Deals on the website (home and menu pages): a row of combo cards and a sheet to pick the
 * choices. Data: api.php?action=getCombos (config/combo_helpers.php). Set up in the dashboard under
 * Offers › Combos & Deals.
 *
 * A combo goes into the cart (localStorage dvaniCart) as
 *   combo_<id>_<choice ids> : { qty, isCombo, comboId, name, varName (what's in it), varPrice, picks, image }
 * so the cart bar counts it like any line; the cart page lists it and checkout sends its id and picks.
 * The server prices it again, so nothing here decides what the customer pays.
 *
 * Mount: <section class="rgc" id="rgCombos" data-variant="home|menu" hidden></section>
 */
(function () {
  'use strict';
  var mount = document.getElementById('rgCombos');
  if (!mount) return;
  var compact = mount.getAttribute('data-variant') === 'menu';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var combos = [];
  var sheet = null;          // { c, picks: {slotId: choiceId}, qty }

  /* ───────── helpers ───────── */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cur() { return window.globalCurrencySymbol || '₹'; }
  function money(n) { n = Math.round((+n || 0) * 100) / 100; return cur() + (n % 1 ? n.toFixed(2) : n.toLocaleString('en-IN')); }
  function imgUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//.test(p)) return p;
    // photos kept in the database get image.php's small WebP copy (the cards are never wider than 400px)
    return p.indexOf('db:') === 0 ? 'image.php?id=' + encodeURIComponent(p.substring(3)) + '&w=400' : 'image.php?id=' + encodeURIComponent(p);
  }
  function rid() { return window.websiteRestaurantId || (document.querySelector('meta[name="restaurant-id"]') || {}).content || ''; }
  function vegMark(t) {
    t = String(t || '').toLowerCase();
    if (!t || t === 'drink' || t === 'beverage') return '';
    return '<i class="rgc-veg' + (t.indexOf('non') !== -1 ? ' non' : t.indexOf('egg') !== -1 ? ' egg' : '') + '"></i>';
  }
  function pics(c) { var p = []; c.slots.forEach(function (s) { var ch = s.choices[0]; if (ch && ch.image && p.length < 3) p.push(ch.image); }); return p; }
  function visual(c, cls) {
    if (c.image) return '<div class="rgc-vis ' + cls + '"><img src="' + imgUrl(c.image) + '" alt="" loading="lazy"></div>';
    var p = pics(c);
    if (p.length) return '<div class="rgc-vis rgc-stack ' + cls + '">' + p.map(function (x, i) { return '<img style="--i:' + i + ';--n:' + p.length + '" src="' + imgUrl(x) + '" alt="" loading="lazy">'; }).join('') + '</div>';
    var names = c.slots.map(function (s) { return s.choices[0] ? s.choices[0].name : ''; }).filter(Boolean).slice(0, 3);
    return '<div class="rgc-vis rgc-letters ' + cls + '">' + names.map(function (n, i) { return '<span style="--i:' + i + '">' + esc(n.charAt(0)) + '</span>'; }).join('') + '<i class="fa-solid fa-utensils"></i></div>';
  }
  function partsLine(c) {
    return c.slots.map(function (s) { var n = s.choices.length > 1 ? s.title : s.choices[0].name; return (s.qty > 1 ? s.qty + ' × ' : '') + n; }).join(' + ');
  }
  function hasChoice(c) { return c.slots.some(function (s) { return s.choices.length > 1; }); }
  function pct(c) { return c.worth > 0 ? Math.round(c.save / c.worth * 100) : 0; }
  function left(iso) {
    var ms = new Date(iso).getTime() - Date.now();
    if (!(ms > 0)) return '';
    var h = Math.floor(ms / 3600000), m = Math.floor(ms % 3600000 / 60000), s = Math.floor(ms % 60000 / 1000);
    return (h ? h + 'h ' : '') + (h || m ? m + 'm ' : '') + (h ? '' : s + 's');
  }

  /* ───────── cart (through the page's window.rgCart, else localStorage) ───────── */
  function cartGet() {
    if (window.rgCart && window.rgCart.get) return window.rgCart.get();
    try { return JSON.parse(localStorage.getItem('dvaniCart') || '{}') || {}; } catch (e) { return {}; }
  }
  function cartSave(items) {
    if (window.rgCart && window.rgCart.save) { window.rgCart.save(); if (window.rgCart.refresh) window.rgCart.refresh(); }
    else { try { localStorage.setItem('dvaniCart', JSON.stringify(items)); } catch (e) {} }
    var total = 0;
    for (var k in items) { var v = items[k]; total += typeof v === 'number' ? v : (v && v.qty) || 0; }
    document.querySelectorAll('#navCartBadge, .cart-badge').forEach(function (b) { b.textContent = total; });
  }
  function inCart(id) {
    var items = cartGet(), n = 0;
    for (var k in items) if (items[k] && items[k].isCombo && items[k].comboId === id) n += items[k].qty || 0;
    return n;
  }

  /* ───────── the row ───────── */
  function load() {
    var r = rid();
    if (!r) return;
    fetch('api.php?action=getCombos&restaurant_id=' + encodeURIComponent(r), { cache: 'no-store' })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        combos = (d && d.combos) || [];
        if (!combos.length) { mount.hidden = true; return; }
        injectCss(); draw();
      }).catch(function () { mount.hidden = true; });
  }

  function card(c, i) {
    var p = pct(c), n = inCart(c.id);
    return '<article class="rgc-card" style="--d:' + (i * 90) + 'ms" data-rgc-open="' + c.id + '" tabindex="0" role="button" aria-label="' + esc(c.name) + ', ' + money(c.price) + '">' +
      '<div class="rgc-top">' + visual(c, '') + (c.badge ? '<span class="rgc-badge">' + esc(c.badge) + '</span>' : '') +
        (c.save > 0 ? '<span class="rgc-save">Save ' + money(c.save) + '</span>' : '') + '</div>' +
      '<div class="rgc-body"><h3>' + esc(c.name) + '</h3><p>' + esc(c.description || partsLine(c)) + '</p>' +
        (c.ends_at ? '<div class="rgc-timer" data-rgc-ends="' + esc(c.ends_at) + '"><i class="fa-regular fa-clock"></i><span>Ends in ' + left(c.ends_at) + '</span></div>' : '') +
        '<div class="rgc-foot"><div class="rgc-price"><b>' + money(c.price) + '</b>' + (c.save > 0 ? '<s>' + money(c.worth) + '</s>' : '') + (p > 0 ? '<em>' + p + '% off</em>' : '') + '</div>' +
          '<span class="rgc-cta">' + (n ? '<i class="fa-solid fa-check"></i>' + n + ' in cart' : (hasChoice(c) ? 'Customise' : 'Add') + '<i class="fa-solid fa-plus"></i>') + '</span></div></div>' +
      '</article>';
  }

  function draw() {
    mount.hidden = false;
    mount.className = 'rgc' + (compact ? ' rgc-compact' : '');
    mount.innerHTML = '<div class="rgc-head"><h2><span class="rgc-spark"><i class="fa-solid fa-fire-flame-curved"></i></span>Combos &amp; Deals</h2>' +
      '<span class="rgc-sub">' + combos.length + ' deal' + (combos.length === 1 ? '' : 's') + ' · save up to ' + money(Math.max.apply(null, combos.map(function (c) { return c.save; }))) + '</span>' +
      (combos.length > 1 ? '<span class="rgc-arrows"><button type="button" data-rgc-scroll="-1" aria-label="Previous deals"><i class="fa-solid fa-chevron-left"></i></button><button type="button" data-rgc-scroll="1" aria-label="More deals"><i class="fa-solid fa-chevron-right"></i></button></span>' : '') + '</div>' +
      '<div class="rgc-rail">' + combos.map(card).join('') + '</div>';
    reveal(); tilt();
  }

  // cards rise in as the row comes into view
  function reveal() {
    var cards = mount.querySelectorAll('.rgc-card');
    if (reduce || !('IntersectionObserver' in window)) { cards.forEach(function (c) { c.classList.add('in'); }); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.15 });
    cards.forEach(function (c) { io.observe(c); });
  }
  // a little 3D lean towards the pointer (mouse only)
  function tilt() {
    if (reduce || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    mount.querySelectorAll('.rgc-card').forEach(function (el) {
      el.addEventListener('pointermove', function (e) {
        var r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
        el.style.setProperty('--rx', (-y * 7).toFixed(2) + 'deg'); el.style.setProperty('--ry', (x * 9).toFixed(2) + 'deg');
        el.style.setProperty('--gx', ((x + 0.5) * 100).toFixed(0) + '%'); el.style.setProperty('--gy', ((y + 0.5) * 100).toFixed(0) + '%');
      });
      el.addEventListener('pointerleave', function () { el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg'); });
    });
  }
  // countdowns
  setInterval(function () {
    document.querySelectorAll('[data-rgc-ends]').forEach(function (t) {
      var l = left(t.getAttribute('data-rgc-ends'));
      var s = t.querySelector('span');
      if (!l) { var cd = t.closest('.rgc-card'); if (cd) cd.classList.add('gone'); if (s) s.textContent = 'Ended'; return; }
      if (s) s.textContent = 'Ends in ' + l;
      t.classList.toggle('soon', new Date(t.getAttribute('data-rgc-ends')).getTime() - Date.now() < 3600000);
    });
  }, 1000);

  /* ───────── the sheet: pick the choices ───────── */
  function open(id) {
    var c = combos.filter(function (x) { return x.id === id; })[0];
    if (!c) return;
    var picks = {};
    c.slots.forEach(function (s) { picks[s.id] = s.choices[0].id; });
    sheet = { c: c, picks: picks, qty: 1 };
    var ov = document.createElement('div');
    ov.className = 'rgc-ov'; ov.id = 'rgcSheet';
    ov.innerHTML = '<div class="rgc-sheet" role="dialog" aria-modal="true" aria-label="' + esc(c.name) + '"><button type="button" class="rgc-x" data-rgc-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
      '<div class="rgc-sh-scroll"><div class="rgc-sh-top">' + visual(c, 'big') + (c.badge ? '<span class="rgc-badge">' + esc(c.badge) + '</span>' : '') + '</div>' +
      '<div class="rgc-sh-b"><h3>' + esc(c.name) + '</h3>' + (c.description ? '<p>' + esc(c.description) + '</p>' : '') + '<div class="rgc-steps"></div></div></div>' +
      '<div class="rgc-sh-f"><div class="rgc-qty"><button type="button" data-rgc-qty="-1" aria-label="One less">−</button><b>1</b><button type="button" data-rgc-qty="1" aria-label="One more">+</button></div>' +
      '<button type="button" class="rgc-add" data-rgc-add><span class="rgc-add-l">Add to cart</span><span class="rgc-add-p"></span></button></div></div>';
    document.body.appendChild(ov);
    document.body.classList.add('rgc-lock');
    drawSteps();
    requestAnimationFrame(function () { ov.classList.add('in'); });
    setTimeout(function () { var b = ov.querySelector('.rgc-add'); if (b) b.focus({ preventScroll: true }); }, 350);
  }
  function close() {
    var ov = document.getElementById('rgcSheet');
    sheet = null;
    document.body.classList.remove('rgc-lock');
    if (!ov) return;
    ov.classList.remove('in');
    setTimeout(function () { ov.remove(); }, 320);
  }
  function chosen(s) { return s.choices.filter(function (o) { return o.id === sheet.picks[s.id]; })[0] || s.choices[0]; }
  function unitPrice() { return sheet.c.slots.reduce(function (t, s) { return t + (+chosen(s).extra || 0); }, +sheet.c.price); }
  function chosenWorth() { return sheet.c.slots.reduce(function (t, s) { return t + chosen(s).price * s.qty; }, 0); }

  function drawSteps() {
    var box = document.querySelector('#rgcSheet .rgc-steps');
    if (!box || !sheet) return;
    var n = 0;
    box.innerHTML = sheet.c.slots.map(function (s) {
      var multi = s.choices.length > 1;
      if (multi) n++;
      return '<section class="rgc-step' + (multi ? '' : ' fixed') + '"><div class="rgc-step-h">' + (multi ? '<span class="rgc-n">' + n + '</span>' : '<span class="rgc-n ok"><i class="fa-solid fa-check"></i></span>') +
        '<b>' + esc((s.qty > 1 ? s.qty + ' × ' : '') + (multi ? s.title : s.choices[0].name)) + '</b><small>' + (multi ? 'Pick 1' : 'Included') + '</small></div>' +
        (multi ? '<div class="rgc-opts">' + s.choices.map(function (o) {
          var on = sheet.picks[s.id] === o.id;
          return '<button type="button" class="rgc-opt' + (on ? ' on' : '') + '" data-rgc-pick="' + s.id + ':' + o.id + '" aria-pressed="' + on + '">' +
            (o.image ? '<img src="' + imgUrl(o.image) + '" alt="" loading="lazy">' : '<span class="rgc-oph">' + esc(o.name.charAt(0)) + '</span>') +
            '<span class="rgc-on"><i class="fa-solid fa-check"></i></span>' +
            '<span class="rgc-ot">' + vegMark(o.type) + '<b>' + esc(o.name) + '</b></span>' + (o.variation ? '<small>' + esc(o.variation) + '</small>' : '') +
            '<em>' + (o.extra > 0 ? '+' + money(o.extra) : 'Included') + '</em></button>';
        }).join('') + '</div>' : '') + '</section>';
    }).join('');
    price();
  }
  function price() {
    var sh = document.getElementById('rgcSheet');
    if (!sh || !sheet) return;
    var u = unitPrice(), total = u * sheet.qty, save = Math.max(0, chosenWorth() - u) * sheet.qty;
    sh.querySelector('.rgc-qty b').textContent = sheet.qty;
    var p = sh.querySelector('.rgc-add-p');
    tween(p, total);
    sh.querySelector('.rgc-add-l').innerHTML = 'Add to cart' + (save > 0 ? '<small>You save ' + money(save) + '</small>' : '');
  }
  // the price counts up / down instead of jumping
  function tween(el, to) {
    var from = +el.getAttribute('data-v') || to;
    el.setAttribute('data-v', to);
    if (reduce || from === to) { el.textContent = money(to); return; }
    var t0 = performance.now();
    (function step(t) {
      var k = Math.min(1, (t - t0) / 380), e = 1 - Math.pow(1 - k, 3);
      el.textContent = money(Math.round(from + (to - from) * e));
      if (k < 1) requestAnimationFrame(step); else { el.textContent = money(to); el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
    })(t0);
  }

  function add(btn) {
    if (!sheet) return;
    var c = sheet.c, items = cartGet();
    var ids = c.slots.map(function (s) { return chosen(s).id; });
    var key = 'combo_' + c.id + '_' + ids.join('-');
    var what = c.slots.map(function (s) { var o = chosen(s); return (s.qty > 1 ? s.qty + ' × ' : '') + o.name + (o.variation ? ' (' + o.variation + ')' : ''); }).join(', ');
    var picks = {};
    c.slots.forEach(function (s) { picks[s.id] = chosen(s).id; });
    var img = c.image || (pics(c)[0] || null);
    if (items[key] && items[key].isCombo) items[key].qty += sheet.qty;
    else items[key] = { qty: sheet.qty, isCombo: true, comboId: c.id, name: c.name, varName: what, varPrice: unitPrice(), picks: picks, image: img };
    cartSave(items);
    burst(btn);
    fly(document.querySelector('#rgcSheet .rgc-vis'));
    if (typeof window.showToast === 'function') window.showToast(c.name + ' added to your cart', 'success');
    setTimeout(function () { close(); draw(); }, reduce ? 0 : 420);
  }

  // confetti from the button
  function burst(from) {
    if (reduce || !from) return;
    var r = from.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    var colors = ['var(--primary-red, #e53935)', '#fbbf24', '#22c55e', '#3b82f6', '#f472b6'];
    for (var i = 0; i < 22; i++) {
      var p = document.createElement('i');
      p.className = 'rgc-conf';
      var a = Math.random() * Math.PI * 2, d = 50 + Math.random() * 90;
      p.style.cssText = 'left:' + cx + 'px;top:' + cy + 'px;background:' + colors[i % colors.length] + ';--x:' + (Math.cos(a) * d).toFixed(0) + 'px;--y:' + (Math.sin(a) * d - 40).toFixed(0) + 'px;--r:' + (Math.random() * 540 - 270).toFixed(0) + 'deg';
      document.body.appendChild(p);
      setTimeout(function (el) { el.remove(); }.bind(null, p), 900);
    }
  }
  // the combo's picture flies into the cart
  function fly(src) {
    if (reduce || !src) return;
    var target = document.querySelector('#checkoutBar.show') || document.querySelector('#navCartBadge') || document.querySelector('.cart-badge');
    if (!target) return;
    var a = src.getBoundingClientRect(), b = target.getBoundingClientRect();
    var ghost = src.cloneNode(true);
    ghost.className += ' rgc-ghost';
    ghost.style.cssText = 'left:' + a.left + 'px;top:' + a.top + 'px;width:' + a.width + 'px;height:' + a.height + 'px;';
    document.body.appendChild(ghost);
    var dx = b.left + b.width / 2 - (a.left + a.width / 2), dy = b.top + b.height / 2 - (a.top + a.height / 2);
    ghost.animate([
      { transform: 'translate(0,0) scale(1)', opacity: 1, borderRadius: '20px' },
      { transform: 'translate(' + dx * 0.5 + 'px,' + (dy * 0.5 - 120) + 'px) scale(.45)', opacity: 1, offset: 0.55 },
      { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.08)', opacity: 0.2, borderRadius: '50%' }
    ], { duration: 780, easing: 'cubic-bezier(.5,0,.3,1)' }).onfinish = function () {
      ghost.remove();
      target.classList.remove('rgc-bump'); void target.offsetWidth; target.classList.add('rgc-bump');
    };
  }

  /* ───────── events ───────── */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-rgc-open],[data-rgc-close],[data-rgc-pick],[data-rgc-qty],[data-rgc-add],[data-rgc-scroll]');
    if (!t) { if (e.target.id === 'rgcSheet') close(); return; }
    if (t.hasAttribute('data-rgc-open')) { open(+t.getAttribute('data-rgc-open')); return; }
    if (t.hasAttribute('data-rgc-close')) { close(); return; }
    if (t.hasAttribute('data-rgc-scroll')) {
      var rail = mount.querySelector('.rgc-rail');
      if (rail) rail.scrollBy({ left: (+t.getAttribute('data-rgc-scroll')) * rail.clientWidth * 0.85, behavior: reduce ? 'auto' : 'smooth' });
      return;
    }
    if (!sheet) return;
    if (t.hasAttribute('data-rgc-pick')) {
      var p = t.getAttribute('data-rgc-pick').split(':');
      sheet.picks[+p[0]] = +p[1];
      t.parentNode.querySelectorAll('.rgc-opt').forEach(function (b) { var on = b === t; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
      price();
      return;
    }
    if (t.hasAttribute('data-rgc-qty')) { sheet.qty = Math.max(1, Math.min(20, sheet.qty + (+t.getAttribute('data-rgc-qty')))); price(); return; }
    if (t.hasAttribute('data-rgc-add')) { add(t); return; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sheet) { close(); return; }
    var c = e.target.closest && e.target.closest('[data-rgc-open]');
    if (c && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(+c.getAttribute('data-rgc-open')); }
  });

  /* ───────── styles (theme colour: --primary-red from the website theme) ───────── */
  function injectCss() {
    if (document.getElementById('rgcCss')) return;
    var s = document.createElement('style');
    s.id = 'rgcCss';
    s.textContent = [
      '.rgc{--rgc:var(--primary-red,#e53935);margin:14px 0 18px;}',
      '.rgc-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:0 4px 10px;}',
      '.rgc-head h2{display:flex;align-items:center;gap:8px;margin:0;font-size:1.25rem;font-weight:800;color:#111827;}',
      '.rgc-spark{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;color:#fff;background:linear-gradient(135deg,#fbbf24,var(--rgc));box-shadow:0 6px 14px -6px var(--rgc);animation:rgcFlame 1.8s ease-in-out infinite;}',
      '@keyframes rgcFlame{50%{transform:rotate(-8deg) scale(1.08);}}',
      '.rgc-sub{color:#6b7280;font-size:.84rem;font-weight:600;}',
      '.rgc-arrows{margin-left:auto;display:flex;gap:6px;}',
      '.rgc-arrows button{width:36px;height:36px;border:1px solid #e5e7eb;border-radius:50%;background:#fff;color:#374151;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.06);transition:transform .15s,background .15s;}',
      '.rgc-arrows button:hover{background:#f9fafb;transform:scale(1.06);}',
      '@media (hover:none){.rgc-arrows{display:none;}}',
      '.rgc-rail{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding:6px 4px 18px;scrollbar-width:none;perspective:900px;}',
      '.rgc-rail::-webkit-scrollbar{display:none;}',
      '.rgc-card{position:relative;flex:0 0 min(280px,78vw);scroll-snap-align:start;display:flex;flex-direction:column;border-radius:22px;background:#fff;border:1px solid #f1f1f1;overflow:hidden;cursor:pointer;outline:0;',
        'box-shadow:0 14px 30px -20px rgba(17,24,39,.45);opacity:0;transform:translateY(26px) scale(.96);transition:opacity .55s cubic-bezier(.2,.8,.2,1) var(--d),transform .55s cubic-bezier(.2,.8,.2,1) var(--d),box-shadow .25s;}',
      '.rgc-card.in{opacity:1;transform:rotateX(var(--rx,0)) rotateY(var(--ry,0));}',
      '.rgc-card.in:hover{box-shadow:0 26px 40px -24px rgba(17,24,39,.55);transition:transform .15s ease-out,box-shadow .25s;}',
      '.rgc-card::after{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(260px circle at var(--gx,50%) var(--gy,0%),rgba(255,255,255,.35),transparent 60%);opacity:0;transition:opacity .25s;}',
      '.rgc-card:hover::after{opacity:1;}',
      '.rgc-card:focus-visible{box-shadow:0 0 0 3px color-mix(in srgb,var(--rgc) 40%,transparent);}',
      '.rgc-card.gone{display:none;}',
      '.rgc-top{position:relative;}',
      '.rgc-vis{position:relative;height:160px;overflow:hidden;background:radial-gradient(120% 120% at 20% 0%,#fff7ed,color-mix(in srgb,var(--rgc) 22%,#fde68a));}',
      '.rgc-vis.big{height:210px;}',
      '.rgc-vis>img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s cubic-bezier(.2,.8,.2,1);}',
      '.rgc-card:hover .rgc-vis>img{transform:scale(1.06);}',
      '.rgc-stack>img{position:absolute;top:50%;left:50%;width:48%;height:auto;aspect-ratio:1;object-fit:cover;border-radius:18px;border:3px solid #fff;box-shadow:0 12px 22px -10px rgba(0,0,0,.45);',
        'transform:translate(calc(-50% + (var(--i) - (var(--n) - 1) / 2) * 50%),-50%) rotate(calc((var(--i) - (var(--n) - 1) / 2) * 9deg));transition:transform .45s cubic-bezier(.2,.8,.2,1.2);}',
      '.rgc-card:hover .rgc-stack>img,.rgc-sheet .rgc-stack>img{transform:translate(calc(-50% + (var(--i) - (var(--n) - 1) / 2) * 62%),-54%) rotate(calc((var(--i) - (var(--n) - 1) / 2) * 13deg));}',
      '.rgc-card:hover .rgc-stack>img{transform:translate(calc(-50% + (var(--i) - (var(--n) - 1) / 2) * 62%),-54%) rotate(calc((var(--i) - (var(--n) - 1) / 2) * 13deg)) scale(1);}',
      '.rgc-letters{display:flex;align-items:center;justify-content:center;gap:10px;}',
      '.rgc-letters>span{width:52px;height:52px;display:grid;place-items:center;border-radius:50%;background:#fff;color:var(--rgc);font-weight:900;font-size:1.3rem;box-shadow:0 8px 18px -10px rgba(0,0,0,.45);animation:rgcBob 2.8s ease-in-out infinite;animation-delay:calc(var(--i) * .25s);}',
      '.rgc-letters>i{position:absolute;right:12px;bottom:10px;color:color-mix(in srgb,var(--rgc) 45%,transparent);font-size:1.3rem;}',
      '@keyframes rgcBob{50%{transform:translateY(-7px);}}',
      '.rgc-badge{position:absolute;top:12px;left:12px;padding:5px 11px;border-radius:999px;background:rgba(17,24,39,.88);color:#fff;font-size:.72rem;font-weight:800;letter-spacing:.02em;backdrop-filter:blur(4px);}',
      '.rgc-save{position:absolute;top:12px;right:12px;padding:5px 11px;border-radius:999px;background:#16a34a;color:#fff;font-size:.76rem;font-weight:900;overflow:hidden;box-shadow:0 6px 14px -6px #16a34a;}',
      '.rgc-save::after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 30%,rgba(255,255,255,.6) 50%,transparent 70%);transform:translateX(-100%);animation:rgcShine 2.8s ease-in-out infinite;}',
      '@keyframes rgcShine{55%,100%{transform:translateX(100%);}}',
      '.rgc-body{flex:1;display:flex;flex-direction:column;padding:12px 14px 14px;}',
      '.rgc-body h3{margin:0;font-size:1.02rem;font-weight:800;color:#111827;}',
      '.rgc-body p{margin:4px 0 0;color:#6b7280;font-size:.8rem;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}',
      '.rgc-timer{display:inline-flex;align-items:center;gap:5px;align-self:flex-start;margin-top:8px;padding:3px 9px;border-radius:999px;background:#fff7ed;color:#c2410c;font-size:.74rem;font-weight:800;}',
      '.rgc-timer.soon{background:#fef2f2;color:#b91c1c;animation:rgcPulse 1.2s ease-in-out infinite;}',
      '@keyframes rgcPulse{50%{transform:scale(1.04);}}',
      '.rgc-foot{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;margin-top:auto;padding-top:12px;}',
      '.rgc-price{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;}',
      '.rgc-price b{font-size:1.25rem;font-weight:900;color:#111827;}',
      '.rgc-price s{color:#9ca3af;font-size:.84rem;}',
      '.rgc-price em{font-style:normal;color:#16a34a;font-weight:800;font-size:.78rem;}',
      '.rgc-cta{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;background:var(--rgc);color:#fff;font-weight:800;font-size:.82rem;white-space:nowrap;box-shadow:0 8px 18px -8px var(--rgc);transition:transform .2s;}',
      '.rgc-card:hover .rgc-cta{transform:translateY(-2px);}',
      '.rgc-cta .fa-check{animation:rgcPop .4s cubic-bezier(.2,.8,.2,1.6);}',
      '.rgc-compact .rgc-card{flex-basis:min(250px,72vw);}',
      '.rgc-compact .rgc-vis{height:118px;}',
      '.rgc-compact .rgc-head h2{font-size:1.08rem;}',
      /* sheet */
      'body.rgc-lock{overflow:hidden;}',
      '.rgc-ov{position:fixed;inset:0;z-index:10050;display:flex;align-items:flex-end;justify-content:center;background:rgba(17,24,39,0);transition:background .3s;}',
      '.rgc-ov.in{background:rgba(17,24,39,.55);}',
      '.rgc-sheet{--rgc:var(--primary-red,#e53935);position:relative;width:100%;max-width:540px;max-height:92vh;display:flex;flex-direction:column;border-radius:26px 26px 0 0;background:#fff;overflow:hidden;transform:translateY(100%);transition:transform .42s cubic-bezier(.2,.9,.25,1.05);}',
      '.rgc-ov.in .rgc-sheet{transform:none;}',
      '@media (min-width:700px){.rgc-ov{align-items:center;}.rgc-sheet{border-radius:26px;transform:translateY(40px) scale(.96);opacity:0;transition:transform .38s cubic-bezier(.2,.9,.25,1.05),opacity .25s;}.rgc-ov.in .rgc-sheet{opacity:1;}}',
      '.rgc-x{position:absolute;top:12px;right:12px;z-index:3;width:38px;height:38px;border:0;border-radius:50%;background:rgba(255,255,255,.92);color:#111827;font-size:1.05rem;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.15);}',
      '.rgc-sh-scroll{overflow-y:auto;flex:1;}',
      '.rgc-sh-top{position:relative;}',
      '.rgc-sh-b{padding:16px 18px 8px;}',
      '.rgc-sh-b>h3{margin:0;font-size:1.3rem;font-weight:900;color:#111827;}',
      '.rgc-sh-b>p{margin:4px 0 0;color:#6b7280;font-size:.88rem;}',
      '.rgc-steps{display:flex;flex-direction:column;gap:16px;margin-top:16px;}',
      '.rgc-step{animation:rgcIn .45s cubic-bezier(.2,.8,.2,1) both;}',
      '.rgc-step:nth-child(2){animation-delay:.06s;}.rgc-step:nth-child(3){animation-delay:.12s;}.rgc-step:nth-child(4){animation-delay:.18s;}.rgc-step:nth-child(n+5){animation-delay:.24s;}',
      '@keyframes rgcIn{from{opacity:0;transform:translateY(14px);}}',
      '.rgc-step-h{display:flex;align-items:center;gap:8px;}',
      '.rgc-step-h b{font-size:.95rem;color:#111827;}',
      '.rgc-step-h small{margin-left:auto;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#6b7280;font-size:.7rem;font-weight:800;}',
      '.rgc-n{width:24px;height:24px;flex-shrink:0;display:grid;place-items:center;border-radius:50%;background:var(--rgc);color:#fff;font-size:.74rem;font-weight:900;}',
      '.rgc-n.ok{background:#16a34a;font-size:.66rem;}',
      '.rgc-step.fixed{padding:10px 12px;border-radius:14px;background:#f9fafb;}',
      '.rgc-opts{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-top:10px;}',
      '.rgc-opt{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:3px;padding:8px;border:2px solid #eef0f3;border-radius:16px;background:#fff;text-align:left;font:inherit;cursor:pointer;transition:border-color .2s,box-shadow .2s,transform .2s;}',
      '.rgc-opt:hover{transform:translateY(-2px);}',
      '.rgc-opt img,.rgc-oph{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:11px;}',
      '.rgc-oph{display:grid;place-items:center;background:#fff7ed;color:var(--rgc);font-size:1.5rem;font-weight:900;}',
      '.rgc-ot{display:flex;align-items:center;gap:5px;margin-top:4px;}',
      '.rgc-ot b{font-size:.84rem;color:#111827;line-height:1.25;}',
      '.rgc-opt small{color:#6b7280;font-size:.72rem;}',
      '.rgc-opt em{font-style:normal;font-size:.76rem;font-weight:800;color:#16a34a;}',
      '.rgc-on{position:absolute;top:14px;right:14px;width:26px;height:26px;display:grid;place-items:center;border-radius:50%;background:var(--rgc);color:#fff;font-size:.72rem;transform:scale(0);transition:transform .3s cubic-bezier(.2,.8,.2,1.6);box-shadow:0 4px 10px -4px var(--rgc);}',
      '.rgc-opt.on{border-color:var(--rgc);box-shadow:0 10px 22px -16px var(--rgc);}',
      '.rgc-opt.on .rgc-on{transform:scale(1);}',
      '.rgc-veg{width:12px;height:12px;flex-shrink:0;border:1.5px solid #16a34a;border-radius:3px;position:relative;}',
      '.rgc-veg::after{content:"";position:absolute;inset:2px;border-radius:50%;background:#16a34a;}',
      '.rgc-veg.non{border-color:#b91c1c;}.rgc-veg.non::after{background:#b91c1c;border-radius:0;clip-path:polygon(50% 0,100% 100%,0 100%);}',
      '.rgc-veg.egg{border-color:#d97706;}.rgc-veg.egg::after{background:#d97706;}',
      '.rgc-sh-f{display:flex;align-items:center;gap:10px;padding:12px 16px calc(12px + env(safe-area-inset-bottom));border-top:1px solid #f3f4f6;background:#fff;}',
      '.rgc-qty{display:flex;align-items:center;border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;}',
      '.rgc-qty button{width:38px;height:46px;border:0;background:#fff;font-size:1.2rem;color:#111827;cursor:pointer;}',
      '.rgc-qty b{min-width:26px;text-align:center;font-weight:900;}',
      '.rgc-add{flex:1;display:flex;align-items:center;justify-content:space-between;gap:10px;height:50px;padding:0 18px;border:0;border-radius:15px;background:var(--rgc);color:#fff;font:inherit;font-weight:800;font-size:.95rem;cursor:pointer;',
        'box-shadow:0 12px 24px -12px var(--rgc);position:relative;overflow:hidden;transition:transform .15s;}',
      '.rgc-add:active{transform:scale(.97);}',
      '.rgc-add::after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 35%,rgba(255,255,255,.28) 50%,transparent 65%);transform:translateX(-100%);animation:rgcShine 3.2s ease-in-out infinite;}',
      '.rgc-add-l{display:flex;flex-direction:column;align-items:flex-start;line-height:1.15;}',
      '.rgc-add-l small{font-size:.7rem;font-weight:700;opacity:.92;}',
      '.rgc-add-p{font-size:1.05rem;font-weight:900;}',
      '.rgc-add-p.bump{animation:rgcPop .35s cubic-bezier(.2,.8,.2,1.6);}',
      '@keyframes rgcPop{from{transform:scale(.6);}}',
      '.rgc-conf{position:fixed;z-index:10060;width:8px;height:12px;border-radius:2px;pointer-events:none;animation:rgcConf .9s cubic-bezier(.2,.7,.3,1) forwards;}',
      '@keyframes rgcConf{to{transform:translate(var(--x),calc(var(--y) + 90px)) rotate(var(--r));opacity:0;}}',
      '.rgc-ghost{position:fixed;z-index:10061;pointer-events:none;overflow:hidden;border-radius:20px;box-shadow:0 18px 40px -16px rgba(0,0,0,.5);}',
      '.rgc-bump{animation:rgcPop .45s cubic-bezier(.2,.8,.2,1.6);}',
      '@media (prefers-reduced-motion:reduce){.rgc *, .rgc-sheet *{animation:none!important;transition:none!important;}.rgc-card{opacity:1;transform:none;}}'
    ].join('');
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
