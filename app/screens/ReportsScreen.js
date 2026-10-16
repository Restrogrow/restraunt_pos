import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import DateRangeSheet, { dateKey, fromKey, rangeLabel } from '../components/DateRangeSheet';
import MiniBars from '../components/MiniBars';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { dayLabel, money, moneyShort } from '../utils/format';

const PERIODS = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'week', label: '7 days' },
  { key: 'month', label: '30 days' },
  { key: 'thisMonth', label: 'This month' },
];

const BREAKDOWNS = [
  { key: 'items', label: 'Dishes', icon: 'fast-food-outline' },
  { key: 'payment', label: 'Payments', icon: 'card-outline' },
  { key: 'customers', label: 'Customers', icon: 'people-outline' },
  { key: 'sales', label: 'Orders', icon: 'receipt-outline' },
  { key: 'staff', label: 'Staff', icon: 'person-outline' },
];

function addDays(d, n) {
  const x = new Date(d);
  x.setDate(x.getDate() + n);
  return x;
}
function today0() {
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  return t;
}
const daysBetween = (a, b) => Math.round((fromKey(b) - fromKey(a)) / 86400000) + 1;

// The period as dates, plus the equally long period just before it for the comparison.
function resolve(period, custom) {
  const t = today0();
  let start;
  let end = dateKey(t);
  if (period === 'today') start = end;
  else if (period === 'yesterday') start = end = dateKey(addDays(t, -1));
  else if (period === 'week') start = dateKey(addDays(t, -6));
  else if (period === 'month') start = dateKey(addDays(t, -29));
  else if (period === 'thisMonth') start = dateKey(new Date(t.getFullYear(), t.getMonth(), 1));
  else {
    start = custom.start;
    end = custom.end;
  }
  const len = daysBetween(start, end);
  let prevStart;
  let prevEnd;
  if (period === 'thisMonth') {
    const lmStart = new Date(t.getFullYear(), t.getMonth() - 1, 1);
    const lmLast = new Date(t.getFullYear(), t.getMonth(), 0);
    prevStart = dateKey(lmStart);
    prevEnd = dateKey(new Date(lmStart.getFullYear(), lmStart.getMonth(), Math.min(t.getDate(), lmLast.getDate())));
  } else {
    prevEnd = dateKey(addDays(fromKey(start), -1));
    prevStart = dateKey(addDays(fromKey(start), -len));
  }
  return { start, end, len, prevStart, prevEnd };
}

const query = (r, type, isToday) =>
  isToday ? `/api/get_sales_report.php?period=today&type=${type}` : `/api/get_sales_report.php?period=custom&start_date=${r.start}&end_date=${r.end}&type=${type}`;

function hourText(h) {
  const n = Number(h);
  const f = (x) => (x === 0 ? '12 AM' : x < 12 ? `${x} AM` : x === 12 ? '12 PM' : `${x - 12} PM`);
  return `${f(n)}–${f((n + 1) % 24)}`;
}

