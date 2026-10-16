// One place that turns the session's role into what this person sees in the Staff app.
//   chef    -> the kitchen: tickets to cook, orders to move along
//   waiter  -> the floor: tables, new orders, waiter calls, bookings
//   manager -> everything a manager can do (also owners and branch admins who log in here)
//   rider   -> delivery riders: their own deliveries only (RiderHomeScreen)
export function staffRole(user) {
  const role = String(user?.role || '').toLowerCase();
  const type = String(user?.user_type || '').toLowerCase();
  if (type === 'admin' || type === 'branch_admin') return 'manager';
  if (role === 'chef') return 'chef';
  if (role === 'delivery') return 'rider';
  if (role === 'waiter' || role === 'staff') return 'waiter';
  return 'manager';
}

export const ROLE_INFO = {
  chef: { label: 'Chef', icon: 'flame', tagline: 'Kitchen' },
  waiter: { label: 'Waiter', icon: 'restaurant', tagline: 'Floor' },
  manager: { label: 'Manager', icon: 'briefcase', tagline: 'Manager' },
  rider: { label: 'Delivery rider', icon: 'bicycle', tagline: 'Deliveries' },
};

// first name for greetings, never a business-sounding name
export function firstName(user) {
  const name = String(user?.staff_name || user?.username || '').replace(/\(.*?\)/g, '').trim();
  if (!name || /@|\d{5,}|restaurant|admin|group|demo/i.test(name)) return '';
  return name.split(/\s+/)[0];
}
