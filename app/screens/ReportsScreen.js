import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import MiniBars from '../components/MiniBars';
import ScreenHeader from '../components/ScreenHeader';
import SelectField from '../components/SelectField';
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
  { key: 'week', label: '7 days' },
  { key: 'month', label: '30 days' },
  { key: 'year', label: '12 months' },
];

// Mirrors the website's Reports > Report Type dropdown — the API returns every dataset in
// one response, so switching type just picks which section to list.
const REPORT_TYPES = [
  { value: 'sales', label: 'Orders' },
  { value: 'items', label: 'Top dishes' },
  { value: 'customers', label: 'Top customers' },
  { value: 'payment', label: 'Payment methods' },
  { value: 'hourly', label: 'Sales by hour' },
  { value: 'staff', label: 'Staff' },
];

function hourLabel(hour) {
  const h = Number(hour);
  if (h === 0) return '12 AM';
  if (h < 12) return `${h} AM`;
  if (h === 12) return '12 PM';
  return `${h - 12} PM`;
}

function getReportRows(reportType, data, currency) {
  const m = (v) => money(v, currency);
  switch (reportType) {
    case 'customers':
      return {
        emptyTitle: 'No customers yet',
        emptySubtitle: 'Customer spending will show up here',
        rows: (data?.top_customers || []).map((c, i) => ({
          id: `${c.customer_name}_${c.phone}_${i}`,
          title: c.customer_name || 'No name',
          subtitle: `${c.phone || 'No phone'} · ${c.total_orders} orders`,
          value: m(c.total_spent),
          meta: c.last_order_date ? `Last ${dayLabel(c.last_order_date)}` : '',
        })),
      };
    case 'items':
      return {
        emptyTitle: 'No dishes sold yet',
        emptySubtitle: 'Best sellers will show up here',
        rows: (data?.top_items || []).map((it, i) => ({
          id: `${it.item_name}_${i}`,
          title: it.item_name || 'Unnamed dish',
          subtitle: `${it.total_quantity} sold`,
          value: m(it.total_revenue),
        })),
      };
    case 'payment':
      return {
        emptyTitle: 'No payments yet',
        emptySubtitle: 'Payment method totals will show up here',
        rows: (data?.payment_methods || []).map((p, i) => ({
          id: `${p.payment_method}_${i}`,
          title: p.payment_method || 'Unknown',
          subtitle: `${p.count} orders`,
          value: m(p.amount),
        })),
      };
    case 'hourly':
      return {
        emptyTitle: 'No hourly data',
        emptySubtitle: 'Sales by hour are shown for Today',
        rows: (data?.hourly_sales || []).map((h, i) => ({
          id: `${h.hour}_${i}`,
          title: hourLabel(h.hour),
          subtitle: `${h.order_count} orders`,
          value: m(h.total_sales),
        })),
      };
    case 'staff':
      return {
        emptyTitle: 'No staff sales yet',
        emptySubtitle: 'Sales per staff member will show up here',
        rows: (data?.staff_performance || []).map((s, i) => ({
          id: `${s.staff_name}_${i}`,
          title: s.staff_name || 'Unknown',
          subtitle: `${s.total_orders} orders`,
          value: m(s.total_sales),
        })),
      };
    case 'sales':
    default:
      return {
        emptyTitle: 'No sales yet',
        emptySubtitle: 'Orders in this period will show up here',
        rows: (data?.sales_details || []).map((o) => ({
          id: o.id,
          title: o.order_number,
          subtitle: `${o.customer_name || 'Guest'} · ${o.payment_method || ''}`,
          value: m(o.total),
          meta: dayLabel(o.created_at),
        })),
      };
  }
}

