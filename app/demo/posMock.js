/*
 * Browser demo: answers for the POS's customer, add-ons, combos, held orders and open bills
 * (api/pos_customer.php search + create, api/get_addons.php, api/combos_admin.php?view=pos,
 * controllers/pos_operations.php list_held / resume_held / discard_held, and KOT → Pay on
 * the same open bill with points earned). All in memory, like the rest of demo/mockApi.js.
 */
import { loyaltyMock } from './loyaltyMock';

const ADDONS = [
  { id: 901, addon_name: 'Extra cheese', addon_price: '40.00' },
  { id: 902, addon_name: 'Butter on top', addon_price: '25.00' },
  { id: 903, addon_name: 'Extra raita', addon_price: '30.00' },
  { id: 904, addon_name: 'Green salad', addon_price: '50.00' },
];

function combosFor(db) {
  const pick = (re) => db.menuItems.filter((m) => re.test(m.item_name_en || '')).slice(0, 3);
  const mains = pick(/paneer|chicken|biryani|dal/i);
  const breads = pick(/naan|roti|paratha|kulcha/i);
  const sweets = pick(/gulab|kulfi|lassi|halwa|jamun/i);
  const ch = (list, extra = 0) => list.map((m, i) => ({ id: Number(m.id), name: m.item_name_en, variation: null, extra: i === 0 ? 0 : extra, image: m.item_image || '' }));
  const slots = [];
  if (mains.length) slots.push({ id: 1, title: 'Main course', qty: 1, choices: ch(mains, 30) });
  if (breads.length) slots.push({ id: 2, title: 'Bread', qty: 2, choices: ch(breads) });
  if (sweets.length) slots.push({ id: 3, title: 'Dessert', qty: 1, choices: ch(sweets, 20) });
  if (!slots.length) return [];
  return [{ id: 77, name: 'Lunch thali deal', price: 349, worth: 449, save: 100, image: '', ends_at: null, slots }];
}

const ok = (extra = {}) => ({ success: true, ...extra });

// newOrder: mockApi's own order builder, so held orders and open bills look like every other order
export function posMock(path, method, q, f, db, newOrder) {
  if (path === '/api/get_addons.php') return ok({ data: ADDONS, count: ADDONS.length });
  if (path === '/api/combos_admin.php') return ok({ combos: combosFor(db) });

  if (path === '/api/pos_customer.php') {
    if (method === 'GET' && q.q !== undefined) {
      const s = String(q.q || '').trim().toLowerCase();
      if (s.length < 2) return ok({ customers: [] });
      const digits = s.replace(/\D/g, '');
      const list = db.customers
        .filter((c) => (c.customer_name || '').toLowerCase().includes(s) || (digits.length >= 3 && String(c.phone || '').includes(digits)))
        .slice(0, 8)
        .map((c) => ({ id: Number(c.id), name: c.customer_name, phone: c.phone, email: c.email || '', points: Number(c.loyalty_points_balance) || 0, visits: Number(c.total_visits) || 0, spent: Number(c.total_spent) || 0, has_account: !!c.has_account }));
      return ok({ customers: list });
    }
    if (method === 'POST' && f.action === 'create') {
      const phone = String(f.phone || '').replace(/\D/g, '');
      if (!String(f.name || '').trim() || phone.length < 6) return { success: false, message: 'Enter a name and a phone number.' };
      let c = db.customers.find((x) => String(x.phone) === phone);
      const existed = !!c;
      if (!c) {
        c = { id: Math.max(0, ...db.customers.map((x) => Number(x.id))) + 1, customer_name: String(f.name).trim(), phone, email: f.email || '', birthday: f.birthday || null, total_visits: 0, total_spent: 0, loyalty_points_balance: 0, stamps: 0, has_account: false };
        db.customers.unshift(c);
      }
      return ok({ existed, customer: loyaltyMock('/api/pos_customer.php', 'GET', { id: String(c.id) }, {}, db).customer });
    }
    return null;   // ?id= is answered by loyaltyMock
  }

  if (path !== '/controllers/pos_operations.php') return null;

  if (f.action === 'list_held') {
    const held = db.orders.filter((o) => o.notes === 'Order on hold' && !o.deleted_at);
    return ok({ orders: held.map((o) => ({ id: o.id, order_number: o.order_number, table_id: o.table_id, table_number: (o.table_name || '').split(' - ')[0] || null, total: o.total, created_at: o.created_at, item_count: (o.items || []).reduce((s, i) => s + Number(i.quantity), 0) })) });
  }
  if (f.action === 'resume_held' || f.action === 'discard_held') {
    const o = db.orders.find((x) => String(x.id) === String(f.orderId) && x.notes === 'Order on hold' && !x.deleted_at);
    if (!o) return { success: false, message: 'This held order was already resumed or discarded.' };
    o.deleted_at = new Date().toISOString();
    db.orders = db.orders.filter((x) => x !== o);
    if (f.action === 'discard_held') return ok();
    const items = (o.items || []).map((i) => {
      const m = db.menuItems.find((x) => x.item_name_en === i.item_name);
      return { menu_item_id: m ? Number(m.id) : null, item_name: i.item_name, variation_name: i.variation_name, quantity: i.quantity, unit_price: i.unit_price, addons: [] };
    });
    return ok({ order_number: o.order_number, table_id: o.table_id, items, customer_id: o._customerId || null });
  }

  if (f.action === 'hold_order') {
    const res = newOrder(f, 'hold');
    const o = db.orders.find((x) => x.id === res.order_id);
    if (o && f.customerId) o._customerId = Number(f.customerId);
    return res;
  }

  if (f.action === 'create_kot') {
    const due = f.paymentMethod === 'Due' || f.kot_only === '1';
    const open = f.openOrderId ? db.orders.find((x) => String(x.id) === String(f.openOrderId)) : null;
    let res;
    if (open) {
      // the open bill: later KOTs add to it, Pay settles it (never a second order)
      Object.assign(open, { subtotal: Number(f.subtotal).toFixed(2), tax: Number(f.tax).toFixed(2), total: Number(f.total).toFixed(2) });
      if (!due) Object.assign(open, { payment_status: 'Paid', payment_method: f.paymentMethod, order_status: 'Ready' });
      const fresh = JSON.parse(f.kitchenItems || '[]');
      res = { success: true, order_id: open.id, order_number: open.order_number, kot_number: fresh.length ? `KOT-${Math.floor(1000 + Math.random() * 8999)}` : null };
    } else {
      res = newOrder({ ...f, kot_only: due ? '1' : '' }, due ? 'kot' : 'pay');
    }
    // points for a paid bill with a customer: 1 per ₹100, minus any used
    if (!due && f.customerId) {
      const c = db.customers.find((x) => String(x.id) === String(f.customerId));
      if (c) {
        const earned = Math.floor((Number(f.total) || 0) / 100);
        c.loyalty_points_balance = Math.max(0, (Number(c.loyalty_points_balance) || 0) - (Number(f.redeemPoints) || 0)) + earned;
        c.total_visits = (Number(c.total_visits) || 0) + 1;
        c.total_spent = (Number(c.total_spent) || 0) + (Number(f.total) || 0);
        const customer = loyaltyMock('/api/pos_customer.php', 'GET', { id: String(c.id) }, {}, db).customer;
        res.loyalty = { earned: { points: earned, stamp: false, reward: null, cashback: 0 }, redeemed_points: Number(f.redeemPoints) || 0, customer };
      }
    }
    return res;
  }
  return null;
}
