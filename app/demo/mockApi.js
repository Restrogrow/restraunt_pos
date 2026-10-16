/*
 * Browser demo of the app (restrogrow.com/app-demo): a stand-in for the server that runs
 * entirely in the visitor's browser. Built only when EXPO_PUBLIC_DEMO=1 (see config/api.js).
 *
 * Starts from a snapshot of a sample restaurant (fixtures.json — seeded test data, secrets and
 * personal fields removed), shifted so its latest order is a few minutes old. Every action a
 * visitor takes (accept, cook, pay, sold out, bookings...) changes this in-memory copy only;
 * nothing reaches a real restaurant. A new website order and a waiter call arrive by themselves
 * shortly after logging in, so the alert flow can be seen.
 */
import fixtures from './fixtures.json';

const clone = (x) => JSON.parse(JSON.stringify(x));
const pad = (n) => String(n).padStart(2, '0');
const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
const dayKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = (s) => new Date(String(s).replace(' ', 'T'));
const DATE_TIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const LIVE = ['Scheduled', 'Pending', 'Accepted', 'Preparing', 'Ready', 'Served'];
const NOT_SALES = ['Cancelled', 'Rejected'];

// ── build the in-memory restaurant ──
function shiftDates(obj, ms, days) {
  if (Array.isArray(obj)) return obj.forEach((x) => shiftDates(x, ms, days));
  if (!obj || typeof obj !== 'object') return;
  for (const k of Object.keys(obj)) {
    const v = obj[k];
    if (typeof v === 'string' && DATE_TIME.test(v)) obj[k] = fmt(new Date(parse(v).getTime() + ms));
    // visit / booking dates move with the data; birthdays and anniversaries stay put
    else if (typeof v === 'string' && DATE_ONLY.test(v) && /(date|day)$/i.test(k) && !/birthday|anniversary/i.test(k)) {
      const d = parse(`${v} 12:00:00`);
      d.setDate(d.getDate() + days);
      obj[k] = dayKey(d);
    } else if (v && typeof v === 'object') shiftDates(v, ms, days);
  }
}

function imageBase() {
  if (typeof window === 'undefined') return '';
  // dev server (expo start) — photos come from the local XAMPP site
  if (window.location.port === '8081') return 'http://localhost/menuwebsite/main/assets/demo_restaurant/';
  // exported demo lives at <site>/app-demo/app/ — photos at <site>/main/assets/demo_restaurant/
  return new URL('../../main/assets/demo_restaurant/', window.location.href).href;
}

const db = (() => {
  const f = clone(fixtures);
  const latest = Math.max(...f.orders.map((o) => parse(o.created_at).getTime()));
  const ms = Date.now() - 12 * 60000 - latest; // latest sample order: 12 minutes ago
  const days = Math.round(ms / 86400000);
  shiftDates(f, ms, days);
  const base = imageBase();
  f.menuItems.forEach((it) => {
    if (String(it.item_image || '').startsWith('demoimg:')) it.item_image = base + it.item_image.slice(8);
  });
  // full order rows: list fields + detail fields
  const orders = f.orders.map((o) => ({ ...(f.orderDetails[o.id] || {}), ...o, items: o.items || f.orderDetails[o.id]?.items || [] }));
  const now = new Date();
  const at = (minAgo) => fmt(new Date(now.getTime() - minAgo * 60000));
  const today = dayKey(now);
  const tomorrow = dayKey(new Date(now.getTime() + 86400000));
  return {
    session: f.session.data,
    orders,
    menuItems: f.menuItems,
    menus: f.menus,
    tables: f.tables,
    tableStatus: f.tableStatus,
    paymentMethods: f.paymentMethods,
    coupons: f.coupons,
    adminCoupons: f.adminCoupons,
    kots: f.kots,
    customers: f.customers,
    loginLogs: f.loginLogs,
    calls: [],
    reservations: [
      { id: 1, table_id: f.tables[1]?.id, table_number: f.tables[1]?.table_number, area_name: f.tables[1]?.area_name, reservation_date: today, time_slot: '7:30 PM', no_of_guests: 4, meal_type: 'Dinner', customer_name: 'Ananya Reddy', phone: '9900000003', special_request: 'Window seat, birthday', status: 'Confirmed' },
      { id: 2, table_id: null, reservation_date: today, time_slot: '8:30 PM', no_of_guests: 2, meal_type: 'Dinner', customer_name: 'Kabir Mehta', phone: '9900000004', special_request: '', status: 'Pending' },
      { id: 3, table_id: f.tables[4]?.id, table_number: f.tables[4]?.table_number, area_name: f.tables[4]?.area_name, reservation_date: tomorrow, time_slot: '1:00 PM', no_of_guests: 6, meal_type: 'Lunch', customer_name: 'Diya Patel', phone: '9900000001', special_request: 'High chair please', status: 'Pending' },
    ],
    nextId: Math.max(...orders.map((o) => Number(o.id))) + 1,
    nextKot: Math.max(0, ...f.kots.map((k) => Number(k.id))) + 1,
    at,
  };
})();

