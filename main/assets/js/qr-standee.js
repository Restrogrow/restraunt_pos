/*
 * Table QR standee designer
 *
 * Renders a 4 x 6 inch (1200 x 1800 px, 300 DPI) colour QR standee for a
 * table on a <canvas>: fixed illustrated artwork + dynamic restaurant logo,
 * restaurant name, table label and the table's real menu QR code, with a
 * "Powered by RestroGrow" footer. Owners pick one of the designs and can
 * customise text, colours and logo; choices are remembered per restaurant
 * in localStorage.
 *
 * Artwork + placement coordinates come from the Restro Grow Colour QR Kit
 * and the Ten QR Designs kit (templates.json, 1024 x 1536 reference frame).
 * qrPanel is the artwork's own white QR panel as measured from the image —
 * it's repainted pure white together with qrBox so the code prints cleanly.
 *
 * Layouts:
 *   strip — name in the header, white footer strip with "Powered by"
 *   pill  — name near the bottom, "Powered by" over a white pill
 */
(function () {
  'use strict';

  var OUT_W = 1200, OUT_H = 1800, REF_W = 1024;
  var S = OUT_W / REF_W;
  var ART_DIR = '../assets/images/qr-templates/';
  var RG_LOGO = '../assets/images/logo-transparent.png';

  var TEMPLATES = [
    { id: "burger-pop", name: "Burger Pop", layout: "strip", qrBox: [286,812,452], qrPanel: [285,800,454,475], logo: [512,120,86], nameY: 236, nameColor: "#c73808", tableColor: "#c73808" },
    { id: "italian-red", name: "Trattoria Red", layout: "strip", qrBox: [260,656,504], qrPanel: [255,652,514,509], logo: [512,125,81], nameY: 236, nameColor: "#fff2ce", tableColor: "#a3201a" },
    { id: "botanical-lime", name: "Fresh Botanical", layout: "strip", qrBox: [272,692,480], qrPanel: [269,688,486,500], logo: [512,142,77], nameY: 246, nameColor: "#134622", tableColor: "#134622" },
    { id: "cafe-lilac", name: "Cafe Lilac", layout: "strip", qrBox: [288,792,448], qrPanel: [286,787,452,458], logo: [512,117,76], nameY: 266, nameColor: "#421332", tableColor: "#421332" },
    { id: "indian-feast", name: "Indian Feast", layout: "strip", qrBox: [277,677,470], qrPanel: [273,655,479,518], logo: [512,155,79], nameY: 306, nameColor: "#fff1c9", tableColor: "#0f3d3a" },
    { id: "pizza-club", name: "Pizza Club", layout: "pill", qrBox: [222,616,583], qrPanel: [213,607,601,602], logo: [512,115,77], nameY: 1360, nameColor: "#fff1db", tableColor: "#2a1a05" },
    { id: "matcha-garden", name: "Matcha Garden", layout: "pill", qrBox: [222,533,580], qrPanel: [232,542,560,563], logo: [512,93,52], nameY: 1360, nameColor: "#173d26", tableColor: "#173d26" },
    { id: "chai-social", name: "Chai Social", layout: "pill", qrBox: [222,609,580], qrPanel: [237,624,550,550], logo: [512,103,68], nameY: 1360, nameColor: "#422818", tableColor: "#422818" },
    { id: "dosa-house", name: "Dosa House", layout: "pill", qrBox: [222,588,580], qrPanel: [219,587,586,582], logo: [512,120,70], nameY: 1360, nameColor: "#fff3d6", tableColor: "#00240f" },
    { id: "coffee-craft", name: "Coffee Craft", layout: "pill", qrBox: [222,617,580], qrPanel: [220,617,584,581], logo: [512,120,70], nameY: 1360, nameColor: "#fff3dc", tableColor: "#301504" },
    { id: "sushi-studio", name: "Sushi Studio", layout: "pill", qrBox: [222,569,580], qrPanel: [235,582,553,555], logo: [512,120,70], nameY: 1360, nameColor: "#15233d", tableColor: "#15233d",
      backdrop: { rect: [330, 1320, 364, 195], radius: 20, color: "#fff5df" } },
    { id: "taco-fiesta", name: "Taco Fiesta", layout: "pill", qrBox: [222,634,580], qrPanel: [223,635,578,578], logo: [512,120,70], nameY: 1360, nameColor: "#fff5de", tableColor: "#760103" },
    { id: "bakery-butter", name: "Butter Bakery", layout: "pill", qrBox: [214,630,596], qrPanel: [205,620,614,616], logo: [512,120,70], nameY: 1360, nameColor: "#402818", tableColor: "#402818" },
    { id: "grill-ember", name: "Ember Grill", layout: "pill", qrBox: [222,622,580], qrPanel: [225,624,574,576], logo: [512,120,70], nameY: 1360, nameColor: "#fff0d8", tableColor: "#3e1107" },
    { id: "mango-summer", name: "Mango Summer", layout: "pill", qrBox: [223,573,580], qrPanel: [235,586,556,555], logo: [512,120,70], nameY: 1360, nameColor: "#163c30", tableColor: "#163c30" }
  ];
  var DEFAULT_TEMPLATE = 'indian-feast';

  // Per-layout geometry (reference frame), from the kits' render.py
  var LAYOUTS = {
    strip: {
      nameSize: 30, nameMaxWidth: 640,
      tab: { height: 72, minWidth: 230, padX: 44, radius: 20, fontSize: 36 },
      poweredY: 1425, poweredColor: '#686868', rgLogoBox: [386, 1447, 252, 44], rgPill: null
    },
    pill: {
      nameSize: 34, nameMaxWidth: 820,
      tab: { height: 64, minWidth: 220, padX: 40, radius: 18, fontSize: 34 },
      poweredY: 1420, poweredColor: null /* = name colour */, rgLogoBox: [386, 1451, 252, 44],
      rgPill: { rect: [362, 1442, 300, 62], radius: 15 }
    }
  };

  var state = null;       // current customisation
  var current = null;     // { table, url }
  var imgCache = {};
  var renderSeq = 0;

  function tpl(id) {
    for (var i = 0; i < TEMPLATES.length; i++) if (TEMPLATES[i].id === id) return TEMPLATES[i];
    return tpl(DEFAULT_TEMPLATE);
  }

  function rid() {
    return (document.getElementById('restaurantId') || {}).textContent || 'default';
  }
  function storageKey() { return 'rg_qr_standee_v2_' + rid().trim(); }

  // Colour fields left empty ('') follow the selected design's defaults.
  function defaultState() {
    return {
      template: DEFAULT_TEMPLATE,
      name: ((document.getElementById('restaurantName') || {}).textContent || '').trim(),
      nameScale: 100,           // % of the design's base name size
      nameColor: '',
      tablePrefix: 'Table',
      showTable: true,
      tableColor: '',
      qrColor: '#111111',
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
    s.template = tpl(s.template).id;
    return s;
  }
  function saveState() {
    try { localStorage.setItem(storageKey(), JSON.stringify(state)); } catch (e) {}
  }

  function nameColor() { return state.nameColor || tpl(state.template).nameColor; }
  function tableColor() { return state.tableColor || tpl(state.template).tableColor; }

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

  // Same logo the dashboard sidebar shows: the restaurant's own upload, or
  // the RestroGrow logo when none has been uploaded yet.
  var RG_SQUARE_LOGO = '../assets/images/logo-512.png';
  function isDefaultLogo(src) { return /logo-(192|512)\.png|logo-transparent\.png/.test(src || ''); }
  function restaurantLogoSrc() {
    var el = document.getElementById('dashboardRestaurantLogo');
    if (!el || !el.src || isDefaultLogo(el.src)) return RG_SQUARE_LOGO;
    return el.src;
  }

  function isCrossOrigin(src) {
    if (!src || src.indexOf('data:') === 0) return false;
    try { return new URL(src, window.location.href).origin !== window.location.origin; }
    catch (e) { return false; }
  }

  function qrSrc(url, color) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&margin=0&qzone=0&ecc=M'
      + '&color=' + color.replace('#', '') + '&bgcolor=ffffff&data=' + encodeURIComponent(url);
  }

  function initials(name) {
    return (name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
  }

  function roundRect(ctx, x, y, w, h, r, squareTop) {
    ctx.beginPath();
    if (squareTop) {
      ctx.moveTo(x, y);
      ctx.lineTo(x + w, y);
    } else {
      ctx.moveTo(x + r, y);
      ctx.lineTo(x + w - r, y);
      ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    }
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    if (squareTop) {
      ctx.lineTo(x, y);
    } else {
      ctx.lineTo(x, y + r);
      ctx.quadraticCurveTo(x, y, x + r, y);
    }
    ctx.closePath();
  }

  function fitFont(ctx, text, weight, size, maxW, minSize) {
    ctx.font = weight + ' ' + size + 'px Poppins, Arial, sans-serif';
    while (ctx.measureText(text).width > maxW && size > minSize) {
      size -= 1;
      ctx.font = weight + ' ' + size + 'px Poppins, Arial, sans-serif';
    }
    return size;
  }

  async function render(canvas) {
    var seq = ++renderSeq;
    var t = tpl(state.template);
    var L = LAYOUTS[t.layout];
    var logoSrc = state.logoMode === 'custom' ? state.customLogo
      : state.logoMode === 'restaurant' ? restaurantLogoSrc() : '';

    var fontsReady = (document.fonts && document.fonts.load)
      ? Promise.all([document.fonts.load('700 30px Poppins'), document.fonts.load('500 16px Poppins')]).catch(function () {})
      : Promise.resolve();
    var imgs = await Promise.all([
      fontsReady,
      loadImage(ART_DIR + t.id + '.jpg'),
      loadImage(qrSrc(current.url, state.qrColor), true),
      // Logos hosted elsewhere must be CORS-loaded or they'd block the
      // PNG export; if that host refuses, we fall back to initials.
      loadImage(logoSrc, isCrossOrigin(logoSrc)),
      loadImage(RG_LOGO)
    ]);
    if (seq !== renderSeq) return false; // a newer render superseded this one
    var art = imgs[1], qr = imgs[2], logo = imgs[3], rg = imgs[4];

    canvas.width = OUT_W;
    canvas.height = OUT_H;
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, OUT_W, OUT_H);
    if (art) ctx.drawImage(art, 0, 0, OUT_W, OUT_H);
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    // ── Restaurant logo (inside the artwork's white circle) ──
    var cx = t.logo[0] * S, cy = t.logo[1] * S, r = t.logo[2] * S;
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.clip();
    if (logo) {
      var lw = logo.naturalWidth, lh = logo.naturalHeight, scale;
      if (state.logoShape === 'circle') {
        scale = Math.max((2 * r) / lw, (2 * r) / lh);          // cover
      } else {
        var box = r * Math.SQRT2 * 0.92;                         // contain in inscribed square
        scale = Math.min(box / lw, box / lh);
      }
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(logo, cx - lw * scale / 2, cy - lh * scale / 2, lw * scale, lh * scale);
    } else {
      ctx.fillStyle = tableColor();
      ctx.font = '700 ' + Math.round(r * 0.8) + 'px Poppins, Arial, sans-serif';
      ctx.fillText(initials(state.name) || 'RG', cx, cy + r * 0.04);
    }
    ctx.restore();

    // ── Design-specific backdrop behind the bottom text (Sushi Studio) ──
    if (t.backdrop) {
      var b = t.backdrop.rect;
      ctx.fillStyle = t.backdrop.color;
      roundRect(ctx, b[0] * S, b[1] * S, b[2] * S, b[3] * S, t.backdrop.radius * S);
      ctx.fill();
    }

    // ── Restaurant name (auto-shrinks to fit) ──
    if (state.name) {
      ctx.fillStyle = nameColor();
      fitFont(ctx, state.name, '700', L.nameSize * S * state.nameScale / 100, L.nameMaxWidth * S, 14);
      ctx.fillText(state.name, OUT_W / 2, t.nameY * S);
    }

    // ── QR code: repaint the white panel, then the real QR with a quiet zone ──
    var qx = t.qrBox[0] * S, qy = t.qrBox[1] * S, qs = t.qrBox[2] * S;
    var p = t.qrPanel;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(p[0] * S, p[1] * S, p[2] * S, p[3] * S);
    ctx.fillRect(qx, qy, qs, qs);
    if (qr) {
      var pad = qs * 0.08;
      ctx.imageSmoothingEnabled = false;
      ctx.drawImage(qr, qx + pad, qy + pad, qs - 2 * pad, qs - 2 * pad);
      ctx.imageSmoothingEnabled = true;
    } else {
      ctx.fillStyle = '#b91c1c';
      ctx.font = '600 ' + Math.round(22 * S) + 'px Poppins, Arial, sans-serif';
      ctx.fillText('QR could not load — check internet', qx + qs / 2, qy + qs / 2);
    }

    // ── Table label: white tab hanging directly below the QR ──
    if (state.showTable) {
      var tab = L.tab;
      var label = ((state.tablePrefix || '').trim() + ' ' + current.table).trim();
      var panelRight = Math.max(p[0] + p[2], t.qrBox[0] + t.qrBox[2]);
      var panelLeft = Math.min(p[0], t.qrBox[0]);
      var maxTabW = (panelRight - panelLeft) * S;
      var fs = fitFont(ctx, label, '700', tab.fontSize * S, maxTabW - 2 * tab.padX * S, 16);
      var tw = Math.min(maxTabW, Math.max(tab.minWidth * S, ctx.measureText(label).width + 2 * tab.padX * S));
      var top = Math.max(p[1] + p[3], t.qrBox[1] + t.qrBox[2]) * S;
      var tx = OUT_W / 2 - tw / 2, ty = top - 2, th = tab.height * S + 2;
      ctx.fillStyle = '#ffffff';
      roundRect(ctx, tx, ty, tw, th, tab.radius * S, true);
      ctx.fill();
      ctx.fillStyle = tableColor();
      ctx.font = '700 ' + fs + 'px Poppins, Arial, sans-serif';
      ctx.fillText(label, OUT_W / 2, ty + th / 2 + 1);
    }

    // ── Powered by RestroGrow ──
    if (L.rgPill) {
      var rp = L.rgPill.rect;
      ctx.fillStyle = '#ffffff';
      roundRect(ctx, rp[0] * S, rp[1] * S, rp[2] * S, rp[3] * S, L.rgPill.radius * S);
      ctx.fill();
    }
    ctx.fillStyle = L.poweredColor || nameColor();
    ctx.font = '500 ' + Math.round(16 * S) + 'px Poppins, Arial, sans-serif';
    ctx.fillText('POWERED BY', OUT_W / 2, L.poweredY * S);
    if (rg) {
      var bx = L.rgLogoBox[0] * S, by = L.rgLogoBox[1] * S, bw = L.rgLogoBox[2] * S, bh = L.rgLogoBox[3] * S;
      var k = Math.min(bw / rg.naturalWidth, bh / rg.naturalHeight);
      var rw = rg.naturalWidth * k, rh = rg.naturalHeight * k;
      ctx.drawImage(rg, bx + (bw - rw) / 2, by + (bh - rh) / 2, rw, rh);
    }
    return !!qr;
  }

  // ── Modal UI ──
  function el(id) { return document.getElementById(id); }

  function designPickerHtml() {
    return TEMPLATES.map(function (t) {
      return '<label class="qs-design" title="' + t.name + '">' +
        '<input type="radio" name="qsTemplate" value="' + t.id + '">' +
        '<img src="' + ART_DIR + 'thumbs/' + t.id + '.jpg" alt="" loading="lazy" width="160" height="240">' +
        '<span>' + t.name + '</span></label>';
    }).join('');
  }

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
          '<p id="qrStandeeSub"></p></div>' +
          '<button type="button" class="qr-standee-close" aria-label="Close" data-close>&times;</button>' +
        '</div>' +
        '<div class="qr-standee-body">' +
          '<div class="qr-standee-preview"><canvas id="qrStandeeCanvas" width="1200" height="1800"></canvas>' +
            '<div class="qr-standee-status" id="qrStandeeStatus" aria-live="polite"></div></div>' +
          '<form class="qr-standee-controls" id="qrStandeeForm" onsubmit="return false">' +
            '<fieldset><legend>Design</legend>' +
              '<div class="qs-designs" role="radiogroup" aria-label="Standee design">' + designPickerHtml() + '</div>' +
            '</fieldset>' +
            '<fieldset><legend>Restaurant</legend>' +
              '<label>Name<input type="text" id="qsName" maxlength="60"></label>' +
              '<div class="qs-row"><label>Text size<input type="range" id="qsNameScale" min="60" max="140" step="5"></label>' +
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
              '<label class="qs-check"><input type="checkbox" id="qsShowTable">Show table number below the QR</label>' +
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
      var keep = { customLogo: state.customLogo, template: state.template };
      state = defaultState();
      state.customLogo = keep.customLogo;
      state.template = keep.template;
      saveState();
      syncControls();
      redraw();
    });
    el('qsDownload').addEventListener('click', download);
    el('qsPrint').addEventListener('click', printStandee);
  }

  function syncControls() {
    var t = tpl(state.template);
    document.querySelectorAll('input[name="qsTemplate"]').forEach(function (r) { r.checked = r.value === t.id; });
    el('qrStandeeSub').textContent = t.name + ' · 4 × 6 inch';
    el('qsName').value = state.name;
    el('qsNameScale').value = state.nameScale;
    el('qsNameColor').value = nameColor();
    el('qsShowTable').checked = !!state.showTable;
    el('qsTablePrefix').value = state.tablePrefix;
    el('qsTableColor').value = tableColor();
    el('qsQrColor').value = state.qrColor;
    document.querySelectorAll('input[name="qsLogoMode"]').forEach(function (r) { r.checked = r.value === state.logoMode; });
    document.querySelectorAll('input[name="qsLogoShape"]').forEach(function (r) { r.checked = r.value === state.logoShape; });
    el('qsTablePrefix').disabled = !state.showTable;
    el('qsTableColor').disabled = !state.showTable;
    el('qsShapeRow').style.display = state.logoMode === 'none' ? 'none' : '';
    var hint = '';
    if (state.logoMode === 'restaurant' && restaurantLogoSrc() === RG_SQUARE_LOGO) hint = 'Showing the RestroGrow logo (same as your dashboard). Upload your restaurant logo in Settings, or use “Upload”, to change it.';
    if (state.logoMode === 'custom') hint = state.customLogo ? 'Click “Upload” again to choose a different image.' : 'Choose a PNG or JPG — a square logo works best.';
    el('qsLogoHint').textContent = hint;
  }

  function onControlChange(e) {
    var t = e.target;
    if (t.name === 'qsTemplate') {
      if (e.type !== 'change') return;
      state.template = t.value;
      // A new design brings its own palette
      state.nameColor = '';
      state.tableColor = '';
    } else if (t.name === 'qsLogoMode') {
      state.logoMode = t.value;
      if (t.value === 'custom' && e.type === 'change') el('qsLogoFile').click();
    } else if (t.name === 'qsLogoShape') {
      state.logoShape = t.value;
    } else if (t.id === 'qsName') state.name = t.value;
    else if (t.id === 'qsNameScale') state.nameScale = parseInt(t.value, 10) || 100;
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
  }

  function closeStandee() {
    var m = el('qrStandeeModal');
    if (m) m.classList.remove('open');
    document.body.style.overflow = '';
  }

  window.openQRStandee = openStandee;
  window.closeQRStandee = closeStandee;
  // Exposed for automated preview/testing of every design
  window.QRStandeeTemplates = TEMPLATES.map(function (t) { return t.id; });
})();
