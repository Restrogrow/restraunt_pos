/*
 * Table QR standee designer
 *
 * Renders a 4 x 6 inch (1200 x 1800 px, 300 DPI) colour QR standee for a
 * table on a <canvas>: fixed illustrated artwork + dynamic restaurant logo,
 * restaurant name, table label and the table's real menu QR code, with a
 * "Powered by RestroGrow" footer. Owners can customise the text, colours and
 * logo; their choices are remembered per restaurant in localStorage.
 *
 * Artwork + placement coordinates come from the Restro Grow Colour QR Kit
 * (templates.json, coordinates in a 1024 x 1536 reference frame).
 */
(function () {
  'use strict';

  var OUT_W = 1200, OUT_H = 1800;

  var TEMPLATES = {
    'indian-feast': {
      name: 'Indian Feast',
      artwork: '../assets/images/qr-templates/indian-feast.jpg',
      ref: [1024, 1536],
      qrBox: [277, 677, 470],          // x, y, size
      qrPanel: [273, 655, 479, 518],   // artwork's off-white QR panel (x, y, w, h) — repainted pure white
      logo: [512, 155, 79],            // centre x, centre y, radius
      nameY: 306,
      nameMaxWidth: 640,
      tableY: 1398,
      poweredY: 1448,
      rgLogoBox: [386, 1470, 252, 44], // x, y, w, h
      defaults: { nameColor: '#fff1c9', tableColor: '#0f3d3a', qrColor: '#111111' }
    }
  };
  var TEMPLATE_ID = 'indian-feast';
  var RG_LOGO = '../assets/images/logo-transparent.png';

  var state = null;       // current customisation
  var current = null;     // { table, url }
  var imgCache = {};
  var renderSeq = 0;

  function rid() {
    return (document.getElementById('restaurantId') || {}).textContent || 'default';
  }
  function storageKey() { return 'rg_qr_standee_' + rid().trim(); }

  function defaultState() {
    var t = TEMPLATES[TEMPLATE_ID];
    return {
      name: ((document.getElementById('restaurantName') || {}).textContent || '').trim(),
      nameSize: 30,
      nameColor: t.defaults.nameColor,
      tablePrefix: 'Table',
      showTable: true,
      tableColor: t.defaults.tableColor,
      qrColor: t.defaults.qrColor,
      logoMode: 'restaurant',   // restaurant | custom | none
      logoShape: 'circle',      // circle | fit
      customLogo: ''
    };
  }

  function loadState() {
    var s = defaultState();
    try {
      var saved = JSON.parse(localStorage.getItem(storageKey()) || 'null');
      if (saved && typeof saved === 'object') {
        Object.keys(s).forEach(function (k) { if (saved[k] !== undefined) s[k] = saved[k]; });
      }
    } catch (e) {}
    return s;
  }
  function saveState() {
    try { localStorage.setItem(storageKey(), JSON.stringify(state)); } catch (e) {}
  }

  function loadImage(src, crossOrigin) {
    if (!src) return Promise.resolve(null);
    if (imgCache[src]) return imgCache[src];
    imgCache[src] = new Promise(function (resolve) {
      var img = new Image();
      if (crossOrigin) img.crossOrigin = 'anonymous';
      img.onload = function () { resolve(img); };
      img.onerror = function () { delete imgCache[src]; resolve(null); };
      img.src = src;
    });
    return imgCache[src];
  }

  function restaurantLogoSrc() {
    var el = document.getElementById('dashboardRestaurantLogo');
    if (!el || !el.src) return '';
    // The dashboard falls back to the RestroGrow mark when a restaurant has
    // no logo of its own — that's not the restaurant's logo.
    if (/logo-192\.png|logo-transparent\.png/.test(el.src)) return '';
    return el.src;
  }

  function qrSrc(url, color) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&margin=0&qzone=0&ecc=M'
      + '&color=' + color.replace('#', '') + '&bgcolor=ffffff&data=' + encodeURIComponent(url);
  }

  function initials(name) {
    return (name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
  }

  async function render(canvas) {
    var seq = ++renderSeq;
    var t = TEMPLATES[TEMPLATE_ID];
    var S = OUT_W / t.ref[0];
    var logoSrc = state.logoMode === 'custom' ? state.customLogo
      : state.logoMode === 'restaurant' ? restaurantLogoSrc() : '';

    var fontsReady = (document.fonts && document.fonts.load)
      ? Promise.all([document.fonts.load('700 30px Poppins'), document.fonts.load('500 16px Poppins')]).catch(function () {})
      : Promise.resolve();
    var imgs = await Promise.all([
      fontsReady,
      loadImage(t.artwork),
      loadImage(qrSrc(current.url, state.qrColor), true),
      loadImage(logoSrc),
      loadImage(RG_LOGO)
    ]);
    if (seq !== renderSeq) return false; // a newer render superseded this one
    var art = imgs[1], qr = imgs[2], logo = imgs[3], rg = imgs[4];

    canvas.width = OUT_W;
    canvas.height = OUT_H;
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, OUT_W, OUT_H);
    if (art) ctx.drawImage(art, 0, 0, OUT_W, OUT_H);

    // ── Restaurant logo (inside the artwork's white circle) ──
    var cx = t.logo[0] * S, cy = t.logo[1] * S, r = t.logo[2] * S;
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.clip();
    if (logo) {
      var lw = logo.naturalWidth, lh = logo.naturalHeight, scale, dw, dh;
      if (state.logoShape === 'circle') {
        scale = Math.max((2 * r) / lw, (2 * r) / lh);          // cover
      } else {
        var box = r * Math.SQRT2 * 0.92;                         // contain in inscribed square
        scale = Math.min(box / lw, box / lh);
      }
      dw = lw * scale; dh = lh * scale;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(logo, cx - dw / 2, cy - dh / 2, dw, dh);
    } else if (state.logoMode !== 'none' || state.name) {
      ctx.fillStyle = t.defaults.tableColor;
      ctx.font = '700 ' + Math.round(r * 0.8) + 'px Poppins, Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(initials(state.name) || 'RG', cx, cy + r * 0.04);
    }
    ctx.restore();

    // ── Restaurant name (auto-shrinks to fit the header) ──
    if (state.name) {
      var size = state.nameSize * S, maxW = t.nameMaxWidth * S;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = state.nameColor;
      do {
        ctx.font = '700 ' + size + 'px Poppins, Arial, sans-serif';
        if (ctx.measureText(state.name).width <= maxW || size <= 14) break;
        size -= 1;
      } while (true);
      ctx.fillText(state.name, OUT_W / 2, t.nameY * S);
    }

    // ── QR code: white box + real QR with a 4-module-ish quiet zone ──
    var qx = t.qrBox[0] * S, qy = t.qrBox[1] * S, qs = t.qrBox[2] * S;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(t.qrPanel[0] * S, t.qrPanel[1] * S, t.qrPanel[2] * S, t.qrPanel[3] * S);
    if (qr) {
      var pad = qs * 0.08;
      ctx.imageSmoothingEnabled = false;
      ctx.drawImage(qr, qx + pad, qy + pad, qs - 2 * pad, qs - 2 * pad);
      ctx.imageSmoothingEnabled = true;
    } else {
      ctx.fillStyle = '#b91c1c';
      ctx.font = '600 ' + Math.round(22 * S) + 'px Poppins, Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('QR could not load — check internet', qx + qs / 2, qy + qs / 2);
    }

    // ── Table label (white footer band) ──
    if (state.showTable) {
      ctx.fillStyle = state.tableColor;
      ctx.font = '700 ' + Math.round(34 * S) + 'px Poppins, Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(((state.tablePrefix || '').trim() + ' ' + current.table).trim(), OUT_W / 2, t.tableY * S);
    }

    // ── Powered by RestroGrow ──
    ctx.fillStyle = '#686868';
    ctx.font = '500 ' + Math.round(16 * S) + 'px Poppins, Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('POWERED BY', OUT_W / 2, t.poweredY * S);
    if (rg) {
      var bx = t.rgLogoBox[0] * S, by = t.rgLogoBox[1] * S, bw = t.rgLogoBox[2] * S, bh = t.rgLogoBox[3] * S;
      var k = Math.min(bw / rg.naturalWidth, bh / rg.naturalHeight);
      var rw = rg.naturalWidth * k, rh = rg.naturalHeight * k;
      ctx.drawImage(rg, bx + (bw - rw) / 2, by + (bh - rh) / 2, rw, rh);
    }
    return !!qr;
  }

  // ── Modal UI ──
  function el(id) { return document.getElementById(id); }

  function buildModal() {
    if (el('qrStandeeModal')) return;
    var wrap = document.createElement('div');
    wrap.id = 'qrStandeeModal';
    wrap.className = 'qr-standee-modal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'qrStandeeTitle');
    wrap.innerHTML =
      '<div class="qr-standee-dialog">' +
        '<div class="qr-standee-head">' +
          '<div><h2 id="qrStandeeTitle">Design table standee</h2>' +
          '<p id="qrStandeeSub">Indian Feast · 4 × 6 inch</p></div>' +
          '<button type="button" class="qr-standee-close" aria-label="Close" data-close>&times;</button>' +
        '</div>' +
        '<div class="qr-standee-body">' +
          '<div class="qr-standee-preview"><canvas id="qrStandeeCanvas" width="1200" height="1800"></canvas>' +
            '<div class="qr-standee-status" id="qrStandeeStatus" aria-live="polite"></div></div>' +
          '<form class="qr-standee-controls" id="qrStandeeForm" onsubmit="return false">' +
            '<fieldset><legend>Restaurant</legend>' +
              '<label>Name<input type="text" id="qsName" maxlength="60"></label>' +
              '<div class="qs-row"><label>Text size<input type="range" id="qsNameSize" min="18" max="40" step="1"></label>' +
              '<label class="qs-color">Colour<input type="color" id="qsNameColor"></label></div>' +
            '</fieldset>' +
            '<fieldset><legend>Logo</legend>' +
              '<div class="qs-seg" role="radiogroup">' +
                '<label><input type="radio" name="qsLogoMode" value="restaurant">My logo</label>' +
                '<label><input type="radio" name="qsLogoMode" value="custom">Upload</label>' +
                '<label><input type="radio" name="qsLogoMode" value="none">Initials</label>' +
              '</div>' +
              '<input type="file" id="qsLogoFile" accept="image/png,image/jpeg,image/webp" hidden>' +
              '<div class="qs-seg" role="radiogroup" id="qsShapeRow">' +
                '<label><input type="radio" name="qsLogoShape" value="circle">Fill circle</label>' +
                '<label><input type="radio" name="qsLogoShape" value="fit">Fit inside</label>' +
              '</div>' +
              '<p class="qs-hint" id="qsLogoHint"></p>' +
            '</fieldset>' +
            '<fieldset><legend>Table label</legend>' +
              '<label class="qs-check"><input type="checkbox" id="qsShowTable">Show table number</label>' +
              '<div class="qs-row"><label>Text before number<input type="text" id="qsTablePrefix" maxlength="20"></label>' +
              '<label class="qs-color">Colour<input type="color" id="qsTableColor"></label></div>' +
            '</fieldset>' +
            '<fieldset><legend>QR code</legend>' +
              '<label class="qs-color qs-color-wide">QR colour<input type="color" id="qsQrColor"></label>' +
              '<p class="qs-hint">Keep it dark so every phone can scan it.</p>' +
            '</fieldset>' +
            '<button type="button" class="qs-reset" id="qsReset">Reset to design defaults</button>' +
          '</form>' +
        '</div>' +
        '<div class="qr-standee-foot">' +
          '<a class="qs-link" id="qsOpenLink" target="_blank" rel="noopener">Test link</a>' +
          '<span class="qs-spacer"></span>' +
          '<button type="button" class="qs-btn" id="qsPrint"><span class="material-symbols-rounded">print</span>Print</button>' +
          '<button type="button" class="qs-btn qs-btn-primary" id="qsDownload"><span class="material-symbols-rounded">download</span>Download PNG</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(wrap);

    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || e.target.hasAttribute('data-close')) closeStandee();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('open')) closeStandee();
    });

    var form = el('qrStandeeForm');
    form.addEventListener('input', onControlChange);
    form.addEventListener('change', onControlChange);
    el('qsLogoFile').addEventListener('change', onLogoFile);
    el('qsReset').addEventListener('click', function () {
      var keepLogo = { customLogo: state.customLogo };
      state = defaultState();
      state.customLogo = keepLogo.customLogo;
      saveState();
      syncControls();
      redraw();
    });
    el('qsDownload').addEventListener('click', download);
    el('qsPrint').addEventListener('click', printStandee);
  }

  function syncControls() {
    el('qsName').value = state.name;
    el('qsNameSize').value = state.nameSize;
    el('qsNameColor').value = state.nameColor;
    el('qsShowTable').checked = !!state.showTable;
    el('qsTablePrefix').value = state.tablePrefix;
    el('qsTableColor').value = state.tableColor;
    el('qsQrColor').value = state.qrColor;
    document.querySelectorAll('input[name="qsLogoMode"]').forEach(function (r) { r.checked = r.value === state.logoMode; });
    document.querySelectorAll('input[name="qsLogoShape"]').forEach(function (r) { r.checked = r.value === state.logoShape; });
    el('qsTablePrefix').disabled = !state.showTable;
    el('qsTableColor').disabled = !state.showTable;
    el('qsShapeRow').style.display = state.logoMode === 'none' ? 'none' : '';
    var hint = '';
    if (state.logoMode === 'restaurant' && !restaurantLogoSrc()) hint = 'No logo uploaded in Settings yet — showing initials. Use “Upload” to add one here.';
    if (state.logoMode === 'custom') hint = state.customLogo ? 'Click “Upload” again to choose a different image.' : 'Choose a PNG or JPG — a square logo works best.';
    el('qsLogoHint').textContent = hint;
  }

  function onControlChange(e) {
    var t = e.target;
    if (t.name === 'qsLogoMode') {
      state.logoMode = t.value;
      if (t.value === 'custom' && e.type === 'change') el('qsLogoFile').click();
    } else if (t.name === 'qsLogoShape') {
      state.logoShape = t.value;
    } else if (t.id === 'qsName') state.name = t.value;
    else if (t.id === 'qsNameSize') state.nameSize = parseInt(t.value, 10) || 26;
    else if (t.id === 'qsNameColor') state.nameColor = t.value;
    else if (t.id === 'qsShowTable') state.showTable = t.checked;
    else if (t.id === 'qsTablePrefix') state.tablePrefix = t.value;
    else if (t.id === 'qsTableColor') state.tableColor = t.value;
    else if (t.id === 'qsQrColor') state.qrColor = t.value;
    else return;
    saveState();
    syncControls();
    redraw();
  }

  function onLogoFile(e) {
    var file = e.target.files && e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { setStatus('That image is over 5 MB — please choose a smaller one.'); return; }
    var reader = new FileReader();
    reader.onload = function () {
      // Downscale so the remembered logo stays small in localStorage.
      var img = new Image();
      img.onload = function () {
        var max = 600, k = Math.min(1, max / Math.max(img.width, img.height));
        var c = document.createElement('canvas');
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        state.customLogo = c.toDataURL('image/png');
        state.logoMode = 'custom';
        saveState();
        syncControls();
        redraw();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  }

  function setStatus(msg) { el('qrStandeeStatus').textContent = msg || ''; }

  function redraw() {
    setStatus('Updating preview…');
    return render(el('qrStandeeCanvas')).then(function (ok) {
      if (ok === false) return; // superseded
      setStatus(ok ? '' : 'QR code could not be loaded. Check your internet connection and try again.');
    });
  }

  function fileName() {
    return ('Table-' + current.table + '-QR-standee.png').replace(/[^\w.\-]+/g, '-');
  }

  function download() {
    var canvas = el('qrStandeeCanvas');
    try {
      canvas.toBlob(function (blob) {
        if (!blob) { setStatus('Could not create the image. Please try again.'); return; }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fileName();
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      }, 'image/png');
    } catch (e) {
      setStatus('Could not export the image (the QR service blocked it). Please try again.');
    }
  }

  function printStandee() {
    var data;
    try { data = el('qrStandeeCanvas').toDataURL('image/png'); }
    catch (e) { setStatus('Could not prepare the print. Please try again.'); return; }
    var w = window.open('', '_blank');
    if (!w) { setStatus('Allow pop-ups for this site to print.'); return; }
    w.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + fileName() + '</title>' +
      '<style>@page{size:4in 6in;margin:0}html,body{margin:0;padding:0;background:#fff}' +
      'img{display:block;width:4in;height:6in}</style></head><body>' +
      '<img src="' + data + '" alt="Table QR standee"></body></html>');
    w.document.close();
    w.focus();
    var img = w.document.querySelector('img');
    var go = function () { w.print(); };
    if (img.complete) setTimeout(go, 150); else img.onload = function () { setTimeout(go, 150); };
  }

  function openStandee(btn) {
    current = { table: btn.getAttribute('data-table'), url: btn.getAttribute('data-url') };
    buildModal();
    state = loadState();
    syncControls();
    el('qrStandeeTitle').textContent = 'Design standee · ' + ((state.tablePrefix || 'Table') + ' ' + current.table).trim();
    el('qsOpenLink').href = current.url;
    el('qrStandeeModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    redraw();
    setTimeout(function () { el('qsName').focus(); }, 50);
  }

  function closeStandee() {
    var m = el('qrStandeeModal');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
  }

  window.openQRStandee = openStandee;
  window.closeQRStandee = closeStandee;
})();
