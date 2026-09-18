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

function textToBytes(str) {
  // ASCII-safe conversion — thermal printers default to a single-byte
  // codepage, and item names here are the English (item_name_en) fields, so
  // this covers the common case without pulling in a full codepage table.
  const bytes = [];
  for (let i = 0; i < str.length; i++) {
    bytes.push(str.charCodeAt(i) & 0xff);
  }
  return bytes;
}

// Thermal printers use a single-byte codepage and can't render most Unicode
// currency symbols — naively truncating one to its low byte (textToBytes
// above) prints a garbled/wrong glyph, not the intended symbol. Map the
// common non-ASCII ones to a safe ASCII stand-in; anything already ASCII
// (like '$') passes straight through.
function printSafeCurrency(currency) {
  const map = { '₹': 'Rs.', '€': 'EUR ', '£': 'GBP ', '¥': 'JPY ', '₩': 'KRW ', '₨': 'Rs.' };
  if (map[currency]) return map[currency];
  return /^[\x00-\x7F]*$/.test(currency || '') ? currency : 'Rs.';
}

function line(str = '') {
  return [...textToBytes(str), 0x0a];
}

function padLine(left, right, width) {
  const space = Math.max(1, width - left.length - right.length);
  return left + ' '.repeat(space) + right;
}

function wrapText(str, width) {
  if (str.length <= width) return [str];
  const words = str.split(' ');
  const lines = [];
  let cur = '';
  for (const w of words) {
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

// items: [{ name, quantity, price, variationName? }]
export function buildReceiptEscPos({
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
  bytes.push(...line(restaurantName || 'Receipt'));
  bytes.push(...CMD.BOLD_OFF);
  if (kotNumber) bytes.push(...line(kotNumber));
  bytes.push(...line(new Date().toLocaleString()));
  bytes.push(...CMD.ALIGN_LEFT);
  bytes.push(...line('-'.repeat(width)));
  bytes.push(...line(`${orderType}${tableName ? ' - ' + tableName : ''}`));
  bytes.push(...line('-'.repeat(width)));

  (items || []).forEach((it) => {
    const label = it.variationName ? `${it.name} (${it.variationName})` : it.name;
    wrapText(label, width).forEach((l) => bytes.push(...line(l)));
    const qtyPrice = `${it.quantity} x ${currency}${Number(it.price).toFixed(2)}`;
    const lineTotal = `${currency}${(Number(it.price) * Number(it.quantity)).toFixed(2)}`;
    bytes.push(...line(padLine(qtyPrice, lineTotal, width)));
  });

  bytes.push(...line('-'.repeat(width)));
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
  bytes.push(...line('-'.repeat(width)));
  bytes.push(...CMD.ALIGN_CENTER);
  bytes.push(...line('Thank you!'));
  bytes.push(...line(''));
  bytes.push(...line(''));
  bytes.push(...CMD.CUT);

  return new Uint8Array(bytes);
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
