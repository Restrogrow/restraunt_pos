// Minimal ESC/POS receipt builder + network relay client for the app's POS
// screen. The website supports two print modes — browser print (not
// meaningful in a native/RN context) and raw ESC/POS over a TCP socket via
// main/api/print_network.php, which is a plain authenticated HTTP relay (the
// PHP server opens the actual TCP connection to the LAN printer). This file
// only implements that second, portable path.
import { apiPostJson } from '../config/api';

const ESC = 0x1b;
const GS = 0x1d;

const CMD = {
  INIT: [ESC, 0x40],
  ALIGN_LEFT: [ESC, 0x61, 0x00],
  ALIGN_CENTER: [ESC, 0x61, 0x01],
  BOLD_ON: [ESC, 0x45, 0x01],
  BOLD_OFF: [ESC, 0x45, 0x00],
  CUT: [GS, 0x56, 0x00],
};

// Thermal printers use a single-byte codepage and can't render most Unicode
// characters — naively truncating one to its low byte prints a garbled/wrong
// glyph (mojibake), not the intended character. Swap the common non-ASCII
// currency symbols for a safe ASCII stand-in first, then fall back to '?'
// for anything else non-ASCII (restaurant names, item names, etc. can
// contain accents or other scripts) — mirrors the website's escpos.js,
// which does the same replace(/[^\x00-\x7E]/g, '?') as its safety net.
const CURRENCY_MAP = { '₹': 'Rs.', '€': 'EUR ', '£': 'GBP ', '¥': 'JPY ', '₩': 'KRW ', '₨': 'Rs.' };

function printSafeCurrency(currency) {
  if (CURRENCY_MAP[currency]) return CURRENCY_MAP[currency];
  return /^[\x00-\x7F]*$/.test(currency || '') ? currency : 'Rs.';
}

function asciiSafe(str) {
  return String(str == null ? '' : str).replace(/[^\x00-\x7E]/g, '?');
}

function textToBytes(str) {
  const clean = asciiSafe(str);
  const bytes = [];
  for (let i = 0; i < clean.length; i++) {
    bytes.push(clean.charCodeAt(i) & 0xff);
  }
  return bytes;
}

function line(str = '') {
  return [...textToBytes(str), 0x0a];
}

function padLine(left, right, width) {
  const space = Math.max(1, width - left.length - right.length);
  return left + ' '.repeat(space) + right;
}

function wrapText(str, width) {
  const clean = asciiSafe(str);
  if (clean.length <= width) return [clean];
  const words = clean.split(' ');
  const lines = [];
  let cur = '';
  for (let w of words) {
    // A single word longer than the whole width (long compound item/
    // restaurant names) would otherwise never break and just overflow —
    // hard-split it at the width first.
    while (w.length > width) {
      if (cur) { lines.push(cur); cur = ''; }
      lines.push(w.slice(0, width));
      w = w.slice(width);
    }
    if ((cur + ' ' + w).trim().length > width) {
      if (cur) lines.push(cur);
      cur = w;
    } else {
      cur = (cur + ' ' + w).trim();
    }
  }
  if (cur) lines.push(cur);
  return lines;
}

function centerText(str, width) {
  if (str.length >= width) return str;
  const space = Math.floor((width - str.length) / 2);
  return ' '.repeat(space) + str;
}

