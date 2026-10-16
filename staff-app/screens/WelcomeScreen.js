import AsyncStorage from '@react-native-async-storage/async-storage';
import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useRef, useState } from 'react';
import { Animated, Image, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import PressableScale from '../components/ui/PressableScale';
import { colors, font, radius, shadow, spacing } from '../theme';

// First-launch introduction: a few swipeable slides (Skip / Next), then the login screen.
// Shown once per install; WelcomeScreen.seen() tells App.js whether to skip it.
const KEY = 'staff_welcome_seen_v1';

const SLIDES = [
  {
    icon: 'people',
    grad: ['#FF7A50', '#E14526'],
    title: 'Your shift,\nin one app',
    text: 'Log in with the email or phone number your manager gave you. You only see what your job needs.',
    badges: [['restaurant', 'Waiter · Chef · Manager', colors.primary], ['checkmark-circle', 'Ready to start', colors.success]],
  },
  {
    icon: 'grid',
    grad: ['#34D399', '#059669'],
    title: 'Tables and\norders',
    text: 'See which tables are free, take an order at the table and send it straight to the kitchen.',
    badges: [['restaurant', 'Table 4 · 3 guests', '#059669'], ['paper-plane', 'Sent to kitchen', colors.info]],
  },
  {
    icon: 'flame',
    grad: ['#FB7185', '#E11D48'],
    title: 'Kitchen\ntickets',
    text: 'Chefs get every ticket the moment it is placed, and mark dishes ready in one tap.',
    badges: [['time', 'KOT #12 · 8 min', '#E11D48'], ['checkmark-done', 'Ready to serve', colors.success]],
  },
  {
    icon: 'hand-left',
    grad: ['#FBBF24', '#D97706'],
    title: 'Never miss\na call',
    text: 'When a guest taps Call Waiter on the table QR, your phone beeps and shows which table.',
    badges: [['notifications', 'Table 7 is calling', '#D97706'], ['volume-high', 'Beep', colors.warning]],
  },
];

function Slide({ s, width, height }) {
  return (
    <View style={[styles.slide, { width, height: height || undefined }]}>
      <View style={styles.art}>
        <View style={[styles.halo, { backgroundColor: s.grad[0] + '22' }]} />
        <LinearGradient colors={s.grad} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.tile, shadow.lg]}>
          <Ionicons name={s.icon} size={64} color="#fff" />
        </LinearGradient>
        {s.badges.map(([icon, label, tint], i) => (
          <View key={label} style={[styles.badge, shadow.md, i === 0 ? styles.badgeLeft : styles.badgeRight]}>
            <View style={[styles.badgeIcon, { backgroundColor: tint + '1F' }]}>
              <Ionicons name={icon} size={14} color={tint} />
            </View>
            <Text style={styles.badgeText} numberOfLines={1}>{label}</Text>
          </View>
        ))}
      </View>
      <Text style={styles.title}>{s.title}</Text>
      <Text style={styles.text}>{s.text}</Text>
    </View>
  );
}

export default function WelcomeScreen({ onDone }) {
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const listRef = useRef(null);
  const scrollX = useRef(new Animated.Value(0)).current;
  const [index, setIndex] = useState(0);
  const [listH, setListH] = useState(0); // slides fill the space between the top and bottom bars
  const last = index === SLIDES.length - 1;

  const finish = async () => {
    try {
      await AsyncStorage.setItem(KEY, '1');
    } catch (e) {
      // not saved: the slides just show again next time
    }
    onDone();
  };
  const next = () => {
    if (last) return finish();
    listRef.current?.scrollToIndex({ index: index + 1, animated: true });
    setIndex(index + 1);
  };

  return (
    <View style={[styles.fill, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
      <View style={styles.top}>
        <Image source={require('../assets/logo-wordmark.png')} style={styles.logo} resizeMode="contain" accessibilityLabel="RestroGrow" />
        {!last ? (
          <PressableScale onPress={finish} hitSlop={12} accessibilityLabel="Skip">
            <Text style={styles.skip}>Skip</Text>
          </PressableScale>
        ) : null}
      </View>

      <Animated.FlatList
        ref={listRef}
        data={SLIDES}
        keyExtractor={(s) => s.icon}
        horizontal
        pagingEnabled
        bounces={false}
        showsHorizontalScrollIndicator={false}
        renderItem={({ item }) => <Slide s={item} width={width} height={listH} />}
        onLayout={(e) => setListH(e.nativeEvent.layout.height)}
        getItemLayout={(_, i) => ({ length: width, offset: width * i, index: i })}
        onScroll={Animated.event([{ nativeEvent: { contentOffset: { x: scrollX } } }], { useNativeDriver: false })}
        onMomentumScrollEnd={(e) => setIndex(Math.round(e.nativeEvent.contentOffset.x / width))}
        scrollEventThrottle={16}
        style={styles.fill}
      />

      <View style={styles.bottom}>
        <View style={styles.dots}>
          {SLIDES.map((s, i) => {
            const range = [(i - 1) * width, i * width, (i + 1) * width];
            const w = scrollX.interpolate({ inputRange: range, outputRange: [8, 24, 8], extrapolate: 'clamp' });
            const o = scrollX.interpolate({ inputRange: range, outputRange: [0.3, 1, 0.3], extrapolate: 'clamp' });
            return <Animated.View key={s.icon} style={[styles.dot, { width: w, opacity: o }]} />;
          })}
        </View>
        <PressableScale style={[styles.next, shadow.md]} onPress={next} accessibilityLabel={last ? 'Get started' : 'Next'}>
          <Text style={styles.nextText}>{last ? 'Get started' : 'Next'}</Text>
          <Ionicons name="arrow-forward" size={18} color="#fff" />
        </PressableScale>
      </View>
    </View>
  );
}

WelcomeScreen.seen = async () => {
  try {
    return (await AsyncStorage.getItem(KEY)) === '1';
  } catch (e) {
    return true; // storage unreadable: go straight to login rather than loop
  }
};

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  top: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.md,
  },
  logo: { width: 130, height: 24 }, // the wordmark is 1000 × 184
  skip: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.muted, paddingHorizontal: 4 },
  slide: {
    flex: 1,
    paddingHorizontal: spacing.xl + 4,
    justifyContent: 'center',
  },
  art: {
    height: 290,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xl,
  },
  halo: {
    position: 'absolute',
    width: 250,
    height: 250,
    borderRadius: 125,
  },
  tile: {
    width: 150,
    height: 150,
    borderRadius: 44,
    alignItems: 'center',
    justifyContent: 'center',
    transform: [{ rotate: '-6deg' }],
  },
  badge: {
    position: 'absolute',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    paddingVertical: 8,
    paddingLeft: 8,
    paddingRight: 14,
    maxWidth: 210,
  },
  badgeLeft: { top: 34, left: 0 },
  badgeRight: { bottom: 30, right: 0 },
  badgeIcon: {
    width: 26,
    height: 26,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.ink },
  title: {
    fontFamily: font.bold,
    fontSize: 30,
    lineHeight: 36,
    color: colors.ink,
  },
  text: {
    fontFamily: font.regular,
    fontSize: 15,
    lineHeight: 23,
    color: colors.inkSoft,
    marginTop: spacing.md,
  },
  bottom: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
    paddingBottom: spacing.xl,
  },
  dots: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  dot: { height: 8, borderRadius: 4, backgroundColor: colors.primary },
  next: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.primary,
    borderRadius: radius.pill,
    paddingHorizontal: 24,
    height: 52,
  },
  nextText: { fontFamily: font.semiBold, fontSize: 15.5, color: '#fff' },
});
