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
// Same orange top as the login screen, so "Get started" flows straight into it.
const KEY = 'welcome_seen_v1';

// Small pieces of the real app, drawn with plain views so they stay sharp on every screen.
function Row({ left, right, bold }) {
  return (
    <View style={styles.row}>
      <Text style={[styles.rowLeft, bold && styles.rowBold]} numberOfLines={1}>{left}</Text>
      <Text style={[styles.rowRight, bold && styles.rowBold]}>{right}</Text>
    </View>
  );
}

function Chip({ icon, label, tint, style }) {
  return (
    <View style={[styles.chip, shadow.md, style]}>
      <View style={[styles.chipIcon, { backgroundColor: tint + '1F' }]}>
        <Ionicons name={icon} size={13} color={tint} />
      </View>
      <Text style={styles.chipText} numberOfLines={1}>{label}</Text>
    </View>
  );
}

function HomePreview() {
  const tiles = [['add', '#FF5A3C', 'New order'], ['flame', '#F43F5E', 'Kitchen'], ['grid', '#10B981', 'Tables'], ['receipt', '#3B82F6', 'Orders']];
  return (
    <View>
      <View style={styles.dark}>
        <View style={styles.darkTop}>
          <Text style={styles.darkLabel}>Sales today</Text>
          <View style={styles.live}><View style={styles.liveDot} /><Text style={styles.liveText}>Live</Text></View>
        </View>
        <Text style={styles.darkBig}>₹12,480</Text>
        <View style={styles.darkStats}>
          {[['26', 'Orders'], ['₹480', 'Avg bill'], ['5', 'Cooking']].map(([n, l]) => (
            <View key={l}><Text style={styles.darkN}>{n}</Text><Text style={styles.darkL}>{l}</Text></View>
          ))}
        </View>
      </View>
      <View style={styles.tiles}>
        {tiles.map(([icon, bg, label]) => (
          <View key={label} style={styles.tileWrap}>
            <View style={[styles.tileIcon, { backgroundColor: bg }]}><Ionicons name={icon} size={18} color="#fff" /></View>
            <Text style={styles.tileText}>{label}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

function OrderPreview() {
  return (
    <View>
      <View style={styles.orderTop}>
        <View style={styles.bell}><Ionicons name="notifications" size={16} color="#fff" /></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.cardTitle}>New order · #1042</Text>
          <Text style={styles.cardSub}>Delivery · Priya S. · just now</Text>
        </View>
      </View>
      <View style={styles.divider} />
      <Row left="2× Paneer Tikka" right="₹498" />
      <Row left="1× Garlic Naan" right="₹60" />
      <Row left="Total" right="₹558" bold />
      <View style={styles.btnRow}>
        <View style={[styles.btn, styles.btnGhost]}><Text style={[styles.btnText, { color: colors.danger }]}>Reject</Text></View>
        <View style={[styles.btn, styles.btnGo]}><Text style={styles.btnText}>Accept · 15 min</Text></View>
      </View>
    </View>
  );
}

function BillPreview() {
  return (
    <View>
      <View style={styles.billHead}>
        <Text style={styles.cardTitle}>Table 4 · Bill</Text>
        <View style={styles.paid}><Ionicons name="checkmark-circle" size={12} color={colors.success} /><Text style={styles.paidText}>Paid · UPI</Text></View>
      </View>
      <View style={styles.dashed} />
      <Row left="1× Butter Chicken" right="₹349" />
      <Row left="2× Butter Naan" right="₹120" />
      <Row left="1× Sweet Lassi" right="₹99" />
      <Row left="GST 5%" right="₹28.40" />
      <View style={styles.dashed} />
      <Row left="Total" right="₹596.40" bold />
    </View>
  );
}

function ChartPreview() {
  const bars = [0.42, 0.55, 0.38, 0.7, 0.6, 0.82, 1];
  const days = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
  return (
    <View>
      <Text style={styles.cardSub}>This week</Text>
      <View style={styles.chartHead}>
        <Text style={styles.chartBig}>₹84,320</Text>
        <View style={styles.up}><Ionicons name="trending-up" size={12} color={colors.success} /><Text style={styles.upText}>18%</Text></View>
      </View>
      <View style={styles.bars}>
        {bars.map((h, i) => (
          <View key={i} style={styles.barCol}>
            <View style={[styles.bar, { height: 74 * h, backgroundColor: i === bars.length - 1 ? colors.primary : colors.primary + '33' }]} />
            <Text style={styles.barDay}>{days[i]}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

const SLIDES = [
  {
    key: 'home',
    Preview: HomePreview,
    chip: ['checkmark-circle', 'Table 4 ready', colors.success],
    title: 'Your restaurant,\nin your pocket',
    text: 'Orders, kitchen, tables and billing in one app, always in sync with your RestroGrow website.',
  },
  {
    key: 'orders',
    Preview: OrderPreview,
    chip: ['volume-high', 'Ringing', colors.warning],
    title: 'Never miss an order',
    text: 'A loud ring for every new online order, even when your phone is locked. Accept it in one tap.',
  },
  {
    key: 'bill',
    Preview: BillPreview,
    chip: ['print', 'Bill printed', '#059669'],
    title: 'Bill in seconds',
    text: 'Take counter orders, send tickets to the kitchen and print bills on your Bluetooth or Wi-Fi printer.',
  },
  {
    key: 'numbers',
    Preview: ChartPreview,
    chip: ['star', 'Top dish: Butter Chicken', '#7C3AED'],
    title: 'Know your numbers',
    text: "Today's sales, best-selling dishes and reports for any dates, wherever you are.",
  },
];

function Slide({ s, width, heroH }) {
  const cardW = Math.min(width - 72, 320);
  const { Preview } = s;
  return (
    <View style={{ width }}>
      <View style={[styles.hero, { height: heroH }]}>
        <View style={{ width: cardW }}>
          <View style={[styles.card, shadow.lg]}>
            <Preview />
          </View>
          <Chip icon={s.chip[0]} label={s.chip[1]} tint={s.chip[2]} style={styles.chipPos} />
        </View>
      </View>
      <View style={styles.copy}>
        <Text style={styles.title}>{s.title}</Text>
        <Text style={styles.text}>{s.text}</Text>
      </View>
    </View>
  );
}

export default function WelcomeScreen({ onDone }) {
  const { width: windowW, height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  // one slide = the app's own width, not the window's: in a browser the app sits in a narrow
  // phone frame, and window-wide slides put the preview card off to the side, out of view
  const [width, setWidth] = useState(windowW);
  const listRef = useRef(null);
  const scrollX = useRef(new Animated.Value(0)).current;
  const [index, setIndex] = useState(0);
  const last = index === SLIDES.length - 1;
  // the orange area runs behind the top bar and holds the preview card; the words sit below it
  const heroH = Math.max(250, Math.min(Math.round(height * 0.56) - insets.top - 56, 440));
  const bandH = insets.top + 56 + heroH;

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
    listRef.current?.scrollTo({ x: width * (index + 1), animated: true });
    setIndex(index + 1);
  };

  return (
    <View style={[styles.fill, { paddingBottom: insets.bottom }]}>
      <LinearGradient colors={[colors.primary, colors.primaryDark]} style={[styles.band, { height: bandH }]}>
        <View style={styles.ring1} />
        <View style={styles.ring2} />
      </LinearGradient>

      <View style={[styles.top, { paddingTop: insets.top + spacing.md }]}>
        <View style={[styles.logoPill, shadow.sm]}>
          <Image source={require('../assets/logo-wordmark.png')} style={styles.logo} resizeMode="contain" accessibilityLabel="RestroGrow" />
        </View>
        {!last ? (
          <PressableScale onPress={finish} hitSlop={12} accessibilityLabel="Skip" style={styles.skipBtn}>
            <Text style={styles.skip}>Skip</Text>
          </PressableScale>
        ) : null}
      </View>

      {/* a plain ScrollView, not a FlatList: all four slides stay drawn, so the next one is
          never blank while it slides in */}
      <Animated.ScrollView
        ref={listRef}
        horizontal
        pagingEnabled
        bounces={false}
        removeClippedSubviews={false}
        showsHorizontalScrollIndicator={false}
        onScroll={Animated.event([{ nativeEvent: { contentOffset: { x: scrollX } } }], {
          useNativeDriver: false,
          // browsers don't send a momentum-end event, so settle the page here too
          listener: (e) => {
            const x = e.nativeEvent.contentOffset.x;
            if (Math.abs(x / width - Math.round(x / width)) < 0.02) setIndex(Math.round(x / width));
          },
        })}
        onMomentumScrollEnd={(e) => setIndex(Math.round(e.nativeEvent.contentOffset.x / width))}
        scrollEventThrottle={16}
        onLayout={(e) => {
          const w = Math.round(e.nativeEvent.layout.width);
          if (w > 0 && w !== width) {
            setWidth(w);
            listRef.current?.scrollTo({ x: w * index, animated: false });
          }
        }}
        style={styles.list}
      >
        {SLIDES.map((item) => <Slide key={item.key} s={item} width={width} heroH={heroH} />)}
      </Animated.ScrollView>

      <View style={styles.bottom}>
        <View style={styles.dots}>
          {SLIDES.map((s, i) => {
            const range = [(i - 1) * width, i * width, (i + 1) * width];
            const w = scrollX.interpolate({ inputRange: range, outputRange: [8, 24, 8], extrapolate: 'clamp' });
            const o = scrollX.interpolate({ inputRange: range, outputRange: [0.3, 1, 0.3], extrapolate: 'clamp' });
            return <Animated.View key={s.key} style={[styles.dot, { width: w, opacity: o }]} />;
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
  band: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    borderBottomLeftRadius: 36,
    borderBottomRightRadius: 36,
    overflow: 'hidden',
  },
  ring1: { position: 'absolute', width: 320, height: 320, borderRadius: 160, borderWidth: 40, borderColor: 'rgba(255,255,255,0.06)', top: -90, right: -120 },
  ring2: { position: 'absolute', width: 220, height: 220, borderRadius: 110, borderWidth: 30, borderColor: 'rgba(255,255,255,0.05)', bottom: -60, left: -80 },
  top: {
    minHeight: 60,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing.md,
  },
  logoPill: { backgroundColor: '#fff', borderRadius: 14, paddingHorizontal: 14, paddingVertical: 8 },
  logo: { width: 110, height: 20 }, // the wordmark is 1000 × 184
  skipBtn: { backgroundColor: 'rgba(255,255,255,0.18)', borderRadius: radius.pill, paddingHorizontal: 14, paddingVertical: 7 },
  skip: { fontFamily: font.semiBold, fontSize: 13.5, color: '#fff' },
  list: { flex: 1 },
  hero: { alignItems: 'center', justifyContent: 'center', paddingBottom: spacing.lg },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: spacing.lg },
  chip: {
    position: 'absolute',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 7,
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    paddingVertical: 6,
    paddingLeft: 6,
    paddingRight: 12,
    maxWidth: 220,
  },
  chipPos: { bottom: -18, right: -10 },
  chipIcon: { width: 24, height: 24, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  chipText: { fontFamily: font.semiBold, fontSize: 12, color: colors.ink },
  copy: { paddingHorizontal: spacing.xl + 4, paddingTop: spacing.xxl + 4 },
  title: { fontFamily: font.bold, fontSize: 27, lineHeight: 34, color: colors.ink },
  text: { fontFamily: font.regular, fontSize: 15, lineHeight: 23, color: colors.inkSoft, marginTop: spacing.sm + 2 },
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

  // previews
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 4 },
  rowLeft: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, flex: 1, marginRight: 8 },
  rowRight: { fontFamily: font.medium, fontSize: 12.5, color: colors.ink },
  rowBold: { fontFamily: font.bold, fontSize: 14, color: colors.ink },
  cardTitle: { fontFamily: font.bold, fontSize: 14, color: colors.ink },
  cardSub: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, marginTop: 1 },
  divider: { height: 1, backgroundColor: colors.border, marginVertical: spacing.sm },
  dashed: { borderTopWidth: 1, borderStyle: 'dashed', borderColor: colors.border, marginVertical: spacing.sm },

  dark: { backgroundColor: '#1D1B26', borderRadius: radius.md, padding: spacing.md },
  darkTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  darkLabel: { fontFamily: font.medium, fontSize: 11.5, color: 'rgba(255,255,255,0.7)' },
  live: { flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: 'rgba(16,185,129,0.18)', borderRadius: radius.pill, paddingHorizontal: 7, paddingVertical: 2 },
  liveDot: { width: 5, height: 5, borderRadius: 3, backgroundColor: '#34D399' },
  liveText: { fontFamily: font.semiBold, fontSize: 10, color: '#34D399' },
  darkBig: { fontFamily: font.bold, fontSize: 24, color: '#fff', marginTop: 4 },
  darkStats: { flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing.sm, paddingTop: spacing.sm, borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.1)' },
  darkN: { fontFamily: font.bold, fontSize: 13, color: '#fff' },
  darkL: { fontFamily: font.regular, fontSize: 10, color: 'rgba(255,255,255,0.6)' },
  tiles: { flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing.md },
  tileWrap: { alignItems: 'center', gap: 4 },
  tileIcon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  tileText: { fontFamily: font.medium, fontSize: 10, color: colors.inkSoft },

  orderTop: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  bell: { width: 34, height: 34, borderRadius: 17, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  btnRow: { flexDirection: 'row', gap: 8, marginTop: spacing.md },
  btn: { height: 36, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  btnGhost: { flex: 0.7, borderWidth: 1, borderColor: colors.danger + '55' },
  btnGo: { flex: 1.3, backgroundColor: colors.success },
  btnText: { fontFamily: font.semiBold, fontSize: 12.5, color: '#fff' },

  billHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  paid: { flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: colors.success + '1A', borderRadius: radius.pill, paddingHorizontal: 8, paddingVertical: 3 },
  paidText: { fontFamily: font.semiBold, fontSize: 10.5, color: colors.success },

  chartHead: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 2 },
  chartBig: { fontFamily: font.bold, fontSize: 22, color: colors.ink },
  up: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: colors.success + '1A', borderRadius: radius.pill, paddingHorizontal: 7, paddingVertical: 2 },
  upText: { fontFamily: font.semiBold, fontSize: 11, color: colors.success },
  bars: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', height: 96, marginTop: spacing.md },
  barCol: { alignItems: 'center', gap: 5, flex: 1 },
  bar: { width: 20, borderRadius: 6 },
  barDay: { fontFamily: font.medium, fontSize: 10, color: colors.muted },
});
