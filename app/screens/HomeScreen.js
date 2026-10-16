import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import MiniBars from '../components/MiniBars';
import ScreenHeader from '../components/ScreenHeader';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { SkeletonBlock } from '../components/ui/Skeleton';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing, statusStyles } from '../theme';
import { money, moneyShort, realName, timeAgo } from '../utils/format';
import { useLiveCounts } from '../utils/liveCounts';

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
  const live = useLiveCounts();

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

  const goOrders = (tab) => navigation.navigate('Orders', { screen: 'OrdersList', params: { tab } });
  const goMore = (screen) => navigation.navigate('More', { screen });

  const attention = [
    { n: Math.max(att.to_accept || 0, live.newOrders || 0), icon: 'notifications', tone: colors.danger, bg: colors.dangerBg, title: (n) => `${n} new order${n > 1 ? 's' : ''} to accept`, sub: 'Customers are waiting for your answer', go: () => goOrders('new') },
    { n: Math.max(att.calls || 0, live.calls || 0), icon: 'hand-left', tone: colors.warning, bg: colors.warningBg, title: (n) => `${n} table${n > 1 ? 's' : ''} calling`, sub: 'A waiter is needed', go: () => goMore('WaiterCalls') },
    { n: att.reservations_pending || 0, icon: 'calendar', tone: colors.purple, bg: colors.purpleBg, title: (n) => `${n} booking${n > 1 ? 's' : ''} to confirm`, sub: 'Confirm or decline table bookings', go: () => goMore('Reservations') },
    { n: att.stale_kot || 0, icon: 'time', tone: colors.inkSoft, bg: colors.border, title: (n) => `${n} kitchen ticket${n > 1 ? 's' : ''} open for hours`, sub: 'Close them so tables show as free', go: () => goMore('Kitchen') },
    { n: att.low_stock || 0, icon: 'cube', tone: colors.info, bg: colors.infoBg, title: (n) => `${n} item${n > 1 ? 's' : ''} running low`, sub: (att.low_names || []).slice(0, 3).join(', ') || 'Check your stock', go: null },
  ].filter((a) => a.n > 0);

  const quick = [
    { icon: 'calculator', label: 'New order', go: () => navigation.navigate('POS') },
    { icon: 'flame', label: 'Kitchen', go: () => goMore('Kitchen') },
    { icon: 'grid', label: 'Tables', go: () => goMore('Tables') },
    { icon: 'bar-chart', label: 'Reports', go: () => goMore('Reports') },
  ];

  const qCols = Math.min(4, columns(72, 4));

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow={greeting()} title={data?.restaurant || user?.restaurant_name || 'Your restaurant'} />
      <ScrollView
        contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
        showsVerticalScrollIndicator={false}
      >
        {/* today */}
        <FadeIn>
          <View style={[styles.card, shadow.sm, { marginTop: -spacing.xl }]}>
            <Text style={styles.cardEyebrow}>Sales today</Text>
            {loading && !data ? (
              <SkeletonBlock width="55%" height={30} style={{ marginTop: 6 }} />
            ) : (
              <View style={styles.salesRow}>
                <Text style={styles.salesBig} numberOfLines={1} adjustsFontSizeToFit>
                  {ok ? money(today.sales, data.currency || currency) : '—'}
                </Text>
                {salesChange !== null && ok ? (
                  <View style={[styles.delta, { backgroundColor: salesChange >= 0 ? colors.successBg : colors.dangerBg }]}>
                    <Ionicons name={salesChange >= 0 ? 'trending-up' : 'trending-down'} size={13} color={salesChange >= 0 ? colors.success : colors.danger} />
                    <Text style={[styles.deltaText, { color: salesChange >= 0 ? colors.success : colors.danger }]}>
                      {salesChange >= 0 ? '+' : ''}
                      {salesChange}% vs yesterday
                    </Text>
                  </View>
                ) : null}
              </View>
            )}
            <View style={styles.miniStats}>
              <View style={styles.miniStat}>
                <Text style={styles.miniValue}>{ok ? today.orders ?? 0 : '—'}</Text>
                <Text style={styles.miniLabel}>Orders</Text>
              </View>
              <View style={styles.miniDivider} />
              <View style={styles.miniStat}>
                <Text style={styles.miniValue}>{ok && today.avg ? money(today.avg, currency) : '—'}</Text>
                <Text style={styles.miniLabel}>Average bill</Text>
              </View>
              <View style={styles.miniDivider} />
              <View style={styles.miniStat}>
                <Text style={styles.miniValue}>{ok ? data.live_count ?? 0 : '—'}</Text>
                <Text style={styles.miniLabel}>In progress</Text>
              </View>
            </View>
          </View>
        </FadeIn>

        {/* quick actions */}
        <FadeIn index={1}>
          <View style={[styles.quickGrid, { gap: spacing.md }]}>
            {quick.slice(0, qCols >= 4 ? 4 : 4).map((q) => (
              <PressableScale key={q.label} style={[styles.quick, shadow.sm]} onPress={q.go}>
                <View style={styles.quickIcon}>
                  <Ionicons name={q.icon} size={20} color={colors.primary} />
                </View>
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
  salesRow: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: spacing.sm,
    marginTop: 4,
  },
  salesBig: {
    fontFamily: font.bold,
    fontSize: 30,
    color: colors.ink,
    flexShrink: 1,
  },
  delta: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 3,
  },
  deltaText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
  },
  miniStats: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  miniStat: {
    flex: 1,
    alignItems: 'center',
  },
  miniValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  miniLabel: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  miniDivider: {
    width: 1,
    height: 28,
    backgroundColor: colors.border,
  },
  quickGrid: {
    flexDirection: 'row',
  },
  quick: {
    flex: 1,
    alignItems: 'center',
    gap: 6,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingVertical: spacing.md,
  },
  quickIcon: {
    width: 42,
    height: 42,
    borderRadius: 14,
    backgroundColor: colors.primaryLight,
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