let loggedIn = false;
let simulated = false;

function counts(o) {
  return !NOT_SALES.includes(o.order_status) && !o.deleted_at && !(o.notes === 'Order on hold' && o.order_status === 'Pending' && o.payment_status === 'Pending');
}

function inRange(o, start, end) {
  const k = String(o.created_at).slice(0, 10);
  return k >= start && k <= end;
}

// A website order and a waiter call arrive on their own, so the alert flow can be seen.
function simulateLife() {
  if (simulated) return;
  simulated = true;
  setTimeout(() => {
    const pick = (name) => db.menuItems.find((m) => m.item_name_en === name) || db.menuItems[0];
    const lines = [
      [pick('Butter Chicken'), 1],
      [pick('Garlic Naan'), 3],
      [pick('Sweet Lassi'), 2],
    ];
    const items = lines.map(([m, q]) => ({ item_name: m.item_name_en, quantity: q, unit_price: m.base_price, total_price: (Number(m.base_price) * q).toFixed(2), item_type: m.item_type, variation_name: null, notes: null }));
    const subtotal = items.reduce((s, i) => s + Number(i.total_price), 0);
    const tax = Math.round(subtotal * 5) / 100;
    db.orders.unshift({
      id: db.nextId++,
      order_number: `ORD-${dayKey(new Date()).replace(/-/g, '')}-${Math.floor(1000 + Math.random() * 8999)}`,
      order_type: 'Delivery',
      order_status: 'Pending',
      payment_status: 'Pending',
      payment_method: 'Cash on Delivery',
      source: 'website',
      customer_name: 'Riya Sharma',
      customer_phone: '9000000000',
      customer_address: '12, 4th Cross, Indiranagar, Bengaluru',
      notes: 'Less spicy please',
      subtotal: subtotal.toFixed(2),
      tax: tax.toFixed(2),
      total: (subtotal + tax).toFixed(2),
      created_at: fmt(new Date()),
      items,
      table_name: null,
    });
  }, 25000);
  setTimeout(() => {
    const t = db.tables[2] || db.tables[0];
    if (t) db.calls.push({ id: 900 + db.calls.length, table_id: t.id, table_number: t.table_number, area_name: t.area_name || '', request_type: 'Bill', notes: '', status: 'Pending', created_at: fmt(new Date()), minutes_ago: 0 });
  }, 55000);
}

// ── reads ──
function getOrders(q) {
  let list = db.orders;
  if (q.include_deleted === '1') list = list.filter((o) => o.deleted_at);
  else list = list.filter((o) => !o.deleted_at);
  if (q.active === '1') list = list.filter((o) => LIVE.includes(o.order_status));
  else if (q.any_date !== '1' && q.status !== 'Scheduled' && q.include_deleted !== '1') {
    const d = q.date || dayKey(new Date());
    list = list.filter((o) => String(o.created_at).slice(0, 10) === d);
  }
  if (q.status) list = list.filter((o) => o.order_status === q.status);
  if (q.search) {
    const s = q.search.toLowerCase();
    list = list.filter((o) => [o.order_number, o.customer_name, o.customer_phone, o.table_name].some((v) => String(v || '').toLowerCase().includes(s)));
  }
  list = [...list].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at))).slice(0, Number(q.limit) || 50);
  return { success: true, orders: clone(list), count: list.length, total_count: list.length, deleted_count: db.orders.filter((o) => o.deleted_at).length };
}

