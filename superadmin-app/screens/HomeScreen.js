import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { useCallback } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import MiniBars from '../components/MiniBars';
import Screen from '../components/Screen';
import { Card, HeaderIcon, Initials, Metric, Pill, SectionTitle } from '../components/kit';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { pf } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, spacing } from '../theme';
import { refreshBadges, useBadges } from '../utils/badges';
import { money, moneyShort, num, timeAgo } from '../utils/format';

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

export default function HomeScreen() {
  const navigation = useNavigation();
  const { user } = useAuth();
  const badges = useBadges();
  const fetcher = useCallback(() => pf.get('analytics'), []);
  const { data, loading, error, refreshing, refresh } = useApiData(fetcher, { cacheKey: 'analytics', pollInterval: 60000, refetchOnFocus: true });

  const filtered = (status) => navigation.navigate('Restaurants', { screen: 'RestaurantsList', params: { status, at: Date.now() } });
  const pull = () => {
    refresh();
    refreshBadges();
  };
  const openRestaurant = (r) => navigation.navigate('RestaurantDetail', { id: r.id, name: r.restaurant_name });
  const c = data?.counts || {};
  const months = data?.months || [];

  return (
    <Screen
      tabs
      eyebrow={`${greeting()}, ${user?.username || 'admin'}`}
      title="Platform overview"
      right={<HeaderIcon icon="settings-outline" label="Settings" onPress={() => navigation.navigate('More', { screen: 'Settings', initial: false })} />}
      loading={loading && !data}
      loadingVariant="grid"
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={pull}
      headerChildren={
        data ? (
          <View style={styles.hero}>
            <View style={{ flex: 1 }}>
              <Text style={styles.heroLabel}>Monthly recurring revenue</Text>
              <Text style={styles.heroValue}>{money(data.mrr)}</Text>
              <Text style={styles.heroSub}>ARR {moneyShort(data.arr)} · plan {money(data.plan_price)}/mo</Text>
            </View>
            <View style={styles.heroSide}>
              <Text style={styles.heroSideValue}>{num(c.paying)}</Text>
              <Text style={styles.heroSideLabel}>paying</Text>
            </View>
          </View>
        ) : null
      }
    >
      {data ? (
        <>
          {/* attention strip */}
          <View style={styles.alerts}>
            <AlertTile icon="chatbubbles" tone={colors.info} bg={colors.infoBg} count={badges.tickets} label="Unread tickets" onPress={() => navigation.navigate('Support')} />
            <AlertTile icon="refresh-circle" tone="#B7791F" bg={colors.warningBg} count={badges.renewals} label="Renewals due" onPress={() => navigation.navigate('Renewals')} />
            <AlertTile icon="person-add" tone={colors.purple} bg={colors.purpleBg} count={badges.leads} label="New leads" onPress={() => navigation.navigate('More', { screen: 'Leads', initial: false })} />
          </View>

          <SectionTitle title="Restaurants" action="See all" onAction={() => navigation.navigate('Restaurants')} />
          <View style={styles.grid}>
            <Metric onPress={() => filtered('')} icon="storefront" tone="primary" label="Total" value={num(c.total)} sub={`${num(c.active)} active`} />
            <Metric onPress={() => filtered('paying')} icon="card" tone="success" label="Paying" value={num(c.paying)} sub={`${data.conversion}% ever converted`} />
            <Metric onPress={() => filtered('trial')} icon="hourglass" tone="info" label="On trial" value={num(c.on_trial)} sub="still within trial" />
            <Metric onPress={() => filtered('expired')} icon="trending-down" tone="danger" label="Lapsed" value={num(c.lapsed)} sub={`${num(data.churn_30d)} churned in 30 days`} />
          </View>

          <SectionTitle title="Orders across all restaurants" />
          <View style={styles.grid}>
            <Metric icon="today" tone="teal" label="Today" value={moneyShort(data.today?.s)} sub={`${num(data.today?.n)} orders`} />
            <Metric icon="calendar" tone="purple" label="This month" value={moneyShort(data.this_month?.s)} sub={`${num(data.this_month?.n)} orders`} />
          </View>

          <SectionTitle title="Subscription revenue · 12 months" />
          <Card>
            <MiniBars
              data={months.map((m, i) => ({ key: i, value: m.revenue, label: m.month }))}
              height={130}
              valueLabel={(v) => moneyShort(v)}
              dayLabel={(d, i) => (i % 2 === months.length % 2 ? '' : String(months[i].month).split(' ')[0])}
            />
            <View style={styles.legendRow}>
              <Legend label="New sign-ups (12 mo)" value={num(months.reduce((a, m) => a + (Number(m.new) || 0), 0))} />
              <Legend label="Collected (12 mo)" value={moneyShort(months.reduce((a, m) => a + (Number(m.revenue) || 0), 0))} />
            </View>
          </Card>

          {data.top?.length ? (
            <>
              <SectionTitle title="Top restaurants · 30 days" />
              <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
                {data.top.slice(0, 6).map((r, i, arr) => (
                  <PressableScale key={r.id} onPress={() => openRestaurant(r)}>
                    <View style={[styles.rankRow, i < arr.length - 1 && styles.divider]}>
                      <Text style={styles.rank}>{i + 1}</Text>
                      <Initials name={r.restaurant_name} size={36} />
                      <View style={{ flex: 1 }}>
                        <Text style={styles.rowTitle} numberOfLines={1}>
                          {r.restaurant_name || r.restaurant_id}
                        </Text>
                        <Text style={styles.rowSub}>{num(r.cur_orders)} orders</Text>
                      </View>
                      <Text style={styles.rowValue}>{moneyShort(r.cur_sales)}</Text>
                    </View>
                  </PressableScale>
                ))}
              </Card>
            </>
          ) : null}

          {data.at_risk?.length ? (
            <>
              <SectionTitle title={`At risk · ${data.at_risk.length}`} />
              <Text style={styles.note}>Active restaurants with no order in 7 days, or orders down by half.</Text>
              <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
                {data.at_risk.slice(0, 8).map((r, i, arr) => (
                  <PressableScale key={r.id} onPress={() => openRestaurant(r)}>
                    <View style={[styles.rankRow, i < arr.length - 1 && styles.divider]}>
                      <Initials name={r.restaurant_name} size={36} tone="danger" />
                      <View style={{ flex: 1 }}>
                        <Text style={styles.rowTitle} numberOfLines={1}>
                          {r.restaurant_name || r.restaurant_id}
                        </Text>
                        <Text style={styles.rowSub}>{r.last_order ? `Last order ${timeAgo(r.last_order)}` : 'No orders yet'}</Text>
                      </View>
                      <Pill small tone="danger" label={`${num(r.cur_orders)} vs ${num(r.prev_orders)}`} />
                    </View>
                  </PressableScale>
                ))}
              </Card>
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function AlertTile({ icon, tone, bg, count, label, onPress }) {
  return (
    <FadeIn style={{ flex: 1 }}>
      <PressableScale onPress={onPress} style={[styles.alert, { backgroundColor: bg }]}>
        <Ionicons name={icon} size={18} color={tone} />
        <Text style={[styles.alertCount, { color: tone }]}>{count}</Text>
        <Text style={styles.alertLabel} numberOfLines={1}>
          {label}
        </Text>
      </PressableScale>
    </FadeIn>
  );
}

function Legend({ label, value }) {
  return (
    <View style={{ flex: 1 }}>
      <Text style={styles.legendValue}>{value}</Text>
      <Text style={styles.legendLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  hero: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: spacing.lg,
    padding: spacing.lg,
    borderRadius: radius.lg,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.1)',
  },
  heroLabel: { fontFamily: font.medium, fontSize: 12, color: 'rgba(255,255,255,0.7)' },
  heroValue: { fontFamily: font.bold, fontSize: 30, color: '#fff', marginTop: 2 },
  heroSub: { fontFamily: font.medium, fontSize: 12, color: 'rgba(255,255,255,0.6)', marginTop: 2 },
  heroSide: {
    alignItems: 'center',
    justifyContent: 'center',
    width: 74,
    height: 74,
    borderRadius: 22,
    backgroundColor: colors.primary,
  },
  heroSideValue: { fontFamily: font.bold, fontSize: 24, color: '#fff' },
  heroSideLabel: { fontFamily: font.medium, fontSize: 10.5, color: 'rgba(255,255,255,0.85)', marginTop: -2 },
  alerts: { flexDirection: 'row', gap: 10 },
  alert: { borderRadius: radius.md, padding: 12, minHeight: 92 },
  alertCount: { fontFamily: font.bold, fontSize: 22, marginTop: 4 },
  alertLabel: { fontFamily: font.medium, fontSize: 11, color: colors.inkSoft },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  legendRow: { flexDirection: 'row', marginTop: spacing.lg, paddingTop: spacing.md, borderTopWidth: 1, borderTopColor: colors.border },
  legendValue: { fontFamily: font.bold, fontSize: 16, color: colors.ink },
  legendLabel: { fontFamily: font.medium, fontSize: 11, color: colors.muted },
  rankRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  divider: { borderBottomWidth: 1, borderBottomColor: colors.border },
  rank: { width: 16, fontFamily: font.bold, fontSize: 13, color: colors.muted, textAlign: 'center' },
  rowTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  rowSub: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  rowValue: { fontFamily: font.bold, fontSize: 14, color: colors.ink },
  note: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: -6, marginBottom: 10 },
});
