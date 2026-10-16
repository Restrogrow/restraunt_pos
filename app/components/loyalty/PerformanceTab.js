import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiGet } from '../../config/api';
import { useApiData } from '../../hooks/useApiData';
import { colors, font, radius, spacing } from '../../theme';
import { money, moneyShort } from '../../utils/format';
import MiniBars from '../MiniBars';
import { ErrorState, LoadingState } from '../ScreenState';
import FadeIn from '../ui/FadeIn';
import { Card, Chips, IconBox, LOYALTY_API, Note, Nums, SectionTitle, pct } from './LoyaltyUi';

// Names for each source of loyalty revenue (same list as loyalty-studio-performance.js)
export const SOURCE_NAMES = {
  welcome: ['hand-right', 'Welcome gifts'],
  birthday: ['gift', 'Birthday treats'],
  anniversary: ['heart', 'Anniversary gifts'],
  winback: ['time', 'We miss you'],
  tier_up: ['medal', 'Level up gifts'],
  milestone: ['trophy', 'Order milestones'],
  referral: ['people', 'Referral gifts'],
  stamps: ['ribbon', 'Stamp cards'],
  challenge: ['flag', 'Challenges'],
  streak: ['flame', 'Weekly streaks'],
  vip: ['diamond', 'Member welcome gifts'],
  scratch: ['dice', 'Scratch card / wheel'],
  points: ['star', 'Points redeemed'],
  vip_orders: ['diamond', 'Member discount'],
};

const RANGES = [[30, '30 days'], [90, '90 days'], [365, '12 months']];