function salesReport(q) {
  const t = dayKey(new Date());
  const start = q.period === 'custom' ? q.start_date : t;
  const end = q.period === 'custom' ? q.end_date : t;
  const list = db.orders.filter((o) => counts(o) && inRange(o, start, end));
  const sum = (a) => a.reduce((s, o) => s + (Number(o.total) || 0), 0);
  const items = new Map();
  const pays = new Map();
  const cust = new Map();
  const days = new Map();
  const hours = new Map();
  let itemCount = 0;
  list.forEach((o) => {
    (o.items || []).forEach((i) => {
      const r = items.get(i.item_name) || { item_name: i.item_name, total_quantity: 0, total_revenue: 0 };
      r.total_quantity += Number(i.quantity) || 0;
      r.total_revenue += Number(i.total_price) || 0;
      items.set(i.item_name, r);
      itemCount += Number(i.quantity) || 0;
    });
    const p = pays.get(o.payment_method) || { payment_method: o.payment_method, count: 0, amount: 0 };
    p.count += 1;
    p.amount += Number(o.total) || 0;
    pays.set(o.payment_method, p);
    const name = ['Takeaway', 'Table Customer', ''].includes(o.customer_name || '') ? null : o.customer_name;
    if (name) {
      const c = cust.get(name) || { customer_name: name, phone: o.customer_phone || '', total_orders: 0, total_spent: 0, last_order_date: o.created_at };
      c.total_orders += 1;
      c.total_spent += Number(o.total) || 0;
      cust.set(name, c);
    }
    const d = String(o.created_at).slice(0, 10);
    const dr = days.get(d) || { day: d, order_count: 0, total_sales: 0 };
    dr.order_count += 1;
    dr.total_sales += Number(o.total) || 0;
    days.set(d, dr);
    const h = parse(o.created_at).getHours();
    const hr = hours.get(h) || { hour: h, order_count: 0, total_sales: 0 };
    hr.order_count += 1;
    hr.total_sales += Number(o.total) || 0;
    hours.set(h, hr);
  });
  const sales = sum(list);
  return {
    success: true,
    summary: { total_sales: sales, total_orders: list.length, total_items: itemCount, total_customers: cust.size, total_expenses: 0, net_profit: sales },
    sales_details: list.slice(0, 100).map((o) => ({ id: o.id, order_number: o.order_number, customer_name: o.customer_name, payment_method: o.payment_method, total: o.total, created_at: o.created_at })),
    daily_sales: [...days.values()].sort((a, b) => a.day.localeCompare(b.day)),
    hourly_all: [...hours.values()].sort((a, b) => a.hour - b.hour),
    hourly_sales: q.period === 'today' ? [...hours.values()] : [],
    top_items: [...items.values()].sort((a, b) => b.total_quantity - a.total_quantity).slice(0, 10),
    payment_methods: [...pays.values()],
    top_customers: [...cust.values()].sort((a, b) => b.total_spent - a.total_spent).slice(0, 10),
    staff_performance: [],
  };
}

function dashboard() {
  const now = new Date();
  const t = dayKey(now);
  const y = dayKey(new Date(now.getTime() - 86400000));
  const stat = (d, cutoff) => {
    const l = db.orders.filter((o) => counts(o) && String(o.created_at).slice(0, 10) === d && (!cutoff || parse(o.created_at).getHours() * 60 + parse(o.created_at).getMinutes() <= cutoff));
    const sales = l.reduce((s, o) => s + (Number(o.total) || 0), 0);
    return { sales, orders: l.length, avg: l.length ? sales / l.length : 0 };
  };
  const nowMin = now.getHours() * 60 + now.getMinutes();
  const daysArr = Array.from({ length: 14 }, (_, i) => {
    const d = dayKey(new Date(now.getTime() - (13 - i) * 86400000));
    const s = stat(d);
    return { date: d, sales: Math.round(s.sales * 100) / 100, orders: s.orders };
  });
  const live = db.orders.filter((o) => !o.deleted_at && LIVE.includes(o.order_status)).sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
  const activeKots = db.kots.filter((k) => ['Pending', 'Preparing', 'Ready'].includes(k.kot_status));
  const stale = activeKots.filter((k) => Date.now() - parse(k.created_at).getTime() > 6 * 3600000).length;
  const top = salesReport({ period: 'today' }).top_items.slice(0, 5).map((i) => ({ name: i.item_name, qty: i.total_quantity, sales: i.total_revenue }));
  return {
    success: true,
    currency: db.session.currency_symbol || '₹',
    restaurant: db.session.restaurant_name,
    now: now.toISOString(),
    today: stat(t),
    yesterday: stat(y, nowMin),
    days: daysArr,
    kot: activeKots.length,
    stale_kot: stale,
    live: live.slice(0, 8).map((o) => ({ id: o.id, number: o.order_number, type: o.order_type, status: o.order_status, total: Number(o.total), customer: o.customer_name, table: o.table_name, source: o.source, at: o.created_at })),
    live_count: live.length,
    attention: { to_accept: live.filter((o) => o.order_status === 'Pending' && o.source !== 'pos').length, calls: db.calls.length, reservations_pending: db.reservations.filter((r) => r.status === 'Pending').length, catering: 0, stale_kot: stale, low_stock: 0, low_names: [] },
    reservations: [],
    tables: { total: db.tables.length, busy: tableStatus().filter((x) => x.status === 'occupied').length },
    top,
    store: { open: true, hours: null },
  };
}

