import { useEffect, useSyncExternalStore } from 'react';
import { pf } from '../config/api';
import { checkAlerts } from './alerts';
import { isForeground, onAppForeground } from './foreground';

// Unread tickets, new leads and renewals due: one shared poll feeds the tab bar and Home.
let counts = { tickets: 0, leads: 0, renewals: 0 };
const listeners = new Set();
let users = 0;
let timer = null;
let offForeground = null;

export async function refreshBadges() {
  try {
    const r = await pf.get('badges');
    counts = { tickets: Number(r.tickets) || 0, leads: Number(r.leads) || 0, renewals: Number(r.renewals) || 0 };
    listeners.forEach((l) => l());
    checkAlerts();
  } catch (e) {
    // keep the last counts; a blip shouldn't clear the badges
  }
}

function start() {
  refreshBadges();
  timer = setInterval(() => isForeground() && refreshBadges(), 60000);
  offForeground = onAppForeground(refreshBadges);
}
function stop() {
  clearInterval(timer);
  offForeground?.();
}

export function useBadges() {
  useEffect(() => {
    if (users++ === 0) start();
    return () => {
      if (--users === 0) stop();
    };
  }, []);
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => counts
  );
}
