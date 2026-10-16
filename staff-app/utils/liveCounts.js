import { useSyncExternalStore } from 'react';

// Small shared counters for the tab-bar badges: website orders waiting to be accepted
// (OrderAlertWatcher), open waiter calls (WaiterCallWatcher), and kitchen tickets that are new
// or ready to serve (KitchenWatcher).
let counts = { newOrders: 0, calls: 0, kitchenNew: 0, ready: 0 };
const listeners = new Set();

export function setLiveCount(key, value) {
  const v = Number(value) || 0;
  if (counts[key] === v) return;
  counts = { ...counts, [key]: v };
  listeners.forEach((l) => l());
}

export function useLiveCounts() {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => counts
  );
}
