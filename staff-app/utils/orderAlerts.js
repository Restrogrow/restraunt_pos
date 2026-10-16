import { AppState, Platform } from 'react-native';
import { createAudioPlayer, setAudioModeAsync } from 'expo-audio';

// Created once at module scope (not per-call) so the sound is already
// loaded by the time the first order alert fires, and so replaying it just
// means seeking back to 0 instead of decoding the file again every time —
// mirrors the website's single reused Audio() instance.
const player = createAudioPlayer(require('../assets/sounds/telephone-ring.mp3'));
player.volume = 0.8;

// Separate, short tap-feedback sound for POS item taps — needs its own
// player instance since it can fire rapidly (tapping several items back to
// back) independently of the new-order alert above.
const clickPlayer = createAudioPlayer(require('../assets/sounds/click.wav'));
clickPlayer.volume = 0.5;

let audioModeReady = false;
async function ensureAudioMode() {
  if (audioModeReady) return;
  audioModeReady = true;
  try {
    // Alerts should still be heard with the phone's ringer switched to
    // silent — this is a counter/kitchen alert, not a media sound.
    await setAudioModeAsync({ playsInSilentMode: true, shouldPlayInBackground: false });
  } catch (e) {
    // non-fatal — playback still works without this, just respects silent mode
  }
}

const RING_TIMEOUT_MS = 10 * 60 * 1000; // matches the website's new-order ring — safety-net auto-stop if nobody's there to mute it
let ringTimeoutId = null;

// Mirrors the website's closeNewOrderOverlay(): the overlay (and with it
// the ring) only goes away once the order is acted on. Here the ring
// deliberately keeps looping after OrderDetailScreen opens — someone
// walking past a ringing tablet shouldn't have to open an order just to
// silence it, the Mute/Unmute button exists for that. Ring therefore stops
// only via: this toggle, accept/reject/cancel on the order, the screen's
// back button, or the 10-minute safety net.
let ringActive = false;
const ringListeners = new Set();

export function isRingActive() {
  return ringActive;
}

// Lets UI (OrderDetailScreen's Mute/Unmute button) subscribe to ring state
// flips and re-render its label. Returns an unsubscribe function.
export function subscribeRingState(fn) {
  ringListeners.add(fn);
  return () => ringListeners.delete(fn);
}

function setRingActive(next) {
  if (ringActive === next) return;
  ringActive = next;
  ringListeners.forEach((fn) => {
    try {
      fn(next);
    } catch (e) {}
  });
}

// Belt-and-braces looping. player.loop is the primary mechanism, but on
// some native devices the flag doesn't survive the trip — set before the
// source finished loading, or silently dropped when the clip ends — so the
// ring played once and went quiet instead of ringing like an incoming call.
// This re-asserts the loop flag on every status tick and, if the clip still
// managed to stop (didJustFinish / player paused mid-ring), restarts it from
// the top. On web the <audio> element loops natively, so this is a no-op.
player.addListener('playbackStatusUpdate', (status) => {
  if (!ringActive) return;
  if (AppState.currentState !== 'active') return;
  // Re-assert the loop flag whenever a status tick reports it dropped —
  // cheap, and covers the load-order quirk on some native devices.
  if (status?.isLoaded && status.loop === false) {
    try {
      player.loop = true;
    } catch (e) {}
    return;
  }
  // Clip reached its end without looping (or got paused mid-ring) —
  // restart it from the top.
  if (status?.didJustFinish || status?.timeControlStatus === 'paused') {
    try {
      player.seekTo(0).catch(() => {});
      player.play();
    } catch (e) {}
  }
});

// Native players are paused when the app is backgrounded (see
// shouldPlayInBackground: false in ensureAudioMode) and paused players emit
// no further status events — so without this, an order that came in while
// the device was pocketed would stay silent forever after returning to the
// app, even though the ring was never muted and the 10-minute cap hasn't
// fired. Resume from the top on foreground, same as an incoming call that
// keeps ringing when you unlock the phone.
let ringAppStateSub = null;
function ensureRingAppStateListener() {
  if (ringAppStateSub) return;
  ringAppStateSub = AppState.addEventListener('change', (state) => {
    // Backgrounded/inactive → silence the alert. A ringing phone nobody can
    // see is worse than a stopped one: the order is still on the Orders list
    // (and now live-refreshed), and staff reopen the app to check anyway.
    // The keepalive and watchdog are gated on AppState 'active', so without
    // this the ring would just silently pause and then RESUME — full volume
    // out of a pocket — when the app came back to the foreground.
    if (state !== 'active' && ringActive) {
      stopNewOrderSound();
      return;
    }
    if (state === 'active' && ringActive) {
      // Defensive: if the ring was re-armed while backgrounded, start
      // sounding from the top on return to the foreground.
      try {
        player.seekTo(0).catch(() => {});
        player.play();
      } catch (e) {
        // non-fatal
      }
    }
  });
}

