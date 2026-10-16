// "Open now · till 11:00 PM" / "Closed · opens 8:00 AM" from the restaurant's business hours
// (users.opening_hours: { monday: { open, opening: '08:00 AM', closing: '11:00 PM' }, … }).
// Returns null when no hours are set.
const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

function minutes(t) {
  const m = String(t || '').match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
  if (!m) return null;
  let h = Number(m[1]) % 12;
  if (m[3].toUpperCase() === 'PM') h += 12;
  return h * 60 + Number(m[2]);
}

const pretty = (t) => String(t || '').replace(/^0(\d)/, '$1');

export function openStatus(rawHours, now = new Date()) {
  let hours = rawHours;
  if (typeof hours === 'string') {
    try {
      hours = JSON.parse(hours);
    } catch (e) {
      return null;
    }
  }
  if (!hours || typeof hours !== 'object') return null;

  const nowMin = now.getHours() * 60 + now.getMinutes();
  const dayIdx = now.getDay();
  const span = (d) => {
    const h = hours[DAYS[(d + 7) % 7]];
    if (!h || !h.open) return null;
    const from = minutes(h.opening);
    let to = minutes(h.closing);
    if (from === null || to === null) return null;
    if (to <= from) to += 24 * 60; // closes after midnight
    return { from, to, opening: h.opening, closing: h.closing };
  };

  const today = span(dayIdx);
  if (today && nowMin >= today.from && nowMin < today.to) return { open: true, label: `Open now · till ${pretty(today.closing)}` };
  // still open from yesterday, past midnight
  const yday = span(dayIdx - 1);
  if (yday && yday.to > 24 * 60 && nowMin < yday.to - 24 * 60) return { open: true, label: `Open now · till ${pretty(yday.closing)}` };
  if (today && nowMin < today.from) return { open: false, label: `Closed · opens ${pretty(today.opening)}` };
  for (let i = 1; i <= 7; i++) {
    const s = span(dayIdx + i);
    if (s) return { open: false, label: `Closed · opens ${i === 1 ? 'tomorrow' : DAYS[(dayIdx + i) % 7].charAt(0).toUpperCase() + DAYS[(dayIdx + i) % 7].slice(1, 3)} ${pretty(s.opening)}` };
  }
  return { open: false, label: 'Closed' };
}
