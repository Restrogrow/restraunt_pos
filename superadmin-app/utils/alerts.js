import AsyncStorage from '@react-native-async-storage/async-storage';
import * as BackgroundTask from 'expo-background-task';
import * as Notifications from 'expo-notifications';
import * as TaskManager from 'expo-task-manager';
import { Platform } from 'react-native';
import { pf } from '../config/api';
import { resumeSession } from '../context/AuthContext';

/*
 * Phone notifications for the super admin: a new or updated support ticket, a new lead from the
 * website, and once a day the plans that end within the renewal notice period.
 * There is no push server: the app checks itself, every minute while it's open (with the tab
 * badges) and about every 15 minutes in the background (Android WorkManager via
 * expo-background-task). The first check on a device only records what's there, so installing
 * the app never fires a burst of old tickets.
 */

const TASK = 'sa-alerts-check';
const STATE_KEY = 'sa_alert_state_v1';
const PREFS_KEY = 'sa_alert_prefs_v1';
export const DEFAULT_PREFS = { tickets: true, leads: true, renewals: true };
const native = Platform.OS !== 'web';

if (native) {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
  });
}

export async function getAlertPrefs() {
  try {
    return { ...DEFAULT_PREFS, ...JSON.parse((await AsyncStorage.getItem(PREFS_KEY)) || '{}') };
  } catch (e) {
    return DEFAULT_PREFS;
  }
}

export async function setAlertPrefs(prefs) {
  await AsyncStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
  await syncBackgroundTask(prefs);
}

let channelReady = false;
async function ensureChannel() {
  if (Platform.OS !== 'android' || channelReady) return;
  channelReady = true;
  await Notifications.setNotificationChannelAsync('alerts', {
    name: 'Tickets, leads and renewals',
    importance: Notifications.AndroidImportance.HIGH,
  }).catch(() => {});
}

export async function notificationsAllowed(ask = false) {
  if (!native) return false;
  try {
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted' && ask) status = (await Notifications.requestPermissionsAsync()).status;
    if (status === 'granted') await ensureChannel();
    return status === 'granted';
  } catch (e) {
    return false;
  }
}

async function show(title, body, screen) {
  await Notifications.scheduleNotificationAsync({
    content: { title, body, data: { screen }, ...(Platform.OS === 'android' ? { channelId: 'alerts' } : {}) },
    trigger: null,
  });
}

const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

async function fetchAll(prefs) {
  const [tickets, leads, renewals] = await Promise.all([
    prefs.tickets ? pf.get('tickets', { status: 'active' }) : null,
    prefs.leads ? pf.get('leads', { stage: 'new' }) : null,
    prefs.renewals ? pf.get('badges') : null,
  ]);
  return { tickets, leads, renewals };
}

let running = false;
// Looks for anything new since the last check and raises notifications for it.
export async function checkAlerts() {
  if (!native || running) return;
  running = true;
  try {
    const prefs = await getAlertPrefs();
    if (!prefs.tickets && !prefs.leads && !prefs.renewals) return;
    if (!(await notificationsAllowed())) return;

    let data;
    try {
      data = await fetchAll(prefs);
    } catch (e) {
      // woken in the background with an expired session: resume with the device token once
      if (!e.sessionExpired || !(await resumeSession())) return;
      data = await fetchAll(prefs);
    }

    const raw = await AsyncStorage.getItem(STATE_KEY);
    const first = !raw;
    const state = raw ? JSON.parse(raw) : { tickets: {}, leads: [], renewalsDay: '' };
    const out = [];

    if (data.tickets) {
      const seen = state.tickets || {};
      const next = {};
      for (const t of data.tickets.rows || []) {
        if (Number(t.unread_admin) !== 1) continue;
        next[t.id] = t.updated_at;
        if (seen[t.id] !== t.updated_at) out.push(['tickets', `💬 ${t.restaurant_name || t.restaurant_id}`, t.last_message ? `${t.subject}: ${t.last_message}` : t.subject]);
      }
      state.tickets = next;
    }
    if (data.leads) {
      const seen = new Set(state.leads || []);
      const rows = data.leads.rows || [];
      for (const l of rows) if (!seen.has(l.id)) out.push(['leads', `🙋 New lead: ${l.name}`, [l.restaurant_name_hint, l.phone, l.message].filter(Boolean).join(' · ').slice(0, 140)]);
      state.leads = rows.map((l) => l.id);
    }
    if (data.renewals && state.renewalsDay !== today() && new Date().getHours() >= 9) {
      const n = Number(data.renewals.renewals) || 0;
      if (n > 0) out.push(['renewals', `⏰ ${n} plan${n > 1 ? 's' : ''} ending soon`, 'Tap to see who to remind today.']);
      state.renewalsDay = today();
    }

    await AsyncStorage.setItem(STATE_KEY, JSON.stringify(state));
    if (first) return;

    // a burst becomes one summary per kind instead of a wall of notifications
    for (const kind of ['tickets', 'leads', 'renewals']) {
      const items = out.filter((o) => o[0] === kind);
      if (!items.length) continue;
      const screen = kind === 'tickets' ? 'Support' : kind === 'leads' ? 'Leads' : 'Renewals';
      if (items.length <= 2) for (const [, title, body] of items) await show(title, body, screen);
      else await show(kind === 'tickets' ? `💬 ${items.length} tickets need a reply` : `🙋 ${items.length} new leads`, items.map((i) => i[1].replace(/^\S+ (New lead: )?/, '')).join(', '), screen);
    }
  } catch (e) {
    // a failed check just waits for the next one
  } finally {
    running = false;
  }
}

export async function resetAlertState() {
  await AsyncStorage.removeItem(STATE_KEY);
}

// The background job (must be defined at start-up, before React mounts: see index.js).
if (native) {
  TaskManager.defineTask(TASK, async () => {
    try {
      await checkAlerts();
      return BackgroundTask.BackgroundTaskResult.Success;
    } catch (e) {
      return BackgroundTask.BackgroundTaskResult.Failed;
    }
  });
}

export async function syncBackgroundTask(prefs) {
  if (!native) return;
  try {
    const p = prefs || (await getAlertPrefs());
    const on = (p.tickets || p.leads || p.renewals) && (await notificationsAllowed());
    const registered = await TaskManager.isTaskRegisteredAsync(TASK);
    if (on && !registered) await BackgroundTask.registerTaskAsync(TASK, { minimumInterval: 15 });
    if (!on && registered) await BackgroundTask.unregisterTaskAsync(TASK);
  } catch (e) {
    // background checks unavailable: alerts still come while the app is open
  }
}

export async function stopAlerts() {
  if (!native) return;
  await resetAlertState();
  try {
    if (await TaskManager.isTaskRegisteredAsync(TASK)) await BackgroundTask.unregisterTaskAsync(TASK);
  } catch (e) {
    // nothing registered
  }
}
