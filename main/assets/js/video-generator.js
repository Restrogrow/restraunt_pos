// Marketing > Video Studio
// Renders short animated promo videos entirely client-side (HTML5 canvas +
// MediaRecorder) - no server/ffmpeg cost per video. New templates only need
// an entry pushed onto VIDEO_TEMPLATES; the picker grid, editor fields and
// preview all pick it up automatically. Mirrors poster-generator.js; sizes, brand kit,
// QR codes, saving and the dish picker come from marketing-studio.js (window.MK).
(function() {
  'use strict';

  var CANVAS_SIZE = 1080;
  var ACCENT_COLORS = ['#e11d48', '#f97316', '#16a34a', '#2563eb', '#7c3aed', '#111827'];
  var DEFAULT_DURATION = 6000;

  // ---------- color helpers ----------
  function hexToRgb(hex) {
    var n = parseInt(String(hex).replace('#', ''), 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
  }
  function rgbToHex(r, g, b) {
    function h(v) { return Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0'); }
    return '#' + h(r) + h(g) + h(b);
  }
  function mixColor(hexA, hexB, t) {
    var a = hexToRgb(hexA), b = hexToRgb(hexB);
    return rgbToHex(a.r + (b.r - a.r) * t, a.g + (b.g - a.g) * t, a.b + (b.b - a.b) * t);
  }
  function shadeColor(hex, percent) {
    return percent >= 0 ? mixColor(hex, '#ffffff', percent) : mixColor(hex, '#000000', -percent);
  }

  // ---------- animation helpers ----------
  function ease(t) { t = Math.max(0, Math.min(1, t)); return 1 - Math.pow(1 - t, 3); }
  function easeOutBack(t) {
    t = Math.max(0, Math.min(1, t));
    var c1 = 1.70158, c3 = c1 + 1;
    return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
  }
  function seg(t, a, b) { if (b <= a) return t >= b ? 1 : 0; return Math.max(0, Math.min(1, (t - a) / (b - a))); }
  function lerp(a, b, t) { return a + (b - a) * t; }

  // ---------- canvas helpers ----------
  function loadImage(src, crossOrigin) {
    return new Promise(function(resolve, reject) {
      var img = new Image();
      if (crossOrigin) img.crossOrigin = 'anonymous';
      img.onload = function() { resolve(img); };
      img.onerror = function() { reject(new Error('Failed to load image')); };
      img.src = src;
    });
  }

  function roundRectPath(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function drawImageCoverRaw(ctx, img, x, y, w, h) {
    var imgRatio = img.width / img.height;
    var boxRatio = w / h;
    var sx, sy, sw, sh;
    if (imgRatio > boxRatio) {
      sh = img.height; sw = sh * boxRatio; sx = (img.width - sw) / 2; sy = 0;
    } else {
      sw = img.width; sh = sw / boxRatio; sx = 0; sy = (img.height - sh) / 2;
    }
    ctx.drawImage(img, sx, sy, sw, sh, x, y, w, h);
  }

  function drawPhotoPlaceholder(ctx, x, y, w, h, radius) {
    roundRectPath(ctx, x, y, w, h, radius);
    ctx.fillStyle = '#e5e7eb';
    ctx.fill();
    ctx.fillStyle = '#9ca3af';
    ctx.font = "600 28px 'Poppins'";
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('Upload a photo', x + w / 2, y + h / 2);
    ctx.textBaseline = 'alphabetic';
  }

  // Ken Burns style slow zoom/pan photo, clipped to a rounded box. t is 0..1
  // across the template's whole duration. panX 0..1 shifts the crop window
  // left->right as the zoom grows (use 0.5 for a centered zoom with no pan).
  function drawKenBurnsPhoto(ctx, img, x, y, w, h, radius, t, zoomFrom, zoomTo, panX) {
    var scale = lerp(zoomFrom, zoomTo, t);
    ctx.save();
    roundRectPath(ctx, x, y, w, h, radius);
    ctx.clip();
    if (img) {
      var cw = w * scale, ch = h * scale;
      var cx = x - (cw - w) * (panX === undefined ? 0.5 : panX);
      var cy = y - (ch - h) / 2;
      drawImageCoverRaw(ctx, img, cx, cy, cw, ch);
    } else {
      drawPhotoPlaceholder(ctx, x, y, w, h, radius);
    }
    ctx.restore();
  }

  function wrapLines(ctx, text, maxWidth, maxLines) {
    var words = String(text || '').split(/\s+/).filter(Boolean);
    var lines = [];
    var line = '';
    for (var i = 0; i < words.length; i++) {
      var test = line ? line + ' ' + words[i] : words[i];
      if (ctx.measureText(test).width > maxWidth && line) {
        lines.push(line);
        line = words[i];
      } else {
        line = test;
      }
      if (maxLines && lines.length >= maxLines) break;
    }
    if (line && (!maxLines || lines.length < maxLines)) lines.push(line);
    return maxLines ? lines.slice(0, maxLines) : lines;
  }

  // Fades + slides text up into place. entrance is [start,end] within t.
  function drawEnterText(ctx, text, x, y, t, opts) {
    var p = ease(seg(t, opts.start, opts.end));
    if (p <= 0) return;
    ctx.save();
    ctx.globalAlpha = p;
    ctx.translate(0, (opts.riseFrom === undefined ? 36 : opts.riseFrom) * (1 - p));
    ctx.font = opts.font;
    ctx.fillStyle = opts.color || '#fff';
    ctx.textAlign = opts.align || 'center';
    var lines = opts.maxWidth ? wrapLines(ctx, text, opts.maxWidth, opts.maxLines) : [String(text)];
    lines.forEach(function(l, i) { ctx.fillText(l, x, y + i * (opts.lineHeight || 40)); });
    ctx.restore();
  }

  function drawPulseBadge(ctx, text, cx, cy, t, opts) {
    opts = opts || {};
    var pulse = 1 + Math.sin(t * Math.PI * 2 * (opts.speed || 2)) * 0.045;
    ctx.save();
    ctx.translate(cx, cy);
    ctx.scale(pulse, pulse);
    ctx.font = opts.font || "64px 'Bebas Neue'";
    var h = opts.h || 84;
    var w = Math.max(240, ctx.measureText(text).width + 90);
    roundRectPath(ctx, -w / 2, -h / 2, w, h, h / 2);
    ctx.fillStyle = opts.bg || '#ffffff';
    ctx.fill();
    ctx.fillStyle = opts.color || '#111827';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, 0, 3);
    ctx.restore();
  }

  function drawFooterBar(ctx, state, assets, opts, reveal) {
    reveal = reveal === undefined ? 1 : reveal;
    if (reveal <= 0) return;
    opts = opts || {};
    var barH = 130;
    var y = CANVAS_SIZE - barH;
    ctx.save();
    ctx.globalAlpha = reveal;
    ctx.translate(0, (1 - reveal) * 28);
    ctx.fillStyle = opts.bg || '#ffffff';
    ctx.fillRect(0, y, CANVAS_SIZE, barH);

    var padX = 60, tx = padX;
    var logo = window.MK && window.MK.logo();   // logo from the Brand kit
    if (logo) {
      ctx.save();
      ctx.beginPath(); ctx.arc(padX + 42, y + barH / 2, 42, 0, Math.PI * 2);
      ctx.fillStyle = '#ffffff'; ctx.fill(); ctx.clip();
      drawImageCoverRaw(ctx, logo, padX + 3, y + barH / 2 - 39, 78, 78);
      ctx.restore();
      tx = padX + 104;
    }
    ctx.textAlign = 'left';
    ctx.fillStyle = opts.textColor || '#111827';
    ctx.font = "700 34px 'Poppins'";
    ctx.fillText(window.restaurantName || 'Your Restaurant', tx, y + 54);

    if (state.phone) {
      ctx.font = "500 26px 'Poppins'";
      ctx.fillStyle = opts.subTextColor || '#6b7280';
      ctx.fillText('Call ' + state.phone, tx, y + 92);
    }

    if (assets.qrImg) {
      var qrSize = 96;
      ctx.drawImage(assets.qrImg, CANVAS_SIZE - padX - qrSize, y + (barH - qrSize) / 2, qrSize, qrSize);
      ctx.font = "500 20px 'Poppins'";
      ctx.fillStyle = opts.subTextColor || '#6b7280';
      ctx.textAlign = 'right';
      ctx.fillText('Scan to order', CANVAS_SIZE - padX - qrSize - 14, y + barH / 2 + 6);
    }
    ctx.restore();
  }

  // ---------- templates ----------
  var VIDEO_TEMPLATES = [
    {
      id: 'zoom_reveal',
      name: 'Zoom Reveal',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Discount Badge (e.g. 25% OFF)',
      duration: 6000,
      sample: { headline: 'Weekend Special', subtext: 'Dine-in & takeaway, this Sat-Sun only', badge: '25% OFF' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        drawKenBurnsPhoto(ctx, state.photoImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE, 0, t, 1.0, 1.18, 0.5);

        var grad = ctx.createLinearGradient(0, CANVAS_SIZE * 0.3, 0, CANVAS_SIZE);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.78)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var badgeP = ease(seg(t, 0.08, 0.28));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'SPECIAL OFFER').toUpperCase(), CANVAS_SIZE / 2, 630, t, { bg: accent, color: '#fff' });
          ctx.restore();
        }

        drawEnterText(ctx, (state.headline || 'Weekend Special').toUpperCase(), CANVAS_SIZE / 2, 730, t, {
          start: 0.22, end: 0.42, font: "800 66px 'Poppins'", color: '#fff', maxWidth: 900, maxLines: 2, lineHeight: 72
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 840, t, {
            start: 0.38, end: 0.58, font: "500 30px 'Poppins'", color: 'rgba(255,255,255,0.92)', maxWidth: 820, maxLines: 2, lineHeight: 38
          });
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.5, 0.72)));
      }
    },
    {
      id: 'combo_slide',
      name: 'Combo Slide-In',
      fields: ['headline', 'subtext', 'badge', 'photo', 'photo2', 'showQR'],
      badgeLabel: 'Badge Text (e.g. COMBO DEAL)',
      duration: 6000,
      sample: { headline: 'Burger + Fries + Drink', subtext: 'Only available this week', badge: 'COMBO DEAL' },
      animate: function(ctx, state, assets, t) {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        var accent = state.accentColor;
        ctx.fillStyle = shadeColor(accent, 0.92);
        ctx.fillRect(0, 0, CANVAS_SIZE, 60);
        ctx.fillRect(0, CANVAS_SIZE - 60, CANVAS_SIZE, 60);

        var slideP = ease(seg(t, 0, 0.32));
        var half = CANVAS_SIZE / 2 - 66;
        var leftX = lerp(-half, 60, slideP);
        var rightX = lerp(CANVAS_SIZE, CANVAS_SIZE - 60 - half, slideP);
        var topY = 140, boxH = 560;

        roundRectPath(ctx, leftX, topY, half, boxH, 24);
        ctx.save(); ctx.clip();
        if (state.photoImg) drawImageCoverRaw(ctx, state.photoImg, leftX, topY, half, boxH);
        else { ctx.fillStyle = '#e5e7eb'; ctx.fillRect(leftX, topY, half, boxH); }
        ctx.restore();

        roundRectPath(ctx, rightX, topY, half, boxH, 24);
        ctx.save(); ctx.clip();
        if (state.photo2Img) drawImageCoverRaw(ctx, state.photo2Img, rightX, topY, half, boxH);
        else { ctx.fillStyle = '#e5e7eb'; ctx.fillRect(rightX, topY, half, boxH); }
        ctx.restore();

        var badgeT = seg(t, 0.3, 0.48);
        if (badgeT > 0) {
          ctx.save();
          ctx.globalAlpha = ease(badgeT);
          ctx.translate(CANVAS_SIZE / 2, topY + boxH + 74);
          ctx.scale(easeOutBack(badgeT), easeOutBack(badgeT));
          ctx.font = "60px 'Bebas Neue'";
          var badgeText = (state.badge || 'COMBO DEAL').toUpperCase();
          var w = Math.max(260, ctx.measureText(badgeText).width + 90);
          roundRectPath(ctx, -w / 2, -42, w, 84, 42);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#fff';
          ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
          ctx.fillText(badgeText, 0, 3);
          ctx.restore();
        }

        drawEnterText(ctx, state.headline || 'Combo Deal', CANVAS_SIZE / 2, topY + boxH + 160, t, {
          start: 0.46, end: 0.64, font: "800 52px 'Poppins'", color: '#111827', maxWidth: 900, maxLines: 2, lineHeight: 58
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, topY + boxH + 214, t, {
            start: 0.56, end: 0.72, font: "500 28px 'Poppins'", color: '#6b7280', maxWidth: 820, maxLines: 2, lineHeight: 34
          });
        }
        drawFooterBar(ctx, state, assets, { bg: shadeColor(accent, 0.9), textColor: '#111827', subTextColor: '#4b5563' }, ease(seg(t, 0.62, 0.8)));
      }
    },
    {
      id: 'pulse_offer',
      name: 'Happy Hour Pulse',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Badge Text (e.g. 6-8 PM ONLY)',
      duration: 6000,
      sample: { headline: 'Happy Hour', subtext: 'Buy 1 Get 1 on all drinks', badge: '6-8 PM ONLY' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        var pulse = 0.5 + 0.5 * Math.sin(t * Math.PI * 2 * 1.2);
        var grad = ctx.createLinearGradient(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        grad.addColorStop(0, shadeColor(accent, pulse * 0.12));
        grad.addColorStop(1, shadeColor(accent, -0.35 + pulse * 0.08));
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var cardP = easeOutBack(seg(t, 0, 0.28));
        ctx.save();
        ctx.translate(CANVAS_SIZE / 2, 350);
        ctx.scale(cardP, cardP);
        ctx.translate(-CANVAS_SIZE / 2, -350);
        drawKenBurnsPhoto(ctx, state.photoImg, 90, 90, 900, 470, 26, t, 1.0, 1.08, 0.5);
        ctx.restore();

        var badgeP = ease(seg(t, 0.22, 0.4));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'LIMITED TIME').toUpperCase(), CANVAS_SIZE / 2, 632, t, { bg: '#fff', color: shadeColor(accent, -0.3), speed: 2.4 });
          ctx.restore();
        }

        drawEnterText(ctx, (state.headline || 'Happy Hour').toUpperCase(), CANVAS_SIZE / 2, 740, t, {
          start: 0.36, end: 0.54, font: "800 68px 'Poppins'", color: '#fff', maxWidth: 900, maxLines: 2, lineHeight: 74, riseFrom: -30
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 850, t, {
            start: 0.5, end: 0.66, font: "500 30px 'Poppins'", color: 'rgba(255,255,255,0.92)', maxWidth: 820, maxLines: 2, lineHeight: 38
          });
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.6, 0.8)));
      }
    },
    {
      id: 'now_open_burst',
      name: 'Grand Opening Burst',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Badge Text (e.g. OPENING WEEK)',
      duration: 6500,
      sample: { headline: 'Now Open!', subtext: 'Come say hello & enjoy our launch offers', badge: 'OPENING WEEK' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        ctx.fillStyle = shadeColor(accent, -0.1);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // slow-rotating sunburst rays
        ctx.save();
        ctx.translate(CANVAS_SIZE / 2, CANVAS_SIZE / 2);
        ctx.rotate(t * Math.PI * 0.3);
        var rays = 16;
        for (var i = 0; i < rays; i++) {
          ctx.save();
          ctx.rotate((i / rays) * Math.PI * 2);
          ctx.fillStyle = i % 2 === 0 ? shadeColor(accent, 0.06) : shadeColor(accent, -0.16);
          ctx.beginPath();
          ctx.moveTo(0, 0);
          ctx.arc(0, 0, 900, 0, Math.PI / rays);
          ctx.closePath();
          ctx.fill();
          ctx.restore();
        }
        ctx.restore();

        // floating confetti dots
        var confettiColors = ['#ffffff', shadeColor(accent, 0.5), '#ffd166'];
        for (var c = 0; c < 18; c++) {
          var seedX = (c * 137) % CANVAS_SIZE;
          var seedY = ((c * 271) % 900) + 40;
          var floatY = seedY + Math.sin(t * Math.PI * 2 + c) * 26;
          ctx.beginPath();
          ctx.fillStyle = confettiColors[c % confettiColors.length];
          ctx.globalAlpha = 0.55;
          ctx.arc(seedX, floatY, 6 + (c % 3) * 2, 0, Math.PI * 2);
          ctx.fill();
          ctx.globalAlpha = 1;
        }

        // logo/photo circular badge
        var logoP = easeOutBack(seg(t, 0, 0.22));
        if (logoP > 0) {
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0, 0.2));
          ctx.translate(CANVAS_SIZE / 2, 280);
          ctx.scale(logoP, logoP);
          ctx.beginPath();
          ctx.arc(0, 0, 130, 0, Math.PI * 2);
          ctx.closePath();
          ctx.save();
          ctx.clip();
          if (state.photoImg) drawImageCoverRaw(ctx, state.photoImg, -130, -130, 260, 260);
          else { ctx.fillStyle = '#ffffff'; ctx.fillRect(-130, -130, 260, 260); }
          ctx.restore();
          ctx.lineWidth = 8;
          ctx.strokeStyle = '#fff';
          ctx.stroke();
          ctx.restore();
        }

        var headP = easeOutBack(seg(t, 0.18, 0.4));
        if (headP > 0) {
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0.18, 0.36));
          ctx.translate(CANVAS_SIZE / 2, 560);
          ctx.scale(headP, headP);
          ctx.font = "800 90px 'Poppins'";
          ctx.fillStyle = '#fff';
          ctx.textAlign = 'center';
          ctx.fillText((state.headline || 'Now Open!').toUpperCase(), 0, 0);
          ctx.restore();
        }

        var badgeP = ease(seg(t, 0.42, 0.58));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'GRAND OPENING').toUpperCase(), CANVAS_SIZE / 2, 660, t, { bg: '#fff', color: shadeColor(accent, -0.3) });
          ctx.restore();
        }
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 760, t, {
            start: 0.5, end: 0.66, font: "500 30px 'Poppins'", color: 'rgba(255,255,255,0.92)', maxWidth: 820, maxLines: 2, lineHeight: 38
          });
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.62, 0.8)));
      }
    },
    {
      id: 'delivery_sweep',
      name: 'We Deliver',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Badge Text (e.g. FREE DELIVERY)',
      duration: 6000,
      sample: { headline: 'We Deliver To You', subtext: 'Order online, fresh & hot at your door', badge: 'FREE DELIVERY' },
      animate: function(ctx, state, assets, t) {
        drawKenBurnsPhoto(ctx, state.photoImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE, 0, t, 1.08, 1.0, lerp(0.15, 0.85, t));
        var grad = ctx.createLinearGradient(0, 0, 0, CANVAS_SIZE);
        grad.addColorStop(0, 'rgba(0,0,0,0.15)');
        grad.addColorStop(0.55, 'rgba(0,0,0,0.1)');
        grad.addColorStop(1, 'rgba(0,0,0,0.8)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // sweeping delivery icon
        var sweepT = (t * 1.4) % 1;
        var iconX = lerp(-140, CANVAS_SIZE + 140, sweepT);
        ctx.save();
        ctx.globalAlpha = 0.95;
        ctx.font = "160px 'Material Symbols Rounded'";
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,0.35)';
        ctx.shadowBlur = 20;
        ctx.fillStyle = state.accentColor;
        ctx.fillText('delivery_dining', iconX, 420);
        ctx.restore();

        var badgeP = ease(seg(t, 0.06, 0.24));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'FREE DELIVERY').toUpperCase(), CANVAS_SIZE / 2, 610, t, { bg: state.accentColor, color: '#fff' });
          ctx.restore();
        }
        drawEnterText(ctx, (state.headline || 'We Deliver To You').toUpperCase(), CANVAS_SIZE / 2, 720, t, {
          start: 0.2, end: 0.4, font: "800 58px 'Poppins'", color: '#fff', maxWidth: 900, maxLines: 2, lineHeight: 64
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 820, t, {
            start: 0.36, end: 0.54, font: "500 30px 'Poppins'", color: 'rgba(255,255,255,0.92)', maxWidth: 820, maxLines: 2, lineHeight: 38
          });
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.5, 0.7)));
      }
    },
    {
      id: 'testimonial_quote',
      name: 'Customer Love',
      fields: ['headline', 'subtext', 'photo', 'showQR'],
      headlineLabel: 'Customer Quote',
      subtextLabel: 'Customer Name',
      duration: 7000,
      sample: { headline: 'Best butter chicken in town, hands down!', subtext: '- Priya S.' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        ctx.fillStyle = '#fffaf5';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        ctx.fillStyle = shadeColor(accent, 0.85);
        ctx.beginPath();
        ctx.arc(CANVAS_SIZE / 2, -180, 620, 0, Math.PI * 2);
        ctx.fill();

        // avatar
        var avatarP = easeOutBack(seg(t, 0, 0.24));
        if (avatarP > 0) {
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0, 0.2));
          ctx.translate(CANVAS_SIZE / 2, 300);
          ctx.scale(avatarP, avatarP);
          ctx.beginPath();
          ctx.arc(0, 0, 140, 0, Math.PI * 2);
          ctx.closePath();
          ctx.save();
          ctx.clip();
          if (state.photoImg) drawImageCoverRaw(ctx, state.photoImg, -140, -140, 280, 280);
          else { ctx.fillStyle = '#e5e7eb'; ctx.fillRect(-140, -140, 280, 280); }
          ctx.restore();
          ctx.lineWidth = 10;
          ctx.strokeStyle = '#fff';
          ctx.stroke();
          ctx.restore();
        }

        // 5 stars, pop in one by one
        var starsY = 500;
        for (var s = 0; s < 5; s++) {
          var starP = easeOutBack(seg(t, 0.24 + s * 0.05, 0.34 + s * 0.05));
          if (starP <= 0) continue;
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0.24 + s * 0.05, 0.32 + s * 0.05));
          ctx.translate(CANVAS_SIZE / 2 - 110 + s * 55, starsY);
          ctx.scale(starP, starP);
          ctx.font = '46px sans-serif';
          ctx.fillStyle = '#f59e0b';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText('★', 0, 0);
          ctx.restore();
        }

        ctx.save();
        ctx.font = "700 140px Georgia, serif";
        ctx.fillStyle = shadeColor(accent, 0.6);
        ctx.textAlign = 'center';
        ctx.fillText('“', CANVAS_SIZE / 2, 610);
        ctx.restore();

        drawEnterText(ctx, state.headline || 'Amazing food and service!', CANVAS_SIZE / 2, 660, t, {
          start: 0.42, end: 0.6, font: "600 42px 'Poppins'", color: '#1f2937', maxWidth: 780, maxLines: 3, lineHeight: 54
        });
        drawEnterText(ctx, state.subtext || '- Happy Customer', CANVAS_SIZE / 2, 900, t, {
          start: 0.58, end: 0.72, font: "600 30px 'Poppins'", color: accent, maxWidth: 780, maxLines: 1, lineHeight: 34
        });

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.66, 0.84)));
      }
    },
    {
      id: 'flash_countdown',
      name: 'Flash Sale Countdown',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Discount Badge (e.g. FLAT 30% OFF)',
      duration: 6000,
      sample: { headline: 'Today Only', subtext: "Offer ends at midnight - don't miss out", badge: 'FLAT 30% OFF' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        ctx.fillStyle = '#0b0b0f';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var pulse = 0.5 + 0.5 * Math.sin(t * Math.PI * 2 * 1.6);
        var glow = ctx.createRadialGradient(CANVAS_SIZE / 2, 340, 60, CANVAS_SIZE / 2, 340, 560);
        glow.addColorStop(0, mixColor(accent, '#ffffff', 0.1 + pulse * 0.1) + '77');
        glow.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var photoP = easeOutBack(seg(t, 0, 0.2));
        ctx.save();
        ctx.translate(CANVAS_SIZE / 2, 300);
        ctx.scale(photoP, photoP);
        ctx.translate(-CANVAS_SIZE / 2, -300);
        drawKenBurnsPhoto(ctx, state.photoImg, 90, 70, 900, 420, 22, t, 1.0, 1.08, 0.5);
        ctx.restore();

        // fast-spinning clock hand = urgency
        ctx.save();
        ctx.translate(CANVAS_SIZE / 2, 560);
        ctx.beginPath();
        ctx.arc(0, 0, 54, 0, Math.PI * 2);
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 6;
        ctx.stroke();
        ctx.rotate(t * Math.PI * 2 * 3);
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(0, -40);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 7;
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.restore();

        var badgeP = ease(seg(t, 0.06, 0.22));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'FLASH SALE').toUpperCase(), CANVAS_SIZE / 2, 660, t, { bg: accent, color: '#fff', speed: 2.6 });
          ctx.restore();
        }

        drawEnterText(ctx, (state.headline || 'Today Only').toUpperCase(), CANVAS_SIZE / 2, 760, t, {
          start: 0.24, end: 0.42, font: "800 62px 'Poppins'", color: '#fff', maxWidth: 900, maxLines: 2, lineHeight: 68
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 866, t, {
            start: 0.4, end: 0.56, font: "500 28px 'Poppins'", color: 'rgba(255,255,255,0.82)', maxWidth: 820, maxLines: 2, lineHeight: 36
          });
        }
        drawFooterBar(ctx, state, assets, { bg: '#111827', textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.7)' }, ease(seg(t, 0.52, 0.72)));
      }
    },
    {
      id: 'chefs_reveal',
      name: "Chef's Special Reveal",
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Price (e.g. 349)',
      duration: 6500,
      sample: { headline: 'Truffle Mushroom Risotto', subtext: "Chef's signature recipe, made fresh to order", badge: '349' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        ctx.fillStyle = '#161616';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // frame that "draws itself" on for the first moment
        var frameP = ease(seg(t, 0, 0.3));
        var frameLen = 2 * ((CANVAS_SIZE - 80) + (CANVAS_SIZE - 80));
        ctx.save();
        ctx.strokeStyle = mixColor(accent, '#ffffff', 0.35);
        ctx.lineWidth = 3;
        ctx.setLineDash([frameLen]);
        ctx.lineDashOffset = frameLen * (1 - frameP);
        roundRectPath(ctx, 40, 40, CANVAS_SIZE - 80, CANVAS_SIZE - 80, 4);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.restore();

        ctx.save();
        ctx.globalAlpha = ease(seg(t, 0.1, 0.26));
        ctx.font = "600 24px 'Poppins'";
        ctx.fillStyle = mixColor(accent, '#ffffff', 0.4);
        ctx.textAlign = 'center';
        ctx.fillText("C H E F ' S   S P E C I A L", CANVAS_SIZE / 2, 130);
        ctx.restore();

        drawKenBurnsPhoto(ctx, state.photoImg, 110, 178, 860, 480, 6, t, 1.0, 1.1, 0.5);

        var priceP = easeOutBack(seg(t, 0.34, 0.5));
        if (priceP > 0 && state.badge) {
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0.34, 0.46));
          ctx.translate(CANVAS_SIZE - 190, 168 + 500 - 20);
          ctx.scale(priceP, priceP);
          ctx.beginPath();
          ctx.arc(0, 0, 90, 0, Math.PI * 2);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.lineWidth = 4;
          ctx.strokeStyle = '#161616';
          ctx.stroke();
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.font = "600 20px 'Poppins'";
          ctx.fillText('FROM', 0, -26);
          ctx.font = "800 40px 'Poppins'";
          ctx.fillText(state.badge, 0, 8);
          ctx.textBaseline = 'alphabetic';
          ctx.restore();
        }

        drawEnterText(ctx, state.headline || 'Signature Dish', CANVAS_SIZE / 2, 780, t, {
          start: 0.46, end: 0.64, font: "800 52px 'Poppins'", color: '#fff', maxWidth: 780, maxLines: 2, lineHeight: 58
        });
        if (state.subtext) {
          ctx.save();
          var subP = ease(seg(t, 0.6, 0.76));
          if (subP > 0) {
            ctx.globalAlpha = subP;
            ctx.font = "italic 500 28px 'Playfair Display'";
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.textAlign = 'center';
            wrapLines(ctx, state.subtext, 760, 2).forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, 838 + i * 36); });
          }
          ctx.restore();
        }
        drawFooterBar(ctx, state, assets, { bg: '#111827', textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.7)' }, ease(seg(t, 0.68, 0.86)));
      }
    },
    {
      id: 'loyalty_card',
      name: 'Loyalty Rewards',
      fields: ['headline', 'subtext', 'badge', 'showQR'],
      badgeLabel: 'CTA Badge (e.g. JOIN FREE)',
      duration: 6500,
      sample: { headline: 'Earn While You Eat', subtext: 'Get 1 point for every 100 spent. Redeem for free food.', badge: 'JOIN FREE' },
      animate: function(ctx, state, assets, t) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.35);
        ctx.fillStyle = mixColor('#ffffff', accent, 0.05);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawEnterText(ctx, state.headline || 'Earn While You Eat', CANVAS_SIZE / 2, 150, t, {
          start: 0, end: 0.18, font: "800 50px 'Poppins'", color: '#111827', maxWidth: 880, maxLines: 2, lineHeight: 58, riseFrom: -24
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 246, t, {
            start: 0.1, end: 0.26, font: "500 28px 'Poppins'", color: '#6b7280', maxWidth: 780, maxLines: 2, lineHeight: 36, riseFrom: -18
          });
        }

        var cardX = 140, cardY = 340, cardW = 800, cardH = 300;
        var cardP = easeOutBack(seg(t, 0.2, 0.42));
        if (cardP > 0) {
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0.2, 0.36));
          ctx.translate(CANVAS_SIZE / 2, cardY + cardH / 2);
          ctx.scale(cardP, cardP);
          ctx.translate(-CANVAS_SIZE / 2, -(cardY + cardH / 2));

          var grad = ctx.createLinearGradient(cardX, cardY, cardX + cardW, cardY + cardH);
          grad.addColorStop(0, accent);
          grad.addColorStop(1, dark);
          roundRectPath(ctx, cardX, cardY, cardW, cardH, 28);
          ctx.fillStyle = grad;
          ctx.fill();

          ctx.font = "700 26px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.85)';
          ctx.textAlign = 'left';
          ctx.fillText((window.restaurantName || 'Your Restaurant').toUpperCase(), cardX + 44, cardY + 66);

          ctx.font = "600 22px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.7)';
          ctx.fillText('REWARDS MEMBER', cardX + 44, cardY + 100);

          ctx.font = "800 34px 'Poppins'";
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'right';
          ctx.fillText('POINTS', cardX + cardW - 44, cardY + 250);
          ctx.restore();
        }

        // stars pop in one by one across the card
        for (var s = 0; s < 5; s++) {
          var starP = easeOutBack(seg(t, 0.42 + s * 0.04, 0.5 + s * 0.04));
          if (starP <= 0) continue;
          ctx.save();
          ctx.globalAlpha = ease(seg(t, 0.42 + s * 0.04, 0.48 + s * 0.04));
          ctx.translate(cardX + 44 + s * 46, cardY + 190);
          ctx.scale(starP, starP);
          ctx.font = '40px sans-serif';
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText('★', 0, 0);
          ctx.restore();
        }

        var badgeP = ease(seg(t, 0.64, 0.8));
        if (badgeP > 0 && state.badge) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, state.badge.toUpperCase(), CANVAS_SIZE / 2, cardY + cardH + 92, t, { bg: accent, color: '#fff' });
          ctx.restore();
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.72, 0.9)));
      }
    },
    {
      id: 'menu_showcase',
      name: 'Menu Showcase',
      fields: ['headline', 'subtext', 'badge', 'photo', 'photo2', 'showQR'],
      badgeLabel: 'Badge Text (e.g. ORDER NOW)',
      photoLabel: 'First Dish Photo',
      duration: 7000,
      sample: { headline: "Today's Favorites", subtext: 'Fresh, made-to-order, always delicious', badge: 'ORDER NOW' },
      animate: function(ctx, state, assets, t) {
        var fadeP = ease(seg(t, 0.4, 0.58));
        drawKenBurnsPhoto(ctx, state.photoImg, 0, 0, CANVAS_SIZE, CANVAS_SIZE, 0, t, 1.05, 1.2, 0.5);
        if (fadeP > 0) {
          ctx.save();
          ctx.globalAlpha = fadeP;
          drawKenBurnsPhoto(ctx, state.photo2Img, 0, 0, CANVAS_SIZE, CANVAS_SIZE, 0, t, 1.2, 1.05, 0.5);
          ctx.restore();
        }

        var grad = ctx.createLinearGradient(0, 0, 0, CANVAS_SIZE);
        grad.addColorStop(0, 'rgba(0,0,0,0.55)');
        grad.addColorStop(0.3, 'rgba(0,0,0,0.05)');
        grad.addColorStop(0.75, 'rgba(0,0,0,0.15)');
        grad.addColorStop(1, 'rgba(0,0,0,0.8)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawEnterText(ctx, (state.headline || "Today's Favorites").toUpperCase(), CANVAS_SIZE / 2, 130, t, {
          start: 0.04, end: 0.2, font: "800 58px 'Poppins'", color: '#fff', maxWidth: 900, maxLines: 2, lineHeight: 64, riseFrom: -26
        });
        if (state.subtext) {
          drawEnterText(ctx, state.subtext, CANVAS_SIZE / 2, 216, t, {
            start: 0.14, end: 0.3, font: "500 28px 'Poppins'", color: 'rgba(255,255,255,0.88)', maxWidth: 800, maxLines: 2, lineHeight: 36, riseFrom: -18
          });
        }

        var badgeP = ease(seg(t, 0.68, 0.84));
        if (badgeP > 0) {
          ctx.save();
          ctx.globalAlpha = badgeP;
          drawPulseBadge(ctx, (state.badge || 'ORDER NOW').toUpperCase(), CANVAS_SIZE / 2, 640, t, { bg: state.accentColor, color: '#fff' });
          ctx.restore();
        }
        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' }, ease(seg(t, 0.58, 0.76)));
      }
    }
  ];

  function getTemplate(id) {
    for (var i = 0; i < VIDEO_TEMPLATES.length; i++) {
      if (VIDEO_TEMPLATES[i].id === id) return VIDEO_TEMPLATES[i];
    }
    return null;
  }

  // ---------- state ----------
  var videoState = {
    templateId: null,
    headline: '',
    subtext: '',
    badge: '',
    accentColor: ACCENT_COLORS[0],
    phone: '',
    showQR: true,
    photoImg: null,
    photo2Img: null,
    format: 'square',
    src: '',
    designId: null,
    edited: {},
    colorPicked: false
  };
  var videoAssets = { qrImg: null };
  var loopStartTime = null;
  var loopRunning = false;
  var square = document.createElement('canvas');
  square.width = square.height = CANVAS_SIZE;
  var firstPhoto = null, inputs = {};
  var VIDEO_FORMATS = ['square', 'story'];   // Status and Reels are upright

  var fontsP = null;
  function waitForFonts() {
    if (!document.fonts) return Promise.resolve();
    if (fontsP) return fontsP;
    var all = Promise.all([
      document.fonts.load("800 60px 'Poppins'"),
      document.fonts.load("700 60px 'Poppins'"),
      document.fonts.load("500 30px 'Poppins'"),
      document.fonts.load("60px 'Bebas Neue'"),
      document.fonts.load("700 60px 'Playfair Display'"),
      document.fonts.load("160px 'Material Symbols Rounded'")
    ]).catch(function() {});
    // never wait more than 2.5s for Google Fonts: draw now, and again once they arrive
    all.then(function() { if (videoState.templateId) redrawThumbnails(); });
    fontsP = Promise.race([all, new Promise(function(r) { setTimeout(r, 2500); })]);
    return fontsP;
  }

  function campaignCoupon() { var c = window.MK && MK.campaign(); return c && c.coupon ? c.coupon : null; }
  function currentSrc() { return videoState.src || (window.MK ? MK.srcFor(videoState.headline) : ''); }
  function videoLink() {
    var cp = campaignCoupon();
    return window.MK ? MK.link({ src: currentSrc(), coupon: cp && cp.code }) : (window.getMarketingMenuUrl ? window.getMarketingMenuUrl() : location.href);
  }

  function refreshVideoQR() {
    var template = getTemplate(videoState.templateId);
    var needsQR = template && template.fields.indexOf('showQR') > -1 && videoState.showQR && videoState.format === 'square';
    videoAssets.qrImg = needsQR && window.MK ? MK.qr(videoLink()) : null;
  }

  // one frame: the template on the square, then the chosen size around it
  function drawFrame(canvas, t) {
    var template = getTemplate(videoState.templateId);
    var sctx = square.getContext('2d');
    if (window.MK) MK.useBrandFont(sctx);
    sctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
    template.animate(sctx, videoState, videoAssets, t);
    var ctx = canvas.getContext('2d');
    if (videoState.format === 'square' || !window.MK) { ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.drawImage(square, 0, 0, canvas.width, canvas.height); return; }
    MK.compose(ctx, square, videoState.format, { photo: videoState.photoImg, headline: videoState.headline, coupon: campaignCoupon(), link: videoLink(), color: videoState.accentColor });
  }
  function sizeCanvas(canvas) {
    var F = window.MK ? MK.FORMATS[videoState.format] : { w: CANVAS_SIZE, h: CANVAS_SIZE };
    if (canvas.width !== F.w) canvas.width = F.w;
    if (canvas.height !== F.h) canvas.height = F.h;
  }

  // ---------- render loop ----------
  function videoRenderLoop(now) {
    requestAnimationFrame(videoRenderLoop);
    var page = document.getElementById('marketingVideosPage');
    var canvas = document.getElementById('videoCanvas');
    var template = getTemplate(videoState.templateId);
    if (!page || !page.classList.contains('active') || !canvas || !template) return;
    if (loopStartTime === null) loopStartTime = now;
    var duration = template.duration || DEFAULT_DURATION;
    var t = ((now - loopStartTime) % duration) / duration;
    sizeCanvas(canvas);
    drawFrame(canvas, t);
  }

  function ensureLoopStarted() {
    if (loopRunning) return;
    loopRunning = true;
    waitForFonts().then(function() { requestAnimationFrame(videoRenderLoop); });
  }

  function renderThumbnail(template, canvas) {
    var ctx = canvas.getContext('2d');
    var scale = canvas.width / CANVAS_SIZE;
    var brand = window.MK ? MK.brandNow() : null;
    waitForFonts().then(function() {
      if (window.MK) MK.useBrandFont(ctx);
      ctx.save();
      ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
      ctx.scale(scale, scale);
      var sampleState = Object.assign({ accentColor: brand ? brand.color1 : ACCENT_COLORS[0], phone: (brand && brand.phone) || window.restaurantPhone || '', showQR: false, photoImg: firstPhoto, photo2Img: firstPhoto }, template.sample);
      template.animate(ctx, sampleState, { qrImg: null }, 0.62);
      ctx.restore();
    });
  }

  // ---------- UI ----------
  function renderTemplateGrid() {
    var grid = document.getElementById('videoTemplateGrid');
    if (!grid) return;
    grid.innerHTML = '';
    VIDEO_TEMPLATES.forEach(function(t) {
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'poster-template-card';
      card.dataset.templateId = t.id;
      card.addEventListener('click', function() { selectTemplate(t.id); });

      var canvas = document.createElement('canvas');
      canvas.width = 300;
      canvas.height = 300;
      card.appendChild(canvas);

      var nameTag = document.createElement('div');
      nameTag.className = 'poster-template-name';
      nameTag.textContent = t.name;
      card.appendChild(nameTag);

      grid.appendChild(card);
      renderThumbnail(t, canvas);
    });
    markActive();
  }
  function redrawThumbnails() {
    document.querySelectorAll('#videoTemplateGrid .poster-template-card').forEach(function(card) {
      var t = getTemplate(card.dataset.templateId), c = card.querySelector('canvas');
      if (t && c) renderThumbnail(t, c);
    });
  }
  function markActive() {
    document.querySelectorAll('#videoTemplateGrid .poster-template-card').forEach(function(c) {
      c.classList.toggle('active', c.dataset.templateId === videoState.templateId);
    });
  }

  function selectTemplate(id) {
    var template = getTemplate(id);
    if (!template) return;
    videoState.templateId = id;
    ['headline', 'subtext', 'badge'].forEach(function(k) { if (!videoState.edited[k]) videoState[k] = template.sample[k] || ''; });
    var cp = campaignCoupon();
    if (cp && template.fields.indexOf('badge') > -1 && !videoState.edited.badge) videoState.badge = cp.badge;
    if (!videoState.photoImg && firstPhoto) videoState.photoImg = firstPhoto;
    loopStartTime = null;
    markActive();
    renderFields(template);
    refreshVideoQR();
  }

  function setField(k, v) { videoState[k] = v; if (inputs[k]) inputs[k].value = v; }

  function section(title, hint) {
    var s = document.createElement('div');
    s.className = 'mk-sec';
    s.innerHTML = '<div class="poster-panel-title">' + title + '</div>' + (hint ? '<p class="mk-hint">' + hint + '</p>' : '');
    return s;
  }

  function buildTextField(key, labelText, value, maxlength, onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'poster-field';
    var label = document.createElement('label');
    label.textContent = labelText;
    var input = document.createElement('input');
    input.type = 'text';
    input.value = value || '';
    input.maxLength = maxlength;
    input.addEventListener('input', function() { if (key) videoState.edited[key] = true; onChange(input.value); });
    if (key) inputs[key] = input;
    wrap.appendChild(label);
    wrap.appendChild(input);
    return wrap;
  }

  function buildCheckboxField(labelText, checked, onChange) {
    var wrap = document.createElement('label');
    wrap.className = 'mk-switch';
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = !!checked;
    input.addEventListener('change', function() { onChange(input.checked); });
    wrap.appendChild(input);
    wrap.insertAdjacentHTML('beforeend', '<i></i><span>' + labelText + '</span>');
    return wrap;
  }

  function buildPhotoField(labelText, which) {
    var img = videoState[which];
    if (window.MK) return window.MK.photoPicker({ label: labelText, currentSrc: img ? img.src : '', selectedName: img && img._dish ? img._dish.name : '',
      onImage: function(im, dish) {
        videoState[which] = im;
        if (dish && which === 'photoImg' && !videoState.edited.subtext && (videoState.templateId === 'zoom_reveal' || videoState.templateId === 'chefs_reveal')) {
          setField('subtext', dish.name + (dish.price ? ' · now ' + MK.cur() + Math.round(dish.price) : ''));
        }
      },
      onUseText: function(d) {
        videoState.edited.headline = videoState.edited.subtext = false;
        setField('headline', d.name);
        if (d.price) setField('subtext', 'Now at ' + (window.globalCurrencySymbol || '₹') + Math.round(d.price));
      } });
    var wrap = document.createElement('div');
    wrap.className = 'poster-field';
    var label = document.createElement('label');
    label.textContent = labelText;
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/png,image/jpeg,image/webp';
    input.addEventListener('change', function() {
      var file = input.files && input.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function(e) { loadImage(e.target.result, false).then(function(im) { videoState[which] = im; }); };
      reader.readAsDataURL(file);
    });
    wrap.appendChild(label);
    wrap.appendChild(input);
    return wrap;
  }

  function swatchColors() {
    var b = window.MK ? MK.brandNow() : null, list = [];
    (b ? [b.color1, b.color2] : []).concat(ACCENT_COLORS).forEach(function(c) { c = String(c).toLowerCase(); if (list.indexOf(c) < 0) list.push(c); });
    return list.slice(0, 8);
  }
  function buildColorField() {
    var wrap = document.createElement('div');
    wrap.className = 'poster-field';
    var label = document.createElement('label');
    label.textContent = 'Colour';
    var swatchRow = document.createElement('div');
    swatchRow.className = 'poster-color-swatches';

    var picker = document.createElement('input');
    picker.type = 'color';
    picker.className = 'poster-color-picker';
    picker.value = videoState.accentColor;
    picker.title = 'Any colour';

    swatchColors().forEach(function(color) {
      var sw = document.createElement('button');
      sw.type = 'button';
      sw.className = 'poster-color-swatch' + (color === String(videoState.accentColor).toLowerCase() ? ' active' : '');
      sw.style.background = color;
      sw.addEventListener('click', function() {
        videoState.accentColor = color; videoState.colorPicked = true;
        picker.value = color;
        swatchRow.querySelectorAll('.poster-color-swatch').forEach(function(el) { el.classList.remove('active'); });
        sw.classList.add('active');
      });
      swatchRow.appendChild(sw);
    });

    picker.addEventListener('input', function() {
      videoState.accentColor = picker.value; videoState.colorPicked = true;
      swatchRow.querySelectorAll('.poster-color-swatch').forEach(function(el) { el.classList.remove('active'); });
    });
    swatchRow.appendChild(picker);

    wrap.appendChild(label);
    wrap.appendChild(swatchRow);
    return wrap;
  }

  function renderFields(template) {
    var container = document.getElementById('videoFieldsContainer');
    if (!container) return;
    container.innerHTML = '';
    inputs = {};

    var s1 = section('2. Dish and words', 'The preview updates as you type.');
    if (template.fields.indexOf('photo') > -1) s1.appendChild(buildPhotoField(template.photoLabel || 'Photo', 'photoImg'));
    if (template.fields.indexOf('photo2') > -1) s1.appendChild(buildPhotoField('Second photo', 'photo2Img'));
    if (template.fields.indexOf('headline') > -1) s1.appendChild(buildTextField('headline', template.headlineLabel || 'Headline', videoState.headline, 70, function(v) { videoState.headline = v; }));
    if (template.fields.indexOf('subtext') > -1) s1.appendChild(buildTextField('subtext', template.subtextLabel || 'Second line', videoState.subtext, 70, function(v) { videoState.subtext = v; }));
    if (template.fields.indexOf('badge') > -1) s1.appendChild(buildTextField('badge', template.badgeLabel || 'Badge text', videoState.badge, 24, function(v) { videoState.badge = v; }));
    container.appendChild(s1);

    var s3 = section('3. Colour and contact');
    s3.appendChild(buildColorField());
    s3.appendChild(buildTextField('', 'Phone number', videoState.phone, 20, function(v) { videoState.phone = v; }));
    if (template.fields.indexOf('showQR') > -1) s3.appendChild(buildCheckboxField('Show a “Scan to order” QR code', videoState.showQR, function(v) { videoState.showQR = v; refreshVideoQR(); }));
    var cp = campaignCoupon();
    var note = document.createElement('p');
    note.className = 'mk-hint';
    note.innerHTML = cp ? 'The Story size shows your offer code <b>' + MK.esc(cp.code) + '</b> and a QR code that applies it.' : 'Attach an offer from the Posters page, or start a campaign, and the Story size shows the code.';
    s3.appendChild(note);
    container.appendChild(s3);
  }

  function buildPreviewPanel() {
    var panel = document.querySelector('#marketingVideosPage .poster-preview-panel');
    if (!panel || panel.querySelector('.mk-fmt') || !window.MK) return;
    panel.innerHTML =
      '<div class="mk-fmt two" role="tablist" aria-label="Size">' + VIDEO_FORMATS.map(function(k) {
        var F = MK.FORMATS[k];
        return '<button type="button" data-vfmt="' + k + '"' + (k === videoState.format ? ' class="on"' : '') + '><i class="mk-ratio r-' + k + '"></i><span><b>' + F.label + '</b><small>' + F.hint + '</small></span></button>';
      }).join('') + '</div>' +
      '<div class="poster-canvas-wrap"><canvas id="videoCanvas" width="1080" height="1080"></canvas></div>' +
      '<div class="mk-acts"><button type="button" class="mk-btn big" id="recordVideoBtn"><span class="material-symbols-rounded">videocam</span><span class="video-record-label">Record and download video</span></button>' +
      '<button type="button" class="mk-btn ghost" data-vact="still"><span class="material-symbols-rounded">imagesmode</span>Save as picture</button>' +
      '<button type="button" class="mk-btn ghost" data-vact="save"><span class="material-symbols-rounded">save</span><span class="mk-save-l">Save</span></button></div>' +
      '<div class="mk-rec" hidden><div class="mk-rec-bar"><i></i></div><span class="mk-rec-t">Recording…</span></div>' +
      '<p class="mk-hint">The preview loops. Recording plays it once from the start (about 6 seconds) and downloads an MP4 where your browser supports it, otherwise WebM.</p>';
    panel.querySelector('#recordVideoBtn').addEventListener('click', function() { window.recordVideo(); });
    panel.addEventListener('click', function(e) {
      var f = e.target.closest('[data-vfmt]');
      if (f) {
        if (window._videoRecording) return;
        videoState.format = f.dataset.vfmt;
        panel.querySelectorAll('[data-vfmt]').forEach(function(b) { b.classList.toggle('on', b === f); });
        refreshVideoQR(); loopStartTime = null;
        return;
      }
      var a = e.target.closest('[data-vact]');
      if (!a) return;
      if (a.dataset.vact === 'still') saveStill();
      else if (a.dataset.vact === 'save') saveVideo(a);
    });
  }

  function fileBase() { return window.MK ? MK.fileName(videoState.headline || 'promo-video') : 'promo-video'; }
  function lockSrc() { if (!videoState.src) videoState.src = currentSrc(); }

  // the finished frame (everything on screen) as a picture
  function saveStill() {
    if (!window.MK) return;
    lockSrc(); refreshVideoQR();
    var c = document.createElement('canvas'), F = MK.FORMATS[videoState.format];
    c.width = F.w; c.height = F.h;
    drawFrame(c, 0.7);
    MK.download(c, fileBase() + (videoState.format === 'square' ? '' : '-story'));
  }

  function saveVideo(btn) {
    if (!window.MK) return;
    lockSrc();
    var c = document.createElement('canvas'), F = MK.FORMATS[videoState.format], cp = campaignCoupon(), camp = MK.campaign();
    c.width = F.w; c.height = F.h; drawFrame(c, 0.7);
    btn.disabled = true;
    MK.saveDesign({ id: videoState.designId, kind: 'video', name: (camp ? camp.name + ' · ' : '') + (videoState.headline || getTemplate(videoState.templateId).name), src: videoState.src, coupon_code: cp ? cp.code : '',
      state: { templateId: videoState.templateId, headline: videoState.headline, subtext: videoState.subtext, badge: videoState.badge, accentColor: videoState.accentColor, phone: videoState.phone,
        showQR: videoState.showQR, format: videoState.format, photo: MK.imgRef(videoState.photoImg), photo2: MK.imgRef(videoState.photo2Img), dish: videoState.photoImg && videoState.photoImg._dish || null },
      thumb: MK.thumb(c) }).then(function(id) {
      btn.disabled = false;
      if (id) { videoState.designId = id; var l = btn.querySelector('.mk-save-l'); if (l) l.textContent = 'Saved'; }
    });
  }

  window.initVideoStudio = function() {
    ensureLoopStarted();
    if (window._videoStudioInited) return;
    window._videoStudioInited = true;
    videoState.phone = window.restaurantPhone || '';
    buildPreviewPanel();
    renderTemplateGrid();
    selectTemplate(VIDEO_TEMPLATES[0].id);
    if (!window.MK) return;
    MK.brand().then(function(b) {
      if (!videoState.colorPicked) videoState.accentColor = b.color1;
      if (b.phone) videoState.phone = b.phone;
      renderFields(getTemplate(videoState.templateId)); redrawThumbnails();
    });
    // start with a real dish photo instead of an empty box
    MK.firstPhoto().then(function(r) {
      if (!r) return;
      firstPhoto = r.img;
      if (!videoState.photoImg) { videoState.photoImg = r.img; renderFields(getTemplate(videoState.templateId)); }
      redrawThumbnails();
    });
    document.addEventListener('mk:brand', function(e) {
      if (!videoState.colorPicked) videoState.accentColor = e.detail.color1;
      if (e.detail.phone) videoState.phone = e.detail.phone;
      renderFields(getTemplate(videoState.templateId)); redrawThumbnails();
    });
    document.addEventListener('mk:link', function() { var pg = document.getElementById('marketingVideosPage'); if (pg && pg.classList.contains('active')) refreshVideoQR(); });
    document.addEventListener('mk:campaign', function() {
      if (!videoState.designId) videoState.src = '';
      var cp = campaignCoupon(), t = getTemplate(videoState.templateId);
      if (cp && t.fields.indexOf('badge') > -1 && !videoState.edited.badge) videoState.badge = cp.badge;
      renderFields(t); refreshVideoQR();
    });
  };

  window.openVideoDesign = function(design) {
    window.initVideoStudio();
    var s = design.state || {};
    var template = getTemplate(s.templateId) || VIDEO_TEMPLATES[0];
    Object.assign(videoState, { templateId: template.id, headline: s.headline || '', subtext: s.subtext || '', badge: s.badge || '', accentColor: s.accentColor || videoState.accentColor,
      phone: s.phone != null ? s.phone : videoState.phone, showQR: s.showQR !== false, format: s.format === 'story' ? 'story' : 'square', src: design.src || '', designId: design.id,
      edited: { headline: true, subtext: true, badge: true }, colorPicked: true, photoImg: null, photo2Img: null });
    document.querySelectorAll('#marketingVideosPage [data-vfmt]').forEach(function(b) { b.classList.toggle('on', b.dataset.vfmt === videoState.format); });
    var l = document.querySelector('#marketingVideosPage .mk-save-l'); if (l) l.textContent = 'Saved';
    loopStartTime = null; markActive(); renderFields(template); refreshVideoQR();
    Promise.all([MK.imgFromRef(s.photo), MK.imgFromRef(s.photo2)]).then(function(r) {
      if (r[0] && s.dish) r[0]._dish = s.dish;
      videoState.photoImg = r[0]; videoState.photo2Img = r[1];
      renderFields(template);
    });
  };

  // ---------- record & download ----------
  window.recordVideo = function() {
    if (window._videoRecording) return;
    var canvas = document.getElementById('videoCanvas');
    var btn = document.getElementById('recordVideoBtn');
    var labelEl = btn ? btn.querySelector('.video-record-label') : null;
    var template = getTemplate(videoState.templateId);
    if (!canvas || !template) return;
    var say = function(m) { if (window.SX) SX.notify(m, 'error'); else alert(m); };

    if (!canvas.captureStream || !window.MediaRecorder) { say('Video recording isn\'t supported in this browser. Try Chrome or Edge.'); return; }

    var mimeCandidates = ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    var mimeType = null;
    for (var i = 0; i < mimeCandidates.length; i++) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(mimeCandidates[i])) { mimeType = mimeCandidates[i]; break; }
    }
    if (!mimeType) { say('Video recording isn\'t supported in this browser. Try Chrome or Edge.'); return; }

    lockSrc(); refreshVideoQR();
    var duration = template.duration || DEFAULT_DURATION;
    var stream = canvas.captureStream(30);
    var recorder;
    try {
      recorder = new MediaRecorder(stream, { mimeType: mimeType, videoBitsPerSecond: 6000000 });
    } catch (e) { say('Could not start recording. Try a different browser.'); return; }

    var chunks = [];
    recorder.ondataavailable = function(e) { if (e.data && e.data.size) chunks.push(e.data); };

    var rec = document.querySelector('#marketingVideosPage .mk-rec'), bar = rec ? rec.querySelector('i') : null, recT = rec ? rec.querySelector('.mk-rec-t') : null;
    window._videoRecording = true;
    loopStartTime = null; // restart template from t=0 so the recorded clip is a clean full loop
    if (btn) btn.disabled = true;
    if (rec) rec.hidden = false;
    var startedAt = Date.now();
    var progressTimer = setInterval(function() {
      var elapsed = Math.min(duration, Date.now() - startedAt);
      if (bar) bar.style.width = Math.round(elapsed * 100 / duration) + '%';
      if (recT) recT.textContent = 'Recording… ' + (elapsed / 1000).toFixed(1) + ' of ' + (duration / 1000).toFixed(0) + ' seconds. Keep this tab open.';
      if (labelEl) labelEl.textContent = 'Recording… ' + Math.round(elapsed * 100 / duration) + '%';
    }, 100);

    recorder.onstop = function() {
      clearInterval(progressTimer);
      window._videoRecording = false;
      if (btn) btn.disabled = false;
      if (labelEl) labelEl.textContent = 'Record and download video';
      if (rec) { if (bar) bar.style.width = '100%'; if (recT) recT.textContent = 'Done. Your video is downloading.'; setTimeout(function() { rec.hidden = true; if (bar) bar.style.width = '0'; }, 2500); }
      if (!chunks.length) return;
      var ext = mimeType.indexOf('mp4') > -1 ? 'mp4' : 'webm';
      var blob = new Blob(chunks, { type: mimeType });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = fileBase() + (videoState.format === 'story' ? '-story' : '') + '.' + ext;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function() { URL.revokeObjectURL(url); }, 4000);
    };

    recorder.start();
    setTimeout(function() { if (recorder.state !== 'inactive') recorder.stop(); }, duration + 200);
  };
})();
