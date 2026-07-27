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

  global.EscPosBuilder = EscPosBuilder;
  global.buildReceiptEscPos = buildReceiptEscPos;
})(window);