function tableStatus() {
  return db.tableStatus.map((t) => {
    const k = db.kots.filter((x) => String(x.table_id) === String(t.id) && ['Pending', 'Preparing', 'Ready'].includes(x.kot_status));
    if (!k.length) return { ...t, status: 'free', is_available: true, order_timer: null, kot_info: null };
    const first = Math.min(...k.map((x) => parse(x.created_at).getTime()));
    const min = Math.floor((Date.now() - first) / 60000);
    return {
      ...t,
      status: 'occupied',
      is_available: false,
      order_timer: `${Math.floor(min / 60)}h ${min % 60}m`,
      kot_info: k.map((x) => ({ table_id: t.id, kot_number: x.kot_number, created_at: x.created_at, total: x.total, kot_status: x.kot_status })),
    };
  });
}

// ── writes ──
function newOrderFromCart(f, kind) {
  const table = db.tables.find((t) => String(t.id) === String(f.tableId));
  const cart = JSON.parse(f.cartItems || '[]');
  const items = cart.map((c) => {
    const m = db.menuItems.find((x) => String(x.id) === String(c.id));
    return { item_name: c.name, quantity: c.quantity, unit_price: c.price, total_price: (Number(c.price) * c.quantity).toFixed(2), variation_name: c.variation_name || null, item_type: m?.item_type, notes: null };
  });
  const id = db.nextId++;
  const num = `ORD-${dayKey(new Date()).replace(/-/g, '')}-${Math.floor(1000 + Math.random() * 8999)}`;
  const kotNum = `KOT-${dayKey(new Date()).replace(/-/g, '')}-${Math.floor(1000 + Math.random() * 8999)}`;
  const paid = kind === 'pay';
  const order = {
    id,
    order_number: num,
    order_type: f.orderType,
    order_status: kind === 'hold' ? 'Pending' : paid ? 'Ready' : 'Preparing',
    payment_status: paid ? 'Paid' : 'Pending',
    payment_method: f.paymentMethod || 'Cash',
    source: 'pos',
    customer_name: f.customerName,
    table_id: table?.id || null,
    table_name: table ? `${table.table_number} - ${table.area_name || ''}`.trim() : null,
    subtotal: Number(f.subtotal).toFixed(2),
    tax: Number(f.tax).toFixed(2),
    total: Number(f.total).toFixed(2),
    notes: kind === 'hold' ? 'Order on hold' : `[KOT: ${kotNum}]`,
    created_at: fmt(new Date()),
    items,
  };
  db.orders.unshift(order);
  if (kind !== 'hold') {
    db.kots.unshift({
      id: db.nextKot++,
      kot_number: kotNum,
      table_id: table?.id || null,
      table_number: table?.table_number || null,
      area_name: table?.area_name || null,
      kot_status: 'Pending',
      order_type: f.orderType,
      customer_name: f.customerName,
      created_at: order.created_at,
      total: order.total,
      notes: '',
      items: items.map((i) => ({ item_name: i.item_name, quantity: i.quantity, notes: null, addons: [] })),
      item_count: items.length,
    });
  }
  return { success: true, order_number: num, kot_number: kotNum, order_id: id };
}

const ok = (extra = {}) => ({ success: true, ...extra });
const no = (message) => ({ success: false, message });