// Chart bars: by hour for one day, by day up to ~2 months, by month beyond.
function buildBars(r, data) {
  const daily = data?.daily_sales;
  const hourly = data?.hourly_all || data?.hourly_sales;
  if (r.len === 1) {
    const map = new Map((hourly || []).map((h) => [Number(h.hour), Number(h.total_sales) || 0]));
    const hours = [...map.keys()];
    if (!hours.length) return [];
    const from = Math.max(0, Math.min(...hours) - 1);
    const to = Math.min(23, Math.max(...hours, from + 7));
    return Array.from({ length: to - from + 1 }, (_, i) => ({ key: from + i, value: map.get(from + i) || 0, hour: from + i }));
  }
  // per-day totals (server series; older servers: summed from the order list)
  const perDay = new Map();
  if (Array.isArray(daily)) daily.forEach((d) => perDay.set(String(d.day).slice(0, 10), Number(d.total_sales) || 0));
  else (data?.sales_details || []).forEach((o) => {
    const k = String(o.created_at).slice(0, 10);
    perDay.set(k, (perDay.get(k) || 0) + (Number(o.total) || 0));
  });
  if (r.len <= 62) {
    return Array.from({ length: r.len }, (_, i) => {
      const day = addDays(fromKey(r.start), i);
      const k = dateKey(day);
      return { key: k, value: perDay.get(k) || 0, day };
    });
  }
  const months = new Map();
  perDay.forEach((v, k) => {
    const mk = k.slice(0, 7);
    months.set(mk, (months.get(mk) || 0) + v);
  });
  const out = [];
  let m = new Date(fromKey(r.start).getFullYear(), fromKey(r.start).getMonth(), 1);
  const last = fromKey(r.end);
  while (m <= last) {
    const mk = `${m.getFullYear()}-${String(m.getMonth() + 1).padStart(2, '0')}`;
    out.push({ key: mk, value: months.get(mk) || 0, month: new Date(m) });
    m = new Date(m.getFullYear(), m.getMonth() + 1, 1);
  }
  return out;
}

function Bar({ pct, tint = colors.primary }) {
  return (
    <View style={styles.barTrack}>
      <View style={[styles.barFill, { width: `${Math.max(3, Math.min(100, pct))}%`, backgroundColor: tint }]} />
    </View>
  );
}

