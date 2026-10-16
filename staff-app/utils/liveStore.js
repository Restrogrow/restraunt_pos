import { useSyncExternalStore } from 'react';
import { apiGet } from '../config/api';
import { setLiveCount } from './liveCounts';

// The shift's live picture — kitchen tickets and waiter calls — fetched once for the whole app
// (by LiveFeed) instead of every screen polling on its own. Anything that changes a ticket or a
// call (Home's one-tap buttons, the Kitchen screen, the POS sending a ticket) patches it here at
// once and asks for a fresh copy, so badges, Home and the other phones' alerts catch up in
// seconds instead of waiting for the next poll.

let state = { kots: [], calls: [], loaded: false, online: true, updatedAt: 0 };
const listeners = new Set();
let wantCalls = true;
let inFlight = null;
let again = false;

function set(patch) {
  state = { ...state, ...patch };
  const kots = state.kots;
  setLiveCount('kitchenNew', kots.filter((k) => k.kot_status === 'Pending').length);
  setLiveCount('ready', kots.filter((k) => k.kot_status === 'Ready').length);
  if (wantCalls) setLiveCount('calls', state.calls.length);
  listeners.forEach((l) => l());
}

export function useLive() {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => state
  );
}

export function subscribeLive(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function getLive() {
  return state;
}

// chefs have no access to waiter calls; their feed skips that request
export function configureLive({ calls }) {
  wantCalls = calls;
}

export function resetLive() {
  state = { kots: [], calls: [], loaded: false, online: true, updatedAt: 0 };
  listeners.forEach((l) => l());
}

// Fetch now. Calls made while one is already running are folded into one more fetch after it,
// so a burst of taps never fires a burst of requests.
export function refreshLive() {
  if (inFlight) {
    again = true;
    return inFlight;
  }
  inFlight = (async () => {
    try {
      const [kot, calls] = await Promise.all([
        apiGet('/api/get_kot.php'),
        wantCalls ? apiGet('/api/get_waiter_requests.php').catch(() => null) : null,
      ]);
      if (!kot?.success) throw new Error(kot?.message || 'Could not load the kitchen');
      set({
        kots: kot.kots || [],
        calls: calls?.success ? calls.requests || [] : state.calls,
        loaded: true,
        online: true,
        updatedAt: Date.now(),
      });
    } catch (e) {
      set({ online: false });
    } finally {
      inFlight = null;
      if (again) {
        again = false;
        refreshLive();
      }
    }
  })();
  return inFlight;
}

// Optimistic edits, shown before the server answers; the refresh that follows confirms them.
export function patchKot(id, status) {
  const kots = state.kots
    .map((k) => (k.id === id ? { ...k, kot_status: status } : k))
    .filter((k) => k.kot_status !== 'Completed');
  set({ kots });
}

export function removeCall(id) {
  set({ calls: state.calls.filter((c) => c.id !== id) });
}
