import { useCallback, useMemo, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import SelectField from '../components/SelectField';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import StatCard from '../components/StatCard';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';

const PERIODS = [
  { key: 'today', label: 'Today' },
  { key: 'week', label: '7 Days' },
  { key: 'month', label: '30 Days' },
  { key: 'year', label: '12 Months' },
];

// Mirrors the website's Reports > Report Type dropdown — the API already
// returns every dataset in one response, so switching type here just picks
// which section of the same payload to display.
const REPORT_TYPES = [
  { value: 'sales', label: 'Sales Report' },
  { value: 'customers', label: 'Customer Report' },
  { value: 'items', label: 'Top Items Report' },
  { value: 'payment', label: 'Payment Methods Report' },
  { value: 'hourly', label: 'Hourly Sales Report' },
  { value: 'staff', label: 'Staff Performance Report' },
];

function formatDate(value) {
  if (!value) return '-';
  const d = new Date(value);
  return isNaN(d.getTime()) ? '-' : d.toLocaleDateString('en-IN');
}

function hourLabel(hour) {
  const h = Number(hour);
  if (h === 0) return '12:00 AM';
  if (h < 12) return `${h}:00 AM`;
  if (h === 12) return '12:00 PM';
  return `${h - 12}:00 PM`;
}

// Each report type's own dataset from the same API response, normalized to
// {id, title, subtitle, value, meta} rows so a single list UI can render any
// of them — same idea as the website swapping its one report table's columns.
function getReportRows(reportType, data, currency) {
  switch (reportType) {
    case 'customers':
      return {
        emptyTitle: 'No customers yet',
        emptySubtitle: 'Customer spending will show up here',
        rows: (data?.top_customers || []).map((c, i) => ({
          id: `${c.customer_name}_${c.phone}_${i}`,
          title: c.customer_name || 'N/A',
          subtitle: `${c.phone || 'No phone'} · ${c.total_orders} orders`,
          value: `${currency}${c.total_spent}`,
          meta: `Last: ${formatDate(c.last_order_date)}`,
        })),
      };
    case 'items':
      return {
        emptyTitle: 'No items sold yet',
        emptySubtitle: 'Best sellers will show up here',
        rows: (data?.top_items || []).map((it, i) => ({
          id: `${it.item_name}_${i}`,
          title: it.item_name || 'Unnamed item',
          subtitle: `Qty sold: ${it.total_quantity}`,
          value: `${currency}${it.total_revenue}`,
        })),
      };
    case 'payment':
      return {
        emptyTitle: 'No payments yet',
        emptySubtitle: 'Payment method totals will show up here',
        rows: (data?.payment_methods || []).map((m, i) => ({
          id: `${m.payment_method}_${i}`,
          title: m.payment_method || 'Unknown',
          subtitle: `${m.count} orders`,
          value: `${currency}${m.amount}`,
        })),
      };
    case 'hourly':
      return {
        emptyTitle: 'No hourly data',
        emptySubtitle: 'Hourly sales only apply to the "Today" period',
        rows: (data?.hourly_sales || []).map((h, i) => ({
          id: `${h.hour}_${i}`,
          title: hourLabel(h.hour),
          subtitle: `${h.order_count} orders`,
          value: `${currency}${h.total_sales}`,
        })),
      };
    case 'staff':
      return {
        emptyTitle: 'No staff performance yet',
        emptySubtitle: 'Sales per staff member will show up here',
        rows: (data?.staff_performance || []).map((s, i) => ({
          id: `${s.staff_name}_${i}`,
          title: s.staff_name || 'Unknown',
          subtitle: `${s.total_orders} orders`,
          value: `${currency}${s.total_sales}`,
        })),
      };
    case 'sales':
    default:
      return {
        emptyTitle: 'No sales yet',
        emptySubtitle: 'Order details will show up here',
        rows: (data?.sales_details || []).map((o) => ({
          id: o.id,
          title: o.order_number,
          subtitle: `${o.customer_name || 'Guest'} · ${o.payment_method}`,
          value: `${currency}${o.total}`,
          meta: formatDate(o.created_at),
        })),
      };
  }
}

export default function ReportsScreen() {
  const { user } = useAuth();
  const [period, setPeriod] = useState('today');
  const [reportType, setReportType] = useState('sales');
  const fetcher = useCallback(
    () => apiGet(`/api/get_sales_report.php?period=${period}&type=${reportType}`),
    [period, reportType]
  );
  // Only poll while looking at "Today" — older periods aren't changing live.
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, {
    pollInterval: period === 'today' ? 30000 : 0,
  });
  const currency = user?.currency_symbol || '₹';

  const summary = data?.summary || {};
  const { rows, emptyTitle, emptySubtitle } = useMemo(
    () => getReportRows(reportType, data, currency),
    [reportType, data, currency]
  );
  const reportTitle = REPORT_TYPES.find((t) => t.value === reportType)?.label || 'Report';

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Performance" title="Report" />

      <View style={styles.periodRow}>
        {PERIODS.map((p) => {
          const active = p.key === period;
          return (
            <Pressable
              key={p.key}
              style={[styles.periodChip, active && styles.periodChipActive]}
              onPress={() => setPeriod(p.key)}
            >
              <Text style={[styles.periodChipText, active && styles.periodChipTextActive]}>{p.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <View style={styles.reportTypeWrap}>
        <SelectField value={reportType} onChange={setReportType} options={REPORT_TYPES} placeholder="Report Type" />
      </View>

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : (
        <ScrollView
          style={styles.fill}
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.grid}>
            <StatCard
              label="Total Sales"
              value={`${currency}${summary.total_sales ?? 0}`}
              icon="trending-up-outline"
              tint={colors.success}
              tintBg={colors.successBg}
            />
            <StatCard
              label="Orders"
              value={summary.total_orders ?? 0}
              icon="receipt-outline"
              tint={colors.info}
              tintBg={colors.infoBg}
            />
            <StatCard
              label="Items Sold"
              value={summary.total_items ?? 0}
              icon="fast-food-outline"
              tint={colors.warning}
              tintBg={colors.warningBg}
            />
            <StatCard
              label="Customers"
              value={summary.total_customers ?? 0}
              icon="people-outline"
              tint={colors.purple}
              tintBg={colors.purpleBg}
            />
            <StatCard
              label="Total Expenses"
              value={`${currency}${summary.total_expenses ?? 0}`}
              icon="trending-down-outline"
              tint={colors.danger}
              tintBg={colors.dangerBg}
            />
            <StatCard
              label="Net Profit"
              value={`${currency}${summary.net_profit ?? 0}`}
              icon="wallet-outline"
              tint={colors.teal}
              tintBg={colors.tealBg}
            />
          </View>

          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle}>{reportTitle}</Text>
          </View>

          {rows.length === 0 ? (
            <EmptyState icon="podium-outline" title={emptyTitle} subtitle={emptySubtitle} />
          ) : (
            <View style={styles.list}>
              {rows.map((row, i) => (
                <View key={row.id ?? i} style={[styles.itemRow, shadow.sm]}>
                  <View style={styles.rankWrap}>
                    <Text style={styles.rank}>{i + 1}</Text>
                  </View>
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
    paddingHorizontal: spacing.xl,
    marginTop: -spacing.lg,
    marginBottom: spacing.sm,
  },
  periodChip: {
    flex: 1,
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    paddingVertical: 10,
    ...shadow.sm,
  },
  periodChipActive: {
    backgroundColor: colors.primary,
  },
  periodChipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  periodChipTextActive: {
    color: '#fff',
  },
  reportTypeWrap: {
    paddingHorizontal: spacing.xl,
    marginBottom: spacing.md,
  },
  content: {
    padding: spacing.xl,
    paddingTop: spacing.sm,
    paddingBottom: 120,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
    marginBottom: spacing.lg,
  },
  sectionHead: {
    marginTop: spacing.md,
    marginBottom: spacing.md,
  },
  sectionTitle: {
    fontFamily: font.semiBold,
    fontSize: 17,
    color: colors.ink,
  },
  list: {
    gap: spacing.sm,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.md,
  },
  rankWrap: {
    width: 28,
    height: 28,
    borderRadius: 10,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rank: {
    fontFamily: font.bold,
    fontSize: 12.5,
    color: colors.primary,
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
    marginTop: 2,
  },
  rowValue: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.primary,
  },
  rowMeta: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    marginTop: 2,
  },
});
