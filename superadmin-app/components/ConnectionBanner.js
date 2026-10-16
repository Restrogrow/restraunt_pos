import { Ionicons } from '@expo/vector-icons';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { Animated, StyleSheet, Text } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { auth, network } from '../config/api';
import { colors, font, radius } from '../theme';

// A small strip under the status bar while the server can't be reached. Screens keep showing
// their last data; this retries quietly and slides away as soon as the connection is back.
export default function ConnectionBanner() {
  const online = useSyncExternalStore(network.subscribe, network.isOnline);
  const insets = useSafeAreaInsets();
  const y = useRef(new Animated.Value(-80)).current;
  const [shown, setShown] = useState(false);

  useEffect(() => {
    if (!online) setShown(true);
    Animated.spring(y, { toValue: online ? -80 : 0, useNativeDriver: true, damping: 18, stiffness: 180 }).start(() => online && setShown(false));
    if (online) return undefined;
    const t = setInterval(() => auth.session().catch(() => {}), 8000);
    return () => clearInterval(t);
  }, [online, y]);

  if (!shown) return null;
  return (
    <Animated.View pointerEvents="none" style={[styles.wrap, { top: insets.top + 6, transform: [{ translateY: y }] }]}>
      <Ionicons name="cloud-offline" size={15} color="#fff" />
      <Text style={styles.text}>No connection · showing saved data</Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    position: 'absolute',
    alignSelf: 'center',
    zIndex: 999,
    elevation: 20,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 7,
    backgroundColor: colors.danger,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: radius.pill,
  },
  text: { fontFamily: font.semiBold, fontSize: 12, color: '#fff' },
});
