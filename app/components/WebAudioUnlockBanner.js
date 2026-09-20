import { useEffect, useState } from 'react';
import { Platform, Pressable, StyleSheet, Text } from 'react-native';
import { isWebAudioPrimed, primeWebAudioNow, subscribeWebAudioPrimed } from '../utils/orderAlerts';
import { colors } from '../theme/colors';

// Chrome/Firefox/Safari block audio.play() until the page has seen a real
// user gesture. The order ring fires from a background poll, so on a POS
// screen left open and untouched it would silently never be heard. This
// banner stays up until tapped (or until any other click/tap in the app
// primes audio via orderAlerts.js's document listener), so sound is
// guaranteed armed rather than left to chance. Native builds never render
// this — the restriction only exists on web.
export default function WebAudioUnlockBanner() {
  const [primed, setPrimed] = useState(() => isWebAudioPrimed());

  useEffect(() => {
    if (primed) return undefined;
    return subscribeWebAudioPrimed(() => setPrimed(true));
  }, [primed]);

  if (Platform.OS !== 'web' || primed) return null;

  return (
    <Pressable onPress={primeWebAudioNow} style={styles.banner}>
      <Text style={styles.text}>🔔 Tap here to enable the new-order alert sound</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banner: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    zIndex: 9999,
    backgroundColor: colors.primary,
    paddingVertical: 10,
    alignItems: 'center',
  },
  text: {
    color: colors.white,
    fontSize: 13,
    fontWeight: '600',
  },
});
