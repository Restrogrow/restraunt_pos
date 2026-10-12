/*
 * Scratch card on the tracking page (Offers > Scratch card).
 *
 *   <div id="tkScratch" data-order="ORD-…" data-phone="98…" data-rid="RES…"></div>
 *
 * Locked until the restaurant accepts the order (checks again every 15 s),
 * then a card you rub off with a finger or the mouse. The prize is drawn on
 * the server and only fetched on the first scratch. A won prize shows its
 * private code with Copy and "Use on my next order" (the cart applies it).
 * The owner can switch the card to a spin wheel (Loyalty Studio > Games):
 * same prize, drawn the same way, and the wheel lands on it.
 */
(function () {
  'use strict';

  var box = document.getElementById('tkScratch');
  if (!box) return;
  var API = '../api/scratch_card.php';
  var order = box.getAttribute('data-order'), phone = box.getAttribute('data-phone'), rid = box.getAttribute('data-rid');
  var card = null, prize = null, revealing = false, done = false, pollT = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var GIFT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9.5 3 11 5 12 8c1-3 2.5-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';
  var LOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';

  function fmtDate(d) {
    if (!d) return '';
    var p = d.split('-'), m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return (+p[2]) + ' ' + m[+p[1] - 1];
  }
  function oddsHtml() {
    if (!card || !card.odds || !card.odds.length) return '';
    return '<details class="sc-odds"><summary>See the chances</summary><ul>' + card.odds.map(function (o) {
      return '<li><span>' + esc(o.label) + '</span><b>' + o.pct + '%</b></li>';
    }).join('') + '</ul></details>';
  }
  function headHtml(sub) {
    return '<div class="sc-head"><span class="sc-ic">' + GIFT + '</span><div><b>' + esc(card.title || (card.style === 'wheel' ? 'Spin the wheel' : 'Your scratch card')) + '</b><small>' + sub + '</small></div></div>';
  }

  function prizeHtml(p) {
    if (!p) return '<div class="sc-prize wait"><span class="sc-spin"></span></div>';
    if (p.type === 'none') return '<div class="sc-prize lose"><b>So close!</b><span>Better luck next time. Thanks for ordering with us.</span></div>';
    var big = p.type === 'percent' ? p.value + '% OFF' : (box.getAttribute('data-cur') || '₹') + (+p.value) + ' OFF';
    return '<div class="sc-prize win"><small>You won</small><b>' + esc(big) + '</b><span>on your next order</span></div>';
  }
  function wonExtras(p) {
    if (!p || p.type === 'none') return '';
    return '<div class="sc-won">' +
      '<div class="sc-code"><span>' + esc(p.code) + '</span><button type="button" class="sc-copy">Copy</button></div>' +
      '<p>Valid till ' + fmtDate(p.expires_on) + (p.min_order > 0 ? ' on orders above ' + (box.getAttribute('data-cur') || '₹') + (+p.min_order) : '') + '. Only for your phone number, one use.</p>' +
      '<button type="button" class="sc-use">Use on my next order</button>' +
    '</div>';
  }

  function render() {
    clearTimeout(pollT);
    if (!card) { box.innerHTML = ''; return; }
    if (card.state === 'locked') {
      box.innerHTML = '<div class="card sc-card">' + headHtml('Unlocks when the restaurant accepts your order') +
        '<div class="sc-area locked"><div class="sc-lockface"><i>' + LOCK + '</i><b>Locked</b><span>Hang tight, it opens in a moment</span></div></div>' + oddsHtml() + '</div>';
      pollT = setTimeout(load, 15000);
      return;
    }
    if (card.state === 'scratched') {
      box.innerHTML = '<div class="card sc-card">' + headHtml(card.prize.type === 'none' ? (card.style === 'wheel' ? 'Spun' : 'Scratched') : 'Your reward') +
        '<div class="sc-area open">' + prizeHtml(card.prize) + '</div>' + wonExtras(card.prize) + oddsHtml() + '</div>';
      return;
    }
    if (card.style === 'wheel') { renderWheel(); return; }
    box.innerHTML = '<div class="card sc-card">' + headHtml('Scratch to reveal your reward') +
      '<div class="sc-area" id="scArea"><div id="scUnder">' + prizeHtml(null) + '</div><canvas id="scCanvas"></canvas><div class="sc-hint">Scratch here</div></div>' +
      '<div id="scAfter"></div>' + oddsHtml() + '</div>';
    setupCanvas();
  }

  function setupCanvas() {
    var area = document.getElementById('scArea'), cv = document.getElementById('scCanvas');
    var w = area.clientWidth, h = area.clientHeight, dpr = window.devicePixelRatio || 1;
    cv.width = w * dpr; cv.height = h * dpr;
    var ctx = cv.getContext('2d');
    ctx.scale(dpr, dpr);
    var css = getComputedStyle(document.documentElement);
    var c1 = (css.getPropertyValue('--primary-red') || '').trim() || '#e53935';
    var g = ctx.createLinearGradient(0, 0, w, h);
    g.addColorStop(0, '#c0c4cc'); g.addColorStop(0.45, '#eef0f3'); g.addColorStop(0.55, '#d5d8de'); g.addColorStop(1, '#a9aeb8');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    // little sparkles and stars on the foil
    for (var i = 0; i < 90; i++) {
      ctx.fillStyle = 'rgba(255,255,255,' + (0.25 + Math.random() * 0.5) + ')';
      ctx.beginPath(); ctx.arc(Math.random() * w, Math.random() * h, Math.random() * 1.8 + 0.4, 0, 7); ctx.fill();
    }
    ctx.fillStyle = c1; ctx.globalAlpha = 0.9;
    ctx.font = '800 15px Poppins, system-ui, sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('★  SCRATCH  ★', w / 2, h / 2 + 5);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'destination-out';

    var drawing = false, last = null, moves = 0;
    function pt(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function line(a, b) { ctx.lineWidth = 34; ctx.lineCap = 'round'; ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke(); }
    function cleared() {
      var d = ctx.getImageData(0, 0, cv.width, cv.height).data, n = 0, step = 16 * 4;
      for (var i = 3; i < d.length; i += step) if (d[i] === 0) n++;
      return n / (d.length / step);
    }
    cv.addEventListener('pointerdown', function (e) {
      drawing = true; last = pt(e); line(last, last); cv.setPointerCapture(e.pointerId);
      area.classList.add('scratching'); reveal();
    });
    cv.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = pt(e); line(last, p); last = p;
      if (++moves % 8 === 0 && prize && cleared() > 0.5) finish();
    });
    function up() { drawing = false; if (prize && cleared() > 0.5) finish(); }
    cv.addEventListener('pointerup', up);
    cv.addEventListener('pointercancel', up);
  }

  function reveal() {
    if (revealing || prize) return;
    revealing = true;
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order_number: order, customer_phone: phone }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        revealing = false;
        if (!d.success) { load(); return; }
        prize = d.prize;
        var u = document.getElementById('scUnder');
        if (u) u.innerHTML = prizeHtml(prize);
      })
      .catch(function () { revealing = false; });
  }

  function finish() {
    if (done) return;
    done = true;
    var cv = document.getElementById('scCanvas'), area = document.getElementById('scArea');
    if (cv) cv.classList.add('gone');
    if (area) area.classList.add('open');
    var after = document.getElementById('scAfter');
    if (after) after.innerHTML = wonExtras(prize);
    var head = box.querySelector('.sc-head small');
    if (head) head.textContent = prize.type === 'none' ? 'Scratched' : 'Your reward';
    if (prize.type !== 'none') confetti(area);
    card.state = 'scratched'; card.prize = prize;
  }

  /* ── Spin the wheel (Loyalty Studio > Games): same prize, drawn on the server ── */
  var spinning = false;
  function injectWheelCss() {
    if (document.getElementById('scWheelCss')) return;
    var st = document.createElement('style');
    st.id = 'scWheelCss';
    st.textContent =
      '.sc-wheel-wrap{position:relative;width:min(290px,100%);aspect-ratio:1;margin:6px auto 4px}' +
      '.sc-wheel{position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 6px #1f1530,0 0 0 9px #f5c451,0 18px 30px -12px rgba(0,0,0,.45);transition:transform 5.2s cubic-bezier(.12,.72,.1,1)}' +
      '.sc-wheel svg{display:block;width:100%;height:100%}' +
      '.sc-wheel-dots{position:absolute;inset:-6px;border-radius:50%;pointer-events:none}' +
      '.sc-wheel-dots i{position:absolute;left:50%;top:50%;width:6px;height:6px;margin:-3px;border-radius:50%;background:#fff6d6;box-shadow:0 0 6px #ffd76a}' +
      '.sc-wheel-pin{position:absolute;left:50%;top:-14px;width:30px;height:38px;margin-left:-15px;z-index:2;filter:drop-shadow(0 3px 3px rgba(0,0,0,.35))}' +
      '.sc-wheel-btn{position:absolute;left:50%;top:50%;width:76px;height:76px;margin:-38px;border-radius:50%;border:4px solid #f5c451;background:radial-gradient(circle at 35% 30%,#3a2a55,#1f1530);color:#ffe08a;font:800 15px/1 Poppins,system-ui,sans-serif;letter-spacing:.06em;cursor:pointer;z-index:2;box-shadow:0 6px 14px -4px rgba(0,0,0,.5)}' +
      '.sc-wheel-btn:disabled{cursor:default;opacity:.9}' +
      '.sc-wheel-btn:not(:disabled){animation:scPulse 1.6s ease-in-out infinite}' +
      '@keyframes scPulse{50%{transform:scale(1.07)}}' +
      '.sc-wheel-res{margin-top:12px}' +
      '.sc-wheel-res .sc-prize{position:static;min-height:0;padding:14px;border-radius:14px}' +
      '@media (prefers-reduced-motion:reduce){.sc-wheel{transition-duration:.01s}.sc-wheel-btn{animation:none}}';
    document.head.appendChild(st);
  }
  function short(o) {
    var c = box.getAttribute('data-cur') || '\u20b9';
    if (o.type === 'flat') return c + (+o.value) + ' OFF';
    if (o.type === 'percent') return (+o.value) + '% OFF';
    return 'TRY AGAIN';
  }
  function segments() {
    var base = (card.odds || []).filter(function (o) { return o.type; });
    if (!base.length) return [];
    var segs = [];
    while (segs.length < 6) segs = segs.concat(base);       // a full-looking wheel: 6 to 8 slices
    return segs.slice(0, Math.max(6, Math.min(8, segs.length)));
  }
  function wheelSvg(segs) {
    var n = segs.length, a = 360 / n, r = 100, css = getComputedStyle(document.documentElement);
    var brand = (css.getPropertyValue('--primary-red') || '').trim() || '#e53935';
    var cols = [brand, '#fff7e0', '#2b1f40', '#ffe08a'];
    var out = '<svg viewBox="-100 -100 200 200" aria-hidden="true">';
    segs.forEach(function (s, i) {
      var a0 = (i * a - 90) * Math.PI / 180, a1 = ((i + 1) * a - 90) * Math.PI / 180;
      var fill = s.type === 'none' ? (i % 2 ? '#e9e3f5' : '#d9d0ec') : cols[i % cols.length];
      var ink = fill === brand || fill === '#2b1f40' ? '#fff' : '#3b2604';
      out += '<path d="M0 0L' + (r * Math.cos(a0)).toFixed(2) + ' ' + (r * Math.sin(a0)).toFixed(2) + 'A' + r + ' ' + r + ' 0 0 1 ' + (r * Math.cos(a1)).toFixed(2) + ' ' + (r * Math.sin(a1)).toFixed(2) + 'Z" fill="' + fill + '" stroke="rgba(0,0,0,.12)" stroke-width=".6"/>';
      out += '<text transform="rotate(' + (i * a + a / 2) + ') translate(0 -62) rotate(90)" fill="' + ink + '" font-family="Poppins,system-ui,sans-serif" font-weight="800" font-size="' + (n > 6 ? 9.5 : 11) + '" text-anchor="middle" dominant-baseline="middle">' + esc(short(s)) + '</text>';
    });
    return out + '</svg>';
  }
  function renderWheel() {
    injectWheelCss();
    var segs = segments();
    if (!segs.length) { box.innerHTML = ''; return; }
    var dots = '';
    for (var i = 0; i < 16; i++) { var t = i / 16 * 2 * Math.PI; dots += '<i style="left:' + (50 + Math.cos(t) * 50).toFixed(2) + '%;top:' + (50 + Math.sin(t) * 50).toFixed(2) + '%"></i>'; }
    box.innerHTML = '<div class="card sc-card">' + headHtml('Tap spin to win a reward on your next order') +
      '<div class="sc-wheel-wrap"><svg class="sc-wheel-pin" viewBox="0 0 30 38"><path d="M15 38L2 12a14 14 0 1 1 26 0z" fill="#ef4444" stroke="#fff" stroke-width="2.5"/><circle cx="15" cy="13" r="4.5" fill="#fff"/></svg>' +
        '<div class="sc-wheel" id="scWheel">' + wheelSvg(segs) + '</div><div class="sc-wheel-dots">' + dots + '</div>' +
        '<button type="button" class="sc-wheel-btn" id="scSpin">SPIN</button></div>' +
      '<div id="scAfter" class="sc-wheel-res"></div>' + oddsHtml() + '</div>';
    document.getElementById('scSpin').addEventListener('click', function () { spin(segs); });
  }
  function spin(segs) {
    if (spinning || done) return;
    spinning = true;
    var btn = document.getElementById('scSpin'), wheel = document.getElementById('scWheel');
    btn.disabled = true; btn.textContent = '...';
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order_number: order, customer_phone: phone }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success || !d.prize) { spinning = false; load(); return; }
        prize = d.prize;
        var hits = [];
        segs.forEach(function (s, i) { if (s.type === prize.type && (prize.type === 'none' || +s.value === +prize.value)) hits.push(i); });
        if (!hits.length) { card.state = 'scratched'; card.prize = prize; render(); return; }   // prizes changed since: just show it
        var i = hits[Math.floor(Math.random() * hits.length)], a = 360 / segs.length;
        var land = 360 * 6 - (i * a + a / 2) + (Math.random() - 0.5) * a * 0.6;
        wheel.addEventListener('transitionend', function () { wheelDone(); }, { once: true });
        requestAnimationFrame(function () { wheel.style.transform = 'rotate(' + land + 'deg)'; });
      })
      .catch(function () { spinning = false; btn.disabled = false; btn.textContent = 'SPIN'; });
  }
  function wheelDone() {
    if (done) return;
    done = true;
    var after = document.getElementById('scAfter'), btn = document.getElementById('scSpin');
    if (btn) btn.textContent = prize.type === 'none' ? 'OOPS' : 'WON';
    if (after) after.innerHTML = prizeHtml(prize) + wonExtras(prize);
    var head = box.querySelector('.sc-head small');
    if (head) head.textContent = prize.type === 'none' ? 'Spun' : 'Your reward';
    if (prize.type !== 'none') confetti(box.querySelector('.sc-wheel-wrap'));
    card.state = 'scratched'; card.prize = prize;
  }
  function confetti(host) {
    if (!host || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    var colors = ['#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#facc15'];
    for (var i = 0; i < 46; i++) {
      var s = document.createElement('i');
      s.className = 'sc-bit';
      s.style.background = colors[i % colors.length];
      s.style.left = (40 + Math.random() * 20) + '%';
      s.style.setProperty('--dx', (Math.random() * 320 - 160) + 'px');
      s.style.setProperty('--dy', (-60 - Math.random() * 160) + 'px');
      s.style.setProperty('--r', (Math.random() * 720 - 360) + 'deg');
      s.style.animationDelay = (Math.random() * 0.15) + 's';
      host.appendChild(s);
      setTimeout(function (el) { el.remove(); }.bind(null, s), 1600);
    }
  }

  box.addEventListener('click', function (e) {
    var p = card && card.prize;
    if (!p) return;
    if (e.target.closest('.sc-copy')) {
      var b = e.target.closest('.sc-copy');
      var ok = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(p.code).then(ok, ok); else ok();
    } else if (e.target.closest('.sc-use')) {
      try { localStorage.setItem('rgRewardCode', JSON.stringify({ rid: rid, code: p.code })); } catch (err) {}
      var b2 = e.target.closest('.sc-use');
      b2.textContent = 'Saved: it applies itself at checkout';
      b2.classList.add('saved');
    }
  });

  function load() {
    fetch(API + '?order_number=' + encodeURIComponent(order) + '&customer_phone=' + encodeURIComponent(phone), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) return;
        var before = card ? card.state : null;
        card = d.card;
        if (card && card.state === 'new' && before === 'new') return;   // don't redraw a card being scratched
        if (done || spinning) return;
        render();
      })
      .catch(function () {});
  }
  load();
})();
