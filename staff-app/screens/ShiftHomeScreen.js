import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useFocusEffect } from '@react-navigation/native';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Avatar from '../components/Avatar';
import SoundToggle from '../components/SoundToggle';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { SkeletonBlock } from '../components/ui/Skeleton';
import { apiGet, restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { durationLabel, minutesSince, realName, timeAgo } from '../utils/format';
import { useLiveCounts } from '../utils/liveCounts';
import { refreshLive, useLive } from '../utils/liveStore';
import { goOrders, goTo } from '../utils/nav';
import { firstName, ROLE_INFO, staffRole } from '../utils/roles';
import { attendCall, moveKot } from '../utils/staffActions';
import { useAccess } from '../utils/access';

function greeting() {
  const h = new Date().getHours();
  if (h < 5) return 'Working late';
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

const LATE_MIN = 20;
const CALL_ICON = { Bill: 'receipt-outline', Water: 'water-outline', 'Clean Table': 'sparkles-outline', Order: 'restaurant-outline', General: 'hand-left-outline' };

function where(k) {
  return k.table_number ? `Table ${k.table_number}` : k.order_type || 'Order';
}

function itemsText(k) {
  return (k.items || []).map((it) => `${Number(it.quantity) || 1}× ${it.item_name}`).join(', ');
}

// One small action button on a row ("Start", "Ready", "Served", "Done").
function RowAction({ label, icon, tone = colors.primary, onPress, busy }) {
  return (
    <PressableScale style={[styles.rowBtn, { backgroundColor: tone }]} onPress={onPress} disabled={busy} feedback="press" scale={0.94} accessibilityLabel={label}>
      <Ionicons name={busy ? 'hourglass-outline' : icon} size={14} color="#fff" />
      <Text style={styles.rowBtnText}>{label}</Text>
    </PressableScale>
  );
}

// Home for waiters and chefs: what needs doing on this shift right now, with the next step one
// tap away on each row. Kitchen tickets and waiter calls come from the app-wide live feed, so a
// change on any phone shows here within seconds. Managers get the sales Home (HomeScreen).
export default function ShiftHomeScreen({ navigation }) {
  const { user } = useAuth();
  const canSee = useAccess(); // sections Super Admin switched off (utils/access.js)
  const role = staffRole(user);
  const isChef = role === 'chef';
  const info = ROLE_INFO[role];
  const tabBarHeight = useBottomTabBarHeight();
  const insets = useSafeAreaInsets();
  const { gutter, columns } = useLayout();
  const counts = useLiveCounts();
  const live = useLive();
  const name = firstName(user);
  const [busy, setBusy] = useState(null);
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 30000);
    return () => clearInterval(id);
  }, []);

  // the floor plan only matters to waiters
  const tablesFetcher = useCallback(() => (isChef ? Promise.resolve(null) : apiGet('/api/get_table_status.php')), [isChef]);
  const tablesData = useApiData(tablesFetcher, { pollInterval: isChef ? 0 : 20000, cacheKey: isChef ? null : 'shift_tables', refetchOnFocus: !isChef });
  const tables = tablesData.data?.data || [];

  useFocusEffect(
    useCallback(() => {
      refreshLive();
    }, [])
  );

  const [refreshing, setRefreshing] = useState(false);
  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await Promise.all([refreshLive(), isChef ? null : tablesData.reload('background')]);
    setRefreshing(false);
  }, [isChef, tablesData.reload]);

  const { kots, calls, loaded, online } = live;
  const byAge = (a, b) => String(a.created_at).localeCompare(String(b.created_at));
  const queue = useMemo(() => kots.filter((k) => k.kot_status === 'Pending').sort(byAge), [kots]);
  const cooking = useMemo(() => kots.filter((k) => k.kot_status === 'Preparing').sort(byAge), [kots]);
  const ready = useMemo(() => kots.filter((k) => k.kot_status === 'Ready').sort(byAge), [kots]);
  const late = [...queue, ...cooking].filter((k) => minutesSince(k.created_at, now) >= LATE_MIN);
  const oldest = [...queue, ...cooking].reduce((m, k) => Math.max(m, minutesSince(k.created_at, now)), 0);
  const busyTables = tables.filter((t) => t.status === 'occupied').length;
  const freeTables = tables.filter((t) => !['occupied', 'reserved'].includes(t.status)).length;

  // chefs: every dish still to make, added up across tickets ("6× Butter Naan")
  const toCook = useMemo(() => {
    if (!isChef) return [];
    const m = new Map();
    [...queue, ...cooking].forEach((k) =>
      (k.items || []).forEach((it) => {
        const key = it.item_name || 'Item';
        m.set(key, (m.get(key) || 0) + (Number(it.quantity) || 1));
      })
    );
    return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8);
  }, [isChef, queue, cooking]);

  const act = async (key, fn) => {
    setBusy(key);
    await fn();
    setBusy(null);
  };

  const hero = isChef
    ? {
        label: 'Tickets to cook',
        big: String(queue.length),
        hint: queue.length ? `Oldest has waited ${durationLabel(minutesSince(queue[0].created_at, now))}` : cooking.length ? `${cooking.length} on the stove, nothing new` : 'No new tickets. Nice and calm.',
        stats: [
          ['Cooking', String(cooking.length)],
          ['Ready', String(ready.length)],
          ['Longest wait', oldest ? durationLabel(oldest) : '—'],
        ],
      }
    : {
        label: 'Tables busy',
        big: tables.length ? `${busyTables} of ${tables.length}` : '—',
        hint: tables.length ? `${freeTables} free for new guests` : 'Tables appear here once your manager adds them',
        stats: [
          ['Calling', String(calls.length)],
          ['Food ready', String(ready.length)],
          ['New orders', String(counts.newOrders || 0)],
        ],
      };

  const attention = (isChef
    ? [
        { n: late.length, icon: 'alarm', tone: colors.danger, bg: colors.dangerBg, title: (n) => `${n} ticket${n > 1 ? 's' : ''} waiting over ${LATE_MIN} min`, sub: late.slice(0, 3).map(where).join(', '), go: () => goTo(navigation, 'Kitchen', { stage: late[0]?.kot_status || 'Pending' }), screen: 'Kitchen' },
        { n: ready.length, icon: 'checkmark-done', tone: colors.success, bg: colors.successBg, title: (n) => `${n} ready, waiting for pickup`, sub: 'Waiters are told when food is ready', go: () => goTo(navigation, 'Kitchen', { stage: 'Ready' }), screen: 'Kitchen' },
      ]
    : [
        { n: counts.newOrders || 0, icon: 'notifications', tone: colors.danger, bg: colors.dangerBg, title: (n) => `${n} new order${n > 1 ? 's' : ''} to accept`, sub: 'Guests are waiting for your answer', go: () => goOrders(navigation, 'new') },
      ]
  ).filter((a) => a.n > 0 && (!a.screen || canSee(a.screen)));

  const quick = isChef
    ? [
        { icon: 'flame', label: 'Kitchen', c: ['#FB7185', '#E11D48'], go: () => goTo(navigation, 'Kitchen', { stage: 'Pending' }), screen: 'Kitchen' },
        { icon: 'timer', label: 'Cooking', c: ['#A78BFA', '#7C3AED'], go: () => goTo(navigation, 'Kitchen', { stage: 'Preparing' }), screen: 'Kitchen' },
        { icon: 'checkmark-done', label: 'Ready', c: ['#34D399', '#059669'], go: () => goTo(navigation, 'Kitchen', { stage: 'Ready' }), screen: 'Kitchen' },
        { icon: 'receipt', label: 'Orders', c: ['#60A5FA', '#2563EB'], go: () => goOrders(navigation, 'cooking') },
      ]
    : [
        { icon: 'add', label: 'New order', c: ['#FF7A50', '#F0461F'], go: () => goTo(navigation, 'POS'), screen: 'POS' },
        { icon: 'grid', label: 'Tables', c: ['#34D399', '#059669'], go: () => goTo(navigation, 'Tables'), screen: 'Tables' },
        { icon: 'hand-left', label: 'Calls', c: ['#FBBF24', '#D97706'], go: () => goTo(navigation, 'WaiterCalls'), badge: calls.length, screen: 'WaiterCalls' },
        { icon: 'receipt', label: 'Orders', c: ['#60A5FA', '#2563EB'], go: () => goOrders(navigation, counts.newOrders ? 'new' : 'cooking'), badge: counts.newOrders },
        { icon: 'flame', label: 'Kitchen', c: ['#FB7185', '#E11D48'], go: () => goTo(navigation, 'Kitchen'), screen: 'Kitchen' },
        { icon: 'calendar', label: 'Bookings', c: ['#A78BFA', '#7C3AED'], go: () => goTo(navigation, 'Reservations'), screen: 'Reservations' },
        { icon: 'people', label: 'Customers', c: ['#38BDF8', '#0284C7'], go: () => goTo(navigation, 'Customers'), screen: 'Customers' },
        { icon: 'settings', label: 'Settings', c: ['#94A3B8', '#475569'], go: () => goTo(navigation, 'Settings'), screen: 'Settings' },
      ];
  const shortcuts = quick.filter((q) => !q.screen || canSee(q.screen));
  const qCols = shortcuts.length <= 4 ? 4 : columns(72, 8) >= 8 ? 8 : 4;

  const nothingToDo = loaded && !attention.length && (isChef ? !queue.length && !cooking.length : !calls.length && !ready.length);

  return (
    <View style={styles.fill}>
      <ScrollView
        contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} colors={[colors.primary]} />}
        showsVerticalScrollIndicator={false}
      >
        <LinearGradient
          colors={[colors.primary, colors.primaryDark]}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={[styles.head, { marginHorizontal: -gutter, paddingHorizontal: gutter, paddingTop: insets.top + spacing.lg }]}
        >
          <View style={styles.headRow}>
            <Avatar name={user?.restaurant_name} uri={restaurantLogoUrl(user)} size={48} bg="rgba(255,255,255,0.22)" color="#fff" />
            <View style={{ flex: 1 }}>
              <Text style={styles.hello} numberOfLines={1}>{greeting()}{name ? `, ${name}` : ''}</Text>
              <Text style={styles.headName} numberOfLines={2}>{user?.restaurant_name || 'Your restaurant'}</Text>
            </View>
            <SoundToggle />
          </View>
          <View style={styles.chips}>
            <View style={styles.chip}>
              <Ionicons name={info.icon} size={13} color="#fff" />
              <Text style={styles.chipText}>{info.label}</Text>
            </View>
            <View style={styles.chip}>
              <Ionicons name="calendar-clear-outline" size={13} color="#fff" />
              <Text style={styles.chipText}>{new Date().toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}</Text>
            </View>
          </View>
        </LinearGradient>

        <FadeIn>
          <LinearGradient colors={['#2A2735', '#1D1B26']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.hero, shadow.md, { marginTop: -60 }]}>
            <View style={styles.heroTop}>
              <Text style={styles.heroLabel}>{hero.label}</Text>
              <View style={[styles.livePill, !online && { backgroundColor: 'rgba(255,138,128,0.16)' }]}>
                <View style={[styles.liveDot, !online && { backgroundColor: '#FF8A80' }]} />
                <Text style={[styles.livePillText, !online && { color: '#FF8A80' }]}>{online ? 'Live' : 'Offline'}</Text>
              </View>
            </View>
            {!loaded ? (
              <SkeletonBlock width="45%" height={34} style={{ marginTop: 8, backgroundColor: 'rgba(255,255,255,0.12)' }} />
            ) : (
              <Text style={styles.big} numberOfLines={1} adjustsFontSizeToFit>{hero.big}</Text>
            )}
            <Text style={styles.heroHint}>{loaded ? hero.hint : ' '}</Text>
            <View style={styles.miniStats}>
              {hero.stats.map(([l, v]) => (
                <View key={l} style={{ flex: 1 }}>
                  <Text style={styles.miniValue} numberOfLines={1} adjustsFontSizeToFit>{loaded ? v : '—'}</Text>
                  <Text style={styles.miniLabel}>{l}</Text>
                </View>
              ))}
            </View>
          </LinearGradient>
        </FadeIn>

        {!online ? (
          <View style={styles.offline}>
            <Ionicons name="cloud-offline-outline" size={17} color={colors.danger} />
            <Text style={styles.offlineText}>No connection. Showing the last update, retrying by itself.</Text>
          </View>
        ) : null}

        <FadeIn index={1}>
          <View style={[styles.card, shadow.sm, styles.quickCard]}>
            {shortcuts.map((q) => (
              <PressableScale key={q.label} style={[styles.quick, { width: `${100 / qCols}%` }]} onPress={q.go} accessibilityLabel={q.label} scale={0.92}>
                <View>
                  <LinearGradient colors={q.c} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.quickIcon}>
                    <Ionicons name={q.icon} size={23} color="#fff" />
                  </LinearGradient>
                  {q.badge ? (
                    <View style={styles.quickBadge}>
                      <Text style={styles.quickBadgeText}>{q.badge > 9 ? '9+' : q.badge}</Text>
                    </View>
                  ) : null}
                </View>
                <Text style={styles.quickText} numberOfLines={1}>{q.label}</Text>
              </PressableScale>
            ))}
          </View>
        </FadeIn>

        {attention.length ? (
          <FadeIn index={2}>
            <View style={{ gap: spacing.sm }}>
              {attention.map((a) => (
                <PressableScale key={a.icon} style={[styles.attn, shadow.sm]} onPress={a.go}>
                  <View style={[styles.attnIcon, { backgroundColor: a.bg }]}>
                    <Ionicons name={a.icon} size={18} color={a.tone} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.attnTitle}>{a.title(a.n)}</Text>
                    {a.sub ? <Text style={styles.attnSub} numberOfLines={1}>{a.sub}</Text> : null}
                  </View>
                  <Ionicons name="chevron-forward" size={18} color={colors.muted} />
                </PressableScale>
              ))}
            </View>
          </FadeIn>
        ) : null}

        {/* waiters: tables calling, each with a one-tap Done */}
        {!isChef && calls.length ? (
          <FadeIn index={3}>
            <View style={styles.cardHead}>
              <Text style={styles.sectionTitle}>Tables calling</Text>
              {canSee('WaiterCalls') ? (
                <PressableScale onPress={() => goTo(navigation, 'WaiterCalls')} hitSlop={8}>
                  <Text style={styles.link}>See all</Text>
                </PressableScale>
              ) : null}
            </View>
            <View style={[styles.card, shadow.sm, styles.listCard]}>
              {calls.slice(0, 5).map((c, i) => (
                <View key={c.id} style={[styles.row, i > 0 && styles.rowLine]}>
                  <View style={[styles.rowIcon, { backgroundColor: colors.warningBg }]}>
                    <Ionicons name={CALL_ICON[c.request_type] || CALL_ICON.General} size={16} color={colors.warning} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rowTitle} numberOfLines={1}>
                      {c.table_number ? `Table ${c.table_number}` : 'Table'}
                      {c.request_type && c.request_type !== 'General' ? ` · ${c.request_type}` : ''}
                    </Text>
                    <Text style={styles.rowSub} numberOfLines={1}>{[c.notes, timeAgo(c.created_at)].filter(Boolean).join(' · ') || 'Calling now'}</Text>
                  </View>
                  <RowAction label="Done" icon="checkmark" tone={colors.warning} busy={busy === `c${c.id}`} onPress={() => act(`c${c.id}`, () => attendCall(c))} />
                </View>
              ))}
            </View>
          </FadeIn>
        ) : null}

        {/* waiters: food at the pass, each with a one-tap Served */}
        {!isChef && ready.length ? (
          <FadeIn index={4}>
            <View style={styles.cardHead}>
              <Text style={styles.sectionTitle}>Ready to serve</Text>
              {canSee('Kitchen') ? (
                <PressableScale onPress={() => goTo(navigation, 'Kitchen', { stage: 'Ready' })} hitSlop={8}>
                  <Text style={styles.link}>Open kitchen</Text>
                </PressableScale>
              ) : null}
            </View>
            <View style={[styles.card, shadow.sm, styles.listCard]}>
              {ready.slice(0, 6).map((k, i) => (
                <View key={k.id} style={[styles.row, i > 0 && styles.rowLine]}>
                  <View style={[styles.rowIcon, { backgroundColor: colors.successBg }]}>
                    <Ionicons name="restaurant" size={16} color={colors.success} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rowTitle} numberOfLines={1}>{where(k)}{realName(k.customer_name) ? ` · ${realName(k.customer_name)}` : ''}</Text>
                    <Text style={styles.rowSub} numberOfLines={1}>{itemsText(k) || `Ticket ${k.kot_number || ''}`}</Text>
                  </View>
                  <RowAction label="Served" icon="checkmark-done" tone={colors.success} busy={busy === `k${k.id}`} onPress={() => act(`k${k.id}`, () => moveKot(k, 'Completed'))} />
                </View>
              ))}
            </View>
          </FadeIn>
        ) : null}

        {/* chefs: what to make, added up */}
        {isChef && toCook.length ? (
          <FadeIn index={3}>
            <Text style={styles.sectionTitle}>What to cook</Text>
            <View style={[styles.card, shadow.sm, styles.cookGrid]}>
              {toCook.map(([dish, qty]) => (
                <View key={dish} style={styles.cookItem}>
                  <Text style={styles.cookQty}>{qty}</Text>
                  <Text style={styles.cookName} numberOfLines={2}>{dish}</Text>
                </View>
              ))}
            </View>
          </FadeIn>
        ) : null}

        {/* chefs: the queue, oldest first, each with its next step */}
        {isChef && (queue.length || cooking.length) ? (
          <FadeIn index={4}>
            <View style={styles.cardHead}>
              <Text style={styles.sectionTitle}>Cooking queue</Text>
              {canSee('Kitchen') ? (
                <PressableScale onPress={() => goTo(navigation, 'Kitchen', { stage: queue.length ? 'Pending' : 'Preparing' })} hitSlop={8}>
                  <Text style={styles.link}>Open kitchen</Text>
                </PressableScale>
              ) : null}
            </View>
            <View style={[styles.card, shadow.sm, styles.listCard]}>
              {[...queue, ...cooking].slice(0, 8).map((k, i) => {
                const min = minutesSince(k.created_at, now);
                const isNew = k.kot_status === 'Pending';
                return (
                  <View key={k.id} style={[styles.row, i > 0 && styles.rowLine]}>
                    <View style={[styles.rowIcon, { backgroundColor: isNew ? colors.primaryLight : colors.purpleBg }]}>
                      <Ionicons name={isNew ? 'receipt' : 'flame'} size={16} color={isNew ? colors.primary : colors.purple} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <View style={styles.rowTitleLine}>
                        <Text style={[styles.rowTitle, { flexShrink: 1 }]} numberOfLines={1}>{where(k)}</Text>
                        <Text style={[styles.ageText, { color: min >= LATE_MIN ? colors.danger : colors.muted }]}>{durationLabel(min)}</Text>
                      </View>
                      <Text style={styles.rowSub} numberOfLines={2}>{itemsText(k) || `Ticket ${k.kot_number || ''}`}</Text>
                    </View>
                    {isNew ? (
                      <RowAction label="Start" icon="flame" busy={busy === `k${k.id}`} onPress={() => act(`k${k.id}`, () => moveKot(k, 'Preparing'))} />
                    ) : (
                      <RowAction label="Ready" icon="checkmark-done" tone={colors.success} busy={busy === `k${k.id}`} onPress={() => act(`k${k.id}`, () => moveKot(k, 'Ready'))} />
                    )}
                  </View>
                );
              })}
            </View>
          </FadeIn>
        ) : null}

        {nothingToDo ? (
          <FadeIn index={2}>
            <View style={[styles.allGood, shadow.sm]}>
              <Ionicons name="checkmark-circle" size={22} color={colors.success} />
              <Text style={styles.allGoodText}>
                {isChef ? 'Kitchen is clear. New tickets ring the moment they come in.' : 'All caught up. Calls and ready food will buzz your phone.'}
              </Text>
            </View>
          </FadeIn>
        ) : null}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  head: {
    paddingBottom: 76,
    borderBottomLeftRadius: 28,
    borderBottomRightRadius: 28,
  },
  headRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  hello: { fontFamily: font.medium, fontSize: 13, color: 'rgba(255,255,255,0.85)' },
  headName: { fontFamily: font.bold, fontSize: 21, lineHeight: 26, color: '#fff', marginTop: 1 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.md },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderRadius: 999,
    paddingHorizontal: 11,
    paddingVertical: 6,
  },
  chipText: { fontFamily: font.semiBold, fontSize: 12, color: '#fff' },
  hero: { borderRadius: radius.lg, padding: spacing.lg },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  heroLabel: { fontFamily: font.medium, fontSize: 13, color: 'rgba(255,255,255,0.7)' },
  livePill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    backgroundColor: 'rgba(74,222,128,0.14)',
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 3,
  },
  liveDot: { width: 6, height: 6, borderRadius: 3, backgroundColor: '#4ADE80' },
  livePillText: { fontFamily: font.semiBold, fontSize: 11, color: '#4ADE80' },
  big: { fontFamily: font.bold, fontSize: 34, color: '#fff', marginTop: 4 },
  heroHint: { fontFamily: font.regular, fontSize: 12.5, color: 'rgba(255,255,255,0.65)', marginTop: 2 },
  miniStats: {
    flexDirection: 'row',
    marginTop: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,0.12)',
  },
  miniValue: { fontFamily: font.bold, fontSize: 17, color: '#fff' },
  miniLabel: { fontFamily: font.regular, fontSize: 11.5, color: 'rgba(255,255,255,0.6)', marginTop: 1 },
  offline: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.dangerBg,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 10,
  },
  offlineText: { flex: 1, fontFamily: font.medium, fontSize: 12.5, color: colors.danger },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: spacing.lg },
  listCard: { paddingVertical: 4, paddingHorizontal: spacing.md },
  quickCard: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: spacing.xs,
    paddingVertical: spacing.md,
    rowGap: spacing.lg,
  },
  quick: { alignItems: 'center', gap: 7 },
  quickIcon: { width: 50, height: 50, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
  quickBadge: {
    position: 'absolute',
    top: -5,
    right: -7,
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    paddingHorizontal: 5,
    backgroundColor: colors.danger,
    borderWidth: 2,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  quickBadgeText: { fontFamily: font.bold, fontSize: 10, color: '#fff' },
  quickText: { fontFamily: font.semiBold, fontSize: 12, color: colors.ink },
  sectionTitle: { fontFamily: font.bold, fontSize: 16, color: colors.ink, marginBottom: spacing.sm },
  attn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  attnIcon: { width: 40, height: 40, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  attnTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  attnSub: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: 1 },
  allGood: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  allGoodText: { flex: 1, fontFamily: font.medium, fontSize: 13.5, color: colors.inkSoft, lineHeight: 19 },
  cardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  link: { fontFamily: font.semiBold, fontSize: 13, color: colors.primary, marginBottom: spacing.sm },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingVertical: 12 },
  rowLine: { borderTopWidth: 1, borderTopColor: colors.border },
  rowIcon: { width: 36, height: 36, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  rowTitleLine: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  rowTitle: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink },
  rowSub: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: 1, lineHeight: 17 },
  ageText: { fontFamily: font.semiBold, fontSize: 11.5 },
  rowBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    borderRadius: radius.pill,
    paddingHorizontal: 13,
    height: 34,
  },
  rowBtnText: { fontFamily: font.semiBold, fontSize: 12.5, color: '#fff' },
  cookGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, padding: spacing.md },
  cookItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    width: '48%',
    flexGrow: 1,
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    paddingHorizontal: 10,
    paddingVertical: 9,
  },
  cookQty: {
    minWidth: 30,
    height: 30,
    lineHeight: 30,
    borderRadius: 10,
    overflow: 'hidden',
    textAlign: 'center',
    backgroundColor: colors.primary,
    color: '#fff',
    fontFamily: font.bold,
    fontSize: 14,
  },
  cookName: { flex: 1, fontFamily: font.semiBold, fontSize: 12.5, color: colors.ink },
});
