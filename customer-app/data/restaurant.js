// Everything the app shows about the restaurant's food. Today this is the RestroGrow demo menu
// (Spice Garden Indiranagar, bundled so the template works with no server). When a restaurant is
// connected, replace this file's exports with data fetched from their RestroGrow account.
import menu from './menu.json';
import IMAGES from './images';

const stripExt = (f) => String(f || '').replace(/\.[^.]+$/, '');
const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

// stable, believable star rating for each dish (the live app reads real reviews)
function ratingOf(id) {
  const stars = 40 + ((id * 7) % 9); // 4.0 - 4.8
  return { rating: stars / 10, ratingCount: 40 + ((id * 53) % 420) };
}

export const CATEGORIES = [...menu]
  .sort((a, b) => a.sort - b.sort)
  .map((c) => ({
    key: slug(c.name),
    name: c.name,
    image: IMAGES[stripExt(c.photo)],
    hours: c.hours,
    subs: c.subs,
    items: [],
  }));

export const ITEMS = [];
menu.forEach((c) => {
  const cat = CATEGORIES.find((x) => x.name === c.name);
  c.items.forEach((i) => {
    const sizes = Object.entries(i.sizes || {}).map(([name, price]) => ({ name, price }));
    const item = {
      id: i.id,
      name: i.name,
      desc: i.desc,
      kind: i.type === 'Veg' ? 'veg' : i.type === 'Drink' ? 'drink' : i.type === 'Egg' ? 'egg' : 'nonveg',
      price: sizes.length ? sizes[0].price : i.price,
      sizes,
      calories: i.cal,
      prep: i.prep,
      image: IMAGES[i.photo],
      category: c.name,
      categoryKey: cat.key,
      sub: i.sub,
      ...ratingOf(i.id),
    };
    cat.items.push(item);
    ITEMS.push(item);
  });
});

export const itemById = (id) => ITEMS.find((i) => i.id === id);
export const isVegetarian = (item) => item.kind === 'veg' || item.kind === 'drink';

const byName = (n) => ITEMS.find((i) => i.name === n);
const pick = (names) => names.map(byName).filter(Boolean);

// "Customer favourites" - some carry an offer price
export const BESTSELLERS = [
  ['Butter Chicken', 315],
  ['Paneer Tikka', 224],
  ['Chicken Dum Biryani', 289],
  ['Dal Makhani', 206],
  ['Masala Dosa', null],
  ['Mutton Rogan Josh', null],
  ['Gulab Jamun', null],
  ['Chicken Tikka', null],
]
  .map(([n, offer]) => {
    const it = byName(n);
    return it ? { ...it, offerPrice: offer, wasPrice: offer ? it.price : null } : null;
  })
  .filter(Boolean);

export const SPECIALS = [
  { ...byName('Kerala Fish Curry'), note: 'Fresh seer fish today' },
  { ...byName('Gajar Halwa'), note: 'Made this morning' },
  { ...byName('Chicken Tikka Masala'), note: "Chef's pick" },
];

export const PAIRS_WITH = pick(['Butter Naan', 'Garlic Naan', 'Sweet Lassi', 'Gulab Jamun', 'Masala Chaas', 'Jeera Rice']);

export const COUPONS = [
  { code: 'WELCOME50', type: 'flat', value: 50, min: 299, text: 'Flat ₹50 off your first order', hint: 'On orders above ₹299' },
  { code: 'SPICE20', type: 'percent', value: 20, max: 200, min: 599, text: '20% off, up to ₹200', hint: 'On orders above ₹599' },
  { code: 'FEAST100', type: 'flat', value: 100, min: 999, text: '₹100 off a feast', hint: 'On orders above ₹999' },
  { code: 'WEEKEND15', type: 'percent', value: 15, max: 150, min: 399, text: '15% off every weekend', hint: 'Sat & Sun, above ₹399', weekend: true },
];

export const BANNERS = [
  { id: 'b1', title: 'Flat ₹50 off', sub: 'on your first order. Use code', code: 'WELCOME50', image: IMAGES['hero'] },
  { id: 'b2', title: 'Biryani feast', sub: 'Family pack serves 3. Free delivery above ₹499', code: null, image: IMAGES['chicken-biryani'] },
  { id: 'b3', title: '20% off ₹599+', sub: 'Dinner with the family? Use code', code: 'SPICE20', image: IMAGES['butter-chicken'] },
];

export const SAMPLE_ADDRESS = {
  id: 'a1',
  label: 'Home',
  line: '24, 12th Main Road, HAL 2nd Stage',
  area: 'Indiranagar, Bengaluru 560038',
};

export const HERO_IMAGE = IMAGES['hero'];
export { IMAGES };

// Menus can have serving hours (breakfast, lunch thali). Minutes since midnight.
const toMin = (hhmm) => {
  const [h, m] = hhmm.split(':').map(Number);
  return h * 60 + m;
};
export function categoryOpenNow(cat, now = new Date()) {
  if (!cat.hours) return true;
  const mins = now.getHours() * 60 + now.getMinutes();
  return mins >= toMin(cat.hours.from) && mins < toMin(cat.hours.to);
}
export function to12h(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  const suffix = h >= 12 ? 'PM' : 'AM';
  const hh = h % 12 === 0 ? 12 : h % 12;
  return `${hh}${m ? ':' + String(m).padStart(2, '0') : ''} ${suffix}`;
}
export const hoursLabel = (cat) => (cat.hours ? `${to12h(cat.hours.from)} - ${to12h(cat.hours.to)}` : '');

export function restaurantOpenNow(brand, now = new Date()) {
  const mins = now.getHours() * 60 + now.getMinutes();
  return mins >= toMin(brand.hours.open) && mins < toMin(brand.hours.close);
}
