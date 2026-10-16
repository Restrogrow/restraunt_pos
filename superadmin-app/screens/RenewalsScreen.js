import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { useCallback, useState } from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Chips, Initials, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { refreshBadges } from '../utils/badges';
import { daysLeftLabel, money, shortDate } from '../utils/format';

export default function RenewalsScreen() {
  const navigation = useNavigation();
  const [days, setDays] = useState(15);
  const fetcher = useCallback(() => pf.get('renewals', { days }), [days]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `renewals:${days}`, refetchOnFocus: true });
  const [busy, setBusy] = useState('');
  const rows = data?.rows || [];
  const overdue = rows.filter((r) => Number(r.days_left) < 0);
  const upcoming = rows.filter((r) => Number(r.days_left) >= 0);

  const extend = async (r, d) => {
    if (!(await feedback.confirm({ title: `Extend by ${d} days?`, message: `${r.restaurant_name} · currently ${daysLeftLabel(r.days_left).toLowerCase()}.`, confirmText: 'Extend' }))) return;
    setBusy(`${r.id}`);
    try {
      const res = await pf.post('extendRenewal', { id: r.id, days: d });
      feedback.success(res.message);
      refresh();
      refreshBadges();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const remind = (r) => {
    const phone = String(r.phone || '').replace(/[^\d]/g, '');
    if (!phone) return feedback.error('No phone number on file');
    const price = data?.plan_price ? ` The plan is ${money(data.plan_price)}/month (or ${money(data.plan_price_yearly)}/year).` : '';
    const msg =
      Number(r.days_left) < 0
        ? `Hi ${r.owner_name || r.restaurant_name}, your RestroGrow plan for ${r.restaurant_name} ended on ${shortDate(r.expires_on, true)}.${price} Reply here to renew and keep taking orders.`
        : `Hi ${r.owner_name || r.restaurant_name}, a reminder that your RestroGrow plan for ${r.restaurant_name} renews on ${shortDate(r.expires_on, true)}.${price} Reply here and we'll send the payment link.`;
    Linking.openURL(`https://wa.me/${phone.length === 10 ? '91' + phone : phone}?text=${encodeURIComponent(msg)}`);
    pf.post('logReminder', { id: r.id }).catch(() => {});
  };

  const renderRow = (r, i) => {
    const late = Number(r.days_left) < 0;
    const soon = !late && Number(r.days_left) <= 3;
    return (
      <FadeIn key={r.id} index={Math.min(i, 8)}>
        <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => navigation.navigate('RestaurantDetail', { id: r.id, name: r.restaurant_name })}>
          <View style={styles.top}>
            <Initials name={r.restaurant_name} tone={late ? 'danger' : soon ? 'primary' : 'info'} />
            <View style={{ flex: 1 }}>
              <Text style={styles.name} numberOfLines={1}>
                {r.restaurant_name}
              </Text>
              <Text style={styles.meta} numberOfLines={1}>
                {r.owner_name || r.username} · {r.subscription_status}
              </Text>
            </View>
            <Pill small tone={late ? 'danger' : soon ? 'warning' : 'info'} label={daysLeftLabel(r.days_left)} />
          </View>
          <View style={styles.info}>
            <Info icon="calendar-outline" text={`Ends ${shortDate(r.expires_on, true)}`} />
            <Info icon="wallet-outline" text={r.last_paid ? `Paid ${shortDate(r.last_paid)}` : 'Never paid'} />
            {Number(r.open_links) ? <Info icon="link-outline" text={`${r.open_links} open link`} /> : null}
          </View>
          <View style={styles.actions}>
            <Button small title="WhatsApp" icon="logo-whatsapp" variant="soft" tone="success" onPress={() => remind(r)} style={{ flex: 1 }} />
            <Button small title="+7 days" variant="soft" tone="info" loading={busy === `${r.id}`} onPress={() => extend(r, 7)} style={{ flex: 1 }} />
            <Button small title="+30 days" variant="soft" tone="purple" onPress={() => extend(r, 30)} style={{ flex: 1 }} />
          </View>
        </Card>
      </FadeIn>
    );
  };

  return (
    <Screen
      tabs
      eyebrow="Plans ending soon"
      title="Renewals"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={
        <View style={styles.summary}>
          <Summary value={overdue.length} label="Overdue" color="#FF8A80" />
          <Summary value={upcoming.filter((r) => Number(r.days_left) <= 7).length} label="This week" color="#FFD27A" />
          <Summary value={upcoming.length} label={`Next ${days} days`} color="#9EC1FF" />
        </View>
      }
    >
      <Chips
        value={days}
        onChange={setDays}
        options={[7, 15, 30, 60].map((d) => ({ label: `${d} days`, value: d }))}
        style={{ marginBottom: spacing.lg }}
      />
      {data && !rows.length ? (
        <EmptyState icon="happy-outline" title="Nothing due" subtitle={`No plan ends in the next ${days} days, and nothing expired in the last 30.`} />
      ) : null}
      {overdue.length ? <Text style={styles.group}>Expired · last 30 days</Text> : null}
      {overdue.map(renderRow)}
      {upcoming.length ? <Text style={styles.group}>Coming up</Text> : null}
      {upcoming.map(renderRow)}
    </Screen>
  );
}

function Info({ icon, text }) {
  return (
    <View style={styles.infoItem}>
      <Ionicons name={icon} size={13} color={colors.muted} />
      <Text style={styles.infoText}>{text}</Text>
    </View>
  );
}

function Summary({ value, label, color }) {
  return (
    <View style={styles.sumBox}>
      <Text style={[styles.sumValue, { color }]}>{value}</Text>
      <Text style={styles.sumLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  summary: { flexDirection: 'row', gap: 10, marginTop: spacing.lg },
  sumBox: { flex: 1, backgroundColor: 'rgba(255,255,255,0.08)', borderRadius: 14, padding: 12 },
  sumValue: { fontFamily: font.bold, fontSize: 22 },
  sumLabel: { fontFamily: font.medium, fontSize: 11, color: 'rgba(255,255,255,0.7)' },
  group: { fontFamily: font.bold, fontSize: 13, color: colors.inkSoft, marginBottom: 10, marginTop: 4, textTransform: 'uppercase', letterSpacing: 0.6 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 15, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted, textTransform: 'capitalize' },
  info: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 12 },
  infoItem: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  infoText: { fontFamily: font.medium, fontSize: 11.5, color: colors.inkSoft },
  actions: { flexDirection: 'row', gap: 8, marginTop: 12 },
});
