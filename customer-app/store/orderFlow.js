// How an order moves through the kitchen. In this template the clock is sped up so a visitor sees
// every step within about a minute and a half; a connected restaurant gets real status updates.
export const FLOWS = {
  Delivery: ['Order placed', 'Confirmed', 'Preparing', 'Out for delivery', 'Delivered'],
  Takeaway: ['Order placed', 'Confirmed', 'Preparing', 'Ready for pickup', 'Picked up'],
  'Dine-in': ['Order placed', 'Confirmed', 'Preparing', 'Served'],
};

const AT_SECONDS = [0, 6, 18, 48, 85]; // when each step starts

export const FLOW_NOTES = {
  'Order placed': 'We have your order',
  Confirmed: 'The restaurant accepted it',
  Preparing: 'The kitchen is cooking',
  'Out for delivery': 'Your rider is on the way',
  'Ready for pickup': 'Come and collect it',
  Delivered: 'Enjoy your meal',
  'Picked up': 'Enjoy your meal',
  Served: 'Enjoy your meal',
};

export function stageIndex(order, now = Date.now()) {
  const flow = FLOWS[order.type] || FLOWS.Delivery;
  const secs = (now - order.createdAt) / 1000;
  let idx = 0;
  for (let i = 0; i < flow.length; i += 1) if (secs >= AT_SECONDS[i]) idx = i;
  return idx;
}

export function isFinished(order, now = Date.now()) {
  const flow = FLOWS[order.type] || FLOWS.Delivery;
  return stageIndex(order, now) >= flow.length - 1;
}

function totalSeconds(order) {
  const flow = FLOWS[order.type] || FLOWS.Delivery;
  return AT_SECONDS[flow.length - 1];
}

// 0..1 along the whole journey, for the progress bar
export function progressOf(order, now = Date.now()) {
  return Math.max(0, Math.min(1, (now - order.createdAt) / 1000 / totalSeconds(order)));
}

export function minutesLeft(order, now = Date.now()) {
  const total = totalSeconds(order);
  const left = total - (now - order.createdAt) / 1000;
  const real = order.type === 'Delivery' ? 35 : order.type === 'Takeaway' ? 20 : 15;
  return Math.max(0, Math.ceil((left / total) * real));
}
