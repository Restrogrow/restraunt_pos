// Small colour helpers so one brand colour produces the whole palette.
function toRgb(hex) {
  let h = String(hex || '').replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const n = parseInt(h, 16);
  if (Number.isNaN(n) || h.length !== 6) return [228, 87, 46];
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function toHex(r, g, b) {
  return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
}

// t = 0 gives a, t = 1 gives b
export function mix(a, b, t) {
  const x = toRgb(a);
  const y = toRgb(b);
  return toHex(x[0] + (y[0] - x[0]) * t, x[1] + (y[1] - x[1]) * t, x[2] + (y[2] - x[2]) * t);
}

export const darken = (hex, t) => mix(hex, '#000000', t);
export const lighten = (hex, t) => mix(hex, '#ffffff', t);

export function rgba(hex, alpha) {
  const [r, g, b] = toRgb(hex);
  return `rgba(${r},${g},${b},${alpha})`;
}

// White or dark text, whichever reads better on this colour
export function readableOn(hex) {
  const [r, g, b] = toRgb(hex);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.68 ? '#1D1B26' : '#FFFFFF';
}

export function isHex(v) {
  return /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(v || ''));
}