function handle(path, method, q, f) {
  // ── auth ──
  if (path === '/admin/get_session.php') return loggedIn ? ok({ data: clone(db.session) }) : no('Not logged in.');
  if (path === '/admin/auth.php') {
    if (f.action === 'login') {
      loggedIn = true;
      simulateLife();
      return ok({ data: clone(db.session) });
    }
    if (f.action === 'logout') {
      loggedIn = false;
      return ok();
    }
    if (f.action === 'toggleOrderType') {
      db.session[f.type === 'dinein' ? 'enable_dinein' : 'enable_delivery'] = Number(f.enabled) ? 1 : 0;
      return ok();
    }
    if (f.action === 'uploadRestaurantLogo') return no('Changing the logo is turned off in the demo.');
    return ok({ message: 'Done (demo)' });
  }
  if (!loggedIn) return no('Session expired. Please login again.');

  // ── restaurant settings: kept in the demo session so changes show up straight away ──
  if (path === '/api/app_settings.php') {
    const S = db.session;
    const keys = ['restaurant_name', 'owner_name', 'description', 'phone', 'email', 'address', 'google_maps_link', 'opening_hours', 'enable_delivery', 'enable_takeaway', 'enable_dinein', 'enable_reservations', 'cod_enabled', 'minimum_order_value', 'packaging_charge', 'delivery_radius_km', 'enable_km_delivery', 'delivery_rate_per_km', 'enable_gst', 'tax_name', 'tax_percent', 'show_business_id', 'business_id_label', 'business_id_no', 'instagram_link', 'facebook_link', 'twitter_link', 'youtube_link', 'linkedin_link'];
    if (method === 'POST') {
      Object.keys(f).forEach((k) => {
        if (!keys.includes(k)) return;
        S[k] = k === 'opening_hours' ? JSON.parse(f[k]) : f[k];
      });
    }
    const out = {};
    keys.forEach((k) => {
      let v = S[k] ?? null;
      if (k === 'opening_hours' && typeof v === 'string') v = v ? JSON.parse(v) : null;
      out[k] = v;
    });
    return ok({ settings: clone(out) });
  }

  // ── reads ──
  if (method === 'GET') {
    switch (path) {
      case '/api/get_orders.php': return getOrders(q);
      case '/api/get_order_details_by_id.php': {
        const o = db.orders.find((x) => String(x.id) === String(q.id));
        return o ? ok({ order: clone(o) }) : no('Order not found');
      }
      case '/api/dashboard_home.php': return dashboard();
      case '/api/get_menu_items.php': return ok({ data: clone(db.menuItems.filter((m) => q.include_hidden === '1' || m.is_hidden != 1)) });
      case '/api/get_menus.php': return ok({ data: clone(db.menus) });
      case '/api/get_tables.php': return ok({ data: clone(db.tables) });
      case '/api/get_table_status.php': return ok({ data: tableStatus() });
      case '/api/get_payment_methods.php': return ok({ data: clone(db.paymentMethods) });
      case '/api/get_coupons.php': return ok({ coupons: clone(db.coupons) });
      case '/api/get_admin_coupons.php': return ok({ coupons: clone(db.adminCoupons) });
      case '/api/get_kot.php': return ok({ kots: clone(db.kots.filter((k) => ['Pending', 'Preparing', 'Ready'].includes(k.kot_status))) });
      case '/api/get_waiter_requests.php': return ok({ requests: db.calls.map((c) => ({ ...c, minutes_ago: Math.floor((Date.now() - parse(c.created_at).getTime()) / 60000) })), total_requests: db.calls.length });
      case '/api/get_reservations.php': return ok({ data: clone(db.reservations.filter((r) => !q.date || r.reservation_date === q.date)) });
      case '/api/get_customers.php': return ok({ data: clone(db.customers) });
      case '/api/get_sales_report.php': return salesReport(q);
      case '/api/get_login_logs.php': return clone(db.loginLogs);
      default: return ok({ data: [] });
    }
  }

  // ── writes ──
  switch (path) {
    case '/api/update_order_status.php': {
      const o = db.orders.find((x) => String(x.id) === String(f.orderId));
      if (!o) return no('Order not found');
      o.order_status = f.status;
      if (f.prep_minutes) {
        o.prep_minutes = Number(f.prep_minutes);
        o.estimated_ready_at = fmt(new Date(Date.now() + o.prep_minutes * 60000));
      }
      if ((f.status === 'Cancelled' || f.status === 'Rejected') && f.reason) {
        o.notes = `${o.notes ? `${o.notes} ` : ''}[${f.status}${f.reason_code ? ` (${f.reason_code})` : ''}: ${f.reason}]`;
      }
      return ok({ message: `Order ${f.status}` });
    }
    case '/api/update_payment_status.php': {
      const o = db.orders.find((x) => String(x.id) === String(f.orderId));
      if (o) o.payment_status = f.status;
      return ok();
    }
    case '/api/delete_order.php': {
      const o = db.orders.find((x) => String(x.id) === String(f.orderId));
      if (!o) return no('Order not found');
      if (o.payment_status === 'Paid') return no('Paid orders cannot be deleted');
      Object.assign(o, { deleted_at: fmt(new Date()), deleted_by: 'demo', delete_reason: f.reason || 'Deleted' });
      return ok();
    }
    case '/controllers/pos_operations.php':
      if (f.action === 'hold_order') return newOrderFromCart(f, 'hold');
      return newOrderFromCart(f, f.kot_only === '1' ? 'kot' : 'pay');
    case '/controllers/kot_operations.php': {
      const k = db.kots.find((x) => String(x.id) === String(f.kotId));
      if (k) k.kot_status = f.status;
      return ok();
    }
    case '/controllers/waiter_request_operations.php':
      db.calls = db.calls.filter((c) => String(c.id) !== String(f.requestId));
      return ok();
    case '/controllers/reservation_operations.php': {
      const r = db.reservations.find((x) => String(x.id) === String(f.reservationId));
      if (r && f.status) r.status = f.status;
      return ok();
    }
    case '/controllers/menu_items_operations_base64.php': {
      const m = db.menuItems.find((x) => String(x.id) === String(f.menuItemId));
      if (f.action === 'set_available' && m) m.is_available = Number(f.isAvailable) ? 1 : 0;
      else if (f.action === 'toggle_hidden' && m) {
        m.is_hidden = m.is_hidden == 1 ? 0 : 1;
        return ok({ is_hidden: m.is_hidden });
      } else if (f.action === 'delete') db.menuItems = db.menuItems.filter((x) => String(x.id) !== String(f.menuItemId));
      else if (f.action === 'update' && m) Object.assign(m, { item_name_en: f.itemNameEn, base_price: f.basePrice, is_available: Number(f.isAvailable) ? 1 : 0, item_type: f.itemType, item_category: f.itemCategory, item_description_en: f.itemDescriptionEn });
      else if (f.action === 'add') {
        const id = Math.max(...db.menuItems.map((x) => Number(x.id))) + 1;
        const menu = db.menus.find((x) => String(x.id) === String(f.chooseMenu));
        db.menuItems.unshift({ id, item_name_en: f.itemNameEn, base_price: f.basePrice, is_available: Number(f.isAvailable) ? 1 : 0, item_type: f.itemType, item_category: f.itemCategory, menu_id: f.chooseMenu, menu_name: menu?.menu_name, has_variations: 0, variations: [], item_image: '' });
        return ok({ data: { id } });
      }
      return ok();
    }
    case '/controllers/coupon_operations.php': {
      if (f.action === 'toggle') db.adminCoupons.forEach((c) => String(c.id) === String(f.id) && (c.is_active = Number(f.is_active)));
      else if (f.action === 'delete') db.adminCoupons = db.adminCoupons.filter((c) => String(c.id) !== String(f.id));
      else if (f.action === 'add') db.adminCoupons.unshift({ id: Date.now(), coupon_code: String(f.coupon_code).toUpperCase(), discount_type: f.discount_type, discount_value: f.discount_value, minimum_order_amount: f.minimum_order_amount, max_uses: f.max_uses, current_uses: 0, is_active: 1, valid_until: f.valid_until || null, description: f.description });
      return ok();
    }
    case '/api/generate_delivery_qr.php':
      return ok({ qr_url: 'https://restrogrow.com/', expires_at: fmt(new Date(Date.now() + 6 * 3600000)) });
    case '/api/print_network.php':
      return no('Printing is turned off in the demo.');
    default:
      return ok();
  }
}

function parseQuery(path) {
  const [p, qs] = path.split('?');
  const q = {};
  new URLSearchParams(qs || '').forEach((v, k) => {
    q[k] = v;
  });
  return { p, q };
}

export function mockRequest(path, options = {}) {
  const { p, q } = parseQuery(path);
  const method = (options.method || 'GET').toUpperCase();
  let f = {};
  if (typeof options.body === 'string') {
    try {
      f = options.body.trim().startsWith('{') ? JSON.parse(options.body) : Object.fromEntries(new URLSearchParams(options.body));
    } catch (e) {
      f = {};
    }
  }
  const result = handle(p, method, q, f);
  // a short, realistic network delay
  return new Promise((resolve) => setTimeout(() => resolve(result), 120 + Math.random() * 220));
}

export const DEMO_LOGIN = { username: 'demo', password: 'demo1234' };