// Keepalive safety net: if the watchdog's status ticks stop arriving
// (native event quirk, web tab throttling a paused media element) the ring
// would silently die mid-alert. Every 5s, quietly verify the player is
// still sounding and nudge it back to life if not. The seekTo promises get
// .catch on their own lines so a rejection never becomes an unhandled one.
let ringKeepaliveId = null;
function ensureRingKeepalive() {
  if (ringKeepaliveId) return;
  ringKeepaliveId = setInterval(() => {
    if (!ringActive || AppState.currentState !== 'active') return; // backgrounded = stay silent
    try {
      // player.playing works on all platforms; player.paused is iOS/Android
      // only (web's getter throws), so never touch it here.
      if (!player.playing) {
        player.seekTo(0).catch(() => {});
        player.play();
      }
    } catch (e) {
      // non-fatal
    }
  }, 5000);
}

// Rings on loop (like an incoming-call alert) until muted, until the order
// is actually acted on, or until the safety-net timeout fires — a single
// short beep is easy to miss on a busy counter.
export async function playNewOrderSound() {
  try {
    await ensureAudioMode();
    ensureRingAppStateListener();
    ensureRingKeepalive();
    setRingActive(true);
    player.loop = true;
    await player.seekTo(0);
    player.play();
    clearTimeout(ringTimeoutId);
    ringTimeoutId = setTimeout(stopNewOrderSound, RING_TIMEOUT_MS);
  } catch (e) {
    // sound is a nice-to-have alert, never worth surfacing an error for
  }
}

// Backs the "Tap to Mute" control and the accept/reject actions on the
// order detail screen — stops the alert without disabling the setting. The
// flag flip must happen before pause() so the watchdog listener can't
// mistake the intentional stop for a dropped ring and start it again.
export function stopNewOrderSound() {
  try {
    clearTimeout(ringTimeoutId);
    setRingActive(false);
    player.loop = false;
    player.pause();
  } catch (e) {
    // non-fatal
  }
}

// Mirrors the website's playClickSound() — a quick tap-feedback beep on POS
// menu item taps.
export async function playClickSound() {
  try {
    await ensureAudioMode();
    await clickPlayer.seekTo(0);
    clickPlayer.play();
  } catch (e) {
    // non-fatal — never block adding an item to cart over a beep failing
  }
}

// Web only: browsers refuse to autoplay media until the page has seen a
// real user gesture. playClickSound() always fires from an actual tap so
// it's fine, but playNewOrderSound() fires from OrderAlertWatcher's polling
// interval (no tap in the call stack) — on a POS screen sitting untouched
// waiting for orders, that play() call gets silently blocked (see the
// unhandled NotAllowedError in AudioPlayerWeb.play()), so the ring is never
// heard. isWebAudioPrimed()/subscribeWebAudioPrimed() let a visible banner
// (WebAudioUnlockBanner) guarantee this happens via an explicit tap, rather
// than hoping the staff happens to click something else first.
let webAudioPrimed = Platform.OS !== 'web';
const primeSubscribers = new Set();

export function isWebAudioPrimed() {
  return webAudioPrimed;
}

export function subscribeWebAudioPrimed(fn) {
  if (webAudioPrimed) {
    fn();
    return () => {};
  }
  primeSubscribers.add(fn);
  return () => primeSubscribers.delete(fn);
}

export function primeWebAudioNow() {
  if (webAudioPrimed) return;
  webAudioPrimed = true;
  [player, clickPlayer].forEach((p) => {
    try {
      p.play();
      p.pause();
      p.seekTo(0);
    } catch (e) {}
  });
  primeSubscribers.forEach((fn) => fn());
  primeSubscribers.clear();
}

if (Platform.OS === 'web' && typeof document !== 'undefined') {
  ['click', 'keydown', 'touchstart'].forEach((evt) => {
    document.addEventListener(evt, primeWebAudioNow, { once: true, capture: true });
  });
}
