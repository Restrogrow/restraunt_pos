import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useMemo } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Avatar from '../components/Avatar';
import MiniBars from '../components/MiniBars';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { SkeletonBlock } from '../components/ui/Skeleton';
import { apiGet, restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing, statusStyles } from '../theme';
import { money, moneyShort, realName, timeAgo } from '../utils/format';
import { useLiveCounts } from '../utils/liveCounts';
import { openStatus } from '../utils/openStatus';
import { useAccess } from '../utils/access';

function greeting() {
  const h = new Date().getHours();
  if (h < 5) return 'Working late';
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

function change(now, before) {
  if (!before) return null;
  const p = Math.round(((now - before) / before) * 100);
  return p;
}

export default function HomeScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, columns } = useLayout();
  const insets = useSafeAreaInsets();
  const live = useLiveCounts();
  const status = openStatus(user?.opening_hours);
  // "Good evening, Neha": a person's first name only, never a business name like "Spice Garden Group"
  const personName = String(user?.staff_name || user?.owner_name || '').trim();
  const showName = personName && !/group|restaurant|demo|admin|kitchen|cafe|café|hotel|foods?|pvt|ltd|llp|&/i.test(personName)
    ? personName.split(/\s+/)[0]
    : '';

  const fetcher = useCallback(() => apiGet('/api/dashboard_home.php'), []);
  const { data, loading, refreshing, refresh } = useApiData(fetcher, { pollInterval: 30000, cacheKey: 'home', refetchOnFocus: true });
  const ok = data?.success;

  const today = data?.today || {};
  const yesterday = data?.yesterday || {};
  const att = data?.attention || {};
  const salesChange = change(Number(today.sales) || 0, Number(yesterday.sales) || 0);

  const bars = useMemo(
    () => (data?.days || []).map((d) => ({ key: d.date, value: Number(d.sales) || 0, date: d.date })),
    [data?.days]
  );

  const canSee = useAccess(); // sections Super Admin switched off (utils/access.js)
  const goOrders = (tab) => navigation.navigate('Orders', { screen: 'OrdersList', params: { tab } });
  const goMore = (screen) => navigation.navigate('More', { screen });

  const attention = [
    { n: Math.max(att.to_accept || 0, live.newOrders || 0), icon: 'notifications', tone: colors.danger, bg: colors.dangerBg, title: (n) => `${n} new order${n > 1 ? 's' : ''} to accept`, sub: 'Customers are waiting for your answer', go: () => goOrders('new') },
    { n: Math.max(att.calls || 0, live.calls || 0), icon: 'hand-left', tone: colors.warning, bg: colors.warningBg, title: (n) => `${n} table${n > 1 ? 's' : ''} calling`, sub: 'A waiter is needed', go: () => goMore('WaiterCalls'), screen: 'WaiterCalls' },
    { n: att.reservations_pending || 0, icon: 'calendar', tone: colors.purple, bg: colors.purpleBg, title: (n) => `${n} booking${n > 1 ? 's' : ''} to confirm`, sub: 'Confirm or decline table bookings', go: () => goMore('Reservations'), screen: 'Reservations' },
    { n: att.stale_kot || 0, icon: 'time', tone: colors.inkSoft, bg: colors.border, title: (n) => `${n} kitchen ticket${n > 1 ? 's' : ''} open for hours`, sub: 'Close them so tables show as free', go: () => goMore('Kitchen'), screen: 'Kitchen' },
    { n: att.low_stock || 0, icon: 'cube', tone: colors.info, bg: colors.infoBg, title: (n) => `${n} item${n > 1 ? 's' : ''} running low`, sub: (att.low_names || []).slice(0, 3).join(', ') || 'Check your stock', go: null },
  ].filter((a) => a.n > 0 && (!a.screen || canSee(a.screen)));

  // Shortcuts: each its own colour so they're told apart at a glance.
  const quick = [
    { icon: 'add', label: 'New order', c: ['#FF7A50', '#F0461F'], go: () => navigation.navigate('POS'), screen: 'POS' },
    { icon: 'flame', label: 'Kitchen', c: ['#FB7185', '#E11D48'], go: () => goMore('Kitchen'), screen: 'Kitchen' },
    { icon: 'grid', label: 'Tables', c: ['#34D399', '#059669'], go: () => goMore('Tables'), screen: 'Tables' },
    { icon: 'receipt', label: 'Orders', c: ['#60A5FA', '#2563EB'], go: () => goOrders(live.newOrders ? 'new' : 'cooking') },
    { icon: 'stats-chart', label: 'Reports', c: ['#A78BFA', '#7C3AED'], go: () => goMore('Reports'), screen: 'Reports' },
    { icon: 'people', label: 'Customers', c: ['#38BDF8', '#0284C7'], go: () => goMore('Customers'), screen: 'Customers' },
    { icon: 'calendar', label: 'Bookings', c: ['#FBBF24', '#D97706'], go: () => goMore('Reservations'), screen: 'Reservations' },
    { icon: 'restaurant', label: 'Menu', c: ['#2DD4BF', '#0D9488'], go: () => navigation.navigate('Menu') },
  ].filter((q) => !q.screen || canSee(q.screen));

  const qCols = columns(72, 8) >= 8 ? 8 : 4;

  return (
    <View style={styles.fill}>
      <ScrollView
        contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
        showsVerticalScrollIndicator={false}
      >
        {/* header: scrolls with the page, so the sales card can sit over its edge without being cut off */}
        <LinearGradient
          colors={[colors.primary, colors.primaryDark]}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={[styles.head, { marginHorizontal: -gutter, paddingHorizontal: gutter, paddingTop: insets.top + spacing.lg }]}
        >
          <View style={styles.headRow}>
            <Avatar name={user?.restaurant_name} uri={restaurantLogoUrl(user)} size={48} bg="rgba(255,255,255,0.22)" color="#fff" />
            <View style={{ flex: 1 }}>
              <Text style={styles.hello} numberOfLines={1}>{greeting()}{showName ? `, ${showName}` : ''}</Text>
              <Text style={styles.headName} numberOfLines={2}>
                {data?.restaurant || user?.restaurant_name || 'Your restaurant'}
              </Text>
            </View>
            <PressableScale style={styles.bell} onPress={() => goOrders('new')} accessibilityLabel={live.newOrders ? `${live.newOrders} new orders` : 'Orders'}>
              <Ionicons name={live.newOrders ? 'notifications' : 'notifications-outline'} size={21} color="#fff" />
              {live.newOrders ? (
                <View style={styles.bellBadge}>
                  <Text style={styles.bellBadgeText}>{live.newOrders > 9 ? '9+' : live.newOrders}</Text>
                </View>
              ) : null}
            </PressableScale>
          </View>
          <View style={styles.chips}>
            <View style={styles.chip}>
              <Ionicons name="calendar-clear-outline" size={13} color="#fff" />
              <Text style={styles.chipText}>{new Date().toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}</Text>
            </View>
            {status ? (
              <View style={styles.chip}>
                <View style={[styles.chipDot, { backgroundColor: status.open ? '#4ADE80' : '#FECACA' }]} />
                <Text style={styles.chipText} numberOfLines={1}>{status.label}</Text>
              </View>
            ) : null}
          </View>
        </LinearGradient>

        {/* today */}
        <FadeIn>
          <LinearGradient colors={['#2A2735', '#1D1B26']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.hero, shadow.md, { marginTop: -60 }]}>
            <View style={styles.heroTop}>
              <Text style={styles.heroLabel}>Sales today</Text>
              <View style={styles.livePill}>
                <View style={styles.liveDot} />
                <Text style={styles.livePillText}>Live</Text>
              </View>
            </View>
            {loading && !data ? (
              <SkeletonBlock width="55%" height={34} style={{ marginTop: 8, backgroundColor: 'rgba(255,255,255,0.12)' }} />
            ) : (
              <Text style={styles.salesBig} numberOfLines={1} adjustsFontSizeToFit>
                {ok ? money(Math.round(Number(today.sales) || 0), data.currency || currency) : '—'}
              </Text>
            )}
            {salesChange !== null && ok ? (
              <View style={styles.deltaRow}>
                <View style={[styles.delta, { backgroundColor: salesChange >= 0 ? 'rgba(74,222,128,0.16)' : 'rgba(255,138,128,0.16)' }]}>
                  <Ionicons name={salesChange >= 0 ? 'arrow-up' : 'arrow-down'} size={12} color={salesChange >= 0 ? '#4ADE80' : '#FF8A80'} />
                  <Text style={[styles.deltaText, { color: salesChange >= 0 ? '#4ADE80' : '#FF8A80' }]}>{Math.abs(salesChange)}%</Text>
                </View>
                <Text style={styles.deltaHint}>vs yesterday by this time</Text>
              </View>
            ) : (
              <Text style={styles.deltaHint}>
                {!ok ? ' ' : Number(today.sales) > 0 ? `From ${today.orders} order${Number(today.orders) === 1 ? '' : 's'} so far today` : 'Sales appear here as orders are paid'}
              </Text>
            )}
            <View style={styles.miniStats}>
              {[
                ['Orders', ok ? String(today.orders ?? 0) : '—'],
                ['Average bill', ok && today.avg ? money(Math.round(today.avg), currency) : '—'],
                ['In progress', ok ? String(data.live_count ?? 0) : '—'],
              ].map(([l, v]) => (
                <View key={l} style={styles.miniStat}>
                  <Text style={styles.miniValue} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
                  <Text style={styles.miniLabel}>{l}</Text>
                </View>
              ))}
            </View>
          </LinearGradient>
        </FadeIn>

        {/* quick actions */}
        <FadeIn index={1}>
          <View style={[styles.card, shadow.sm, styles.quickCard]}>
            {quick.map((q) => (
              <PressableScale key={q.label} style={[styles.quick, { width: `${100 / qCols}%` }]} onPress={q.go} accessibilityLabel={q.label} scale={0.92}>
                <LinearGradient colors={q.c} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.quickIcon}>
                  <Ionicons name={q.icon} size={23} color="#fff" />
                </LinearGradient>
                <Text style={styles.quickText} numberOfLines={1}>{q.label}</Text>
              </PressableScale>
            ))}
          </View>
        </FadeIn>

        {/* needs attention */}
        {attention.length ? (
          <FadeIn index={2}>
            <Text style={styles.sectionTitle}>Needs attention</Text>
            <View style={{ gap: spacing.sm }}>
              {attention.map((a) => (
                <PressableScale key={a.icon} style={[styles.attn, shadow.sm]} onPress={a.go || undefined} disabled={!a.go}>
                  <View style={[styles.attnIcon, { backgroundColor: a.bg }]}>
                    <Ionicons name={a.icon} size={18} color={a.tone} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.attnTitle}>{a.title(a.n)}</Text>
                    <Text style={styles.attnSub} numberOfLines={1}>{a.sub}</Text>
                  </View>
                  {a.go ? <Ionicons name="chevron-forward" size={18} color={colors.muted} /> : null}
                </PressableScale>
              ))}
            </View>
          </FadeIn>
        ) : ok ? (
          <FadeIn index={2}>
            <View style={[styles.allGood, shadow.sm]}>
              <Ionicons name="checkmark-circle" size={22} color={colors.success} />
              <Text style={styles.allGoodText}>All caught up. Nothing needs you right now.</Text>
            </View>
          </FadeIn>
        ) : null}

        {/* chart */}
        {bars.length ? (
          <FadeIn index={3}>
            <View style={[styles.card, shadow.sm]}>
              <View style={styles.cardHead}>
                <Text style={styles.cardTitle}>Last 14 days</Text>
                <Text style={styles.cardHint}>{money(bars.reduce((s, b) => s + b.value, 0), currency)}</Text>
              </View>
              <MiniBars
                data={bars}
                valueLabel={(v) => moneyShort(v, currency)}
                dayLabel={(d, i) => (i % 2 === bars.length % 2 || i === bars.length - 1 ? new Date(d.date).toLocaleDateString(undefined, { weekday: 'narrow' }) : '')}
              />
            </View>
          </FadeIn>
        ) : null}

        {/* live orders */}
        {(data?.live || []).length ? (
          <FadeIn index={4}>
            <View style={styles.cardHead}>
              <Text style={styles.sectionTitle}>Orders in progress</Text>
              <PressableScale onPress={() => goOrders('cooking')} hitSlop={8}>
                <Text style={styles.link}>See all</Text>
              </PressableScale>
            </View>
            <View style={[styles.card, shadow.sm, { paddingVertical: 4 }]}>
              {data.live.slice(0, 6).map((o, i) => {
                const st = statusStyles[o.status] || {};
                return (
                  <PressableScale
                    key={o.id}
                    style={[styles.liveRow, i > 0 && styles.liveRowLine]}
                    scale={0.99}
                    onPress={() =>
                      navigation.navigate('Orders', {
                        screen: 'OrderDetail',
                        initial: false,
                        params: { order: { id: o.id, order_number: o.number, order_type: o.type, order_status: o.status, total: o.total, customer_name: o.customer, table_name: o.table, source: o.source, created_at: o.at, items: [] } },
                      })
                    }
                  >
                    <View style={{ flex: 1 }}>
                      <Text style={styles.liveNum} numberOfLines={1}>{o.number}</Text>
                      <Text style={styles.liveMeta} numberOfLines={1}>
                        {o.table ? `Table ${o.table}` : o.type}
                        {realName(o.customer) ? ` · ${realName(o.customer)}` : ''} · {timeAgo(o.at)}
                      </Text>
                    </View>
                    <View style={[styles.liveStatus, { backgroundColor: st.bg || colors.border }]}>
                      <Text style={[styles.liveStatusText, { color: st.fg || colors.inkSoft }]}>{o.status}</Text>
                    </View>
                    <Text style={styles.liveTotal}>{money(o.total, currency)}</Text>
                  </PressableScale>
                );
              })}
            </View>
          </FadeIn>
        ) : null}

        {(data?.top || []).length ? (
          <FadeIn index={5}>
            <Text style={styles.sectionTitle}>Top dishes today</Text>
            <View style={[styles.card, shadow.sm, { gap: 10 }]}>
              {data.top.slice(0, 5).map((t, i) => (
                <View key={`${t.name}_${i}`} style={styles.topRow}>
                  <Text style={styles.topRank}>{i + 1}</Text>
                  <Text style={styles.topName} numberOfLines={1}>{t.name || t.item_name}</Text>
                  <Text style={styles.topQty}>{t.qty ?? t.quantity ?? ''} sold</Text>
                </View>
              ))}
            </View>
          </FadeIn>
        ) : null}

        {data && !ok ? (
          <Text style={styles.noAccess}>Sales figures are only shown to the owner and managers.</Text>
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
  headRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  hello: {
    fontFamily: font.medium,
    fontSize: 13,
    color: 'rgba(255,255,255,0.85)',
  },
  headName: {
    fontFamily: font.bold,
    fontSize: 21,
    lineHeight: 26,
    color: '#fff',
    marginTop: 1,
  },
  bell: {
    width: 42,
    height: 42,
    borderRadius: 14,
    backgroundColor: 'rgba(255,255,255,0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  bellBadge: {
    position: 'absolute',
    top: -4,
    right: -4,
    minWidth: 19,
    height: 19,
    borderRadius: 10,
    paddingHorizontal: 4,
    backgroundColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  bellBadgeText: {
    fontFamily: font.bold,
    fontSize: 10.5,
    color: colors.primaryDark,
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginTop: spacing.md,
  },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderRadius: 999,
    paddingHorizontal: 11,
    paddingVertical: 6,
    maxWidth: '100%',
  },
  chipDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: '#fff',
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  cardEyebrow: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.muted,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
  },
  hero: {
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  heroTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  heroLabel: {
    fontFamily: font.medium,
    fontSize: 13,
    color: 'rgba(255,255,255,0.7)',
  },
  livePill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    backgroundColor: 'rgba(74,222,128,0.14)',
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 3,
  },
  liveDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#4ADE80',
  },
  livePillText: {
    fontFamily: font.semiBold,
    fontSize: 11,
    color: '#4ADE80',
  },
  salesBig: {
    fontFamily: font.bold,
    fontSize: 34,
    color: '#fff',
    marginTop: 4,
  },
  deltaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 2,
  },
  delta: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 3,
  },
  deltaText: {
    fontFamily: font.bold,
    fontSize: 12,
  },
  deltaHint: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: 'rgba(255,255,255,0.65)',
    marginTop: 2,
  },
  miniStats: {
    flexDirection: 'row',
    marginTop: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,0.12)',
  },
  miniStat: {
    flex: 1,
  },
  miniValue: {
    fontFamily: font.bold,
    fontSize: 17,
    color: '#fff',
  },
  miniLabel: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: 'rgba(255,255,255,0.6)',
    marginTop: 1,
  },
  quickCard: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: spacing.xs,
    paddingVertical: spacing.md,
    rowGap: spacing.lg,
  },
  quick: {
    alignItems: 'center',
    gap: 7,
  },
  quickIcon: {
    width: 50,
    height: 50,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  quickText: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.ink,
  },
  sectionTitle: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  attn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  attnIcon: {
    width: 40,
    height: 40,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
  },
  attnTitle: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  attnSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
  },
  allGood: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  allGoodText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.inkSoft,
  },
  cardHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  cardTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  cardHint: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  link: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
    marginBottom: spacing.sm,
  },
  liveRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: 12,
  },
  liveRowLine: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  liveNum: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  liveMeta: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  liveStatus: {
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 3,
  },
  liveStatusText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
  },
  liveTotal: {
    fontFamily: font.bold,
    fontSize: 13.5,
    color: colors.ink,
    minWidth: 64,
    textAlign: 'right',
  },
  topRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  topRank: {
    width: 24,
    height: 24,
    borderRadius: 8,
    overflow: 'hidden',
    textAlign: 'center',
    lineHeight: 24,
    backgroundColor: colors.primaryLight,
    color: colors.primary,
    fontFamily: font.bold,
    fontSize: 12,
  },
  topName: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  topQty: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  noAccess: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    textAlign: 'center',
  },
});