export default function PerformanceTab({ currency, contentStyle }) {
  const [days, setDays] = useState(30);
  const [series, setSeries] = useState('revenue');
  const fetcher = useCallback(() => apiGet(`${LOYALTY_API}?section=performance&days=${days}`), [days]);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { cacheKey: `loyalty:perf:${days}` });
  const cur = data?.currency || currency;
  const m = (v) => money(Math.round(Number(v) || 0), cur);

  const body = () => {
    if (loading && !data) return <LoadingState />;
    if (error && !data) return <ErrorState message={error} onRetry={refresh} />;
    if (!data?.success) return <ErrorState message={data?.message || 'Could not load the numbers.'} onRetry={refresh} />;
    const n = data.now || {};
    const p = data.prev || {};
    const r = data.repeat || {};
    const share = pct(n.loyal_revenue, n.revenue);
    const roi = n.given > 0 ? n.loyal_revenue / n.given : 0;
    const change = p.loyal_revenue > 0 ? Math.round(((n.loyal_revenue - p.loyal_revenue) * 100) / p.loyal_revenue) : null;
    const weeks = data.weeks || [];
    const bars = weeks.map((w) => ({ key: w.from, value: series === 'loyal' ? w.loyal : w.revenue, from: w.from }));
    const g = data.segments || {};
    const segs = [['members', 'Signed-up members', 'person'], ['guests', 'Guests (phone only)', 'person-outline']];
    if (g.vip?.orders) segs.push(['vip', 'VIP members', 'diamond']);
    const aovs = segs.map(([k]) => (g[k]?.orders ? g[k].revenue / g[k].orders : 0));
    const aovMax = Math.max(1, ...aovs);
    const o = data.owed || {};
    const owed = [];
    if (data.program_type === 'points' || o.points) owed.push(['star', 'Points not used yet', `${Number(o.points || 0).toLocaleString('en-IN')} points`, `Worth ${m(o.points_value)} in discounts`]);
    owed.push(['wallet', 'Money in wallets', m(o.wallet), 'Top-ups, refunds and cashback customers can spend']);
    owed.push(['pricetags', 'Unused gift codes', `${o.gift_codes || 0} code${o.gift_codes === 1 ? '' : 's'}`, `${m(o.gift_flat)} in fixed amounts${o.gift_pct ? ` + ${o.gift_pct} % codes` : ''}`]);
    if (o.stamp_cards || data.program_type === 'stamps') owed.push(['ribbon', 'Stamp cards in progress', `${o.stamp_cards || 0} card${o.stamp_cards === 1 ? '' : 's'}`, 'Each fills up to one reward']);
    owed.push(['diamond', 'Active VIP members', String(o.vip_members || 0), 'Their perks run until their plan ends']);
    const sources = data.sources || [];

    return (
      <>
        <FadeIn>
          <LinearGradient colors={['#2A2735', '#1D1B26']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
            <View style={styles.heroTop}>
              <Text style={styles.heroLabel}>Revenue loyalty helped bring in</Text>
              {change !== null ? (
                <View style={[styles.delta, { backgroundColor: change >= 0 ? 'rgba(74,222,128,0.16)' : 'rgba(255,138,128,0.16)' }]}>
                  <Ionicons name={change >= 0 ? 'arrow-up' : 'arrow-down'} size={12} color={change >= 0 ? '#4ADE80' : '#FF8A80'} />
                  <Text style={[styles.deltaText, { color: change >= 0 ? '#4ADE80' : '#FF8A80' }]}>{Math.abs(change)}%</Text>
                </View>
              ) : null}
            </View>
            <Text style={styles.heroValue} numberOfLines={1} adjustsFontSizeToFit>{m(n.loyal_revenue)}</Text>
            <Text style={styles.heroSub}>
              {share}% of all revenue ({m(n.revenue)}) · {n.loyal_orders || 0} of {n.orders || 0} orders
              {change !== null ? ` · previous period ${m(p.loyal_revenue)}` : n.loyal_revenue > 0 ? ' · new this period' : ''}
            </Text>
            <Nums
              dark
              items={[
                [roi ? `${roi.toFixed(1)}x` : '–', 'Return on rewards'],
                [m(n.given), 'Discounts given'],
                [`${pct(r.repeaters, r.customers)}%`, 'Came back (2+ orders)'],
              ]}
            />
          </LinearGradient>
        </FadeIn>

        {!n.loyal_orders ? (
          <FadeIn index={1}>
            <Note icon="bulb" tone="warn">
              No orders used a loyalty reward in this period yet. Turn on the welcome gift in Automations so every first order earns a reason to come back.
            </Note>
          </FadeIn>
        ) : null}

        <FadeIn index={2}>
          <Card>
            <SectionTitle right={<Chips small options={[['revenue', 'All'], ['loyal', 'Loyalty']]} value={series} onChange={setSeries} />}>Last 12 weeks</SectionTitle>
            {bars.some((b) => b.value > 0) ? (
              <MiniBars
                data={bars}
                height={130}
                valueLabel={(v) => moneyShort(v, cur)}
                dayLabel={(b, i) => {
                  if (i % 2 !== 0 && i !== bars.length - 1) return '';
                  const d = new Date(`${b.from}T00:00:00`);
                  return Number.isNaN(d.getTime()) ? '' : `${d.getDate()}/${d.getMonth() + 1}`;
                }}
              />
            ) : (
              <Text style={styles.empty}>{series === 'loyal' ? 'No loyalty-helped orders in the last 12 weeks.' : 'No orders in the last 12 weeks.'}</Text>
            )}
            <Text style={styles.small}>{series === 'loyal' ? 'Revenue from orders that used a reward, each week.' : 'All revenue, each week. Switch to Loyalty for the part rewards helped.'}</Text>
          </Card>
        </FadeIn>

        <FadeIn index={3}>
          <SectionTitle sub="Gift codes, points, games and member discounts.">Revenue by source</SectionTitle>
          <Card style={{ paddingVertical: 4 }}>
            {sources.length ? (
              sources.map((s, i) => {
                const nm = SOURCE_NAMES[s.source] || ['pricetag', s.source];
                const ret = s.cost > 0 ? s.revenue / s.cost : 0;
                return (
                  <View key={s.source} style={[styles.row, i > 0 && styles.rowLine]}>
                    <IconBox name={nm[0]} size={34} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.rowTitle} numberOfLines={1}>{nm[1]}</Text>
                      <Text style={styles.rowSub} numberOfLines={1}>
                        {s.issued == null ? `${s.used} orders` : `${s.issued} sent · ${s.used} used (${pct(s.used, s.issued)}%)`} · cost {m(s.cost)}
                      </Text>
                    </View>
                    <View style={{ alignItems: 'flex-end' }}>
                      <Text style={styles.rowValue}>{m(s.revenue)}</Text>
                      {ret ? <Text style={styles.ret}>{ret.toFixed(1)}x return</Text> : null}
                    </View>
                  </View>
                );
              })
            ) : (
              <Text style={[styles.empty, { paddingVertical: spacing.lg }]}>No gift codes, points or member discounts used in this period yet.</Text>
            )}
          </Card>
        </FadeIn>

        <FadeIn index={4}>
          <SectionTitle sub="Customers with a phone number on their orders.">Members vs guests</SectionTitle>
          <Card style={{ gap: spacing.md }}>
            {segs.map(([k, label, icon], i) => {
              const x = g[k] || { orders: 0, revenue: 0, customers: 0 };
              const freq = x.customers ? x.orders / x.customers : 0;
              return (
                <View key={k} style={[{ gap: 6 }, i > 0 && styles.segLine]}>
                  <View style={styles.segHead}>
                    <Ionicons name={icon} size={16} color={k === 'vip' ? colors.purple : colors.primary} />
                    <Text style={styles.rowTitle}>{label}</Text>
                    <Text style={styles.rowSub}>{x.customers} customer{x.customers === 1 ? '' : 's'}</Text>
                  </View>
                  <View style={styles.segNums}>
                    <Text style={styles.segNum}><Text style={styles.segB}>{m(aovs[i])}</Text> average order</Text>
                    <Text style={styles.segNum}><Text style={styles.segB}>{freq.toFixed(1)}</Text> orders each</Text>
                    <Text style={styles.segNum}><Text style={styles.segB}>{m(x.revenue)}</Text> revenue</Text>
                  </View>
                  <View style={styles.track}>
                    <View style={[styles.fill, { width: `${Math.max(3, (aovs[i] / aovMax) * 100)}%`, backgroundColor: k === 'vip' ? colors.purple : k === 'members' ? colors.primary : colors.info }]} />
                  </View>
                </View>
              );
            })}
            <Text style={styles.small}>Repeat customers: {r.repeaters || 0} of {r.customers || 0} ordered twice or more in this period.</Text>
          </Card>
        </FadeIn>

        <FadeIn index={5}>
          <SectionTitle sub="Rewards earned but not used yet: plan for them like you plan stock.">What you owe</SectionTitle>
          <View style={styles.owedGrid}>
            {owed.map(([icon, label, value, sub]) => (
              <Card key={label} style={styles.owed}>
                <IconBox name={icon} size={32} tint={colors.teal} bg={colors.tealBg} />
                <Text style={styles.owedLabel}>{label}</Text>
                <Text style={styles.owedValue} numberOfLines={1} adjustsFontSizeToFit>{value}</Text>
                <Text style={styles.owedSub}>{sub}</Text>
              </Card>
            ))}
          </View>
        </FadeIn>

        <Text style={styles.fine}>
          An order counts as loyalty-helped when it used a personal gift code, scratch card or wheel prize, points, the member discount or wallet money. Return = revenue from those orders divided by the discount given on them. Cancelled and rejected orders are left out.
        </Text>
      </>
    );
  };

  return (
    <ScrollView
      contentContainerStyle={contentStyle}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
      showsVerticalScrollIndicator={false}
    >
      <Chips options={RANGES} value={days} onChange={setDays} />
      {body()}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
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
  heroValue: {
    fontFamily: font.bold,
    fontSize: 32,
    color: '#fff',
    marginTop: 4,
  },
  heroSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: 'rgba(255,255,255,0.7)',
    marginTop: 2,
    lineHeight: 18,
  },
  empty: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
    paddingVertical: spacing.md,
  },
  small: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: spacing.sm,
    lineHeight: 16,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: 12,
  },
  rowLine: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  rowTitle: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  rowSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  rowValue: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  ret: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.success,
  },
  segLine: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingTop: spacing.md,
  },
  segHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  segNums: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    columnGap: spacing.md,
  },
  segNum: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  segB: {
    fontFamily: font.semiBold,
    color: colors.ink,
  },
  track: {
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.bg,
    overflow: 'hidden',
  },
  fill: {
    height: 6,
    borderRadius: 3,
  },
  owedGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  owed: {
    flexBasis: '47%',
    flexGrow: 1,
    padding: spacing.md,
    gap: 2,
  },
  owedLabel: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
    marginTop: spacing.sm,
  },
  owedValue: {
    fontFamily: font.bold,
    fontSize: 18,
    color: colors.ink,
  },
  owedSub: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    lineHeight: 15,
  },
  fine: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    lineHeight: 17,
  },
});