// Bars for the period: by hour today, by day for 7/30 days, by month for 12 months.
function buildBars(period, data) {
  if (period === 'today') {
    const byHour = new Map((data?.hourly_sales || []).map((h) => [Number(h.hour), Number(h.total_sales) || 0]));
    const nowH = new Date().getHours();
    const from = Math.max(0, Math.min(10, nowH - 11));
    return Array.from({ length: Math.max(1, nowH - from + 1) }, (_, i) => ({ key: from + i, value: byHour.get(from + i) || 0, hour: from + i }));
  }
  const rows = data?.sales_details || [];
  if (period === 'year') {
    const out = [];
    const d = new Date();
    for (let i = 11; i >= 0; i -= 1) {
      const m = new Date(d.getFullYear(), d.getMonth() - i, 1);
      out.push({ key: `${m.getFullYear()}-${m.getMonth()}`, value: 0, month: m });
    }
    rows.forEach((o) => {
      const t = new Date(String(o.created_at).replace(' ', 'T'));
      const hit = out.find((b) => b.month.getFullYear() === t.getFullYear() && b.month.getMonth() === t.getMonth());
      if (hit) hit.value += Number(o.total) || 0;
    });
    return out;
  }
  const n = period === 'week' ? 7 : 30;
  const out = [];
  for (let i = n - 1; i >= 0; i -= 1) {
    const day = new Date();
    day.setDate(day.getDate() - i);
    const key = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
    out.push({ key, value: 0, day });
  }
  rows.forEach((o) => {
    const k = String(o.created_at).slice(0, 10);
    const hit = out.find((b) => b.key === k);
    if (hit) hit.value += Number(o.total) || 0;
  });
  return out;
}

