import { useSyncExternalStore } from 'react';
import { getOrderSoundEnabled, setOrderSoundEnabled } from '../config/notificationSettings';
import { haptic } from './haptics';
import { playClickSound } from './orderAlerts';

// One "alert sounds" switch for the whole app (the same setting as Settings › New-order alerts),
// readable as a hook so the bell on Home flips the moment it is tapped.
let on = true;
const listeners = new Set();
getOrderSoundEnabled().then((v) => {
  on = v;
  listeners.forEach((l) => l());
});

export function useAlertSound() {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => on
  );
}

export async function setAlertSound(next) {
  on = next;
  listeners.forEach((l) => l());
  await setOrderSoundEnabled(next).catch(() => {});
}

// A short double beep plus a buzz; the buzz still comes when sounds are muted.
export async function staffBeep() {
  haptic.warning();
  if (!(await getOrderSoundEnabled())) return;
  playClickSound();
  setTimeout(playClickSound, 260);
}
