import { useEffect, useRef } from 'react';
import { configureLive, refreshLive, resetLive, subscribeLive, getLive } from '../utils/liveStore';
import { staffBeep } from '../utils/alertSound';
import { isForeground, onAppForeground } from '../utils/foreground';
import { ensureNotificationPermission, notifyStaff } from '../utils/notifications';
import { feedback } from './ui/Feedback';
import { makesTickets } from '../utils/roles';

const POLL_MS = 8000;

function where(k) {
  return k.table_number ? `Table ${k.table_number}` : k.order_type || 'Order';
}

// Mounted once while someone is logged in: keeps the shared live feed (utils/liveStore) fresh
// and raises the alerts this role must not miss, wherever they are in the app.
//   chef:    a new kitchen (or bar) ticket
//   bartender: a new bar ticket (the server sends a bartender only bar tickets)
//   waiter:  food or drinks ready to serve, a table calling
//   manager: a table calling
// The first load only sets a baseline, so opening the app never fires for old tickets.
export default function LiveFeed({ role }) {
  const seen = useRef(null);

  useEffect(() => {
    ensureNotificationPermission().catch(() => {});
  }, []);

  useEffect(() => {
    configureLive({ calls: !makesTickets(role) });
    seen.current = null;

    const off = subscribeLive(() => {
      const { kots, calls, loaded } = getLive();
      if (!loaded) return;
      const now = {
        fresh: new Set(kots.filter((k) => k.kot_status === 'Pending').map((k) => k.id)),
        ready: new Set(kots.filter((k) => k.kot_status === 'Ready').map((k) => k.id)),
        calls: new Set(calls.map((c) => c.id)),
      };
      const before = seen.current;
      seen.current = now;
      if (!before) return;

      const alerts = [];
      if (makesTickets(role)) {
        const arrived = kots.filter((k) => k.kot_status === 'Pending' && !before.fresh.has(k.id));
        if (arrived.length) {
          const k = arrived[0];
          const bar = role === 'bartender' || arrived.every((x) => x.station === 'bar');
          alerts.push([bar ? 'New bar ticket' : 'New kitchen ticket', arrived.length === 1 ? `New ${bar ? 'bar ' : ''}ticket #${String(k.kot_number || '').split('-').pop()} · ${where(k)}` : `${arrived.length} new ${bar ? 'bar' : 'kitchen'} tickets`]);
        }
      }
      if (role === 'waiter') {
        const ready = kots.filter((k) => k.kot_status === 'Ready' && !before.ready.has(k.id));
        // a bar ticket (BOT) ready means drinks to take out
        const drinks = ready.length && ready.every((x) => x.station === 'bar');
        if (ready.length) alerts.push([drinks ? 'Drinks ready' : 'Food ready', ready.length === 1 ? `${where(ready[0])}${drinks ? ': drinks are' : ' is'} ready to serve` : `${ready.length} ${drinks ? 'bar tickets' : 'orders'} are ready to serve`]);
      }
      if (!makesTickets(role)) {
        const called = calls.filter((c) => !before.calls.has(c.id));
        if (called.length) {
          const c = called[0];
          alerts.push(['Waiter call', called.length === 1
            ? `${c.table_number ? `Table ${c.table_number}` : 'A table'} is calling${c.request_type && c.request_type !== 'General' ? `: ${c.request_type}` : ''}`
            : `${called.length} tables are calling a waiter`]);
        }
      }
      if (!alerts.length) return;
      feedback.info(alerts.map((a) => a[1]).join(' · '), 5000);
      staffBeep();
      alerts.forEach(([title, body]) => notifyStaff(title, body));
    });

    refreshLive();
    const id = setInterval(() => {
      if (isForeground()) refreshLive();
    }, POLL_MS);
    const offFront = onAppForeground(() => refreshLive());
    return () => {
      off();
      clearInterval(id);
      offFront();
      resetLive();
    };
  }, [role]);

  return null;
}
