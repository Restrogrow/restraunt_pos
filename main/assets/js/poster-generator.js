// Marketing > Poster Studio
// Renders promotional posters entirely client-side (HTML5 canvas) - no server
// cost per poster. New templates only need an entry pushed onto
// POSTER_TEMPLATES; the picker grid, editor fields and preview all pick it up
// automatically.
(function() {
  'use strict';

  var CANVAS_SIZE = 1080;
  var ACCENT_COLORS = ['#e11d48', '#f97316', '#16a34a', '#2563eb', '#7c3aed', '#111827'];

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

  function drawImageCoverRounded(ctx, img, x, y, w, h, radius) {
    ctx.save();
    roundRectPath(ctx, x, y, w, h, radius);
    ctx.clip();
    drawImageCoverRaw(ctx, img, x, y, w, h);
    ctx.restore();
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

  // Restaurant name (with the logo from the Brand kit), phone and offer code, and the QR code
  function drawFooterBar(ctx, state, assets, opts) {
    opts = opts || {};
    var barH = 130;
    var y = CANVAS_SIZE - barH;
    ctx.fillStyle = opts.bg || '#ffffff';
    ctx.fillRect(0, y, CANVAS_SIZE, barH);

    var padX = 60, tx = padX;
    var showQR = state.showQR && assets.qrImg && !opts.hideQR;
    var maxW = CANVAS_SIZE - padX * 2 - (showQR ? 130 : 0);
    var logo = window.MK && window.MK.logo();
    if (logo) {
      ctx.save();
      ctx.beginPath(); ctx.arc(padX + 42, y + barH / 2, 42, 0, Math.PI * 2);
      ctx.fillStyle = '#ffffff'; ctx.fill(); ctx.clip();
      drawImageCoverRaw(ctx, logo, padX + 3, y + barH / 2 - 39, 78, 78);
      ctx.restore();
      tx = padX + 104; maxW -= 104;
    }
    ctx.textAlign = 'left';
    ctx.fillStyle = opts.textColor || '#111827';
    ctx.font = "700 34px 'Poppins'";
    var name = window.restaurantName || 'Your Restaurant';
    while (ctx.measureText(name).width > maxW && name.length > 4) name = name.slice(0, -2);
    ctx.fillText(name, tx, y + 54);

    var x2 = tx;
    if (state.couponCode) {
      ctx.font = "700 26px 'Poppins'";
      var codeText = 'Code ' + state.couponCode;
      var cw = ctx.measureText(codeText).width + 28;
      roundRectPath(ctx, x2, y + 68, cw, 38, 10);
      ctx.fillStyle = state.accentColor || '#F97316';
      ctx.fill();
      ctx.fillStyle = '#ffffff';
      ctx.fillText(codeText, x2 + 14, y + 96);
      x2 += cw + 16;
    }
    if (state.phone) {
      ctx.font = "500 26px 'Poppins'";
      ctx.fillStyle = opts.subTextColor || '#6b7280';
      ctx.fillText('Call ' + state.phone, x2, y + 96);
    }

    if (showQR) {
      var qrSize = 100;
      var qrX = CANVAS_SIZE - padX - qrSize;
      var qrY = y + (barH - qrSize) / 2;
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(qrX - 6, qrY - 6, qrSize + 12, qrSize + 12);
      ctx.drawImage(assets.qrImg, qrX, qrY, qrSize, qrSize);
    }
  }

  function drawSoftShadowFrame(ctx, x, y, w, h, r) {
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.28)';
    ctx.shadowBlur = 28;
    ctx.shadowOffsetY = 10;
    roundRectPath(ctx, x, y, w, h, r);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.restore();
  }

  // ---------- templates ----------
  var POSTER_TEMPLATES = [
    {
      id: 'bold_discount',
      name: 'Bold Discount',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Discount Badge (e.g. 25% OFF)',
      sample: { headline: 'Weekend Special', subtext: 'Dine-in & takeaway, this Sat-Sun only', badge: '25% OFF' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.35);
        var grad = ctx.createLinearGradient(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        grad.addColorStop(0, accent);
        grad.addColorStop(1, dark);
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawSoftShadowFrame(ctx, 74, 64, 932, 496, 30);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 80, 900, 468, 22);
        } else {
          drawPhotoPlaceholder(ctx, 90, 80, 900, 468, 22);
        }

        var badgeText = (state.badge || 'SPECIAL OFFER').toUpperCase();
        ctx.font = "70px 'Bebas Neue'";
        var badgeW = Math.max(260, ctx.measureText(badgeText).width + 90);
        var badgeH = 88;
        var badgeX = CANVAS_SIZE / 2 - badgeW / 2;
        var badgeY = 596;
        roundRectPath(ctx, badgeX, badgeY, badgeW, badgeH, badgeH / 2);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.fillStyle = dark;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(badgeText, CANVAS_SIZE / 2, badgeY + badgeH / 2 + 4);
        ctx.textBaseline = 'alphabetic';

        var headY = badgeY + badgeH + 66;
        ctx.font = "800 58px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, (state.headline || 'Weekend Special').toUpperCase(), 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 64); });

        if (state.subtext) {
          var subY = headY + headLines.length * 64 + 26;
          ctx.font = "500 28px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.92)';
          var subLines = wrapLines(ctx, state.subtext, 820, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, subY + i * 36); });
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'new_item',
      name: 'New on the Menu',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Ribbon Text (e.g. NEW)',
      sample: { headline: 'Signature Butter Chicken', subtext: 'Starting at 249', badge: 'NEW' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = mixColor('#ffffff', accent, 0.06);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawSoftShadowFrame(ctx, 74, 64, 932, 636, 28);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 80, 900, 604, 20);
        } else {
          drawPhotoPlaceholder(ctx, 90, 80, 900, 604, 20);
        }

        if (state.badge) {
          ctx.font = "700 30px 'Poppins'";
          var ribbonText = state.badge.toUpperCase();
          var ribbonW = ctx.measureText(ribbonText).width + 56;
          roundRectPath(ctx, 116, 106, ribbonW, 58, 12);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'left';
          ctx.textBaseline = 'middle';
          ctx.fillText(ribbonText, 116 + 28, 106 + 29);
          ctx.textBaseline = 'alphabetic';
        }

        var headY = 780;
        ctx.font = "800 56px 'Playfair Display'";
        ctx.fillStyle = '#111827';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'New Dish Name', 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 62); });

        if (state.subtext) {
          var subY = headY + headLines.length * 62 + 24;
          ctx.font = "700 36px 'Poppins'";
          ctx.fillStyle = accent;
          ctx.fillText(state.subtext, CANVAS_SIZE / 2, subY);
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'combo',
      name: 'Combo Deal',
      fields: ['headline', 'subtext', 'photo', 'photo2', 'showQR'],
      sample: { headline: 'Meal for Two', subtext: 'Only 499' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.3);
        var grad = ctx.createLinearGradient(0, 0, 0, CANVAS_SIZE);
        grad.addColorStop(0, accent);
        grad.addColorStop(1, dark);
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var r = 195;
        var c1x = 285, c2x = 795, cy = 380;
        [[c1x, state.photoImg], [c2x, state.photo2Img]].forEach(function(pair) {
          var cx = pair[0], img = pair[1];
          ctx.beginPath();
          ctx.arc(cx, cy, r + 12, 0, Math.PI * 2);
          ctx.fillStyle = '#ffffff';
          ctx.fill();
          if (img) {
            drawImageCoverRounded(ctx, img, cx - r, cy - r, r * 2, r * 2, r);
          } else {
            drawPhotoPlaceholder(ctx, cx - r, cy - r, r * 2, r * 2, r);
          }
        });

        ctx.font = "120px 'Bebas Neue'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('+', CANVAS_SIZE / 2, cy + 6);
        ctx.textBaseline = 'alphabetic';

        var headY = 700;
        ctx.font = "800 60px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        var headLines = wrapLines(ctx, state.headline || 'Combo Deal', 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 66); });

        if (state.subtext) {
          var pillY = headY + headLines.length * 66 + 30;
          ctx.font = "700 40px 'Poppins'";
          var pillText = state.subtext;
          var pillW = ctx.measureText(pillText).width + 90;
          roundRectPath(ctx, CANVAS_SIZE / 2 - pillW / 2, pillY, pillW, 78, 39);
          ctx.fillStyle = '#ffffff';
          ctx.fill();
          ctx.fillStyle = dark;
          ctx.textBaseline = 'middle';
          ctx.fillText(pillText, CANVAS_SIZE / 2, pillY + 41);
          ctx.textBaseline = 'alphabetic';
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'happy_hour',
      name: 'Happy Hour',
      fields: ['headline', 'subtext', 'badge', 'showQR'],
      badgeLabel: 'Offer Text (optional)',
      sample: { headline: 'Happy Hour', subtext: '4 PM - 7 PM', badge: '20% OFF Beverages' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var bg = shadeColor(accent, -0.62);
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var glow = ctx.createRadialGradient(CANVAS_SIZE / 2, 300, 40, CANVAS_SIZE / 2, 300, 420);
        glow.addColorStop(0, mixColor(accent, '#ffffff', 0.15) + '55');
        glow.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // simple clock face motif
        var cx = CANVAS_SIZE / 2, cy = 270, r = 100;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 8;
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx, cy - r * 0.55);
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + r * 0.4, cy + r * 0.15);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 9;
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.lineCap = 'butt';

        var headY = 500;
        ctx.font = "110px 'Bebas Neue'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, (state.headline || 'Happy Hour').toUpperCase(), 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, cx, headY + i * 106); });

        var afterHead = headY + headLines.length * 106;
        if (state.subtext) {
          ctx.font = "700 46px 'Poppins'";
          ctx.fillStyle = mixColor(accent, '#ffffff', 0.35);
          ctx.fillText(state.subtext, cx, afterHead + 50);
        }

        if (state.badge) {
          var badgeY = afterHead + 110;
          ctx.font = "600 30px 'Poppins'";
          var badgeW = ctx.measureText(state.badge).width + 70;
          roundRectPath(ctx, cx - badgeW / 2, badgeY, badgeW, 64, 32);
          ctx.strokeStyle = '#ffffff';
          ctx.lineWidth = 2;
          ctx.stroke();
          ctx.fillStyle = '#ffffff';
          ctx.textBaseline = 'middle';
          ctx.fillText(state.badge, cx, badgeY + 33);
          ctx.textBaseline = 'alphabetic';
        }

        drawFooterBar(ctx, state, assets, { bg: bg, textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.75)' });
      }
    },
    {
      id: 'order_direct',
      name: 'Order Direct & Save',
      fields: ['headline', 'subtext', 'badge'],
      badgeLabel: 'Savings Badge (e.g. Extra 10% OFF)',
      forceQR: true,
      sample: { headline: 'Order Direct & Save', subtext: 'Skip the delivery app fees - order straight from us', badge: 'Extra 10% OFF' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = mixColor('#ffffff', accent, 0.05);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        ctx.font = "800 52px 'Poppins'";
        ctx.fillStyle = '#111827';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'Order Direct & Save', 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, 150 + i * 58); });

        var afterHead = 150 + headLines.length * 58;
        if (state.subtext) {
          ctx.font = "500 30px 'Poppins'";
          ctx.fillStyle = '#6b7280';
          var subLines = wrapLines(ctx, state.subtext, 800, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, afterHead + 44 + i * 38); });
        }

        var qrBoxSize = 460;
        var qrBoxX = CANVAS_SIZE / 2 - qrBoxSize / 2;
        var qrBoxY = 330;
        drawSoftShadowFrame(ctx, qrBoxX, qrBoxY, qrBoxSize, qrBoxSize, 24);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 6;
        roundRectPath(ctx, qrBoxX + 8, qrBoxY + 8, qrBoxSize - 16, qrBoxSize - 16, 18);
        ctx.stroke();
        if (assets.qrImg) {
          var pad = 40;
          ctx.drawImage(assets.qrImg, qrBoxX + pad, qrBoxY + pad, qrBoxSize - pad * 2, qrBoxSize - pad * 2);
        } else {
          ctx.fillStyle = '#9ca3af';
          ctx.font = "500 26px 'Poppins'";
          ctx.textAlign = 'center';
          ctx.fillText('QR code unavailable', CANVAS_SIZE / 2, qrBoxY + qrBoxSize / 2);
        }

        if (state.badge) {
          var badgeY = qrBoxY + qrBoxSize + 46;
          ctx.font = "700 34px 'Poppins'";
          var badgeW = ctx.measureText(state.badge).width + 80;
          roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, badgeY, badgeW, 66, 33);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textBaseline = 'middle';
          ctx.fillText(state.badge, CANVAS_SIZE / 2, badgeY + 35);
          ctx.textBaseline = 'alphabetic';
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280', hideQR: true });
      }
    },
    {
      id: 'festive_special',
      name: 'Festive Special',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Occasion Badge (e.g. Diwali Special)',
      sample: { headline: 'Happy Diwali', subtext: 'Celebrate the season with us', badge: 'Festive Special' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.4);
        var grad = ctx.createLinearGradient(0, 0, CANVAS_SIZE, CANVAS_SIZE);
        grad.addColorStop(0, dark);
        grad.addColorStop(1, accent);
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // string-lights motif along the top
        var lightY = 46;
        ctx.strokeStyle = 'rgba(255,255,255,0.35)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(0, lightY);
        for (var lx = 0; lx <= CANVAS_SIZE; lx += 90) {
          ctx.quadraticCurveTo(lx + 45, lightY + 26, lx + 90, lightY);
        }
        ctx.stroke();
        for (var bx = 40; bx < CANVAS_SIZE; bx += 90) {
          ctx.beginPath();
          ctx.arc(bx, lightY + 13, 7, 0, Math.PI * 2);
          ctx.fillStyle = ['#fde68a', '#fca5a5', '#bbf7d0', '#bfdbfe'][(bx / 90) % 4 | 0];
          ctx.fill();
        }

        drawSoftShadowFrame(ctx, 74, 110, 932, 468, 28);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 126, 900, 436, 20);
        } else {
          drawPhotoPlaceholder(ctx, 90, 126, 900, 436, 20);
        }

        if (state.badge) {
          ctx.font = "700 32px 'Poppins'";
          var badgeText = state.badge.toUpperCase();
          var badgeW = ctx.measureText(badgeText).width + 70;
          var badgeH = 62;
          roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, 616, badgeW, badgeH, badgeH / 2);
          ctx.fillStyle = '#ffffff';
          ctx.fill();
          ctx.fillStyle = dark;
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(badgeText, CANVAS_SIZE / 2, 616 + badgeH / 2 + 2);
          ctx.textBaseline = 'alphabetic';
        }

        var headY = 760;
        ctx.font = "800 58px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'Happy Festive Season', 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 62); });

        if (state.subtext) {
          var subY = headY + headLines.length * 62 + 24;
          ctx.font = "500 28px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.9)';
          var subLines = wrapLines(ctx, state.subtext, 820, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, subY + i * 36); });
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'now_open',
      name: 'Grand Opening',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Ribbon Text (e.g. GRAND OPENING)',
      sample: { headline: 'We Are Now Open!', subtext: 'Come taste what everyone is talking about', badge: 'GRAND OPENING' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = '#111827';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawSoftShadowFrame(ctx, 74, 64, 932, 560, 28);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 80, 900, 528, 20);
        } else {
          drawPhotoPlaceholder(ctx, 90, 80, 900, 528, 20);
        }

        if (state.badge) {
          ctx.save();
          ctx.translate(150, 150);
          ctx.rotate(-Math.PI / 8);
          ctx.font = "700 30px 'Poppins'";
          var ribbonText = state.badge.toUpperCase();
          var ribbonW = ctx.measureText(ribbonText).width + 70;
          roundRectPath(ctx, -ribbonW / 2, -29, ribbonW, 58, 8);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(ribbonText, 0, 2);
          ctx.restore();
        }

        var headY = 720;
        ctx.font = "800 60px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        var headLines = wrapLines(ctx, state.headline || 'We Are Now Open!', 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 66); });

        if (state.subtext) {
          var subY = headY + headLines.length * 66 + 28;
          ctx.font = "500 30px 'Poppins'";
          ctx.fillStyle = mixColor('#ffffff', accent, 0.25);
          var subLines = wrapLines(ctx, state.subtext, 820, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, subY + i * 38); });
        }

        drawFooterBar(ctx, state, assets, { bg: '#111827', textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.7)' });
      }
    },
    {
      id: 'we_deliver',
      name: 'We Deliver',
      fields: ['headline', 'subtext', 'badge', 'photo'],
      badgeLabel: 'Offer Text (e.g. Free delivery over 299)',
      forceQR: true,
      sample: { headline: 'We Deliver', subtext: 'Hot food, straight to your door', badge: 'Free delivery over 299' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.35);

        ctx.fillStyle = accent;
        ctx.fillRect(0, 0, CANVAS_SIZE, 430);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 430, CANVAS_SIZE, CANVAS_SIZE - 430);

        ctx.font = "800 66px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, (state.headline || 'We Deliver').toUpperCase(), 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, 150 + i * 72); });

        if (state.subtext) {
          var afterHead = 150 + headLines.length * 72;
          ctx.font = "500 30px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.92)';
          var subLines = wrapLines(ctx, state.subtext, 820, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, afterHead + 46 + i * 38); });
        }

        var qrBoxSize = 360;
        var qrBoxX = CANVAS_SIZE / 2 - qrBoxSize / 2;
        var qrBoxY = 480;
        drawSoftShadowFrame(ctx, qrBoxX, qrBoxY, qrBoxSize, qrBoxSize, 22);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 5;
        roundRectPath(ctx, qrBoxX + 8, qrBoxY + 8, qrBoxSize - 16, qrBoxSize - 16, 16);
        ctx.stroke();
        if (assets.qrImg) {
          var pad = 32;
          ctx.drawImage(assets.qrImg, qrBoxX + pad, qrBoxY + pad, qrBoxSize - pad * 2, qrBoxSize - pad * 2);
        } else {
          ctx.fillStyle = '#9ca3af';
          ctx.font = "500 24px 'Poppins'";
          ctx.fillText('QR code unavailable', CANVAS_SIZE / 2, qrBoxY + qrBoxSize / 2);
        }

        if (state.badge) {
          var badgeY = qrBoxY + qrBoxSize + 40;
          ctx.font = "700 32px 'Poppins'";
          var badgeW = ctx.measureText(state.badge).width + 76;
          roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, badgeY, badgeW, 62, 31);
          ctx.fillStyle = dark;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textBaseline = 'middle';
          ctx.fillText(state.badge, CANVAS_SIZE / 2, badgeY + 33);
          ctx.textBaseline = 'alphabetic';
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280', hideQR: true });
      }
    },
    {
      id: 'testimonial',
      name: 'Customer Favorite',
      fields: ['headline', 'subtext', 'photo', 'showQR'],
      sample: { headline: 'Best butter chicken in town, hands down.', subtext: '- Priya S., Google Review' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = mixColor('#ffffff', accent, 0.06);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 80, 900, 380, 22);
        }

        var quoteTop = state.photoImg ? 520 : 260;
        ctx.font = "800 130px 'Playfair Display'";
        ctx.fillStyle = accent;
        ctx.textAlign = 'center';
        ctx.fillText('“', CANVAS_SIZE / 2, quoteTop);

        ctx.font = "600 44px 'Playfair Display'";
        ctx.fillStyle = '#111827';
        var headLines = wrapLines(ctx, state.headline || 'Amazing food, amazing service.', 820, 4);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, quoteTop + 40 + i * 56); });

        var starsY = quoteTop + 40 + headLines.length * 56 + 50;
        ctx.font = '46px sans-serif';
        ctx.fillStyle = '#f59e0b';
        ctx.fillText('★★★★★', CANVAS_SIZE / 2, starsY);

        if (state.subtext) {
          ctx.font = "600 30px 'Poppins'";
          ctx.fillStyle = '#6b7280';
          ctx.fillText(state.subtext, CANVAS_SIZE / 2, starsY + 56);
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'flash_sale',
      name: 'Flash Sale',
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Discount Badge (e.g. FLAT 30% OFF)',
      sample: { headline: 'Today Only', subtext: 'Offer ends at midnight - don\'t miss out', badge: 'FLAT 30% OFF' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = '#0b0b0f';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        var glow = ctx.createRadialGradient(CANVAS_SIZE / 2, 360, 60, CANVAS_SIZE / 2, 360, 560);
        glow.addColorStop(0, mixColor(accent, '#ffffff', 0.1) + '66');
        glow.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        drawSoftShadowFrame(ctx, 74, 64, 932, 470, 26);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 90, 80, 900, 442, 18);
        } else {
          drawPhotoPlaceholder(ctx, 90, 80, 900, 442, 18);
        }

        // diagonal "limited time" corner ribbon
        ctx.save();
        ctx.translate(150, 150);
        ctx.rotate(-Math.PI / 8);
        ctx.font = "700 26px 'Poppins'";
        var ribbonText = 'LIMITED TIME';
        var ribbonW = ctx.measureText(ribbonText).width + 64;
        roundRectPath(ctx, -ribbonW / 2, -27, ribbonW, 54, 8);
        ctx.fillStyle = accent;
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(ribbonText, 0, 2);
        ctx.restore();

        var badgeText = (state.badge || 'FLASH SALE').toUpperCase();
        ctx.font = "76px 'Bebas Neue'";
        var badgeW = Math.max(280, ctx.measureText(badgeText).width + 100);
        var badgeH = 96;
        var badgeY = 566;
        roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, badgeY, badgeW, badgeH, badgeH / 2);
        ctx.fillStyle = accent;
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(badgeText, CANVAS_SIZE / 2, badgeY + badgeH / 2 + 4);
        ctx.textBaseline = 'alphabetic';

        var headY = badgeY + badgeH + 68;
        ctx.font = "800 60px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, (state.headline || 'Today Only').toUpperCase(), 900, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 66); });

        if (state.subtext) {
          var subY = headY + headLines.length * 66 + 26;
          ctx.font = "500 28px 'Poppins'";
          ctx.fillStyle = 'rgba(255,255,255,0.85)';
          var subLines = wrapLines(ctx, state.subtext, 820, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, subY + i * 36); });
        }

        drawFooterBar(ctx, state, assets, { bg: '#111827', textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.7)' });
      }
    },
    {
      id: 'chefs_special',
      name: "Chef's Special",
      fields: ['headline', 'subtext', 'badge', 'photo', 'showQR'],
      badgeLabel: 'Price (e.g. 349)',
      sample: { headline: 'Truffle Mushroom Risotto', subtext: "Chef's signature recipe, made fresh to order", badge: '349' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        ctx.fillStyle = '#161616';
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        // thin gold-style accent border frame
        ctx.strokeStyle = mixColor(accent, '#ffffff', 0.35);
        ctx.lineWidth = 3;
        roundRectPath(ctx, 40, 40, CANVAS_SIZE - 80, CANVAS_SIZE - 80, 4);
        ctx.stroke();

        ctx.font = "600 24px 'Poppins'";
        ctx.fillStyle = mixColor(accent, '#ffffff', 0.4);
        ctx.textAlign = 'center';
        ctx.fillText("C H E F ' S   S P E C I A L", CANVAS_SIZE / 2, 130);

        drawSoftShadowFrame(ctx, 100, 168, 880, 500, 12);
        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 110, 178, 860, 480, 6);
        } else {
          drawPhotoPlaceholder(ctx, 110, 178, 860, 480, 6);
        }

        // circular price "stamp" overlapping the photo corner
        if (state.badge) {
          var stampR = 90;
          var stampCx = CANVAS_SIZE - 190, stampCy = 168 + 500 - 20;
          ctx.save();
          ctx.beginPath();
          ctx.arc(stampCx, stampCy, stampR, 0, Math.PI * 2);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.lineWidth = 4;
          ctx.strokeStyle = '#161616';
          ctx.stroke();
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.font = "600 20px 'Poppins'";
          ctx.fillText('FROM', stampCx, stampCy - 26);
          ctx.font = "800 40px 'Poppins'";
          ctx.fillText(state.badge, stampCx, stampCy + 8);
          ctx.textBaseline = 'alphabetic';
          ctx.restore();
        }

        var headY = 780;
        ctx.font = "800 54px 'Playfair Display'";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'Signature Dish', 780, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 60); });

        if (state.subtext) {
          var subY = headY + headLines.length * 60 + 26;
          ctx.font = "italic 500 28px 'Playfair Display'";
          ctx.fillStyle = 'rgba(255,255,255,0.7)';
          var subLines = wrapLines(ctx, state.subtext, 760, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, subY + i * 36); });
        }

        drawFooterBar(ctx, state, assets, { bg: '#111827', textColor: '#ffffff', subTextColor: 'rgba(255,255,255,0.7)' });
      }
    },
    {
      id: 'loyalty_rewards',
      name: 'Loyalty Rewards',
      fields: ['headline', 'subtext', 'badge', 'showQR'],
      badgeLabel: 'CTA Badge (e.g. JOIN FREE)',
      forceQR: true,
      sample: { headline: 'Earn While You Eat', subtext: 'Get 1 point for every 100 spent. Redeem for free food.', badge: 'JOIN FREE' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.35);
        ctx.fillStyle = mixColor('#ffffff', accent, 0.05);
        ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

        ctx.font = "800 52px 'Poppins'";
        ctx.fillStyle = '#111827';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'Earn While You Eat', 880, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, 130 + i * 58); });

        var afterHead = 130 + headLines.length * 58;
        if (state.subtext) {
          ctx.font = "500 28px 'Poppins'";
          ctx.fillStyle = '#6b7280';
          var subLines = wrapLines(ctx, state.subtext, 780, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, afterHead + 42 + i * 36); });
        }

        // membership card graphic
        var cardX = 140, cardY = afterHead + 100, cardW = 800, cardH = 300;
        var grad = ctx.createLinearGradient(cardX, cardY, cardX + cardW, cardY + cardH);
        grad.addColorStop(0, accent);
        grad.addColorStop(1, dark);
        drawSoftShadowFrame(ctx, cardX, cardY, cardW, cardH, 28);
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

        // stars row
        ctx.font = '44px sans-serif';
        ctx.fillStyle = '#ffffff';
        ctx.fillText('★ ★ ★ ★ ★', cardX + 44, cardY + 190);

        ctx.font = "800 34px 'Poppins'";
        ctx.textAlign = 'right';
        ctx.fillText('POINTS', cardX + cardW - 44, cardY + 250);

        if (state.badge) {
          var badgeY = cardY + cardH + 60;
          ctx.font = "700 32px 'Poppins'";
          ctx.textAlign = 'center';
          var badgeText = state.badge.toUpperCase();
          var badgeW = ctx.measureText(badgeText).width + 80;
          roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, badgeY, badgeW, 64, 32);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textBaseline = 'middle';
          ctx.fillText(badgeText, CANVAS_SIZE / 2, badgeY + 34);
          ctx.textBaseline = 'alphabetic';
        }

        drawFooterBar(ctx, state, assets, { bg: '#ffffff', textColor: '#111827', subTextColor: '#6b7280' });
      }
    },
    {
      id: 'reserve_table',
      name: 'Reserve a Table',
      fields: ['headline', 'subtext', 'badge', 'photo'],
      badgeLabel: 'Occasion Badge (e.g. This Weekend)',
      sample: { headline: 'Reserve Your Table', subtext: 'Great food deserves a great seat. Book ahead.', badge: 'This Weekend' },
      draw: function(ctx, state, assets) {
        var accent = state.accentColor;
        var dark = shadeColor(accent, -0.35);

        if (state.photoImg) {
          drawImageCoverRounded(ctx, state.photoImg, 0, 0, CANVAS_SIZE, 560, 0);
        } else {
          ctx.fillStyle = '#e5e7eb';
          ctx.fillRect(0, 0, CANVAS_SIZE, 560);
        }
        var fade = ctx.createLinearGradient(0, 380, 0, 560);
        fade.addColorStop(0, 'rgba(255,255,255,0)');
        fade.addColorStop(1, '#ffffff');
        ctx.fillStyle = fade;
        ctx.fillRect(0, 380, CANVAS_SIZE, 180);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 560, CANVAS_SIZE, CANVAS_SIZE - 560);

        if (state.badge) {
          ctx.font = "700 28px 'Poppins'";
          var badgeText = state.badge.toUpperCase();
          var badgeW = ctx.measureText(badgeText).width + 60;
          roundRectPath(ctx, CANVAS_SIZE / 2 - badgeW / 2, 610, badgeW, 56, 28);
          ctx.fillStyle = accent;
          ctx.fill();
          ctx.fillStyle = '#ffffff';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(badgeText, CANVAS_SIZE / 2, 610 + 29);
          ctx.textBaseline = 'alphabetic';
        }

        var headY = 748;
        ctx.font = "800 58px 'Poppins'";
        ctx.fillStyle = '#111827';
        ctx.textAlign = 'center';
        var headLines = wrapLines(ctx, state.headline || 'Reserve Your Table', 880, 2);
        headLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, headY + i * 62); });

        var afterHead = headY + headLines.length * 62;
        if (state.subtext) {
          ctx.font = "500 28px 'Poppins'";
          ctx.fillStyle = '#6b7280';
          var subLines = wrapLines(ctx, state.subtext, 800, 2);
          subLines.forEach(function(l, i) { ctx.fillText(l, CANVAS_SIZE / 2, afterHead + 40 + i * 36); });
        }

        // large call-to-book phone line instead of the usual footer/QR
        var barY = CANVAS_SIZE - 150;
        ctx.fillStyle = dark;
        ctx.fillRect(0, barY, CANVAS_SIZE, 150);
        ctx.font = "600 26px 'Poppins'";
        ctx.fillStyle = 'rgba(255,255,255,0.75)';
        ctx.textAlign = 'center';
        ctx.fillText('CALL TO BOOK', CANVAS_SIZE / 2, barY + 50);
        ctx.font = "800 52px 'Poppins'";
        ctx.fillStyle = '#ffffff';
        ctx.fillText(state.phone || 'Add your phone number', CANVAS_SIZE / 2, barY + 106);
      }
    }
  ];

  function getTemplate(id) {
    for (var i = 0; i < POSTER_TEMPLATES.length; i++) {
      if (POSTER_TEMPLATES[i].id === id) return POSTER_TEMPLATES[i];
    }
    return null;
  }

  // which caption fits each design (caption-generator.js post types)
  var CAPTION_TYPE = { bold_discount: 'discount', new_item: 'new_item', combo: 'combo', happy_hour: 'happy_hour', order_direct: 'general', festive_special: 'festive',
    now_open: 'now_open', we_deliver: 'we_deliver', testimonial: 'testimonial', flash_sale: 'discount', chefs_special: 'new_item', loyalty_rewards: 'general', reserve_table: 'general' };
  // designs about one dish: picking a dish fills in its name (headline) and price
  var DISH_HEADLINE = { new_item: 'subtext', chefs_special: 'badge' };
  var DISH_SUBTEXT = { bold_discount: 1, flash_sale: 1 };

  // ---------- state ----------
  var posterState = {
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
    coupon: null,          // {code, label, badge}
    src: '',               // link tag, fixed once the poster is downloaded or saved
    designId: null,
    edited: {},            // fields the owner typed in (picking a dish won't overwrite them)
    colorPicked: false,
    caption: '', captionEdited: false, captionTurn: 0
  };
  var square = document.createElement('canvas');
  square.width = square.height = CANVAS_SIZE;
  var renderToken = 0, firstPhoto = null, inputs = {};

  var fontsP = null, fontsDone = false;
  function waitForFonts() {
    if (!document.fonts) return Promise.resolve();
    if (!fontsP) fontsP = Promise.all([
      document.fonts.load("800 60px 'Poppins'"),
      document.fonts.load("700 60px 'Poppins'"),
      document.fonts.load("500 30px 'Poppins'"),
      document.fonts.load("800 60px 'Playfair Display'"),
      document.fonts.load("700 60px 'Playfair Display'"),
      document.fonts.load("60px 'Bebas Neue'")
    ]).catch(function() {});
    // never wait more than 2.5s for Google Fonts: draw now, and again once they arrive
    if (!fontsDone) { fontsDone = true; var all = fontsP; fontsP = Promise.race([all, new Promise(function(r) { setTimeout(r, 2500); })]); all.then(function() { if (posterState.templateId) { redrawThumbnails(); renderPoster(); } }); }
    return fontsP;
  }

  function buildMenuUrl() {
    var rid = (document.querySelector('meta[name="restaurant-id"]') || {}).content
      || window.restaurant_id
      || (document.getElementById('restaurantId') || {}).textContent
      || '';
    if (window.restaurantCustomDomain && window.restaurantEmbedEnabled) {
      var domain = window.restaurantCustomDomain.replace(/^https?:\/\//, '');
      return window.location.protocol + '//' + domain + '/menu';
    }
    if (window.restaurantWebsiteSlug) {
      var siteRoot = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/'));
      return siteRoot + '/' + encodeURIComponent(window.restaurantWebsiteSlug) + '/menu';
    }
    var basePath = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/') + 6) + 'website/menu.php';
    return basePath + '?restaurant_id=' + encodeURIComponent(rid);
  }

  window.getMarketingMenuUrl = buildMenuUrl;

  function currentSrc() { return posterState.src || (window.MK ? MK.srcFor(posterState.headline) : ''); }
  function posterLink() {
    return window.MK ? MK.link({ src: currentSrc(), coupon: posterState.coupon && posterState.coupon.code }) : buildMenuUrl();
  }

  // the 1080 square design, then the chosen size around it
  function drawSquare(fmt) {
    var template = getTemplate(posterState.templateId);
    var ctx = square.getContext('2d');
    if (window.MK) MK.useBrandFont(ctx);
    var needsQR = template.forceQR || (template.fields.indexOf('showQR') > -1 && posterState.showQR && fmt === 'square');
    var st = Object.assign({}, posterState, { couponCode: posterState.coupon ? posterState.coupon.code : '', showQR: posterState.showQR && fmt === 'square' });
    ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
    template.draw(ctx, st, { qrImg: needsQR && window.MK ? MK.qr(posterLink()) : null });
  }
  function drawFormat(canvas, fmt) {
    var F = (window.MK && MK.FORMATS[fmt]) || { w: CANVAS_SIZE, h: CANVAS_SIZE };
    if (canvas.width !== F.w) canvas.width = F.w;
    if (canvas.height !== F.h) canvas.height = F.h;
    drawSquare(fmt);
    var ctx = canvas.getContext('2d');
    if (!window.MK) { ctx.drawImage(square, 0, 0); return; }
    MK.compose(ctx, square, fmt, { photo: posterState.photoImg, headline: posterState.headline, coupon: posterState.coupon, link: posterState.showQR || fmt !== 'square' ? posterLink() : '', color: posterState.accentColor });
  }

  function renderPoster() {
    var canvas = document.getElementById('posterCanvas');
    var template = getTemplate(posterState.templateId);
    if (!canvas || !template) return;
    var myToken = ++renderToken;
    // draw straight away, and again once the fonts are in (first visit only)
    drawFormat(canvas, posterState.format);
    waitForFonts().then(function() { if (myToken === renderToken) drawFormat(canvas, posterState.format); });
    refreshCaption();
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
      template.draw(ctx, sampleState, { qrImg: window.MK ? MK.qr(buildMenuUrl()) : null });
      ctx.restore();
    });
  }

  // ---------- UI ----------
  function renderTemplateGrid() {
    var grid = document.getElementById('posterTemplateGrid');
    if (!grid) return;
    grid.innerHTML = '';
    POSTER_TEMPLATES.forEach(function(t) {
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
    document.querySelectorAll('#posterTemplateGrid .poster-template-card').forEach(function(card) {
      var t = getTemplate(card.dataset.templateId), c = card.querySelector('canvas');
      if (t && c) renderThumbnail(t, c);
    });
  }
  function markActive() {
    document.querySelectorAll('#posterTemplateGrid .poster-template-card').forEach(function(c) {
      c.classList.toggle('active', c.dataset.templateId === posterState.templateId);
    });
  }

  function selectTemplate(id, keep) {
    var template = getTemplate(id);
    if (!template) return;
    var prev = getTemplate(posterState.templateId);
    posterState.templateId = id;
    // keep what the owner typed when switching designs; otherwise show the design's example text
    ['headline', 'subtext', 'badge'].forEach(function(k) { if (!keep && !posterState.edited[k]) posterState[k] = template.sample[k] || ''; });
    if (!keep && posterState.coupon && template.fields.indexOf('badge') > -1 && !posterState.edited.badge && id !== 'chefs_special') posterState.badge = posterState.coupon.badge;
    if (!keep && posterState.photoImg && posterState.photoImg._dish && !(prev && prev.id === id)) fillFromDish(posterState.photoImg._dish, true);
    if (!keep && !posterState.photoImg && firstPhoto) posterState.photoImg = firstPhoto;
    markActive();
    renderFields(template);
    renderPoster();
  }

  function setField(k, v) {
    posterState[k] = v;
    if (inputs[k]) inputs[k].value = v;
  }
  // a dish was picked: fill its name and price in where the design is about one dish
  function fillFromDish(dish, quiet) {
    if (!dish) return;
    var id = posterState.templateId, cur = window.globalCurrencySymbol || '₹', price = dish.price ? Math.round(dish.price) : 0;
    if (DISH_HEADLINE[id]) {
      if (!posterState.edited.headline) setField('headline', dish.name);
      if (price && DISH_HEADLINE[id] === 'subtext' && !posterState.edited.subtext) setField('subtext', 'Starting at ' + cur + price);
      if (price && DISH_HEADLINE[id] === 'badge' && !posterState.edited.badge) setField('badge', cur + price);
    } else if (DISH_SUBTEXT[id] && !posterState.edited.subtext) {
      setField('subtext', dish.name + (price ? ' · now ' + cur + price : ''));
    }
    if (!quiet) renderPoster();
  }

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
    input.addEventListener('input', function() { if (key) posterState.edited[key] = true; onChange(input.value); });
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
    var img = posterState[which];
    if (window.MK) return window.MK.photoPicker({ label: labelText, currentSrc: img ? img.src : '', selectedName: img && img._dish ? img._dish.name : '',
      onImage: function(im, dish) { posterState[which] = im; if (dish && which === 'photoImg') fillFromDish(dish, true); renderPoster(); },
      onUseText: function(d) {
        posterState.edited.headline = posterState.edited.subtext = false;
        setField('headline', d.name);
        if (d.price) setField('subtext', 'Now at ' + (window.globalCurrencySymbol || '₹') + Math.round(d.price));
        renderPoster();
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
      reader.onload = function(e) { loadImage(e.target.result, false).then(function(im) { posterState[which] = im; renderPoster(); }); };
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
    picker.value = posterState.accentColor;
    picker.title = 'Any colour';

    swatchColors().forEach(function(color, i) {
      var sw = document.createElement('button');
      sw.type = 'button';
      sw.className = 'poster-color-swatch' + (color === String(posterState.accentColor).toLowerCase() ? ' active' : '');
      sw.style.background = color;
      sw.title = i < 2 && window.MK && MK.brandNow().saved ? 'Brand colour' : color;
      sw.addEventListener('click', function() {
        posterState.accentColor = color; posterState.colorPicked = true;
        picker.value = color;
        swatchRow.querySelectorAll('.poster-color-swatch').forEach(function(el) { el.classList.remove('active'); });
        sw.classList.add('active');
        renderPoster();
      });
      swatchRow.appendChild(sw);
    });

    picker.addEventListener('input', function() {
      posterState.accentColor = picker.value; posterState.colorPicked = true;
      swatchRow.querySelectorAll('.poster-color-swatch').forEach(function(el) { el.classList.remove('active'); });
      renderPoster();
    });
    swatchRow.appendChild(picker);

    wrap.appendChild(label);
    wrap.appendChild(swatchRow);
    return wrap;
  }

  function suggestCode() {
    var c = window.MK && MK.campaign();
    var words = String(c ? c.name : posterState.headline || 'OFFER').toUpperCase().replace(/[^A-Z ]/g, ' ').split(/s+/).filter(Boolean);
    var base = (words[0] && words[0].length >= 4 ? words[0] : words.slice(0, 2).join('')).slice(0, 10) || 'OFFER';
    return base + '20';
  }

  function renderFields(template) {
    var container = document.getElementById('posterFieldsContainer');
    if (!container) return;
    container.innerHTML = '';
    inputs = {};

    var s1 = section('2. Dish and words', 'Pick a dish and its name and price fill in by themselves. Change any text you like.');
    if (template.fields.indexOf('photo') > -1) s1.appendChild(buildPhotoField('Photo', 'photoImg'));
    if (template.fields.indexOf('photo2') > -1) s1.appendChild(buildPhotoField('Second photo', 'photo2Img'));
    if (template.fields.indexOf('headline') > -1) s1.appendChild(buildTextField('headline', 'Headline', posterState.headline, 40, function(v) { posterState.headline = v; renderPoster(); }));
    if (template.fields.indexOf('subtext') > -1) s1.appendChild(buildTextField('subtext', 'Second line', posterState.subtext, 70, function(v) { posterState.subtext = v; renderPoster(); }));
    if (template.fields.indexOf('badge') > -1) s1.appendChild(buildTextField('badge', template.badgeLabel || 'Badge text', posterState.badge, 24, function(v) { posterState.badge = v; renderPoster(); }));
    container.appendChild(s1);

    var s2 = section('3. Offer', 'Attach a coupon. The QR code and link open your menu with it already applied, and orders show in My campaigns.');
    if (window.MK) s2.appendChild(MK.offerPicker({ current: posterState.coupon, suggestCode: suggestCode(), desc: posterState.headline, onChange: function(cp) {
      posterState.coupon = cp;
      if (cp && template.fields.indexOf('badge') > -1 && !posterState.edited.badge && posterState.templateId !== 'chefs_special') setField('badge', cp.badge);
      if (window.MK && MK.campaign()) MK.setCampaignCoupon(cp);
      renderPoster();
    } }));
    if (template.fields.indexOf('showQR') > -1) s2.appendChild(buildCheckboxField('Show a “Scan to order” QR code', posterState.showQR, function(v) { posterState.showQR = v; renderPoster(); }));
    container.appendChild(s2);

    var s3 = section('4. Colour and contact');
    s3.appendChild(buildColorField());
    s3.appendChild(buildTextField('', 'Phone number', posterState.phone, 20, function(v) { posterState.phone = v; renderPoster(); }));
    var kit = document.createElement('button');
    kit.type = 'button'; kit.className = 'mk-link'; kit.setAttribute('data-mk-kit', '');
    kit.innerHTML = '<span class="material-symbols-rounded">palette</span>Logo, colours and font are in your Brand kit';
    s3.appendChild(kit);
    container.appendChild(s3);
  }

  // ---------- preview panel: sizes, download, save, caption ----------
  function buildPreviewPanel() {
    var panel = document.querySelector('#marketingPostersPage .poster-preview-panel');
    if (!panel || panel.querySelector('.mk-fmt')) return;
    var F = window.MK ? MK.FORMATS : {};
    panel.innerHTML =
      '<div class="mk-fmt" role="tablist" aria-label="Size">' + Object.keys(F).map(function(k) {
        return '<button type="button" data-pfmt="' + k + '"' + (k === posterState.format ? ' class="on"' : '') + '><i class="mk-ratio r-' + k + '"></i><span><b>' + F[k].label + '</b><small>' + F[k].hint + '</small></span></button>';
      }).join('') + '</div>' +
      '<div class="poster-canvas-wrap"><canvas id="posterCanvas" width="1080" height="1080"></canvas></div>' +
      '<div class="mk-acts"><button type="button" class="mk-btn big" data-pact="download"><span class="material-symbols-rounded">download</span><span class="mk-dl-l">Download</span></button>' +
      '<button type="button" class="mk-btn ghost" data-pact="all"><span class="material-symbols-rounded">photo_library</span>All 3 sizes</button>' +
      '<button type="button" class="mk-btn ghost" data-pact="save"><span class="material-symbols-rounded">save</span><span class="mk-save-l">Save</span></button></div>' +
      '<div class="mk-share"><div class="mk-share-h"><b>Caption to post with it</b><button type="button" class="mk-link" data-pact="reword"><span class="material-symbols-rounded">shuffle</span>New wording</button></div>' +
      '<textarea class="mk-cap" rows="6" aria-label="Caption"></textarea>' +
      '<div class="mk-share-a"><button type="button" class="mk-btn sm" data-pact="copy"><span class="material-symbols-rounded">content_copy</span>Copy caption</button>' +
      '<button type="button" class="mk-btn sm wa" data-pact="wa"><span class="material-symbols-rounded">share</span>Share on WhatsApp</button>' +
      '<button type="button" class="mk-btn sm ghost" data-pact="link"><span class="material-symbols-rounded">link</span>Copy link</button></div>' +
      '<p class="mk-hint">Post the picture first, then paste this caption with it. The link is tracked in My campaigns.</p></div>';
    panel.querySelector('.mk-cap').addEventListener('input', function() { posterState.caption = this.value; posterState.captionEdited = true; });
    panel.addEventListener('click', function(e) {
      var f = e.target.closest('[data-pfmt]');
      if (f) {
        posterState.format = f.dataset.pfmt;
        panel.querySelectorAll('[data-pfmt]').forEach(function(b) { b.classList.toggle('on', b === f); });
        updateDownloadLabel();
        renderPoster();
        return;
      }
      var a = e.target.closest('[data-pact]');
      if (!a) return;
      var act = a.dataset.pact;
      if (act === 'download') window.downloadPoster();
      else if (act === 'all') downloadAll(a);
      else if (act === 'save') savePoster(a);
      else if (act === 'reword') { posterState.captionEdited = false; posterState.captionTurn++; refreshCaption(); }
      else if (act === 'copy' && window.MK) MK.copy(posterState.caption, a);
      else if (act === 'wa' && window.MK) MK.whatsapp(posterState.caption);
      else if (act === 'link' && window.MK) { lockSrc(); MK.copy(posterLink(), a); }
    });
    updateDownloadLabel();
  }
  function updateDownloadLabel() {
    var l = document.querySelector('#marketingPostersPage .mk-dl-l');
    if (l && window.MK) l.textContent = 'Download ' + MK.FORMATS[posterState.format].label.toLowerCase();
  }

  function refreshCaption() {
    var box = document.querySelector('#marketingPostersPage .mk-cap');
    if (!box || posterState.captionEdited || typeof window.mkCaptions !== 'function') return;
    var t = getTemplate(posterState.templateId);
    var dish = posterState.photoImg && posterState.photoImg._dish ? posterState.photoImg._dish.name : '';
    var subject = posterState.templateId === 'festive_special' ? (window.MK && MK.campaign() ? MK.campaign().name.replace(/\s*\d{4}$/, '') : posterState.headline.replace(/^happy\s+/i, '')) : (DISH_HEADLINE[posterState.templateId] ? posterState.headline : (posterState.headline || dish));
    var detail = [posterState.subtext, !posterState.coupon && /%|off|free|flat/i.test(posterState.badge) ? posterState.badge : ''].filter(Boolean).join('. ');
    var list = window.mkCaptions({ type: CAPTION_TYPE[t.id] || 'general', subject: subject, detail: detail, coupon: posterState.coupon, link: posterLink() });
    posterState.caption = list[posterState.captionTurn % list.length] || '';
    box.value = posterState.caption;
  }

  function lockSrc() { if (!posterState.src) posterState.src = currentSrc(); }
  function fileBase() { return (window.MK ? MK.fileName : function(s) { return s; })(posterState.headline || 'poster'); }

  window.downloadPoster = function() {
    var canvas = document.getElementById('posterCanvas');
    if (!canvas) return;
    lockSrc();
    renderPoster();
    var name = fileBase() + (posterState.format === 'square' ? '' : '-' + posterState.format);
    if (window.MK) { MK.download(canvas, name); return; }
    try {
      var a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = name + '.png';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
    } catch (e) {
      if (window.showMessage) window.showMessage('Could not export the poster. Try again.', 'error');
    }
  };
  function downloadAll(btn) {
    if (!window.MK) return;
    lockSrc();
    btn.disabled = true;
    var c = document.createElement('canvas'), fmts = Object.keys(MK.FORMATS), i = 0;
    (function next() {
      if (i >= fmts.length) { btn.disabled = false; if (window.SX) SX.notify('Downloaded 3 sizes: square, story and wide', 'success'); renderPoster(); return; }
      var f = fmts[i++];
      drawFormat(c, f);
      MK.download(c, fileBase() + '-' + f).then(function() { setTimeout(next, 250); });
    })();
  }

  function stateForSave() {
    return {
      templateId: posterState.templateId, headline: posterState.headline, subtext: posterState.subtext, badge: posterState.badge,
      accentColor: posterState.accentColor, phone: posterState.phone, showQR: posterState.showQR, format: posterState.format, coupon: posterState.coupon,
      photo: window.MK ? MK.imgRef(posterState.photoImg) : null, photo2: window.MK ? MK.imgRef(posterState.photo2Img) : null,
      dish: posterState.photoImg && posterState.photoImg._dish ? posterState.photoImg._dish : null,
      caption: posterState.caption, captionEdited: posterState.captionEdited, edited: posterState.edited
    };
  }
  function savePoster(btn) {
    if (!window.MK) return;
    lockSrc();
    var camp = MK.campaign(), t = getTemplate(posterState.templateId);
    btn.disabled = true;
    MK.saveDesign({ id: posterState.designId, kind: 'poster', name: (camp ? camp.name + ' · ' : '') + (posterState.headline || t.name), src: posterState.src,
      coupon_code: posterState.coupon ? posterState.coupon.code : '', state: stateForSave(), thumb: MK.thumb(document.getElementById('posterCanvas')) }).then(function(id) {
      btn.disabled = false;
      if (id) { posterState.designId = id; var l = btn.querySelector('.mk-save-l'); if (l) l.textContent = 'Saved'; }
    });
  }

  function init() {
    posterState.phone = window.restaurantPhone || '';
    buildPreviewPanel();
    renderTemplateGrid();
    selectTemplate(POSTER_TEMPLATES[0].id);
    if (!window.MK) return;
    // brand colours and phone, and a real dish photo, instead of an empty box
    MK.brand().then(function(b) {
      if (!posterState.colorPicked) posterState.accentColor = b.color1;
      if (b.phone) posterState.phone = b.phone;
      renderFields(getTemplate(posterState.templateId));
      renderPoster(); redrawThumbnails();
    });
    MK.firstPhoto().then(function(r) {
      if (!r) return;
      firstPhoto = r.img;
      if (!posterState.photoImg) { posterState.photoImg = r.img; renderFields(getTemplate(posterState.templateId)); renderPoster(); }
      redrawThumbnails();
    });
    var c = MK.campaign();
    if (c && c.coupon && !posterState.coupon) posterState.coupon = c.coupon;
    document.addEventListener('mk:brand', function(e) {
      if (!posterState.colorPicked) posterState.accentColor = e.detail.color1;
      if (e.detail.phone) posterState.phone = e.detail.phone;
      renderFields(getTemplate(posterState.templateId)); renderPoster(); redrawThumbnails();
    });
    document.addEventListener('mk:campaign', function(e) {
      if (e.detail && e.detail.coupon && (!posterState.coupon || posterState.coupon.code !== e.detail.coupon.code)) { posterState.coupon = e.detail.coupon; renderFields(getTemplate(posterState.templateId)); }
      if (!posterState.designId) posterState.src = '';
      renderPoster();
    });
  }

  window.initPosterStudio = function() {
    if (window._posterStudioInited) return;
    window._posterStudioInited = true;
    init();
  };

  window.prefillPoster = function(templateId, fields) {
    var template = getTemplate(templateId);
    if (!template) return;
    window.initPosterStudio();
    fields = fields || {};
    posterState.edited = {}; posterState.designId = null; posterState.src = ''; posterState.captionEdited = false;
    var c = window.MK && MK.campaign();
    posterState.coupon = c && c.coupon ? c.coupon : null;
    selectTemplate(templateId);
    if (fields.headline !== undefined) posterState.headline = fields.headline;
    if (fields.subtext !== undefined) posterState.subtext = fields.subtext;
    if (fields.badge !== undefined) posterState.badge = posterState.coupon ? posterState.coupon.badge : fields.badge;
    renderFields(template);
    renderPoster();
  };

  // open a saved design from My campaigns
  window.openPosterDesign = function(design) {
    window.initPosterStudio();
    var s = design.state || {};
    var template = getTemplate(s.templateId) || POSTER_TEMPLATES[0];
    Object.assign(posterState, {
      templateId: template.id, headline: s.headline || '', subtext: s.subtext || '', badge: s.badge || '', accentColor: s.accentColor || posterState.accentColor,
      phone: s.phone != null ? s.phone : posterState.phone, showQR: s.showQR !== false, format: s.format || 'square', coupon: s.coupon || null,
      src: design.src || '', designId: design.id, edited: s.edited || { headline: true, subtext: true, badge: true }, colorPicked: true,
      caption: s.caption || '', captionEdited: !!s.captionEdited, photoImg: null, photo2Img: null
    });
    document.querySelectorAll('#marketingPostersPage [data-pfmt]').forEach(function(b) { b.classList.toggle('on', b.dataset.pfmt === posterState.format); });
    var l = document.querySelector('#marketingPostersPage .mk-save-l'); if (l) l.textContent = 'Saved';
    updateDownloadLabel();
    var box = document.querySelector('#marketingPostersPage .mk-cap'); if (box && posterState.captionEdited) box.value = posterState.caption;
    markActive(); renderFields(template); renderPoster();
    Promise.all([MK.imgFromRef(s.photo), MK.imgFromRef(s.photo2)]).then(function(r) {
      if (r[0] && s.dish) r[0]._dish = s.dish;
      posterState.photoImg = r[0]; posterState.photo2Img = r[1];
      if (s.dish && s.dish.name) MK.lastPicked.Photo = s.dish.name;
      renderFields(template); renderPoster();
    });
  };
})();
