import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useFocusEffect } from '@react-navigation/native';
import * as Location from 'expo-location';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Platform, RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Avatar from '../components/Avatar';
import FadeIn from '../components/ui/FadeIn';
import { feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import { SkeletonBlock } from '../components/ui/Skeleton';
import { apiGet, apiPostJson, restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { firstName } from '../utils/roles';

// Home for delivery riders (data: api/rider_app.php). My deliveries with the next step one big tap
// away (picked up → on the way → delivered), directions, a call button, the cash to collect, and
// deliveries waiting for a rider (when the restaurant lets riders pick). While a delivery is under way
// the phone's location goes to the customer's tracking page every ~20 seconds, only while the app is open.
const STEP = {
  Pending: ['Open', colors.muted],
  Preparing: ['Open', colors.muted],
  Assigned: ['Go to the restaurant', colors.info],
  Picked_Up: ['Picked up', colors.warning],
  In_Transit: ['On the way', colors.primary],
};
const NEXT = {
  Pending: ['Picked_Up', 'I picked up the order', 'bag-handle'],
  Preparing: ['Picked_Up', 'I picked up the order', 'bag-handle'],
  Assigned: ['Picked_Up', 'I picked up the order', 'bag-handle'],
  Picked_Up: ['In_Transit', 'Start the ride', 'bicycle'],
  In_Transit: ['Delivered', 'Delivered', 'checkmark-done'],
};

function money(cur, n) {
  return `${cur}${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
}

function DeliveryCard({ d, cur, open, busy, onStep, onClaim }) {
  const st = STEP[d.delivery_status] || STEP.Assigned;
  const next = open ? null : NEXT[d.delivery_status];
  const cod = d.cod_due > 0;
  const [cash, setCash] = useState(false);
  const pay = cod ? `Collect ${money(cur, d.cod_due)} in cash` : d.payment_status === 'Paid' ? 'Already paid, collect nothing' : 'Paid online, collect nothing';
  return (
    <View style={[styles.card, shadow.sm]}>
      <View style={styles.cardTop}>
        <View style={{ flex: 1 }}>
          <Text style={styles.orderNo}>{d.order_number}</Text>
          <Text style={styles.sub} numberOfLines={1}>
            {d.customer_name || 'Customer'} · {d.order_status === 'Ready' ? 'food is ready' : `kitchen: ${String(d.order_status).toLowerCase()}`}
          </Text>
        </View>
        <View style={[styles.pill, { backgroundColor: st[1] }]}>
          <Text style={styles.pillText}>{st[0]}</Text>
        </View>
      </View>
      <Text style={styles.addr}>{d.address || 'No address given'}</Text>
      {d.landmark ? <Text style={styles.sub}>Near {d.landmark}</Text> : null}
      {d.items ? <Text style={styles.items}>{d.items}</Text> : null}
      <View style={[styles.cash, !cod && styles.paid]}>
        <Ionicons name={cod ? 'cash-outline' : 'checkmark-circle'} size={18} color={cod ? colors.warning : colors.success} />
        <Text style={[styles.cashText, !cod && { color: colors.success }]}>{pay}</Text>
      </View>
      <View style={styles.row2}>
        {d.maps_url ? (
          <PressableScale style={styles.soft} onPress={() => Linking.openURL(d.maps_url)} accessibilityLabel="Directions">
            <Ionicons name="navigate" size={17} color={colors.ink} />
            <Text style={styles.softText}>Directions</Text>
          </PressableScale>
        ) : null}
        {d.customer_phone ? (
          <PressableScale style={styles.soft} onPress={() => Linking.openURL(`tel:${String(d.customer_phone).replace(/[^\d+]/g, '')}`)} accessibilityLabel="Call customer">
            <Ionicons name="call" size={17} color={colors.ink} />
            <Text style={styles.softText}>Call customer</Text>
          </PressableScale>
        ) : null}
      </View>
      {open ? (
        <PressableScale style={styles.big} onPress={() => onClaim(d)} disabled={busy} feedback="press">
          <Ionicons name={busy ? 'hourglass-outline' : 'add-circle'} size={20} color="#fff" />
          <Text style={styles.bigText}>Take this delivery</Text>
        </PressableScale>
      ) : next ? (
        <>
          {next[0] === 'Delivered' && cod ? (
            <View style={styles.cashCheck}>
              <Switch value={cash} onValueChange={setCash} trackColor={{ true: colors.success }} />
              <Text style={styles.cashCheckText}>I collected {money(cur, d.cod_due)} in cash</Text>
            </View>
          ) : null}
          <PressableScale
            style={[styles.big, next[0] === 'Delivered' && { backgroundColor: colors.success }]}
            onPress={() => {
              if (next[0] === 'Delivered' && cod && !cash) {
                feedback.info('Switch on "I collected the cash" first');
                return;
              }
              onStep(d, next[0], cash);
            }}
            disabled={busy}
            feedback="press"
          >
            <Ionicons name={busy ? 'hourglass-outline' : next[2]} size={20} color="#fff" />
            <Text style={styles.bigText}>{next[1]}</Text>
          </PressableScale>
        </>
      ) : null}
    </View>
  );
}

export default function RiderHomeScreen() {
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [data, setData] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState(null);
  const [loc, setLoc] = useState('idle'); // idle | on | denied | off
  const lastSent = useRef(0);
  const watcher = useRef(null);

  const load = useCallback(async () => {
    try {
      const d = await apiGet('/api/rider_app.php');
      setData(d);
    } catch (e) {
      if (!data) feedback.errorFrom(e, 'Could not load your deliveries');
    }
  }, [data]);

  useFocusEffect(
    useCallback(() => {
      load();
      const t = setInterval(load, 20000);
      return () => clearInterval(t);
    }, [load])
  );

  // live location while something is under way
  const underWay = !!data && data.mine.some((d) => ['Assigned', 'Picked_Up', 'In_Transit'].includes(d.delivery_status));
  useEffect(() => {
    let cancelled = false;
    const send = (lat, lng) => {
      if (Date.now() - lastSent.current < 20000) return;
      lastSent.current = Date.now();
      apiPostJson('/api/rider_app.php', { action: 'location', lat, lng }).catch(() => {});
    };
    async function start() {
      if (Platform.OS === 'web') {
        if (!navigator.geolocation) return setLoc('off');
        watcher.current = { web: navigator.geolocation.watchPosition(
          (p) => { setLoc('on'); send(p.coords.latitude, p.coords.longitude); },
          () => setLoc('denied'),
          { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 }
        ) };
        return;
      }
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (cancelled) return;
      if (status !== 'granted') return setLoc('denied');
      watcher.current = { native: await Location.watchPositionAsync(
        { accuracy: Location.Accuracy.High, timeInterval: 15000, distanceInterval: 30 },
        (p) => { setLoc('on'); send(p.coords.latitude, p.coords.longitude); }
      ) };
    }
    function stop() {
      if (watcher.current?.web !== undefined && navigator.geolocation) navigator.geolocation.clearWatch(watcher.current.web);
      if (watcher.current?.native) watcher.current.native.remove();
      watcher.current = null;
    }
    if (underWay) start(); else { stop(); setLoc('idle'); }
    return () => { cancelled = true; stop(); };
  }, [underWay]);

  const act = async (d, body, done) => {
    setBusy(d.id);
    try {
      const r = await apiPostJson('/api/rider_app.php', body);
      feedback.success(r.message || done);
      await load();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(null);
    }
  };

  const cur = data?.restaurant?.currency || '₹';
  const name = firstName(user);
  const locText = { idle: 'Location sharing starts when you have a delivery.', on: 'Sharing your location with the customer.', denied: 'Allow location so the customer can follow you.', off: 'Location is not available on this device.' }[loc];

  return (
    <ScrollView
      style={{ flex: 1, backgroundColor: colors.bg }}
      contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} tintColor={colors.primary} />}
      showsVerticalScrollIndicator={false}
    >
      <LinearGradient colors={[colors.primary, colors.primaryDark]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
        style={[styles.head, { marginHorizontal: -gutter, paddingHorizontal: gutter, paddingTop: insets.top + spacing.lg }]}>
        <View style={styles.headRow}>
          <Avatar name={user?.restaurant_name} uri={restaurantLogoUrl(user)} size={48} bg="rgba(255,255,255,0.22)" color="#fff" />
          <View style={{ flex: 1 }}>
            <Text style={styles.hello} numberOfLines={1}>Hi{name ? ` ${name}` : ''} 👋</Text>
            <Text style={styles.headName} numberOfLines={2}>{user?.restaurant_name || 'Your restaurant'}</Text>
          </View>
        </View>
        <View style={styles.chip}>
          <Ionicons name="bicycle" size={13} color="#fff" />
          <Text style={styles.chipText}>Delivery rider</Text>
        </View>
        <View style={styles.stats}>
          {[['to deliver', data ? String(data.mine.length) : '—'], ['delivered today', data ? String(data.delivered_today) : '—'], ['cash with you', data ? money(cur, data.cash_in_hand) : '—']].map(([l, v]) => (
            <View key={l} style={styles.stat}>
              <Text style={styles.statV} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
              <Text style={styles.statL}>{l}</Text>
            </View>
          ))}
        </View>
      </LinearGradient>

      <View style={styles.loc}>
        <View style={[styles.dot, loc === 'on' && styles.dotOn]} />
        <Text style={styles.locText}>{locText}</Text>
      </View>

      {!data ? (
        <SkeletonBlock height={220} style={{ borderRadius: radius.lg }} />
      ) : data.enabled === false ? (
        <View style={styles.empty}><Text style={styles.emptyText}>{data.message || 'Your restaurant is not using its own riders right now.'}</Text></View>
      ) : (
        <FadeIn style={{ gap: spacing.md }}>
          <Text style={styles.h2}>Your deliveries <Text style={styles.h2n}>{data.mine.length}</Text></Text>
          {data.mine.length ? data.mine.map((d) => (
            <DeliveryCard key={d.id} d={d} cur={cur} busy={busy === d.id}
              onStep={(x, status, cash) => act(x, { action: 'step', order_id: x.id, status, cod_collected: cash ? 1 : 0 }, 'Done')} />
          )) : (
            <View style={styles.empty}><Text style={styles.emptyText}>Nothing to deliver right now. New deliveries show up here by themselves.</Text></View>
          )}

          {data.self_pick && data.open.length ? (
            <>
              <Text style={styles.h2}>Waiting for a rider <Text style={styles.h2n}>{data.open.length}</Text></Text>
              {data.open.map((d) => (
                <DeliveryCard key={d.id} d={d} cur={cur} open busy={busy === d.id} onClaim={(x) => act(x, { action: 'claim', order_id: x.id }, 'Taken')} />
              ))}
            </>
          ) : null}

          {data.done_today.length ? (
            <>
              <Text style={styles.h2}>Delivered today <Text style={styles.h2n}>{data.done_today.length}</Text></Text>
              <View style={[styles.card, shadow.sm, { gap: 0 }]}>
                {data.done_today.map((d) => (
                  <View key={d.id} style={styles.doneRow}>
                    <Text style={styles.doneText} numberOfLines={1}>{d.order_number} · {d.customer_name || ''}</Text>
                    <Text style={styles.doneAmt}>{d.cod_amount ? `${money(cur, d.cod_amount)} cash` : 'paid'}</Text>
                  </View>
                ))}
              </View>
            </>
          ) : null}

          {data.restaurant?.phone ? (
            <PressableScale style={styles.soft} onPress={() => Linking.openURL(`tel:${String(data.restaurant.phone).replace(/[^\d+]/g, '')}`)}>
              <Ionicons name="storefront-outline" size={17} color={colors.ink} />
              <Text style={styles.softText}>Call the restaurant</Text>
            </PressableScale>
          ) : null}
        </FadeIn>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  head: { paddingBottom: spacing.xl, borderBottomLeftRadius: 28, borderBottomRightRadius: 28, gap: spacing.md },
  headRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  hello: { fontFamily: font.medium, fontSize: 14, color: 'rgba(255,255,255,0.9)' },
  headName: { fontFamily: font.bold, fontSize: 20, color: '#fff' },
  chip: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 10, paddingVertical: 5, borderRadius: 999, backgroundColor: 'rgba(255,255,255,0.2)' },
  chipText: { fontFamily: font.semiBold, fontSize: 12, color: '#fff' },
  stats: { flexDirection: 'row', gap: spacing.sm },
  stat: { flex: 1, backgroundColor: 'rgba(255,255,255,0.16)', borderRadius: radius.md, padding: spacing.md },
  statV: { fontFamily: font.bold, fontSize: 19, color: '#fff' },
  statL: { fontFamily: font.medium, fontSize: 11.5, color: 'rgba(255,255,255,0.9)' },
  loc: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, backgroundColor: colors.surface, borderRadius: radius.md, padding: spacing.md, borderWidth: 1, borderColor: colors.border },
  dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: colors.muted },
  dotOn: { backgroundColor: colors.success },
  locText: { flex: 1, fontFamily: font.regular, fontSize: 13, color: colors.inkSoft },
  h2: { fontFamily: font.bold, fontSize: 16, color: colors.ink, marginTop: spacing.xs },
  h2n: { fontFamily: font.medium, color: colors.muted },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: spacing.lg, gap: spacing.sm },
  cardTop: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm },
  orderNo: { fontFamily: font.bold, fontSize: 15.5, color: colors.ink },
  sub: { fontFamily: font.regular, fontSize: 12.5, color: colors.muted },
  pill: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999 },
  pillText: { fontFamily: font.semiBold, fontSize: 11.5, color: '#fff' },
  addr: { fontFamily: font.medium, fontSize: 14.5, color: colors.ink, lineHeight: 21 },
  items: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, backgroundColor: colors.bg, borderRadius: radius.sm, padding: spacing.sm },
  cash: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, backgroundColor: colors.warningBg, borderRadius: radius.sm, padding: spacing.sm },
  paid: { backgroundColor: colors.successBg },
  cashText: { fontFamily: font.semiBold, fontSize: 14, color: '#9A5B00' },
  row2: { flexDirection: 'row', gap: spacing.sm },
  soft: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: '#F3EFE8', borderRadius: radius.md, paddingVertical: 12 },
  softText: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  big: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: colors.primary, borderRadius: radius.md, paddingVertical: 15 },
  bigText: { fontFamily: font.bold, fontSize: 15.5, color: '#fff' },
  cashCheck: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: 2 },
  cashCheckText: { flex: 1, fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  empty: { borderWidth: 1, borderStyle: 'dashed', borderColor: colors.border, borderRadius: radius.lg, padding: spacing.xl, backgroundColor: colors.surface },
  emptyText: { fontFamily: font.regular, fontSize: 14, color: colors.muted, textAlign: 'center' },
  doneRow: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.border },
  doneText: { flex: 1, fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft },
  doneAmt: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink },
});
