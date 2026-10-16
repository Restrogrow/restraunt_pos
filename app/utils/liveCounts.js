import { useSyncExternalStore } from 'react';

// Small shared counters for the tab-bar badges: website orders waiting to be accepted
// (fed by OrderAlertWatcher's poll) and open waiter calls (fed by the waiter-call poll).
let counts = { newOrders: 0, calls: 0 };
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
