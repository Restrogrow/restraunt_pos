import { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import { apiGet } from '../config/api';
import { getOrderSoundEnabled } from '../config/notificationSettings';
import { navigationRef } from '../navigationRef';
import { playNewOrderSound } from '../utils/orderAlerts';
import { ensureNotificationPermission, notifyNewOrder } from '../utils/notifications';

const POLL_MS = 10000;

function todayKey() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

// Mounted once at the app root (not per-screen) so a new order still rings
// and shows a notification no matter which tab is open — POS, Menu,
// wherever — not just while the Orders tab happens to be on screen.
export default function OrderAlertWatcher() {
  const seenIds = useRef(null); // null until the first poll seeds a baseline

  useEffect(() => {
    ensureNotificationPermission().catch(() => {});
  }, []);

  useEffect(() => {
    let cancelled = false;

    const poll = async () => {
      if (AppState.currentState !== 'active') return;
      try {
        const res = await apiGet(`/api/get_orders.php?limit=50&date=${todayKey()}`);
        if (cancelled || !res?.success) return;
        const orders = res.orders || [];
        const currentIds = new Set(orders.map((o) => o.id));

        if (seenIds.current === null) {
          // First poll this session — seed the baseline only. Otherwise
          // every order already sitting there from before the app opened
          // would fire an alert all at once.
          seenIds.current = currentIds;
          return;
        }

        const freshOrders = orders.filter((o) => !seenIds.current.has(o.id));
        seenIds.current = currentIds;
        if (freshOrders.length === 0) return;

        freshOrders.forEach((o) => notifyNewOrder(o));

        // A new order still awaiting a response is the one that actually
        // needs interrupting for — like an incoming call, not just a toast.
        // Anything that shows up already past Pending (e.g. created
        // straight from the counter) just gets the quiet notification above.
        const freshPending = freshOrders.filter((o) => o.order_status === 'Pending');
        if (freshPending.length === 0) return;

        const soundOn = await getOrderSoundEnabled();
        if (soundOn) playNewOrderSound();

        // Don't yank someone off an order they're already reviewing if a
        // second new order lands moments later — the sound/notification
        // above already told them; let them finish what they're looking at.
        const alreadyOnAnOrder = navigationRef.isReady() && navigationRef.getCurrentRoute()?.name === 'OrderDetail';
        if (navigationRef.isReady() && !alreadyOnAnOrder) {
          navigationRef.navigate('Orders', {
            screen: 'OrderDetail',
            params: { order: freshPending[0] },
          });
        }
      } catch (e) {
        // silent — this is a background alert loop, not a user-facing fetch
      }
    };

    poll();
    const id = setInterval(poll, POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, []);

  return null;
}
