/*
 * Browser demo: answers for the Loyalty screen (api/loyalty_admin.php) and the customer sheet
 * (api/pos_customer.php?id=). A points program that is switched on, a few automations running,
 * two VIP plans, a scratch card and two challenges — all in memory, built from the demo's own
 * customers, menu and orders so the numbers line up with the rest of the demo.
 */

const pad = (n) => String(n).padStart(2, '0');
const dayKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const fmt = (d) => `${dayKey(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
const parse = (s) => new Date(String(s).replace(' ', 'T'));
const mask = (p) => (p ? `${String(p).slice(0, 2)}******${String(p).slice(-2)}` : '');
const NOT_SALES = ['Cancelled', 'Rejected'];
const LOYAL_SHARE = 0.18; // part of revenue the demo says loyalty helped bring in

let L = null;

export function seedLoyalty(db) {
  if (L) return L;
  const items = db.menuItems.map((m) => ({ id: Number(m.id), name: m.item_name_en, price: Number(m.base_price) || 0, category: m.menu_name || m.item_category || '' }));
  const dish = items.find((i) => /gulab|kulfi|lassi|brownie/i.test(i.name)) || items[items.length - 1];
  // points: 1 per ₹100 spent, about a third already spent on rewards
  db.customers.forEach((c, i) => {
    if (!Number(c.loyalty_points_balance)) c.loyalty_points_balance = Math.round(((Number(c.total_spent) || 0) / 100) * (0.55 + (i % 4) * 0.1));
    if (i % 3 === 0 && !c.birthday) c.birthday = `199${i % 10}-${pad((i % 12) + 1)}-${pad((i % 27) + 1)}`;
    if (i % 2 === 0) c.whatsapp_optin = 1;
  });
  const now = Date.now();
  const ago = (h) => fmt(new Date(now - h * 3600000));
  const custs = [...db.customers].sort((a, b) => (Number(b.total_spent) || 0) - (Number(a.total_spent) || 0));
  const code = (i) => `GIFT${(7301 + i * 977).toString(36).toUpperCase()}Q`;
  const recent = [
    ['birthday', `Free ${dish?.name || 'dessert'}`, 5, 0],
    ['welcome', '₹50 off', 20, 1],
    ['winback', '₹75 off', 30, 0],
    ['milestone', '20% off', 46, 1],
    ['welcome', '₹50 off', 70, 0],
    ['scratch', '₹40 off', 96, 1],
    ['winback', '₹75 off', 130, 1],
    ['welcome', '₹50 off', 160, 0],
  ].map(([source, label, h, used], i) => {
    const c = custs[(i * 3 + 1) % custs.length] || {};
    return { source, label, code: code(i), at: ago(h), expires_on: dayKey(new Date(now + 14 * 86400000)), name: c.customer_name || 'Guest', phone: mask(c.phone), used, notify: 'sent', phoneFull: c.phone };
  });

  L = {
    items,
    program: {
      loyalty_enabled: 1, program_type: 'points', cashback_pct: 5, cashback_max: 100, stamp_goal: 6, stamp_min_order: 199,
      stamp_reward_kind: 'item', stamp_reward_item_id: dish?.id || null, stamp_reward_value: 0, reward_valid_days: 30,
      earn_points_per_amount: 1, earn_amount_threshold: 100, redeem_value_per_point: 0.5,
    },
    automations: [
      ['welcome', 1, 'flat', 50, null, 0, 199, 30],
      ['birthday', 1, 'item', 0, dish?.id || null, 7, 0, 14],
      ['anniversary', 0, 'percent', 15, null, 7, 499, 14],
      ['winback', 1, 'flat', 75, null, 30, 299, 14],
      ['tier_up', 0, 'flat', 100, null, 0, 399, 30],
      ['milestone', 1, 'percent', 20, null, 10, 0, 30],
      ['referral', 0, 'flat', 50, null, 0, 199, 30],
    ].map(([type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days]) => ({ type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days, message: '', saved: true })),
    stats: {
      welcome: { issued: 34, used: 15, revenue: 11840 },
      birthday: { issued: 9, used: 6, revenue: 7420 },
      winback: { issued: 21, used: 7, revenue: 5610 },
      milestone: { issued: 6, used: 4, revenue: 3980 },
    },
    recent,
    vip: {
      settings: { enabled: 1, name: 'Spice Club', tagline: 'Members save on every order', loyalty_enabled: 1, program_type: 'points' },
      plans: [
        { id: 1, name: 'Monthly', price: 199, duration_days: 30, discount_pct: 10, discount_max: 100, free_delivery: 1, free_delivery_min: 0, earn_boost: 1.5, gift_kind: null, gift_value: 0, gift_item_id: null, highlight: 0, active: 1, gift: '', members: 14, sold: 31, revenue: 6169 },
        { id: 2, name: 'Quarterly', price: 499, duration_days: 90, discount_pct: 10, discount_max: 150, free_delivery: 1, free_delivery_min: 0, earn_boost: 2, gift_kind: 'flat', gift_value: 100, gift_item_id: null, highlight: 1, active: 1, gift: '₹100 off', members: 9, sold: 12, revenue: 5988 },
      ],
      recent: custs.slice(2, 6).map((c, i) => ({ name: c.customer_name, phone: mask(c.phone), plan: i % 2 ? 'Quarterly' : 'Monthly', amount: i % 2 ? 499 : 199, via: 'wallet', at: ago(20 + i * 41), ends_at: fmt(new Date(now + (i % 2 ? 80 : 25) * 86400000)) })),
    },
    games: {
      game: { enabled: 1, style: 'scratch', title: 'Scratch and win', budget: 3000, spent: 1180, played: 142, won: 58, prizes: [{}, {}, {}, {}] },
      challenges: [
        { id: 1, kind: 'visits', title: null, target: 3, item_id: null, reward_kind: 'flat', reward_value: 100, reward_item_id: null, min_order: 299, valid_days: 30, active: 1, display_title: 'Order 3 times this month', reward: '₹100 off', stats: { taking_part: 27, completed: 8, used: 5 } },
        { id: 2, kind: 'streak', title: null, target: 3, item_id: null, reward_kind: 'item', reward_value: 0, reward_item_id: dish?.id || null, min_order: 0, valid_days: 30, active: 0, display_title: 'Order 3 weeks in a row', reward: `Free ${dish?.name || 'dessert'}`, stats: { taking_part: 0, completed: 3, used: 2 } },
      ],
    },
  };
  return L;
}

function performance(db, days) {
  const now = Date.now();
  const from = now - days * 86400000;
  const prevFrom = now - 2 * days * 86400000;
  const sales = db.orders.filter((o) => !o.deleted_at && !NOT_SALES.includes(o.order_status));
  const period = (a, b) => {
    const list = sales.filter((o) => {
      const t = parse(o.created_at).getTime();
      return t >= a && t < b;
    });
    const revenue = list.reduce((t, o) => t + (Number(o.total) || 0), 0);
    const loyalOrders = Math.round(list.length * LOYAL_SHARE * 0.8);
    return {
      orders: list.length, revenue, customers: new Set(list.map((o) => o.customer_phone).filter(Boolean)).size,
      loyal_revenue: Math.round(revenue * LOYAL_SHARE), loyal_orders: loyalOrders, given: Math.round(revenue * LOYAL_SHARE * 0.11),
    };
  };
  const cur = period(from, now + 60000);
  const prev = period(prevFrom, from);
  prev.loyal_revenue = Math.round(prev.loyal_revenue * 0.82);
  // weekly trend, last 12 weeks
  const weeks = [];
  for (let w = 11; w >= 0; w--) {
    const a = now - (w + 1) * 7 * 86400000;
    const b = now - w * 7 * 86400000;
    const rev = sales.filter((o) => {
      const t = parse(o.created_at).getTime();
      return t >= a && t < b;
    }).reduce((t, o) => t + (Number(o.total) || 0), 0);
    if (rev > 0) weeks.push({ from: dayKey(new Date(a)), revenue: Math.round(rev), loyal: Math.round(rev * (LOYAL_SHARE - 0.06 + (11 - w) * 0.01)) });
  }
  const pts = db.customers.reduce((t, c) => t + (Number(c.loyalty_points_balance) || 0), 0);
  const scale = Math.max(0.2, Math.min(1, days / 90));
  const src = [
    ['welcome', 34, 15, 11840, 750], ['birthday', 9, 6, 7420, 980], ['points', null, 41, 26300, 2140],
    ['winback', 21, 7, 5610, 525], ['vip_orders', null, 38, 21900, 1870], ['scratch', 58, 22, 9140, 880], ['milestone', 6, 4, 3980, 610],
  ].map(([source, issued, used, revenue, cost]) => ({ source, issued: issued == null ? null : Math.max(1, Math.round(issued * scale)), used: Math.max(1, Math.round(used * scale)), revenue: Math.round(revenue * scale), cost: Math.round(cost * scale) }))
    .sort((a, b) => b.revenue - a.revenue);
  return {
    success: true, currency: '₹', days, program_type: L.program.program_type, loyalty_enabled: L.program.loyalty_enabled,
    now: cur, prev, sources: src,
    segments: {
      members: { orders: Math.round(cur.orders * 0.34), revenue: Math.round(cur.revenue * 0.41), customers: Math.max(1, Math.round(cur.customers * 0.3)) },
      guests: { orders: Math.round(cur.orders * 0.66), revenue: Math.round(cur.revenue * 0.59), customers: Math.max(1, Math.round(cur.customers * 0.7)) },
      vip: { orders: Math.round(cur.orders * 0.12), revenue: Math.round(cur.revenue * 0.17), customers: 23 },
    },
    repeat: { customers: cur.customers, repeaters: Math.round(cur.customers * 0.62) },
    weeks,
    owed: { points: pts, points_value: Math.round(pts * L.program.redeem_value_per_point), wallet: 8640, gift_codes: 27, gift_flat: 1650, gift_pct: 4, stamp_cards: 0, vip_members: 23 },
  };
}

function customerSummary(db, c) {
  const p = L.program;
  const spent = Number(c.total_spent) || 0;
  const tier = spent >= 20000 ? 'Gold' : spent >= 8000 ? 'Silver' : spent >= 2000 ? 'Bronze' : null;
  const pts = Number(c.loyalty_points_balance) || 0;
  const gifts = L.recent.filter((r) => r.phoneFull === c.phone && !r.used).map((r) => ({
    code: r.code, type: /%/.test(r.label) ? 'percent' : 'flat', value: Number(String(r.label).replace(/[^\d.]/g, '')) || 0,
    min_order: r.source === 'winback' ? 299 : r.source === 'welcome' ? 199 : 0, label: r.label, valid_until: r.expires_on,
  }));
  return {
    id: Number(c.id), name: c.customer_name, phone: c.phone, email: c.email || '', has_account: Number(c.id) % 3 === 0,
    visits: Number(c.total_visits) || 0, spent, last_visit: c.last_visit_date, birthday: c.birthday || null,
    loyalty: {
      enabled: !!p.loyalty_enabled, type: p.program_type, points: pts, points_value: Math.round(pts * p.redeem_value_per_point * 100) / 100,
      redeem_value_per_point: p.redeem_value_per_point, min_redeem: 100, earn_per: p.earn_amount_threshold, earn_points: p.earn_points_per_amount,
      stamps: Number(c.stamps) || 0, stamp_goal: p.stamp_goal, stamp_reward: p.program_type === 'stamps' ? 'a free dish' : '', cashback_pct: p.cashback_pct, tier,
    },
    wallet: { enabled: true, balance: Number(c.id) % 4 === 0 ? 120 : 0 },
    gifts,
  };
}

// Returns a response for the loyalty endpoints, or null for everything else.
export function loyaltyMock(path, method, q, f, db) {
  if (path !== '/api/loyalty_admin.php' && path !== '/api/pos_customer.php') return null;
  seedLoyalty(db);
  const ok = (x = {}) => ({ success: true, ...x });

  if (path === '/api/pos_customer.php') {
    const c = db.customers.find((x) => String(x.id) === String(q.id));
    return c ? ok({ customer: customerSummary(db, c) }) : { success: false, message: 'Customer not found.' };
  }

  if (method === 'GET') {
    const s = q.section || 'program';
    const members = {
      total: db.customers.length, with_points: db.customers.filter((c) => Number(c.loyalty_points_balance) > 0).length, with_stamps: 0,
      with_birthday: db.customers.filter((c) => c.birthday).length, opted_in: db.customers.filter((c) => Number(c.whatsapp_optin)).length,
    };
    if (s === 'program') return ok({ currency: '₹', program: { ...L.program }, items: L.items, members, phonepe_ready: true });
    if (s === 'autopilot') {
      return ok({
        currency: '₹', loyalty_enabled: L.program.loyalty_enabled, program_type: L.program.program_type, referral_enabled: 1, last_run: fmt(new Date(Date.now() - 40 * 60000)),
        automations: L.automations.map((a) => ({ ...a })), stats: L.stats,
        upcoming: { birthday: 3, anniversary: 1, winback: 6, birthdays_known: members.with_birthday, opted_in: members.opted_in },
        recent: L.recent.map(({ phoneFull, ...r }) => r), items: L.items,
      });
    }
    if (s === 'vip') {
      const v = L.vip;
      return ok({
        currency: '₹', settings: { ...v.settings }, plans: v.plans.map((p) => ({ ...p })), items: L.items, phonepe_ready: true, wallet_enabled: 1,
        kpis: { members: v.plans.reduce((t, p) => t + (p.active ? p.members : 0), 0), revenue_month: 4370, revenue_total: 12157, given: 3920, renewing_soon: 2 }, recent: v.recent,
      });
    }
    if (s === 'games') return ok({ currency: '₹', loyalty_enabled: L.program.loyalty_enabled, game: { ...L.games.game }, challenges: L.games.challenges.map((c) => ({ ...c })), items: L.items });
    if (s === 'performance') return performance(db, [30, 90, 365].includes(Number(q.days)) ? Number(q.days) : 30);
    if (s === 'card') return ok({ currency: '₹', enabled: 0, color: '#1c1230', text_color: '#ffffff', loyalty_enabled: 1, platforms: {}, counts: { apple: 0, google: 0 }, preview: {} });
    return { success: false, message: 'Unknown section' };
  }

  // writes
  if (f.section === 'program') {
    const keys = Object.keys(L.program);
    keys.forEach((k) => {
      if (k in f && !/^(earn_|redeem_)/.test(k)) L.program[k] = f[k];
    });
    L.program.loyalty_enabled = f.loyalty_enabled ? 1 : 0;
    return ok();
  }
  if (f.section === 'autopilot') {
    if (f.action === 'run') return ok({ issued: 0 });
    const a = L.automations.find((x) => x.type === f.type);
    if (!a) return { success: false, message: 'Unknown automation' };
    if (f.enabled && f.reward_kind === 'item' && !f.reward_item_id) return { success: false, message: 'Pick the free dish' };
    Object.assign(a, { enabled: f.enabled ? 1 : 0 });
    return ok();
  }
  if (f.section === 'vip') {
    if (f.action === 'settings') L.vip.settings.enabled = f.enabled ? 1 : 0;
    else if (f.action === 'toggle') L.vip.plans.forEach((p) => p.id === Number(f.id) && (p.active = f.active ? 1 : 0));
    else return { success: false, message: 'Editing plans is turned off in the demo.' };
    return ok();
  }
  if (f.section === 'games') {
    if (f.action === 'toggle') L.games.challenges.forEach((c) => c.id === Number(f.id) && (c.active = f.active ? 1 : 0));
    else return { success: false, message: 'Editing games is turned off in the demo.' };
    return ok();
  }
  return { success: false, message: 'Unknown section' };
}