// items: [{ name, quantity, price, variationName? }]
// type: 'bill' (default) prints the full priced receipt a customer gets;
// 'kot' prints a kitchen ticket — item names/quantities only, no prices —
// mirroring the website's KOT template, which never shows the kitchen a
// subtotal/tax/total/payment method that has nothing to do with cooking.
export function buildReceiptEscPos({
  type = 'bill',
  restaurantName,
  kotNumber,
  orderType,
  tableName,
  items,
  subtotal,
  discount = 0,
  couponCode,
  tax,
  total,
  paymentMethod,
  currency = 'Rs.',
  width = 32,
}) {
  currency = printSafeCurrency(currency);
  // Double-width/height mode (GS ! n) used to wrap the restaurant name here.
  // It's inconsistently supported on cheap ESC/POS clone printers — on at
  // least one real printer it corrupted part of the name itself (the first
  // several characters printed as garbage before the rest recovered), most
  // likely the printer's font table hadn't finished switching by the time
  // the text bytes arrived. Bold is universally supported and doesn't have
  // this failure mode, so that's all the header uses now.
  const bytes = [...CMD.INIT, ...CMD.ALIGN_CENTER, ...CMD.BOLD_ON];
  // A long restaurant name in single-width mode would otherwise run past
  // the paper width and either get cut off or wrapped mid-word by the
  // printer's own firmware — wrap and center it ourselves, same as any
  // other line, instead of leaving it unbounded.
  wrapText(restaurantName || 'Receipt', width).forEach((l) => bytes.push(...line(centerText(l, width))));
  bytes.push(...CMD.BOLD_OFF);
  if (type === 'kot') bytes.push(...line('KOT'));
  if (kotNumber) bytes.push(...line(kotNumber));
  bytes.push(...line(new Date().toLocaleString()));
  bytes.push(...CMD.ALIGN_LEFT);
  bytes.push(...line('-'.repeat(width)));
  bytes.push(...line(`${orderType}${tableName ? ' - ' + tableName : ''}`));
  bytes.push(...line('-'.repeat(width)));

  let itemCount = 0;
  (items || []).forEach((it) => {
    itemCount += Number(it.quantity) || 0;
    const label = it.variationName ? `${it.name} (${it.variationName})` : it.name;
    if (type === 'kot') {
      wrapText(`${it.quantity} x ${label}`, width).forEach((l) => bytes.push(...line(l)));
    } else {
      wrapText(label, width).forEach((l) => bytes.push(...line(l)));
      const qtyPrice = `${it.quantity} x ${currency}${Number(it.price).toFixed(2)}`;
      const lineTotal = `${currency}${(Number(it.price) * Number(it.quantity)).toFixed(2)}`;
      bytes.push(...line(padLine(qtyPrice, lineTotal, width)));
    }
  });

  bytes.push(...line('-'.repeat(width)));

  if (type === 'kot') {
    bytes.push(...CMD.BOLD_ON);
    bytes.push(...line(padLine('Total Items', String(itemCount), width)));
    bytes.push(...CMD.BOLD_OFF);
  } else {
    bytes.push(...line(padLine('Subtotal', `${currency}${Number(subtotal).toFixed(2)}`, width)));
    if (Number(discount) > 0) {
      const label = couponCode ? `Coupon (${couponCode})` : 'Discount';
      bytes.push(...line(padLine(label, `-${currency}${Number(discount).toFixed(2)}`, width)));
    }
    if (Number(tax) > 0) {
      bytes.push(...line(padLine('Tax', `${currency}${Number(tax).toFixed(2)}`, width)));
    }
    bytes.push(...CMD.BOLD_ON);
    bytes.push(...line(padLine('TOTAL', `${currency}${Number(total).toFixed(2)}`, width)));
    bytes.push(...CMD.BOLD_OFF);
    if (paymentMethod) {
      bytes.push(...line(`Payment: ${paymentMethod}`));
    }
  }

  bytes.push(...line('-'.repeat(width)));
  bytes.push(...CMD.ALIGN_CENTER);
  bytes.push(...line('Thank you!'));
  bytes.push(...line(''));
  bytes.push(...line(''));
  bytes.push(...CMD.CUT);

  return new Uint8Array(bytes);
}

// Strips ESC/GS control sequences back out, leaving the exact characters
// that would land on paper — mirrors the website's escpos.js decodePlainText.
// Used to drive the on-screen preview off the *actual* print bytes instead
// of a hand-maintained parallel layout, so the preview can never drift from
// what the printer really produces.
export function receiptToPlainText(bytes) {
  let out = '';
  for (let i = 0; i < bytes.length; i++) {
    const b = bytes[i];
    if (b === ESC || b === GS) {
      const next = bytes[i + 1];
      if (b === ESC && next === 0x40) { i += 1; continue; } // ESC @
      if (b === ESC && next === 0x61) { i += 2; continue; } // ESC a n
      if (b === ESC && next === 0x45) { i += 2; continue; } // ESC E n
      if (b === GS && next === 0x56) { i += 2; continue; } // GS V n
      continue;
    }
    out += String.fromCharCode(b);
  }
  return out;
}

const BASE64_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

// RN's JS engine doesn't reliably expose btoa/Buffer for binary data, so this
// is a small dependency-free base64 encoder for the raw ESC/POS byte array.
export function bytesToBase64(bytes) {
  let result = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const b1 = bytes[i];
    const b2 = i + 1 < bytes.length ? bytes[i + 1] : undefined;
    const b3 = i + 2 < bytes.length ? bytes[i + 2] : undefined;

    result += BASE64_CHARS[b1 >> 2];
    result += BASE64_CHARS[((b1 & 0x03) << 4) | (b2 === undefined ? 0 : b2 >> 4)];
    result += b2 === undefined ? '=' : BASE64_CHARS[((b2 & 0x0f) << 2) | (b3 === undefined ? 0 : b3 >> 6)];
    result += b3 === undefined ? '=' : BASE64_CHARS[b3 & 0x3f];
  }
  return result;
}

export async function printToNetworkPrinter({ ip, port, bytes }) {
  if (!ip) throw new Error('No printer configured — set the printer IP in Settings first');
  const res = await apiPostJson('/api/print_network.php', {
    ip,
    port: port ? Number(port) : 9100,
    data: bytesToBase64(bytes),
  });
  if (!res.success) throw new Error(res.message || 'Print failed');
  return res;
}

// Opens and immediately closes the TCP socket — no data sent — so the UI can
// show a real "Connected" state before committing to a print.
export async function testNetworkPrinter({ ip, port }) {
  if (!ip) throw new Error('Enter the printer IP first');
  const res = await apiPostJson('/api/print_network.php', {
    ip,
    port: port ? Number(port) : 9100,
    test: true,
  });
  if (!res.success) throw new Error(res.message || 'Could not connect to printer');
  return res;
}
