// ESC/POS raw command builder for thermal receipt printers (58mm/80mm).
// Used for the "Network Printer" print mode, which sends raw bytes directly
// to a LAN thermal printer (port 9100) instead of going through the browser
// print dialog. See print_network.php for the server-side relay and
// tools/virtual_printer_server.php for a no-hardware test target.
(function (global) {
  const ESC = 0x1B;
  const GS = 0x1D;

  // Thermal printers speak a single-byte Latin code page, so anything outside
  // ASCII is unprintable. Map the punctuation our own data actually contains
  // (combo summaries use "×" and "·", prices the Rupee sign, names smart
  // quotes and dashes) to safe ASCII, drop accents from Latin letters (Café →
  // Cafe) and only fall back to "?" for scripts with no Latin stand-in
  // (e.g. Hindi/Tamil).
  const ASCII_FALLBACK = {
    '₹': 'Rs.', '×': 'x', '·': '-', '•': '*', '–': '-', '—': '-', '−': '-',
    '’': "'", '‘': "'", '“': '"', '”': '"', '…': '...', '→': '->', '←': '<-',
    '≥': '>=', '≤': '<=', '\u00A0': ' ', '€': 'EUR', '£': 'GBP'
  };
  var sawUnprintable = false;
  function toAscii(str) {
    var s = String(str == null ? '' : str)
      .replace(/[₹×·•–—−’‘“”…→←≥≤\u00A0€£]/g, function (ch) { return ASCII_FALLBACK[ch]; });
    if (s.normalize) s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); // Café → Cafe
    // Anything still outside ASCII has no Latin stand-in (Hindi/Tamil/...):
    // remember it, so the receipt can be sent as an image instead of "?".
    if (/[^\x00-\x7E]/.test(s)) sawUnprintable = true;
    return s.replace(/[^\x00-\x7E]/g, '?');
  }

  function EscPosBuilder() {
    this.bytes = [];
  }

  EscPosBuilder.prototype.raw = function (arr) {
    for (let i = 0; i < arr.length; i++) this.bytes.push(arr[i] & 0xFF);
    return this;
  };

  // Appends a ready-built ESC/POS raster command (see rgImageUrlToRaster), so a
  // logo or payment QR can be printed alongside the text.
  EscPosBuilder.prototype.raster = function (bytes) {
    if (!bytes) return this;
    for (let i = 0; i < bytes.length; i++) this.bytes.push(bytes[i] & 0xFF);
    return this;
  };

  // True when toAscii() would have to emit "?" for this string - i.e. it holds
  // a script with no Latin stand-in (Hindi, Tamil, ...). That's the signal that
  // the text path cannot represent it and the receipt must go out as an image.
  function hasUnprintable(str) {
    var t = String(str == null ? '' : str)
      .replace(/[₹×·•–—−’‘“”…→←≥≤\u00A0€£]/g, '');
    if (t.normalize) t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return /[^\x00-\x7E]/.test(t);
  }

  // See toAscii(): common symbols get an ASCII stand-in before encoding.
  EscPosBuilder.prototype.text = function (str) {
    if (!this.hadUnprintable && hasUnprintable(str)) this.hadUnprintable = true;
    const clean = toAscii(str);
    for (let i = 0; i < clean.length; i++) this.bytes.push(clean.charCodeAt(i) & 0xFF);
    return this;
  };

  EscPosBuilder.prototype.line = function (str) {
    this.text(str || '');
    this.bytes.push(0x0A);
    return this;
  };

  EscPosBuilder.prototype.init = function () {
    return this.raw([ESC, 0x40]); // ESC @ : initialize printer
  };

  EscPosBuilder.prototype.align = function (a) {
    const m = { left: 0, center: 1, right: 2 };
    return this.raw([ESC, 0x61, m[a] === undefined ? 0 : m[a]]);
  };

  EscPosBuilder.prototype.bold = function (on) {
    return this.raw([ESC, 0x45, on ? 1 : 0]);
  };

  EscPosBuilder.prototype.doubleHeight = function (on) {
    return this.raw([GS, 0x21, on ? 0x01 : 0x00]);
  };

  EscPosBuilder.prototype.feed = function (n) {
    return this.raw([ESC, 0x64, n == null ? 1 : n]);
  };

  EscPosBuilder.prototype.cut = function () {
    return this.raw([GS, 0x56, 0x00]); // full cut
  };

  EscPosBuilder.prototype.divider = function (width, ch) {
    return this.line((ch || '-').repeat(Math.max(1, width)));
  };

  // Left-aligned label, right-aligned value, padded/truncated to `width` chars.
  EscPosBuilder.prototype.twoCol = function (left, right, width) {
    left = String(left == null ? '' : left);
    right = String(right == null ? '' : right);
    let space = width - left.length - right.length;
    if (space < 1) {
      // Not enough room - truncate the label rather than wrap.
      left = left.slice(0, Math.max(0, width - right.length - 1));
      space = width - left.length - right.length;
    }
    return this.line(left + ' '.repeat(Math.max(1, space)) + right);
  };

  EscPosBuilder.prototype.toUint8Array = function () {
    return new Uint8Array(this.bytes);
  };

  EscPosBuilder.prototype.toBase64 = function () {
    const arr = this.toUint8Array();
    let binary = '';
    for (let i = 0; i < arr.length; i++) binary += String.fromCharCode(arr[i]);
    return btoa(binary);
  };

  // Renders the exact text a thermal printer would produce - useful to
  // preview/verify the ESC/POS output without owning a physical printer.
  EscPosBuilder.prototype.toPlainText = function () {
    return decodePlainText(this.bytes);
  };

  function decodePlainText(bytes) {
    let out = '';
    for (let i = 0; i < bytes.length; i++) {
      const b = bytes[i];
      if (b === 0x1B || b === 0x1D) {
        // Skip known control sequences and their fixed-length params.
        const next = bytes[i + 1];
        if (b === 0x1B && next === 0x40) { i += 1; continue; } // ESC @
        if (b === 0x1B && next === 0x61) { i += 2; continue; } // ESC a n
        if (b === 0x1B && next === 0x45) { i += 2; continue; } // ESC E n
        if (b === 0x1B && next === 0x64) { i += 2; continue; } // ESC d n
        if (b === 0x1D && next === 0x21) { i += 2; continue; } // GS ! n
        if (b === 0x1D && next === 0x76 && bytes[i + 2] === 0x30) { // GS v 0 - raster image
          const bytesPerRow = bytes[i + 4] + (bytes[i + 5] << 8);
          const rows = bytes[i + 6] + (bytes[i + 7] << 8);
          i += 7 + bytesPerRow * rows; // skip the header + the packed pixels
          continue;
        }
        if (b === 0x1D && next === 0x56) { i += 2; continue; } // GS V n
        continue;
      }
      out += String.fromCharCode(b);
    }
    return out;
  }

  // Builds a full receipt (KOT or invoice) from generic structured data.
  // charWidth: 32 for 58mm paper, 48 for 80mm paper (standard Font A widths).
  function buildReceiptEscPos(opts) {
    const width = opts.charWidth || 32;
    sawUnprintable = false;
    const p = new EscPosBuilder();
    p.init();
    p.align('center');

    if (opts.restaurantName) { p.bold(true); p.line(opts.restaurantName); p.bold(false); }
    if (opts.address) p.line(opts.address);
    if (opts.phone) p.line('Ph: ' + opts.phone);

    if (opts.title) {
      p.line('');
      p.doubleHeight(true);
      p.bold(true);
      p.line(opts.title);
      p.bold(false);
      p.doubleHeight(false);
    }
    if (opts.subtitle) p.line(opts.subtitle);

    p.divider(width, '-');
    p.align('left');

    (opts.metaLines || []).forEach(function (pair) {
      p.twoCol(pair[0], pair[1], width);
    });

    p.divider(width, '-');

    (opts.items || []).forEach(function (it) {
      p.line(it.name || '');
      if (it.sub) p.line('  ' + it.sub);
      if (it.qtyRate || it.amount) p.twoCol(it.qtyRate || '', it.amount || '', width);
    });

    p.divider(width, '-');

    (opts.totals || []).forEach(function (t) {
      if (t[2]) p.bold(true);
      p.twoCol(t[0], t[1], width);
      if (t[2]) p.bold(false);
    });

    p.divider(width, '=');
    p.align('center');

    (opts.footerLines || ['Thank you!', 'Powered by Restro Grow']).forEach(function (l) {
      p.line(l);
    });

    p.feed(3);
    p.cut();
    p.hadUnprintable = p.hadUnprintable || sawUnprintable;
    return p;
  }

  /* ── Bill and KOT in the phone app's layout (app/utils/receiptPrinter.js buildReceiptLines) ── */

  EscPosBuilder.prototype.size = function (big) {
    return this.raw([GS, 0x21, big ? 0x11 : 0x00]); // double width + height for the restaurant name
  };

  function wrapText(str, width) {
    var clean = toAscii(str);
    if (clean.length <= width) return [clean];
    var lines = [], cur = '';
    clean.split(' ').forEach(function (w) {
      while (w.length > width) { if (cur) { lines.push(cur); cur = ''; } lines.push(w.slice(0, width)); w = w.slice(width); }
      if ((cur + ' ' + w).trim().length > width) { if (cur) lines.push(cur); cur = w; }
      else cur = (cur + ' ' + w).trim();
    });
    if (cur) lines.push(cur);
    return lines;
  }

  // plain numbers in the table and totals ("149", "318.50"), like the app
  function fmtAmount(n) {
    var num = Math.round((Number(n) || 0) * 100) / 100;
    return Number.isInteger(num) ? String(num) : num.toFixed(2);
  }

  // dd/mm/yy h:mm AM/PM
  function fmtBillDateTime(value) {
    var d = value instanceof Date ? value : value ? new Date(String(value).replace(' ', 'T')) : new Date();
    if (isNaN(d.getTime())) return String(value || '');
    var pad = function (x) { return String(x).padStart(2, '0'); };
    var h = d.getHours(), ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + String(d.getFullYear()).slice(-2) + ' ' + h + ':' + pad(d.getMinutes()) + ' ' + ampm;
  }

  // ITEM NAME | QTY | RATE | TOTAL, amounts on the first line of a wrapped name
  // only. Returns the lines plus the column widths, so the totals rows can line
  // their figures up under the QTY column.
  function itemTableLines(items, width) {
    var rows = (items || []).map(function (it) {
      var q = Number(it.quantity) || 0, rate = Number(it.rate) || 0;
      return { label: it.name || '', quantity: q, rate: rate, amount: it.amount != null ? Number(it.amount) : rate * q, sub: it.sub || '' };
    });
    // QTY widens for bills with 3+ digit quantities so the column (and the
    // totals that align to it) never overruns on a big catering/combo bill.
    var qtyW = Math.max(3, Math.max.apply(null, [3].concat(rows.map(function (r) { return String(r.quantity).length; }))));
    if (!rows.length) return { lines: [], nameW: Math.max(8, width - qtyW - 12), qtyW: qtyW };
    var rateW = Math.max(4, Math.max.apply(null, rows.map(function (r) { return fmtAmount(r.rate).length; })));
    var totW = Math.max(5, Math.max.apply(null, rows.map(function (r) { return fmtAmount(r.amount).length; })));
    var nameW = Math.max(8, width - qtyW - rateW - totW - 3);
    var cell = function (v, w) { return String(v).padStart(w); };
    var lines = [['ITEM NAME'.padEnd(nameW), cell('QTY', qtyW), cell('RATE', rateW), cell('TOTAL', totW)].join(' ')];
    // never let a row run past the paper: a very wide amount (or a cramped name
    // column on a long bill) would otherwise push the columns off the right edge
    var pushRow = function (row) {
      while (row.length > width) { lines.push(row.slice(0, width)); row = row.slice(width); }
      lines.push(row);
    };
    rows.forEach(function (r) {
      wrapText(r.label, nameW).forEach(function (nl, i) {
        if (i === 0) pushRow(nl.padEnd(nameW) + ' ' + cell(r.quantity, qtyW) + ' ' + cell(fmtAmount(r.rate), rateW) + ' ' + cell(fmtAmount(r.amount), totW));
        else lines.push(nl);
      });
      if (r.sub) wrapText(r.sub, width - 2).forEach(function (l) { lines.push('  ' + l); });
    });
    return { lines: lines, nameW: nameW, qtyW: qtyW };
  }

  function padLine(left, right, width) {
    left = String(left == null ? '' : left); right = String(right == null ? '' : right);
    if (left.length + right.length + 1 > width) left = left.slice(0, Math.max(0, width - right.length - 1));
    return left + ' '.repeat(Math.max(1, width - left.length - right.length)) + right;
  }

  // o: { charWidth, restaurantName, address, phone, email, website, businessIdLabel, businessIdNo,
  //      billNo, createdAt, billTo, items: [{ name, quantity, rate, amount?, sub? }], subtotal,
  //      discounts: [[label, amount]], taxLabel, tax, total, afterTotal: [[label, value]],
  //      paymentMethod, notes: [line], logoRaster, qrRaster, qrLabel }
  function buildBillEscPos(o) {
    var width = o.charWidth || 32;
    sawUnprintable = false;
    var p = new EscPosBuilder();
    var dash = function () { p.line('-'.repeat(width)); };
    p.init().align('center');
    if (o.logoRaster) p.raster(o.logoRaster);
    // restaurant name: big and bold, wrapped for the double-width font
    p.bold(true).size(true);
    wrapText(o.restaurantName || 'Receipt', Math.max(8, Math.floor(width / 2))).forEach(function (l) { p.line(l); });
    p.size(false).bold(false);
    wrapText(o.address || '', width).forEach(function (l) { if (l) p.line(l); });
    if (o.phone) p.line('Phone: ' + o.phone);
    if (o.email) p.line('Email: ' + o.email);
    if (o.website) wrapText('Website Menu: ' + o.website, width).forEach(function (l) { p.line(l); });
    if (o.businessIdNo) p.line((o.businessIdLabel || 'PAN') + ': ' + o.businessIdNo);
    p.align('left');
    dash();
    if (o.billNo) { p.bold(true).line('Bill No: ' + o.billNo).bold(false); }
    p.line('Created On: ' + fmtBillDateTime(o.createdAt));
    wrapText('Bill To: ' + (o.billTo || 'Cash Sale'), width).forEach(function (l) { p.line(l); });
    dash();
    var table = itemTableLines(o.items, width);
    table.lines.forEach(function (l) { p.line(l); });
    dash();
    // the counts line up under the QTY column above, so the bill reads as a table
    var qtyRow = function (label, value) {
      var l = String(label);
      if (l.length > table.nameW) l = l.slice(0, table.nameW);
      return l.padEnd(table.nameW) + ' ' + String(value).padStart(table.qtyW);
    };
    var totalQty = (o.items || []).reduce(function (s, it) { return s + (Number(it.quantity) || 0); }, 0);
    p.line(qtyRow('Total Items', (o.items || []).length));
    p.line(qtyRow('Total Quantity', totalQty));
    p.bold(true).line(padLine('  Sub Total', fmtAmount(o.subtotal), width)).bold(false);
    (o.discounts || []).forEach(function (d) { if (Number(d[1]) > 0) p.line(padLine('  ' + d[0], '-' + fmtAmount(d[1]), width)); });
    if (Number(o.tax) > 0) p.line(padLine('  ' + (o.taxLabel || 'Tax'), fmtAmount(o.tax), width));
    p.bold(true).line(padLine('  Total', fmtAmount(o.total), width)).bold(false);
    (o.afterTotal || []).forEach(function (t) { p.line(padLine('  ' + t[0], t[1], width)); });
    if (o.paymentMethod) { dash(); p.line(padLine('Mode of Payment', o.paymentMethod, width)); }
    if (o.qrRaster) {
      p.align('center');
      p.raster(o.qrRaster);
      p.line(o.qrLabel || 'Scan to Pay');
      p.align('left');
    }
    dash();
    p.align('center');
    (o.notes || []).forEach(function (n) { wrapText(n, width).forEach(function (l) { p.line(l); }); });
    p.line('Thank You! Visit Again!');
    p.line('Powered by RestroGrow');
    p.feed(3).cut();
    p.hadUnprintable = p.hadUnprintable || sawUnprintable;
    return p;
  }

  // o: { charWidth, kotNumber, createdAt, billNo, table, items: [{ name, quantity, sub? }], notes: [line], bar? }
  // bar: a Bar Order Ticket (BOT) for drinks, headed BAR ORDER TICKET
  function buildKotEscPos(o) {
    var width = o.charWidth || 32;
    sawUnprintable = false;
    var p = new EscPosBuilder();
    var dash = function () { p.line('-'.repeat(width)); };
    p.init();
    if (o.bar) { p.align('center').bold(true).doubleHeight(true).line('BAR ORDER TICKET').doubleHeight(false).bold(false); dash(); }
    p.align('left');
    if (o.kotNumber) { p.bold(true).line((o.bar ? 'BOT' : 'KOT') + ' No: ' + o.kotNumber).bold(false); }
    p.line('Date: ' + fmtBillDateTime(o.createdAt));
    if (o.billNo) p.line('Bill No: ' + o.billNo);
    p.line('Table: ' + (o.table || 'Sale'));
    dash();
    var count = 0;
    (o.items || []).forEach(function (it) {
      count += Number(it.quantity) || 0;
      wrapText(it.name || '', width - 4).forEach(function (l) { p.line(l); });
      if (it.sub) wrapText('  ' + it.sub, width).forEach(function (l) { p.line(l); });
      p.line('(' + (Number(it.quantity) || 0) + ')');
    });
    dash();
    (o.notes || []).forEach(function (n) { wrapText(n, width).forEach(function (l) { p.line(l); }); });
    p.bold(true).line('Total Items: ' + count).bold(false);
    dash();
    p.align('center').line('Thank you!');
    p.feed(3).cut();
    p.hadUnprintable = p.hadUnprintable || sawUnprintable;
    return p;
  }

  global.EscPosBuilder = EscPosBuilder;
  global.buildReceiptEscPos = buildReceiptEscPos;
  global.buildBillEscPos = buildBillEscPos;
  global.buildKotEscPos = buildKotEscPos;

  /* ── Raster helpers ──────────────────────────────────────────────────────
     Thermal printers' built-in text mode only speaks a single-byte Latin
     code page, so a script with no Latin stand-in (Hindi/Tamil/...) can only
     come out as "?" - and a logo or a payment QR cannot come out at all.
     Both are solved by sending a picture: the text (or the logo/QR) is drawn
     onto a canvas and packed as an ESC/POS GS v 0 raster image. */

  // Packs a canvas into ESC/POS raster bytes: 1 bit per pixel, MSB first, each
  // row padded to a whole byte. Threshold leans dark so anti-aliased strokes
  // survive the low-resolution thermal output.
  function canvasToRaster(canvas, threshold) {
    var w = canvas.width, h = canvas.height;
    var data = canvas.getContext('2d').getImageData(0, 0, w, h).data;
    var bytesPerRow = Math.ceil(w / 8);
    var out = new Uint8Array(bytesPerRow * h);
    var t = threshold == null ? 180 : threshold;
    for (var y = 0; y < h; y++) {
      for (var x = 0; x < w; x++) {
        var i = (y * w + x) * 4;
        var lum = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
        if (lum < t) out[y * bytesPerRow + (x >> 3)] |= (1 << (7 - (x & 7)));
      }
    }
    return out;
  }

  // Wraps raster bytes in a GS v 0 command; the caller aligns it.
  function rasterCommand(w, h, raster) {
    var bytesPerRow = Math.ceil(w / 8);
    var cmd = [GS, 0x76, 0x30, 0x00, bytesPerRow & 0xFF, (bytesPerRow >> 8) & 0xFF, h & 0xFF, (h >> 8) & 0xFF];
    var out = new Uint8Array(cmd.length + raster.length);
    out.set(cmd, 0);
    out.set(raster, cmd.length);
    return out;
  }

  // Loads an image URL and returns a ready-to-append GS v 0 command (or null on
  // any failure, so a missing/unreachable image never blocks a print).
  function imageUrlToRaster(url, dotWidth, threshold) {
    return new Promise(function (resolve) {
      if (!url || typeof document === 'undefined') return resolve(null);
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () {
        try {
          var w = dotWidth || 256;
          var iw = img.naturalWidth || img.width || w;
          var ih = img.naturalHeight || img.height || w;
          var h = Math.max(1, Math.round(ih * (w / iw)));
          var c = document.createElement('canvas');
          c.width = w; c.height = h;
          var ctx = c.getContext('2d');
          ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
          ctx.drawImage(img, 0, 0, w, h);
          resolve(rasterCommand(w, h, canvasToRaster(c, threshold)));
        } catch (e) { resolve(null); }
      };
      img.onerror = function () { resolve(null); };
      img.src = url;
    });
  }

  // Renders the builder's plain text (exactly what the text path would print)
  // as one raster image. The widest line is scaled to the paper width, so the
  // columns (which are just padded spaces) stay aligned.
  function rasterizePlainText(text, widthMM) {
    if (typeof document === 'undefined') throw new Error('no canvas');
    var dotWidth = widthMM === 58 ? 384 : 576;
    var lines = String(text == null ? '' : text).replace(/\n+$/, '').split('\n');
    if (!lines.length) lines = [''];
    var fam = '"Courier New", Courier, monospace';
    var c = document.createElement('canvas');
    var ctx = c.getContext('2d');
    ctx.font = '600 20px ' + fam;
    var maxW = 1;
    for (var i = 0; i < lines.length; i++) {
      maxW = Math.max(maxW, ctx.measureText(lines[i]).width);
    }
    var fontPx = Math.max(9, Math.round(20 * Math.min(1, dotWidth / maxW)));
    var lineH = Math.round(fontPx * 1.35);
    c.width = dotWidth;
    c.height = Math.max(1, lines.length * lineH);
    ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#000';
    ctx.font = '600 ' + fontPx + 'px ' + fam;
    ctx.textBaseline = 'top';
    for (var j = 0; j < lines.length; j++) ctx.fillText(lines[j], 0, j * lineH);
    var raster = canvasToRaster(c, 170);
    var head = [ESC, 0x40, ESC, 0x61, 0x00]; // init + left align
    var body = rasterCommand(dotWidth, c.height, raster);
    var tail = [ESC, 0x64, 0x03, GS, 0x56, 0x00]; // feed 3 + full cut
    var out = new Uint8Array(head.length + body.length + tail.length);
    out.set(head, 0);
    out.set(body, head.length);
    out.set(tail, head.length + body.length);
    return out;
  }

  function settingsWidth() {
    try { return global.getPrinterSettings ? global.getPrinterSettings().widthMM : 80; } catch (e) { return 80; }
  }

  function bytesToBase64(bytes) {
    var binary = '';
    for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
  }

  // The bytes to actually send: normally the ESC/POS text stream, but when the
  // receipt holds a script the text mode cannot render it is sent as a single
  // raster image instead, so nothing comes out as "?".
  global.rgEscPosBytes = function (builder, opts) {
    opts = opts || {};
    var widthMM = opts.widthMM == null ? settingsWidth() : opts.widthMM;
    if (builder && builder.hadUnprintable) {
      try { return rasterizePlainText(builder.toPlainText(), widthMM); } catch (e) { /* fall back to text */ }
    }
    return builder.toUint8Array();
  };

  global.rgEscPosBase64 = function (builder, opts) {
    return bytesToBase64(global.rgEscPosBytes(builder, opts));
  };

  // Turns an image URL into a ready-to-append GS v 0 raster command (or null).
  global.rgImageUrlToRaster = function (url, dotWidth, threshold) {
    return imageUrlToRaster(url, dotWidth, threshold);
  };

  /* ── The bill as a picture ────────────────────────────────────────────────
     A bill on the printer looks exactly like its print preview: the preview's HTML is laid out by the
     browser at the paper's width, drawn onto a canvas (SVG foreignObject: the browser's own renderer, so
     fonts, tables, logo and QR come out as on screen) and sent as raster bands. The direct print modes
     (USB / Bluetooth / network) only exist in Chrome, where this works. Returns a builder-like object
     that printDirect / printViaNetwork take like an EscPosBuilder, or null when anything fails (the
     caller then prints the text layout instead, so a bill is never lost). */
  function dataUrlOf(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('image ' + r.status);
      return r.blob();
    }).then(function (b) {
      return new Promise(function (ok, no) { var fr = new FileReader(); fr.onload = function () { ok(fr.result); }; fr.onerror = no; fr.readAsDataURL(b); });
    });
  }

  global.rgHtmlBillBuilder = async function (html, widthMM) {
    if (typeof document === 'undefined' || !global.XMLSerializer) return null;
    widthMM = widthMM === 58 ? 58 : 80;
    var dotWidth = widthMM === 58 ? 384 : 576;
    var cssWidth = Math.round(widthMM * 96 / 25.4);   // the paper at screen size, as the preview lays it out
    var frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + cssWidth + 'px;height:200px;border:0;visibility:hidden;pointer-events:none';
    document.body.appendChild(frame);
    try {
      var doc = frame.contentDocument;
      doc.open(); doc.write(html); doc.close();
      // what the printer gets is what @media print gives: black ink on white, nothing pale lost
      var ink = doc.createElement('style');
      ink.textContent = '*,*::before,*::after{color:#000!important;-webkit-text-fill-color:#000!important;text-shadow:none!important;box-shadow:none!important;border-color:#000!important}'
        + 'html,body,*{background-color:#fff!important;background-image:none!important}html,body{margin:0!important}';
      (doc.head || doc.documentElement).appendChild(ink);
      // pictures (logo, payment QR) go in as data: so the canvas may read them
      await Promise.all([].slice.call(doc.images).map(function (im) {
        var src = im.getAttribute('src');
        if (!src || /^data:/.test(src)) return null;
        return dataUrlOf(new URL(src, location.href).href).then(function (d) { im.setAttribute('src', d); }, function () { im.remove(); });
      }));
      await Promise.all([].slice.call(doc.images).map(function (im) {
        return im.complete ? null : new Promise(function (ok) { im.onload = im.onerror = ok; });
      }));
      if (doc.fonts && doc.fonts.ready) await doc.fonts.ready;
      var root = doc.documentElement;
      var height = Math.ceil(Math.max(root.scrollHeight, doc.body ? doc.body.scrollHeight : 0));
      if (!height) return null;
      var scale = dotWidth / cssWidth;
      var xml = new XMLSerializer().serializeToString(root);
      var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + dotWidth + '" height="' + Math.ceil(height * scale) + '" viewBox="0 0 ' + cssWidth + ' ' + height + '">'
        + '<foreignObject x="0" y="0" width="' + cssWidth + '" height="' + height + '">' + xml + '</foreignObject></svg>';
      var pic = await new Promise(function (ok, no) {
        var im = new Image();
        im.onload = function () { ok(im); };
        im.onerror = function () { no(new Error('bill picture')); };
        im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
      });
      var c = document.createElement('canvas');
      c.width = dotWidth;
      c.height = Math.ceil(height * scale);
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(pic, 0, 0, c.width, c.height);
      // drop the white below the bill
      var rows = c.height, data = ctx.getImageData(0, 0, c.width, c.height).data;
      for (var y = c.height - 1; y > 0; y--) {
        var dark = false;
        for (var x = 0; x < c.width; x += 2) { var i = (y * c.width + x) * 4; if (data[i] + data[i + 1] + data[i + 2] < 540) { dark = true; break; } }
        if (dark) { rows = Math.min(c.height, y + 8); break; }
      }
      // sent in bands of 240 rows: small printers have small buffers
      var parts = [new Uint8Array([ESC, 0x40, ESC, 0x61, 0x00])], total = 5;
      for (var top = 0; top < rows; top += 240) {
        var h = Math.min(240, rows - top);
        var band = document.createElement('canvas');
        band.width = dotWidth; band.height = h;
        band.getContext('2d').drawImage(c, 0, top, dotWidth, h, 0, 0, dotWidth, h);
        var cmd = rasterCommand(dotWidth, h, canvasToRaster(band, 190));   // leans dark: small grey text stays readable
        parts.push(cmd); total += cmd.length;
      }
      var tail = new Uint8Array([ESC, 0x64, 0x03, GS, 0x56, 0x00]);   // feed 3 + cut
      parts.push(tail); total += tail.length;
      var bytes = new Uint8Array(total), at = 0;
      parts.forEach(function (p) { bytes.set(p, at); at += p.length; });
      return {
        isPicture: true,
        hadUnprintable: false,
        canvas: c,
        rows: rows,
        toUint8Array: function () { return bytes; },
        toPlainText: function () { return ''; }
      };
    } catch (e) {
      if (global.console) console.warn('Bill picture failed, printing the text layout', e);
      return null;
    } finally {
      frame.remove();
    }
  };

  /* ── The bill as text, laid out like its preview (Bluetooth / USB) ───────
     A picture of a bill is ~75 KB: over Bluetooth (20-byte packets) that takes a minute and comes out in
     strips. These links get the preview turned into printer text instead (a few KB, prints at once): the
     HTML is laid out at the paper's width, every piece of text goes in the column where the preview shows
     it (left / centred / right), bold stays bold, the big name prints double size, ruled lines become
     ---- / ====, and the logo and payment QR go as small pictures. Returns an EscPosBuilder, or null when
     it can't be made (the caller then prints buildBillEscPos). */
  global.rgHtmlBillText = async function (html, widthMM, charWidth) {
    if (typeof document === 'undefined') return null;
    widthMM = widthMM === 58 ? 58 : 80;
    var width = charWidth || (widthMM === 58 ? 32 : 48);
    var dotWidth = widthMM === 58 ? 384 : 576;
    var cssWidth = Math.round(widthMM * 96 / 25.4);
    var frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + cssWidth + 'px;height:200px;border:0;visibility:hidden;pointer-events:none';
    document.body.appendChild(frame);
    try {
      var doc = frame.contentDocument, win = frame.contentWindow;
      doc.open(); doc.write(html); doc.close();
      var st = doc.createElement('style');
      st.textContent = 'html,body{margin:0!important}';
      (doc.head || doc.documentElement).appendChild(st);
      // the logo / QR must be loaded before measuring, or they take no room and are left out
      // a hidden frame may not fetch its pictures: give them as data: (as the picture bill does)
      await Promise.all([].slice.call(doc.images).map(function (im) {
        var src = im.getAttribute('src');
        if (!src || /^data:/.test(src)) return null;
        return dataUrlOf(new URL(src, location.href).href).then(function (d) { im.setAttribute('src', d); }, function () { im.remove(); });
      }));
      for (var waited = 0; waited < 4000; waited += 50) {
        if ([].every.call(doc.images, function (im) { return im.complete && (im.naturalWidth > 0 || !im.getAttribute('src')); })) break;
        await new Promise(function (ok) { setTimeout(ok, 50); });
      }
      if (doc.fonts && doc.fonts.ready) await doc.fonts.ready;
      await new Promise(function (ok) { setTimeout(ok, 30); });

      // every word with its box, weight and size
      var words = [], sizes = [];
      var walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null);
      for (var n = walker.nextNode(); n; n = walker.nextNode()) {
        var el = n.parentElement;
        if (!el || /^(SCRIPT|STYLE|TITLE)$/.test(el.tagName)) continue;
        var cs = win.getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        var fs = parseFloat(cs.fontSize) || 12, bold = (parseInt(cs.fontWeight, 10) || 400) >= 600;
        var upper = cs.textTransform === 'uppercase';
        // how its paragraph is aligned in the preview (centre / right), for lines that wrap on paper
        var blk = el;
        while (blk.parentElement && /^inline/.test(win.getComputedStyle(blk).display)) blk = blk.parentElement;
        var talign = win.getComputedStyle(blk).textAlign;
        talign = talign === 'center' || talign === '-webkit-center' ? 'center' : (talign === 'right' || talign === 'end' ? 'right' : 'left');
        // words, and long codes split after "-" the way the browser wraps them
        var re = /[^\s-]+-*|-+/g, m, txt = n.nodeValue;
        while ((m = re.exec(txt))) {
          var r = doc.createRange();
          r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length);
          var rects = r.getClientRects();
          if (!rects.length || !rects[0].width) continue;
          var b = rects[0];
          var w = upper ? m[0].toUpperCase() : m[0];
          words.push({ t: w, x1: b.left, x2: b.right, y: b.top + b.height / 2, h: b.height, top: b.top, bold: bold, fs: fs, al: talign,
            glued: m.index > 0 && !/\s/.test(txt.charAt(m.index - 1)) });
          sizes.push(fs);
        }
      }
      if (!words.length) return null;
      sizes.sort(function (a, c) { return a - c; });
      var baseFs = sizes[Math.floor(sizes.length / 2)];
      var minL = Math.min.apply(null, words.map(function (w) { return w.x1; }));
      var maxR = Math.max.apply(null, words.map(function (w) { return w.x2; }));
      var span = Math.max(1, maxR - minL), cpx = span / width, mid = (minL + maxR) / 2;

      // ruled lines (borders across most of the bill) and pictures (logo, QR)
      var marks = [];
      [].slice.call(doc.body.querySelectorAll('*')).forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (!r.width || !r.height && el.tagName !== 'HR') return;
        if (el.tagName === 'IMG') {
          if (r.width >= 30) marks.push({ kind: 'img', y: r.top, h: r.height, url: el.currentSrc || el.src, w: r.width });
          return;
        }
        if (r.width < span * 0.55) return;
        var cs = win.getComputedStyle(el);
        [['Top', r.top], ['Bottom', r.bottom]].forEach(function (side) {
          var sw = parseFloat(cs['border' + side[0] + 'Width']) || 0, ss = cs['border' + side[0] + 'Style'];
          if (sw < 1 || ss === 'none' || ss === 'hidden') return;
          marks.push({ kind: 'rule', y: side[1], ch: (ss === 'double' || (ss === 'solid' && sw >= 2)) ? '=' : '-' });
        });
        if (el.tagName === 'HR') marks.push({ kind: 'rule', y: r.top, ch: '-' });
      });

      // words into lines (by height on the page), lines into runs (words close together)
      words.sort(function (a, c) { return a.y - c.y || a.x1 - c.x1; });
      var lines = [];
      words.forEach(function (w) {
        var L = lines[lines.length - 1];
        if (L && Math.abs(w.y - L.y) < Math.min(w.h, L.h) * 0.5) { L.words.push(w); L.bottom = Math.max(L.bottom, w.top + w.h); }
        else lines.push({ y: w.y, h: w.h, top: w.top, bottom: w.top + w.h, words: [w] });
      });
      lines.forEach(function (L) {
        L.words.sort(function (a, c) { return a.x1 - c.x1; });
        var runs = [];
        L.words.forEach(function (w) {
          var R = runs[runs.length - 1];
          if (R && w.x1 - R.x2 < Math.max(cpx * 1.6, w.fs * 0.9)) {
            R.t += (w.glued && w.x1 - R.x2 < 1.5 ? '' : ' ') + w.t; R.x2 = w.x2; R.bold = R.bold || w.bold; R.fs = Math.max(R.fs, w.fs);
          } else runs.push({ t: w.t, x1: w.x1, x2: w.x2, bold: w.bold, fs: w.fs, al: w.al });
        });
        L.runs = runs;
        L.big = runs.some(function (r) { return r.fs >= baseFs * 1.3; });
      });

      var items = lines.map(function (L) { return { kind: 'text', y: L.top, L: L }; }).concat(marks);
      items.sort(function (a, c) { return a.y - c.y || (a.kind === 'rule' ? -1 : 1); });

      var p = new EscPosBuilder();
      sawUnprintable = false;
      p.init();
      var lastRuleY = -99, lastKind = '', lastBottom = null;
      var emitRule = function (ch) { p.align('left').line(ch.repeat(width)); };
      var place = function (L, w) {
        // runs into columns: right edge → right, middle → centred, else where the preview starts it
        var cells = L.runs.map(function (r) {
          var t = toAscii(r.t), len = t.length, s;
          if (L.runs.length === 1 && r.al === 'center') s = Math.round((w - len) / 2);
          else if (L.runs.length === 1 && r.al === 'right') s = w - len;
          else if (Math.abs(r.x2 - maxR) <= cpx * 0.9 && r.x1 - minL > cpx) s = w - len;
          else if (Math.abs((r.x1 + r.x2) / 2 - mid) <= cpx * 1.2 && r.x1 - minL > cpx) s = Math.round((w - len) / 2);
          else s = Math.round((r.x1 - minL) / span * w);
          return { t: t, s: Math.max(0, s), bold: r.bold };
        });
        var cur = 0;
        cells.forEach(function (c) { if (c.s < cur) c.s = cur; cur = c.s + c.t.length + 1; });
        var end = cells.length ? cells[cells.length - 1].s + cells[cells.length - 1].t.length : 0;
        if (end > w) {
          // too wide: pull from the right, then squeeze to one space apart
          var over = end - w;
          for (var i = cells.length - 1; i >= 0 && over > 0; i--) {
            var gap = i ? cells[i].s - (cells[i - 1].s + cells[i - 1].t.length + 1) : cells[i].s;
            var mv = Math.min(gap, over); if (mv > 0) { for (var j = i; j < cells.length; j++) cells[j].s -= mv; over -= mv; }
          }
          if (over > 0) return null;
        }
        return cells;
      };
      for (var k = 0; k < items.length; k++) {
        var it = items[k];
        if (it.kind === 'rule') {
          if (it.y - lastRuleY < 6 || lastKind === 'rule') continue;
          emitRule(it.ch); lastRuleY = it.y; lastKind = 'rule'; lastBottom = it.y;
          continue;
        }
        if (it.kind === 'img') {
          var dots = Math.min(dotWidth, Math.max(96, Math.round(it.w / cssWidth * dotWidth / 8) * 8));
          var ras = await imageUrlToRaster(it.url, dots, 180);
          if (ras) { p.align('center').raster(ras).align('left'); lastKind = 'img'; lastBottom = it.y + it.h; }
          continue;
        }
        var L = it.L;
        // a clear gap in the preview: one empty line
        if (lastBottom != null && lastKind === 'text' && L.top - lastBottom > L.h * 0.9) p.line('');
        var single = L.runs.length === 1 ? toAscii(L.runs[0].t) : null;
        if (L.big && single && single.length <= Math.floor(width / 2)) {
          var centred = L.runs[0].al === 'center' || Math.abs((L.runs[0].x1 + L.runs[0].x2) / 2 - mid) <= cpx * 1.5;
          p.align(centred ? 'center' : 'left').bold(true).size(true).line(single).size(false).bold(false).align('left');
        } else {
          var cells = place(L, width);
          if (L.big) p.bold(true).doubleHeight(true);
          if (!cells) {
            // one line can't hold it: wrap the words like the preview does
            var all = L.runs.map(function (r) { return r.t; }).join(' ');
            p.align(L.runs[0].al === 'center' ? 'center' : L.runs[0].al === 'right' ? 'right' : 'left');
            if (L.runs.every(function (r) { return r.bold; })) p.bold(true);
            wrapText(all, width).forEach(function (l) { p.line(l); });
            p.bold(false).align('left');
          } else {
            var pos = 0;
            cells.forEach(function (cl) {
              if (cl.s > pos) p.text(' '.repeat(cl.s - pos));
              if (cl.bold && !L.big) p.bold(true);
              p.text(cl.t);
              if (cl.bold && !L.big) p.bold(false);
              pos = cl.s + cl.t.length;
            });
            p.line('');
          }
          if (L.big) p.doubleHeight(false).bold(false);
        }
        lastKind = 'text'; lastBottom = L.bottom;
      }
      p.feed(3).cut();
      p.hadUnprintable = p.hadUnprintable || sawUnprintable;
      return p;
    } catch (e) {
      if (global.console) console.warn('Bill text from preview failed, printing the standard layout', e);
      return null;
    } finally {
      frame.remove();
    }
  };

  // What a bill sends to the printer in Printer settings: the picture on a network printer (fast link),
  // the preview as text on Bluetooth / USB (slow links). null: print buildBillEscPos instead.
  global.rgBillForPrinter = async function (html, settings) {
    settings = settings || (global.getPrinterSettings ? global.getPrinterSettings() : { widthMM: 80, mode: 'browser' });
    var cw = global.getPrinterCharWidth ? global.getPrinterCharWidth(settings.widthMM) : null;
    if (settings.mode === 'network') return (await global.rgHtmlBillBuilder(html, settings.widthMM)) || (await global.rgHtmlBillText(html, settings.widthMM, cw));
    return global.rgHtmlBillText(html, settings.widthMM, cw);
  };
})(window);
