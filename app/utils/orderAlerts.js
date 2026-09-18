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
const clickPlayer = createAudioPlayer(require('../assets/sounds/click.mp3'));
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

const RING_TIMEOUT_MS = 2 * 60 * 1000; // matches the website's new-order ring — safety-net auto-stop if nobody's there to mute it
let ringTimeoutId = null;

// Rings on loop (like an incoming-call alert) until muted, until the order
// is actually acted on, or until the safety-net timeout fires — a single
// short beep is easy to miss on a busy counter.
export async function playNewOrderSound() {
  try {
    await ensureAudioMode();
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
// order detail screen — stops the alert without disabling the setting.
export function stopNewOrderSound() {
  try {
    clearTimeout(ringTimeoutId);
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