export default function ReportsScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const m = (v) => money(v, currency);
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [period, setPeriod] = useState('today');
  const [custom, setCustom] = useState(() => ({ start: dateKey(addDays(today0(), -6)), end: dateKey(today0()) }));
  const [pickerOpen, setPickerOpen] = useState(false);
  const [breakdown, setBreakdown] = useState('items');

  const r = useMemo(() => resolve(period, custom), [period, custom]);
  const isToday = period === 'today';

  const fetcher = useCallback(() => apiGet(query(r, 'sales', isToday)), [r, isToday]);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, {
    pollInterval: isToday ? 30000 : 0,
    cacheKey: `reports2:${r.start}:${r.end}:${isToday}`,
  });
  const prevFetcher = useCallback(() => apiGet(query({ start: r.prevStart, end: r.prevEnd }, 'sales', false)), [r.prevStart, r.prevEnd]);
  const prev = useApiData(prevFetcher, { cacheKey: `reports2:${r.prevStart}:${r.prevEnd}:false` });

  const s = data?.summary || {};
  const sales = Number(s.total_sales) || 0;
  const orders = Number(s.total_orders) || 0;
  const avg = orders ? sales / orders : 0;

  // Today is compared with yesterday up to the same hour, so a morning isn't set against a full day.
  const prevSales = useMemo(() => {
    if (!prev.data?.summary) return null;
    if (!isToday) return Number(prev.data.summary.total_sales) || 0;
    const hrs = prev.data.hourly_all;
    if (!Array.isArray(hrs)) return null;
    const nowH = new Date().getHours();
    return hrs.filter((h) => Number(h.hour) <= nowH).reduce((t, h) => t + (Number(h.total_sales) || 0), 0);
  }, [prev.data, isToday]);
  const change = prevSales ? Math.round(((sales - prevSales) / prevSales) * 100) : null;
  const vsText = isToday ? 'vs yesterday by this time' : period === 'yesterday' ? 'vs the day before' : period === 'thisMonth' ? 'vs same days last month' : `vs previous ${r.len} days`;

  const bars = useMemo(() => buildBars(r, data), [r, data]);
  const hasBars = bars.some((b) => b.value > 0);
  const best = bars.reduce((b, x) => (x.value > (b?.value || 0) ? x : b), null);
  const busiest = useMemo(() => {
    const hrs = data?.hourly_all || data?.hourly_sales || [];
    return hrs.reduce((b, h) => ((Number(h.order_count) || 0) > (Number(b?.order_count) || 0) ? h : b), null);
  }, [data]);
  const topDish = (data?.top_items || [])[0];

  const insights = [];
  if (orders && best && r.len > 1) {
    insights.push({
      icon: 'trophy',
      text: best.day
        ? `Best day: ${best.day.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short' })} with ${m(Math.round(best.value))}`
        : best.month
          ? `Best month: ${best.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}`
          : '',
    });
  }
  if (orders && busiest) insights.push({ icon: 'time', text: `Busiest time: ${hourText(busiest.hour)} (${busiest.order_count} orders)` });
  if (topDish) insights.push({ icon: 'flame', text: `Most ordered: ${topDish.item_name} (${topDish.total_quantity} sold)` });
  if (Number(s.total_expenses) > 0) insights.push({ icon: 'wallet', text: `Expenses ${m(Math.round(s.total_expenses))}, so profit is ${m(Math.round(s.net_profit))}` });

  const rangeText = period === 'today' ? 'Today' : rangeLabel(r.start, r.end);

  // ── breakdown lists ──
  const lists = {
    items: (data?.top_items || [])
      .map((it) => ({ title: it.item_name, sub: `${it.total_quantity} sold`, value: Number(it.total_revenue) || 0 }))
      .sort((a, b) => b.value - a.value),
    payment: (data?.payment_methods || []).map((p) => ({ title: p.payment_method || 'Unknown', sub: `${p.count} order${Number(p.count) === 1 ? '' : 's'}`, value: Number(p.amount) || 0 })),
    customers: (data?.top_customers || []).map((c) => ({ title: c.customer_name || 'No name', sub: `${c.total_orders} orders${c.phone ? ` · ${c.phone}` : ''}`, value: Number(c.total_spent) || 0 })),
    sales: (data?.sales_details || []).map((o) => ({ title: o.order_number, sub: `${dayLabel(o.created_at)} · ${o.payment_method || ''}`, value: Number(o.total) || 0, plain: true })),
    staff: (data?.staff_performance || []).map((st) => ({ title: st.staff_name || 'Unknown', sub: `${st.total_orders} orders`, value: Number(st.total_sales) || 0 })),
  };
  const shownBreakdowns = BREAKDOWNS.filter((b) => b.key !== 'staff' || lists.staff.length);
  const rows = lists[breakdown] || [];
  const rowTotal = rows.reduce((t, x) => t + x.value, 0) || 1;
  const rowMax = Math.max(1, ...rows.map((x) => x.value));
  const EXPLAIN = {
    items: 'Dishes that brought in the most money in this period.',
    payment: 'How customers paid. The bar shows the share of sales.',
    customers: 'Customers who spent the most in this period.',
    sales: 'Every order in this period, newest first.',
    staff: 'Sales by the staff member who rang them up.',
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Performance" title="Reports" onBack={navigation?.canGoBack() ? () => navigation.goBack() : undefined} />

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.periodScroll} contentContainerStyle={{ gap: spacing.sm, paddingHorizontal: gutter, alignItems: 'center' }}>
        {PERIODS.map((p) => {
          const on = p.key === period;
          return (
            <PressableScale key={p.key} feedback="select" style={[styles.chip, shadow.sm, on && styles.chipOn]} onPress={() => setPeriod(p.key)}>
              <Text style={[styles.chipText, on && styles.chipTextOn]}>{p.label}</Text>
            </PressableScale>
          );
        })}
        <PressableScale feedback="select" style={[styles.chip, shadow.sm, period === 'custom' && styles.chipOn]} onPress={() => setPickerOpen(true)} accessibilityLabel="Choose dates">
          <Ionicons name="calendar" size={14} color={period === 'custom' ? '#fff' : colors.primary} />
          <Text style={[styles.chipText, period === 'custom' ? styles.chipTextOn : { color: colors.primary }]}>
            {period === 'custom' ? rangeLabel(custom.start, custom.end) : 'Pick dates'}
          </Text>
        </PressableScale>
      </ScrollView>

      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <ScrollView
          style={styles.fill}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.sm, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          showsVerticalScrollIndicator={false}
        >
          {/* headline */}
          <FadeIn>
            <LinearGradient colors={['#2A2735', '#1D1B26']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
              <View style={styles.heroTop}>
                <Text style={styles.heroLabel}>Sales · {rangeText}</Text>
                {change !== null ? (
                  <View style={[styles.delta, { backgroundColor: change >= 0 ? 'rgba(74,222,128,0.16)' : 'rgba(255,138,128,0.16)' }]}>
                    <Ionicons name={change >= 0 ? 'arrow-up' : 'arrow-down'} size={12} color={change >= 0 ? '#4ADE80' : '#FF8A80'} />
                    <Text style={[styles.deltaText, { color: change >= 0 ? '#4ADE80' : '#FF8A80' }]}>{Math.abs(change)}%</Text>
                  </View>
                ) : null}
              </View>
              <Text style={styles.heroValue} numberOfLines={1} adjustsFontSizeToFit>{m(Math.round(sales))}</Text>
              <Text style={styles.heroSub}>
                {change !== null ? `${change >= 0 ? 'Up' : 'Down'} ${Math.abs(change)}% ${vsText} (${m(Math.round(prevSales))})` : orders ? `${orders} order${orders === 1 ? '' : 's'} in this period` : 'No sales in this period yet'}
              </Text>
              <View style={styles.heroGrid}>
                {[
                  ['Orders', String(orders)],
                  ['Average bill', orders ? m(Math.round(avg)) : '—'],
                  ['Dishes sold', String(s.total_items ?? 0)],
                  ['Customers', String(s.total_customers ?? 0)],
                ].map(([l, v]) => (
                  <View key={l} style={styles.heroStat}>
                    <Text style={styles.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
                    <Text style={styles.heroStatLabel}>{l}</Text>
                  </View>
                ))}
              </View>
            </LinearGradient>
          </FadeIn>

          {insights.length ? (
            <FadeIn index={1}>
              <View style={[styles.card, shadow.sm, { gap: 12 }]}>
                <Text style={styles.cardTitle}>In short</Text>
                {insights.map((it) => (
                  <View key={it.text} style={styles.insight}>
                    <View style={styles.insightIcon}>
                      <Ionicons name={it.icon} size={15} color={colors.primary} />
                    </View>
                    <Text style={styles.insightText}>{it.text}</Text>
                  </View>
                ))}
              </View>
            </FadeIn>
          ) : null}

          {hasBars ? (
            <FadeIn index={2}>
              <View style={[styles.card, shadow.sm]}>
                <Text style={styles.cardTitle}>{r.len === 1 ? 'Sales by hour' : r.len <= 62 ? 'Sales by day' : 'Sales by month'}</Text>
                <MiniBars
                  data={bars}
                  height={140}
                  highlightLast={isToday || r.end === dateKey(today0())}
                  valueLabel={(v) => moneyShort(v, currency)}
                  dayLabel={(b, i) => {
                    const every = bars.length > 20 ? 5 : bars.length > 10 ? 2 : 1;
                    if (i % every !== 0 && i !== bars.length - 1) return '';
                    if (b.hour != null) return hourText(b.hour).split('–')[0].replace(' ', '');
                    if (b.month) return b.month.toLocaleDateString(undefined, { month: 'short' }).slice(0, 3);
                    return String(b.day.getDate());
                  }}
                />
              </View>
            </FadeIn>
          ) : null}

          <FadeIn index={3}>
            <Text style={styles.sectionTitle}>Breakdown</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: spacing.sm, paddingBottom: spacing.sm }}>
              {shownBreakdowns.map((b) => {
                const on = b.key === breakdown;
                return (
                  <PressableScale key={b.key} feedback="select" style={[styles.seg, on && styles.segOn]} onPress={() => setBreakdown(b.key)}>
                    <Ionicons name={b.icon} size={15} color={on ? '#fff' : colors.inkSoft} />
                    <Text style={[styles.segText, on && { color: '#fff' }]}>{b.label}</Text>
                  </PressableScale>
                );
              })}
            </ScrollView>
            <Text style={styles.explain}>{EXPLAIN[breakdown]}</Text>
            {rows.length === 0 ? (
              <View style={[styles.card, shadow.sm]}>
                <EmptyState icon="podium-outline" title="Nothing here yet" subtitle="Try a longer period." />
              </View>
            ) : (
              <View style={[styles.card, shadow.sm, { paddingVertical: 6 }]}>
                {rows.slice(0, 50).map((row, i) => (
                  <View key={`${row.title}_${i}`} style={[styles.row, i > 0 && styles.rowLine]}>
                    {!row.plain ? <Text style={styles.rank}>{i + 1}</Text> : null}
                    <View style={{ flex: 1, gap: 4 }}>
                      <View style={styles.rowTop}>
                        <Text style={styles.rowTitle} numberOfLines={1}>{row.title}</Text>
                        <Text style={styles.rowValue}>{m(row.value)}</Text>
                      </View>
                      <View style={styles.rowTop}>
                        <Text style={styles.rowSub} numberOfLines={1}>{row.sub}</Text>
                        {breakdown === 'payment' ? <Text style={styles.rowPct}>{Math.round((row.value / rowTotal) * 100)}%</Text> : null}
                      </View>
                      {!row.plain ? <Bar pct={(row.value / (breakdown === 'payment' ? rowTotal : rowMax)) * 100} tint={breakdown === 'payment' ? colors.info : colors.primary} /> : null}
                    </View>
                  </View>
                ))}
              </View>
            )}
          </FadeIn>
        </ScrollView>
      )}

      <DateRangeSheet
        visible={pickerOpen}
        start={custom.start}
        end={custom.end}
        onClose={() => setPickerOpen(false)}
        onApply={(start, end) => {
          setCustom({ start, end });
          setPeriod('custom');
          setPickerOpen(false);
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  periodScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 48,
    marginTop: -spacing.lg,
  },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 40,
    paddingHorizontal: 16,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
  },
  chipOn: {
    backgroundColor: colors.ink,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.inkSoft,
  },
  chipTextOn: {
    color: '#fff',
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
    fontSize: 34,
    color: '#fff',
    marginTop: 4,
  },
  heroSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: 'rgba(255,255,255,0.7)',
    marginTop: 2,
  },
  heroGrid: {
    flexDirection: 'row',
    marginTop: spacing.lg,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,0.12)',
  },
  heroStat: {
    flex: 1,
  },
  heroStatValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: '#fff',
  },
  heroStatLabel: {
    fontFamily: font.regular,
    fontSize: 11,
    color: 'rgba(255,255,255,0.6)',
    marginTop: 1,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  cardTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  insight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  insightIcon: {
    width: 30,
    height: 30,
    borderRadius: 10,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  insightText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
    lineHeight: 19,
  },
  sectionTitle: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  seg: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 38,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  segOn: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  segText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  explain: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginBottom: spacing.sm,
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
  rank: {
    width: 26,
    height: 26,
    lineHeight: 26,
    borderRadius: 9,
    overflow: 'hidden',
    textAlign: 'center',
    backgroundColor: colors.primaryLight,
    color: colors.primary,
    fontFamily: font.bold,
    fontSize: 12,
  },
  rowTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
  },
  rowTitle: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  rowValue: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  rowSub: {
    flex: 1,
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  rowPct: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.info,
  },
  barTrack: {
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.bg,
    overflow: 'hidden',
  },
  barFill: {
    height: 6,
    borderRadius: 3,
  },
});
