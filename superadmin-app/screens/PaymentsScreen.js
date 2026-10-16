import { useCallback, useState } from 'react';
import { Platform, StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Card, Chips, Metric, Pill, Row, SectionTitle } from '../components/kit';
import { pf, sa, BASE_URL } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { money, moneyShort, num, shortDate } from '../utils/format';

function monthKey(offset) {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - offset);
  return { value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, label: d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' }) };
}

export default function PaymentsScreen() {
  const [tab, setTab] = useState('recent');
  const [month, setMonth] = useState(monthKey(0).value);

  const statsFetcher = useCallback(() => sa.get('getSubscriptionStats'), []);
  const stats = useApiData(statsFetcher, { cacheKey: 'substats' });
  const listFetcher = useCallback(() => (tab === 'recent' ? sa.get('getSubscriptionPayments', { limit: 60 }) : pf.get('invoices', { month })), [tab, month]);
  const list = useApiData(listFetcher, { cacheKey: tab === 'recent' ? 'subpay' : `invoices:${month}` });

  const s = stats.data?.stats || {};
  const rows = tab === 'recent' ? list.data?.payments || [] : list.data?.rows || [];
  const openInvoice = (p) => Platform.OS === 'web' && window.open(`${BASE_URL}/superadmin/api_platform.php?action=invoice&id=${p.id}`, '_blank');

  return (
    <Screen
      back
      eyebrow="Subscriptions"
      title="Payments"
      loading={list.loading && !list.data}
      error={!list.data ? list.error : ''}
      onRetry={list.refresh}
      refreshing={list.refreshing}
      onRefresh={() => {
        list.refresh();
        stats.refresh();
      }}
    >
      <View style={styles.grid}>
        <Metric icon="checkmark-circle" tone="success" label="Active plans" value={num(s.active)} />
        <Metric icon="hourglass" tone="info" label="On trial" value={num(s.trial)} />
        <Metric icon="close-circle" tone="danger" label="Expired" value={num(s.expired)} />
        <Metric icon="cash" tone="primary" label="Collected · 30 days" value={moneyShort(s.monthly_revenue)} />
      </View>

      <Chips
        value={tab}
        onChange={setTab}
        style={{ marginTop: spacing.xl, marginBottom: spacing.md }}
        options={[
          { value: 'recent', label: 'Recent payments' },
          { value: 'invoices', label: 'Invoices by month' },
        ]}
      />
      {tab === 'invoices' ? (
        <>
          <Chips value={month} onChange={setMonth} options={[0, 1, 2, 3, 4, 5].map(monthKey)} style={{ marginBottom: spacing.md }} />
          {list.data?.sum ? (
            <Card style={{ marginBottom: 12 }}>
              <View style={styles.sumRow}>
                <Sum label="Taxable" value={money(list.data.sum.base)} />
                <Sum label="GST" value={money(list.data.sum.tax)} />
                <Sum label="Total" value={money(list.data.sum.total)} strong />
              </View>
              {!list.data.gst_ready ? <Text style={styles.warn}>Add your GSTIN in Settings to issue GST invoices.</Text> : null}
            </Card>
          ) : null}
        </>
      ) : null}

      {list.data && !rows.length ? <EmptyState icon="wallet-outline" title="No payments" subtitle={tab === 'recent' ? 'Subscription payments will show here.' : 'Nothing was paid in this month.'} /> : null}
      {rows.length ? (
        <>
          <SectionTitle title={`${rows.length} ${tab === 'recent' ? 'latest' : 'invoices'}`} style={{ marginTop: 4 }} />
          <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
            {rows.map((p, i) => (
              <Row
                key={p.id}
                icon="receipt-outline"
                iconTone={p.payment_status === 'success' ? 'success' : p.payment_status === 'failed' ? 'danger' : 'warning'}
                title={p.restaurant_name || p.restaurant_id}
                subtitle={`${shortDate(p.created_at, true)} · ${p.payment_method || ''}${p.duration_months ? ` · ${p.duration_months} mo` : ''}${p.invoice_no ? ` · ${p.invoice_no}` : ''}${p.notes ? `\n${p.notes}` : ''}`}
                value={money(p.amount)}
                right={tab === 'recent' && p.payment_status !== 'success' ? <Pill small tone="warning" label={p.payment_status} /> : null}
                onPress={Platform.OS === 'web' && p.payment_status === 'success' ? () => openInvoice(p) : undefined}
                last={i === rows.length - 1}
              />
            ))}
          </Card>
        </>
      ) : null}
    </Screen>
  );
}

function Sum({ label, value, strong }) {
  return (
    <View style={{ flex: 1 }}>
      <Text style={styles.sumLabel}>{label}</Text>
      <Text style={[styles.sumValue, strong && { color: colors.primary }]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  sumRow: { flexDirection: 'row' },
  sumLabel: { fontFamily: font.medium, fontSize: 11.5, color: colors.muted },
  sumValue: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  warn: { fontFamily: font.medium, fontSize: 12, color: '#B7791F', marginTop: 10 },
});
