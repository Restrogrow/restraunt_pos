// ───────────────────────────────────────────────────────────────────────────────────────────
// THIS IS THE FILE A RESTAURANT CHANGES TO MAKE THE APP THEIRS.
//
// Pick a preset below (ACTIVE_PRESET) or edit it: name, colour, address, hours, fees, tax.
// The whole app (colours, logo mark, copy) is generated from this one object. The menu comes
// from data/ (the demo menu today, the restaurant's own menu once it is connected).
//
// In the browser demo the page can override the look with ?preset=leaf, ?name=My Cafe and
// ?color=%237048E8, which is how the "make it yours" panel on /customer-app-demo works.
// ───────────────────────────────────────────────────────────────────────────────────────────
import { Platform } from 'react-native';
import { darken, isHex, lighten, readableOn, rgba } from '../utils/color';

export const ACTIVE_PRESET = process.env.EXPO_PUBLIC_PRESET || 'spice';

const COMMON = {
  currency: '₹',
  cuisine: 'North Indian · Tandoor · Biryani · South Indian',
  address: '12, 100 Feet Road, HAL 2nd Stage, Indiranagar, Bengaluru 560038',
  area: 'Indiranagar, Bengaluru',
  phone: '+91 98000 00002',
  email: 'hello@spicegarden.example',
  rating: 4.5,
  ratingCount: 1284,
  hours: { open: '08:00', close: '23:00', label: '8:00 AM – 11:00 PM' },
  etaMinutes: [30, 40],
  deliveryFee: 35,
  freeDeliveryAbove: 499,
  packagingFee: 15,
  taxName: 'GST',
  taxPercent: 5,
  minOrder: 0,
  upiId: 'spicegarden@upi',
  tables: 15,
  instagram: '@spicegarden.in',
  website: 'restrogrow.com/spice-garden-indiranagar',
  loyaltyName: 'Spice Points',
  pointsPerRupee: 0.1, // 1 point for every ₹10
};

export const PRESETS = {
  spice: { ...COMMON, name: 'Spice Garden', tagline: 'Cooked to order since 2014', primary: '#E4572E' },
  leaf: {
    ...COMMON,
    name: 'Green Leaf Kitchen',
    tagline: 'Fresh, local and made today',
    primary: '#2F9E5B',
    loyaltyName: 'Leaf Points',
    instagram: '@greenleaf.kitchen',
  },
  royal: {
    ...COMMON,
    name: 'Royal Brew Café',
    tagline: 'Coffee, bakes and slow brunches',
    primary: '#7048E8',
    loyaltyName: 'Brew Points',
    instagram: '@royalbrew.cafe',
  },
  harbour: {
    ...COMMON,
    name: 'Blue Harbour',
    tagline: 'Coastal kitchen and seafood',
    primary: '#1C7ED6',
    loyaltyName: 'Harbour Points',
    instagram: '@blueharbour.in',
  },
};

function fromQuery() {
  if (Platform.OS !== 'web' || typeof window === 'undefined' || !window.location) return {};
  try {
    const q = new URLSearchParams(window.location.search);
    const out = {};
    if (q.get('preset')) out.preset = q.get('preset');
    if (q.get('name')) out.name = q.get('name').slice(0, 32);
    if (q.get('color') && isHex(q.get('color'))) out.primary = '#' + q.get('color').replace('#', '');
    if (q.get('tagline')) out.tagline = q.get('tagline').slice(0, 48);
    return out;
  } catch (e) {
    return {};
  }
}

const query = fromQuery();
const base = PRESETS[query.preset] || PRESETS[ACTIVE_PRESET] || PRESETS.spice;
const merged = { ...base };
if (query.name) merged.name = query.name;
if (query.primary) merged.primary = query.primary;
if (query.tagline) merged.tagline = query.tagline;

export const BRAND = {
  ...merged,
  initials: merged.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join(''),
  primaryDark: darken(merged.primary, 0.18),
  primaryLight: lighten(merged.primary, 0.9),
  primarySoft: rgba(merged.primary, 0.12),
  onPrimary: readableOn(merged.primary),
};

// true in the browser demo build (EXPO_PUBLIC_DEMO=1): shows the "template" hints and uses fake
// payments / OTP. A restaurant's real build leaves this off.
export const DEMO = process.env.EXPO_PUBLIC_DEMO === '1' || Platform.OS === 'web';
