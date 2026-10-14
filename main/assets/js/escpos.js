// ESC/POS raw command builder for thermal receipt printers (58mm/80mm).
// Used for the "Network Printer" print mode, which sends raw bytes directly
// to a LAN thermal printer (port 9100) instead of going through the browser
// print dialog. See print_network.php for the server-side relay and
// tools/virtual_printer_server.php for a no-hardware test target.
(function (global) {
  const ESC = 0x1B;
  const GS = 0x1D;

  function EscPosBuilder() {
    this.bytes = [];
  }

  EscPosBuilder.prototype.raw = function (arr) {
    for (let i = 0; i < arr.length; i++) this.bytes.push(arr[i] & 0xFF);
    return this;
  };

  // Thermal printers commonly speak CP437/CP1252, not Unicode. Currency
  // symbols like Rupee (₹) have no byte representation on most printers,
  // so they're swapped for "Rs." before encoding.
  EscPosBuilder.prototype.text = function (str) {
    const clean = String(str == null ? '' : str)
      .replace(/₹/g, 'Rs.')
      .replace(/[^\x00-\x7E]/g, '?');
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
    return p;
  }

  /* ── Bill and KOT in the phone app's layout (app/utils/receiptPrinter.js buildReceiptLines) ── */

  EscPosBuilder.prototype.size = function (big) {
    return this.raw([GS, 0x21, big ? 0x11 : 0x00]); // double width + height for the restaurant name
  };

  function wrapText(str, width) {
    var clean = String(str == null ? '' : str).replace(/₹/g, 'Rs.').replace(/[^\x00-\x7E]/g, '?');
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

  // ITEM NAME | QTY | RATE | TOTAL, amounts on the first line of a wrapped name only
  function itemTableLines(items, width) {
    var rows = (items || []).map(function (it) {
      var q = Number(it.quantity) || 0, rate = Number(it.rate) || 0;
      return { label: it.name || '', quantity: q, rate: rate, amount: it.amount != null ? Number(it.amount) : rate * q, sub: it.sub || '' };
    });
    if (!rows.length) return [];
    var qtyW = 3;
    var rateW = Math.max(4, Math.max.apply(null, rows.map(function (r) { return fmtAmount(r.rate).length; })));
    var totW = Math.max(5, Math.max.apply(null, rows.map(function (r) { return fmtAmount(r.amount).length; })));
    var nameW = Math.max(8, width - qtyW - rateW - totW - 3);
    var cell = function (v, w) { return String(v).padStart(w); };
    var lines = [['ITEM NAME'.padEnd(nameW), cell('QTY', qtyW), cell('RATE', rateW), cell('TOTAL', totW)].join(' ')];
    rows.forEach(function (r) {
      wrapText(r.label, nameW).forEach(function (nl, i) {
        lines.push(i === 0 ? nl.padEnd(nameW) + ' ' + cell(r.quantity, qtyW) + ' ' + cell(fmtAmount(r.rate), rateW) + ' ' + cell(fmtAmount(r.amount), totW) : nl);
      });
      if (r.sub) wrapText('  ' + r.sub, width).forEach(function (l) { lines.push(l); });
    });
    return lines;
  }

  function padLine(left, right, width) {
    left = String(left == null ? '' : left); right = String(right == null ? '' : right);
    if (left.length + right.length + 1 > width) left = left.slice(0, Math.max(0, width - right.length - 1));
    return left + ' '.repeat(Math.max(1, width - left.length - right.length)) + right;
  }

  // o: { charWidth, restaurantName, address, phone, email, website, businessIdLabel, businessIdNo,
  //      billNo, createdAt, billTo, items: [{ name, quantity, rate, amount?, sub? }], subtotal,
  //      discounts: [[label, amount]], taxLabel, tax, total, afterTotal: [[label, value]],
  //      paymentMethod, notes: [line] }
  function buildBillEscPos(o) {
    var width = o.charWidth || 32;
    var p = new EscPosBuilder();
    var dash = function () { p.line('-'.repeat(width)); };
    p.init().align('center');
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
    itemTableLines(o.items, width).forEach(function (l) { p.line(l); });
    dash();
    var totalQty = (o.items || []).reduce(function (s, it) { return s + (Number(it.quantity) || 0); }, 0);
    p.line('Total Items: ' + (o.items || []).length);
    p.line('Total Quantity: ' + totalQty);
    p.bold(true).line(padLine('  Sub Total', fmtAmount(o.subtotal), width)).bold(false);
    (o.discounts || []).forEach(function (d) { if (Number(d[1]) > 0) p.line(padLine('  ' + d[0], '-' + fmtAmount(d[1]), width)); });
    if (Number(o.tax) > 0) p.line(padLine('  ' + (o.taxLabel || 'Tax'), fmtAmount(o.tax), width));
    p.bold(true).line(padLine('  Total', fmtAmount(o.total), width)).bold(false);
    (o.afterTotal || []).forEach(function (t) { p.line(padLine('  ' + t[0], t[1], width)); });
    if (o.paymentMethod) { dash(); p.line(padLine('Mode of Payment', o.paymentMethod, width)); }
    dash();
    p.align('center');
    (o.notes || []).forEach(function (n) { wrapText(n, width).forEach(function (l) { p.line(l); }); });
    p.line('Thank You! Visit Again!');
    p.line('Powered by RestroGrow');
    p.feed(3).cut();
    return p;
  }

  // o: { charWidth, kotNumber, createdAt, billNo, table, items: [{ name, quantity, sub? }], notes: [line] }
  function buildKotEscPos(o) {
    var width = o.charWidth || 32;
    var p = new EscPosBuilder();
    var dash = function () { p.line('-'.repeat(width)); };
    p.init().align('left');
    if (o.kotNumber) { p.bold(true).line('KOT No: ' + o.kotNumber).bold(false); }
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
    return p;
  }

  global.EscPosBuilder = EscPosBuilder;
  global.buildReceiptEscPos = buildReceiptEscPos;
  global.buildBillEscPos = buildBillEscPos;
  global.buildKotEscPos = buildKotEscPos;
})(window);