export default function ReportsScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [period, setPeriod] = useState('today');
  const [reportType, setReportType] = useState('sales');

  const fetcher = useCallback(() => apiGet(`/api/get_sales_report.php?period=${period}&type=${reportType}`), [period, reportType]);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, {
    pollInterval: period === 'today' ? 30000 : 0,
    cacheKey: `reports:${period}:${reportType}`,
  });

  const summary = data?.summary || {};
  const orders = Number(summary.total_orders) || 0;
  const avg = orders ? (Number(summary.total_sales) || 0) / orders : 0;
  const { rows, emptyTitle, emptySubtitle } = useMemo(() => getReportRows(reportType, data, currency), [reportType, data, currency]);
  const bars = useMemo(() => buildBars(period, data), [period, data]);
  const hasBars = bars.some((b) => b.value > 0);
  const reportTitle = REPORT_TYPES.find((t) => t.value === reportType)?.label || 'Report';
  const expenses = Number(summary.total_expenses) || 0;

  const stats = [
    // whole rupees in the tiles so big totals fit; exact amounts are in the list below
    { label: 'Sales', value: money(Math.round(Number(summary.total_sales) || 0), currency), icon: 'trending-up', tint: colors.success, bg: colors.successBg },
    { label: 'Orders', value: String(orders), icon: 'receipt', tint: colors.info, bg: colors.infoBg },
    { label: 'Average bill', value: money(Math.round(avg), currency), icon: 'calculator', tint: colors.primary, bg: colors.primaryLight },
    { label: 'Dishes sold', value: String(summary.total_items ?? 0), icon: 'fast-food', tint: colors.warning, bg: colors.warningBg },
    { label: 'Customers', value: String(summary.total_customers ?? 0), icon: 'people', tint: colors.purple, bg: colors.purpleBg },
    expenses > 0
      ? { label: 'Profit after expenses', value: money(Math.round(Number(summary.net_profit) || 0), currency), icon: 'wallet', tint: colors.teal, bg: colors.tealBg }
      : null,
  ].filter(Boolean);

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Performance" title="Reports" onBack={navigation?.canGoBack() ? () => navigation.goBack() : undefined} />

      <View style={[styles.periodRow, { paddingHorizontal: gutter }]}>
        {PERIODS.map((p) => {
          const active = p.key === period;
          return (
            <PressableScale key={p.key} feedback="select" style={[styles.periodChip, active && styles.periodChipActive]} onPress={() => setPeriod(p.key)}>
              <Text style={[styles.periodChipText, active && styles.periodChipTextActive]} numberOfLines={1}>{p.label}</Text>
            </PressableScale>
          );
        })}
      </View>

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
          <FadeIn>
            <View style={styles.grid}>
              {stats.map((s) => (
                <View key={s.label} style={[styles.stat, shadow.sm]}>
                  <View style={[styles.statIcon, { backgroundColor: s.bg }]}>
                    <Ionicons name={s.icon} size={16} color={s.tint} />
                  </View>
                  <Text style={styles.statValue} numberOfLines={1} adjustsFontSizeToFit>{s.value}</Text>
                  <Text style={styles.statLabel} numberOfLines={1}>{s.label}</Text>
                </View>
              ))}
            </View>
          </FadeIn>

          {hasBars ? (
            <FadeIn index={1}>
              <View style={[styles.card, shadow.sm]}>
                <Text style={styles.cardTitle}>{period === 'today' ? 'Sales by hour' : period === 'year' ? 'Sales by month' : 'Sales by day'}</Text>
                <MiniBars
                  data={bars}
                  height={130}
                  valueLabel={(v) => moneyShort(v, currency)}
                  dayLabel={(b, i) => {
                    const every = bars.length > 14 ? 5 : bars.length > 8 ? 2 : 1;
                    if (i % every !== 0 && i !== bars.length - 1) return '';
                    if (period === 'today') return hourLabel(b.hour).replace(' ', '');
                    if (period === 'year') return b.month.toLocaleDateString(undefined, { month: 'short' }).slice(0, 3);
                    return String(b.day.getDate());
                  }}
                />
              </View>
            </FadeIn>
          ) : null}

          <FadeIn index={2}>
            <View style={styles.sectionHead}>
              <Text style={styles.sectionTitle}>{reportTitle}</Text>
              <View style={{ width: 170 }}>
                <SelectField value={reportType} onChange={setReportType} options={REPORT_TYPES} placeholder="Report" />
              </View>
            </View>
            {rows.length === 0 ? (
              <EmptyState icon="podium-outline" title={emptyTitle} subtitle={emptySubtitle} />
            ) : (
              <View style={[styles.card, shadow.sm, { paddingVertical: 4 }]}>
                {rows.slice(0, 100).map((row, i) => (
                  <View key={row.id ?? i} style={[styles.itemRow, i > 0 && styles.itemRowLine]}>
                    <Text style={styles.rank}>{i + 1}</Text>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.itemName} numberOfLines={1}>{row.title}</Text>
                      {row.subtitle ? <Text style={styles.itemSubtitle} numberOfLines={1}>{row.subtitle}</Text> : null}
                    </View>
                    <View style={{ alignItems: 'flex-end' }}>
                      <Text style={styles.rowValue}>{row.value}</Text>
                      {row.meta ? <Text style={styles.rowMeta}>{row.meta}</Text> : null}
                    </View>
                  </View>
                ))}
              </View>
            )}
          </FadeIn>
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  periodRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: -spacing.lg,
    marginBottom: spacing.sm,
  },
  periodChip: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    height: 40,
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    paddingHorizontal: 4,
    ...shadow.sm,
  },
  periodChipActive: {
    backgroundColor: colors.ink,
  },
  periodChipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  periodChipTextActive: {
    color: '#fff',
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
  },
  stat: {
    flexBasis: '30%',
    flexGrow: 1,
    minWidth: 100,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  statIcon: {
    width: 32,
    height: 32,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.sm,
  },
  statValue: {
    fontFamily: font.bold,
    fontSize: 18,
    color: colors.ink,
  },
  statLabel: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 2,
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
    marginBottom: spacing.md,
  },
  sectionHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.md,
    marginBottom: spacing.md,
  },
  sectionTitle: {
    flex: 1,
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: 12,
  },
  itemRowLine: {
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
  itemName: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  itemSubtitle: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
  },
  rowValue: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  rowMeta: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    marginTop: 1,
  },
});
