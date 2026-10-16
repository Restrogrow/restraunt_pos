import { AppState, Platform } from 'react-native';

// Is the app on screen right now (not in the background, not a hidden browser tab)?
export function isForeground() {
  if (Platform.OS === 'web') {
    return typeof document === 'undefined' || document.visibilityState !== 'hidden';
  }
  return AppState.currentState === 'active';
}

// Calls cb each time the app comes back to the front (from another app, or a browser tab
// shown again), so screens catch up with changes made on the website straight away.
// Returns the unsubscribe function.
export function onAppForeground(cb) {
  if (Platform.OS === 'web') {
    if (typeof document === 'undefined') return () => {};
    const handler = () => document.visibilityState === 'visible' && cb();
    document.addEventListener('visibilitychange', handler);
    return () => document.removeEventListener('visibilitychange', handler);
  }
  let last = AppState.currentState;
  const sub = AppState.addEventListener('change', (next) => {
    if (next === 'active' && last !== 'active') cb();
    last = next;
  });
  return () => sub.remove();
}
