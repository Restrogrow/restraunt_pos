import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { Linking, Platform, StyleSheet, Text, View } from 'react-native';
import MiniBars from '../components/MiniBars';
import Screen from '../components/Screen';
import { Button, Card, Chips, Field, Metric, Pill, Row, SectionTitle, ToggleRow } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import Sheet from '../components/ui/Sheet';
import { SITE_URL, pf, sa } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, spacing } from '../theme';
import { refreshBadges } from '../utils/badges';
import { dateTime, daysLeftLabel, money, moneyShort, num, planState, shortDate, timeAgo } from '../utils/format';

const FEATURES = [
  { key: 'show_install_app', action: 'updateInstallApp', title: 'Install-app button', subtitle: 'Show “Install app” on their website', icon: 'phone-portrait-outline', tone: 'info' },
  { key: 'whatsapp_orders', action: 'updateWhatsappOrders', title: 'WhatsApp orders', subtitle: 'Customers can order over WhatsApp', icon: 'logo-whatsapp', tone: 'success' },
  { key: 'photo_gallery_enabled', action: 'updatePhotoGalleryEnabled', title: 'Photo gallery', subtitle: 'Gallery page on their website', icon: 'images-outline', tone: 'purple' },
  { key: 'meal_subscriptions_enabled', action: 'updateMealSubscriptionsEnabled', title: 'Meal plans (tiffin)', subtitle: 'Meal subscriptions in admin and website', icon: 'fast-food-outline', tone: 'teal' },
];

// same rule as the website router (main/website/custom-domain.php)
function slugOf(name) {
  return String(name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

function digits(phone) {
  return String(phone || '').replace(/[^\d]/g, '');
}

export default function RestaurantDetailScreen({ route }) {
  const { id, name } = route.params || {};
  const fetcher = useCallback(() => pf.get('restaurant360', { id }), [id]);
  const { data, loading, error, refresh, refreshing, setData } = useApiData(fetcher, { cacheKey: `r360:${id}` });
  const [busy, setBusy] = useState('');
  const [sheet, setSheet] = useState(null); // 'password' | 'plan' | 'extend'

  const r = data?.restaurant;
  const plan = planState(r);
  const sales = data?.sales || {};

  // run one action, show the server's message, then reload the 360
  const act = async (key, fn, { confirm } = {}) => {
    if (confirm && !(await feedback.confirm(confirm))) return false;
    setBusy(key);
    try {
      const res = await fn();
      feedback.success(res?.message || 'Done');
      await refresh();
      refreshBadges();
      return true;
    } catch (e) {
      feedback.errorFrom(e);
      return false;
    } finally {
      setBusy('');
    }
  };

  const toggleFeature = async (f, on) => {
    setData((d) => (d ? { ...d, restaurant: { ...d.restaurant, [f.key]: on ? 1 : 0 } } : d));
    setBusy(f.key);
    try {
      await sa.post(f.action, { id: r.id, [f.key]: on ? 1 : 0 });
      feedback.success(`${f.title} ${on ? 'on' : 'off'}`);
    } catch (e) {
      setData((d) => (d ? { ...d, restaurant: { ...d.restaurant, [f.key]: on ? 0 : 1 } } : d));
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const toggleGateway = async (on) => {
    const type = on ? 'cash_and_gateway' : 'cash_only';
    setData((d) => (d ? { ...d, restaurant: { ...d.restaurant, payment_gateway_type: type } } : d));
    setBusy('gateway');
    try {
      await sa.post('updatePaymentGateway', { id: r.id, type });
      feedback.success(on ? 'Online payments allowed' : 'Cash only');
    } catch (e) {
      setData((d) => (d ? { ...d, restaurant: { ...d.restaurant, payment_gateway_type: on ? 'cash_only' : 'cash_and_gateway' } } : d));
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const toggleActive = () =>
    Number(r.is_active)
      ? act('active', () => sa.post('toggleRestaurant', { id: r.id, is_active: 0 }), {
          confirm: { title: 'Disable this restaurant?', message: `${r.restaurant_name} will be locked out of the dashboard and their website stops taking orders.`, confirmText: 'Disable', danger: true },
        })
      : act('active', () => sa.post('toggleRestaurant', { id: r.id, is_active: 1 }), {
          confirm: { title: 'Enable this restaurant?', message: 'They get back in with a 30-day trial from today.', confirmText: 'Enable' },
        });

  const openDashboard = async () => {
    setBusy('login');
    try {
      const res = await sa.post('generateAutoLogin', { id: r.id });
      if (Platform.OS === 'web') window.open(res.url, '_blank');
      else Linking.openURL(res.url);
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const remindWhatsApp = () => {
    const phone = digits(r.phone);
    const msg = `Hi ${r.owner_name || r.restaurant_name}, this is RestroGrow. Your plan for ${r.restaurant_name} ${Number(r.days_left) < 0 ? 'has expired' : `renews on ${shortDate(r.expires_on, true)}`}. Reply here and we'll help you renew.`;
    Linking.openURL(`https://wa.me/${phone.length === 10 ? '91' + phone : phone}?text=${encodeURIComponent(msg)}`);
    pf.post('logReminder', { id: r.id }).catch(() => {});
  };

  return (
    <Screen
      back
      eyebrow={r ? r.restaurant_id : 'Restaurant'}
      title={r?.restaurant_name || name || 'Restaurant'}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={
        r ? (
          <View style={styles.headerRow}>
            <Pill label={plan.label} tone={plan.tone} />
            <Text style={styles.headerMeta}>{daysLeftLabel(r.days_left)}</Text>
            <View style={{ flex: 1 }} />
            {r.phone ? <Quick icon="call" onPress={() => Linking.openURL(`tel:${r.phone}`)} /> : null}
            {r.phone ? <Quick icon="logo-whatsapp" onPress={() => Linking.openURL(`https://wa.me/${digits(r.phone).length === 10 ? '91' : ''}${digits(r.phone)}`)} /> : null}
            {r.email ? <Quick icon="mail" onPress={() => Linking.openURL(`mailto:${r.email}`)} /> : null}
          </View>
        ) : null
      }
    >
      {r ? (
        <>
          {/* plan */}
          <Card>
            <View style={styles.planTop}>
              <View style={{ flex: 1 }}>
                <Text style={styles.label}>Plan {r.subscription_status ? `· ${r.subscription_status}` : ''}</Text>
                <Text style={styles.planDate}>{r.expires_on ? `Until ${shortDate(r.expires_on, true)}` : 'No end date'}</Text>
                <Text style={styles.small}>
                  {r.trial_end_date ? `Trial ${new Date(String(r.trial_end_date).replace(' ', 'T')) < new Date() ? 'ended' : 'until'} ${shortDate(r.trial_end_date, true)} · ` : ''}Joined {shortDate(r.created_at, true)}
                </Text>
              </View>
              <View style={[styles.daysBox, { backgroundColor: plan.tone === 'danger' ? colors.dangerBg : plan.tone === 'warning' ? colors.warningBg : colors.successBg }]}>
                <Text style={[styles.daysNum, { color: plan.tone === 'danger' ? colors.danger : plan.tone === 'warning' ? '#B7791F' : colors.success }]}>
                  {r.days_left === null ? '—' : Math.max(0, Number(r.days_left))}
                </Text>
                <Text style={styles.daysLabel}>days left</Text>
              </View>
            </View>
            <View style={styles.btnRow}>
              <Button small title="Activate plan" icon="checkmark-circle-outline" onPress={() => setSheet('plan')} style={{ flex: 1 }} />
              <Button small title="Extend" icon="add-circle-outline" variant="soft" tone="info" onPress={() => setSheet('extend')} style={{ flex: 1 }} />
            </View>
            <View style={[styles.btnRow, { marginTop: 8 }]}>
              <Button
                small
                title="Mark expired"
                icon="close-circle-outline"
                variant="soft"
                tone="warning"
                loading={busy === 'expire'}
                onPress={() =>
                  act('expire', () => sa.post('updateSubscription', { id: r.id, action_type: 'expire' }), {
                    confirm: { title: 'Mark plan as expired?', message: 'They keep their account but the plan shows as expired.', confirmText: 'Mark expired', danger: true },
                  })
                }
                style={{ flex: 1 }}
              />
              {r.phone ? <Button small title="Remind" icon="logo-whatsapp" variant="soft" tone="success" onPress={remindWhatsApp} style={{ flex: 1 }} /> : null}
            </View>
          </Card>

          {/* sales */}
          <SectionTitle title="Business" />
          <View style={styles.grid}>
            <Metric icon="today" tone="teal" label="Today" value={moneyShort(sales.today_sales)} sub={`${num(sales.today_orders)} orders`} />
            <Metric icon="calendar" tone="purple" label="This month" value={moneyShort(sales.month_sales)} sub={`${num(sales.month_orders)} orders`} />
            <Metric icon="stats-chart" tone="primary" label="All time" value={moneyShort(sales.all_sales)} sub={`${num(sales.all_orders)} orders`} />
            <Metric icon="pulse" tone="info" label="Last order" value={sales.last_order_at ? timeAgo(sales.last_order_at) : 'Never'} sub={sales.last_order_at ? dateTime(sales.last_order_at) : ''} />
          </View>
          <Card style={{ marginTop: 10 }}>
            <Text style={[styles.label, { marginBottom: 10 }]}>Sales · last 14 days</Text>
            <MiniBars data={last14(data.daily)} height={100} valueLabel={(v) => moneyShort(v)} dayLabel={(d, i) => (i % 2 ? '' : d.day)} />
          </Card>

          {/* account */}
          <SectionTitle title="Account" />
          <Card padded={false} style={styles.listCard}>
            <Row icon="person-outline" title={r.owner_name || 'Owner not set'} subtitle={`@${r.username}`} />
            <Row icon="call-outline" title={r.phone || 'No phone'} subtitle={r.email || 'No email'} />
            {r.address ? <Row icon="location-outline" title="Address" subtitle={r.address} /> : null}
            <Row
              icon="globe-outline"
              iconTone="info"
              title="View their website"
              subtitle={r.custom_domain || `${SITE_URL.replace(/^https?:\/\//, '')}/${slugOf(r.restaurant_name)}`}
              onPress={() => Linking.openURL(r.custom_domain ? `https://${r.custom_domain}` : `${SITE_URL}/${slugOf(r.restaurant_name)}`)}
              chevron
            />
            <Row icon="people-outline" title="Staff · menu · customers" value={`${num(data.staff)} · ${num(data.menu_items)} · ${num(data.customers)}`} />
            <Row icon="notifications-outline" title="Push devices" value={num(data.push_devices)} />
            <Row icon="log-in-outline" title="Last sign-in" value={data.last_login ? timeAgo(data.last_login) : 'Never'} />
            <Row
              icon="logo-whatsapp"
              iconTone={data.whatsapp?.enabled ? 'success' : 'muted'}
              title="WhatsApp (RepeatGrow)"
              value={data.whatsapp?.enabled ? data.whatsapp.rg_phone || 'Connected' : 'Not connected'}
            />
            <Row icon="bug-outline" iconTone={data.errors_7d ? 'danger' : 'muted'} title="Errors · 7 days" value={num(data.errors_7d)} last />
          </Card>

          {/* features */}
          <SectionTitle title="Features" />
          <Card padded={false} style={styles.listCard}>
            {FEATURES.map((f) => (
              <ToggleRow
                key={f.key}
                icon={f.icon}
                iconTone={f.tone}
                title={f.title}
                subtitle={f.subtitle}
                value={Number(r[f.key]) === 1}
                busy={busy === f.key}
                onChange={(on) => toggleFeature(f, on)}
              />
            ))}
            <ToggleRow
              icon="card-outline"
              iconTone="primary"
              title="Online payments"
              subtitle="Off = cash only on their website"
              value={r.payment_gateway_type === 'cash_and_gateway'}
              busy={busy === 'gateway'}
              onChange={toggleGateway}
              last
            />
          </Card>

          {/* access */}
          <SectionTitle title="Access" />
          <Card padded={false} style={styles.listCard}>
            {Platform.OS === 'web' ? (
              <Row icon="open-outline" iconTone="info" title="Open their dashboard" subtitle="Signs in as this restaurant in a new tab" onPress={openDashboard} chevron />
            ) : null}
            <Row icon="key-outline" iconTone="warning" title="Reset password" subtitle="Set a new dashboard password for the owner" onPress={() => setSheet('password')} chevron />
            <Row
              icon="lock-open-outline"
              iconTone="purple"
              title="Clear OTP lock & devices"
              subtitle="Unlocks WhatsApp-code lockouts and forgets trusted devices"
              onPress={() =>
                act('otp', () => sa.post('unlockOtp', { id: r.id }), {
                  confirm: { title: 'Clear OTP lock?', message: 'Their next sign-in on every device will ask for a WhatsApp code again.', confirmText: 'Clear' },
                })
              }
              chevron
            />
            <Row
              icon={Number(r.is_active) ? 'ban-outline' : 'checkmark-done-outline'}
              danger={!!Number(r.is_active)}
              iconTone="success"
              title={Number(r.is_active) ? 'Disable restaurant' : 'Enable restaurant'}
              subtitle={Number(r.is_active) ? 'Locks them out and stops their website' : 'Back on with 30 days from today'}
              onPress={toggleActive}
              last
            />
          </Card>

          {data.payments?.length ? (
            <>
              <SectionTitle title="Subscription payments" />
              <Card padded={false} style={styles.listCard}>
                {data.payments.map((p, i, arr) => (
                  <Row
                    key={p.id}
                    icon="receipt-outline"
                    iconTone={p.payment_status === 'success' ? 'success' : 'warning'}
                    title={`${money(p.amount)} · ${p.subscription_type || 'plan'}${p.duration_months ? ` · ${p.duration_months} mo` : ''}`}
                    subtitle={`${shortDate(p.created_at, true)} · ${p.payment_method || ''} · ${p.invoice_no || p.transaction_id || ''}`}
                    last={i === arr.length - 1}
                  />
                ))}
              </Card>
            </>
          ) : null}

          {data.logins?.length ? (
            <>
              <SectionTitle title="Recent sign-ins" />
              <Card padded={false} style={styles.listCard}>
                {data.logins.map((l, i, arr) => (
                  <Row
                    key={i}
                    icon={l.outcome === 'success' ? 'checkmark-circle-outline' : 'alert-circle-outline'}
                    iconTone={l.outcome === 'success' ? 'success' : 'danger'}
                    title={`${l.actor_name || l.username_tried || '—'} · ${l.user_type || ''}`}
                    subtitle={`${l.outcome} · ${l.device_label || l.ip_address || ''} · ${timeAgo(l.created_at)}`}
                    last={i === arr.length - 1}
                  />
                ))}
              </Card>
            </>
          ) : null}

          {data.errors?.length ? (
            <>
              <SectionTitle title="Recent errors" />
              <Card padded={false} style={styles.listCard}>
                {data.errors.map((e, i, arr) => (
                  <Row
                    key={e.id}
                    icon="bug-outline"
                    iconTone={/critical|error/.test(e.severity) ? 'danger' : 'warning'}
                    title={e.message}
                    subtitle={`${e.severity} · ${e.source || ''} · ${timeAgo(e.created_at)}`}
                    last={i === arr.length - 1}
                  />
                ))}
              </Card>
            </>
          ) : null}
        </>
      ) : null}

      {r ? (
        <>
          <PasswordSheet visible={sheet === 'password'} onClose={() => setSheet(null)} r={r} />
          <PlanSheet visible={sheet === 'plan'} onClose={() => setSheet(null)} r={r} onDone={() => { refresh(); refreshBadges(); }} />
          <ExtendSheet visible={sheet === 'extend'} onClose={() => setSheet(null)} r={r} onDone={() => { refresh(); refreshBadges(); }} />
        </>
      ) : null}
    </Screen>
  );
}

function last14(daily = []) {
  const map = {};
  daily.forEach((d) => (map[d.d] = Number(d.s) || 0));
  const out = [];
  for (let i = 13; i >= 0; i--) {
    const dt = new Date();
    dt.setDate(dt.getDate() - i);
    const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
    out.push({ key, value: map[key] || 0, day: String(dt.getDate()) });
  }
  return out;
}

function Quick({ icon, onPress }) {
  return (
    <PressableScale onPress={onPress} style={styles.quick} hitSlop={4}>
      <Ionicons name={icon} size={17} color="#fff" />
    </PressableScale>
  );
}

function PasswordSheet({ visible, onClose, r }) {
  const [pw, setPw] = useState('');
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (pw.length < 6) return feedback.error('Use at least 6 characters');
    setBusy(true);
    try {
      await sa.post('resetPassword', { id: r.id, password: pw });
      feedback.success(`Password changed for @${r.username}`);
      setPw('');
      onClose();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };
  const generate = () => setPw(Math.random().toString(36).slice(2, 6) + '@' + Math.floor(1000 + Math.random() * 9000));
  return (
    <Sheet scroll visible={visible} onClose={onClose} title="Reset password" subtitle={`${r.restaurant_name} · @${r.username}`}>
      <Field label="New password" value={pw} onChangeText={setPw} placeholder="At least 6 characters" />
      <View style={styles.btnRow}>
        <Button title="Suggest one" icon="sparkles-outline" variant="soft" tone="muted" onPress={generate} style={{ flex: 1 }} />
        <Button title="Save" icon="checkmark" onPress={save} loading={busy} style={{ flex: 1 }} />
      </View>
      <Text style={styles.sheetNote}>Share it with the owner yourself; nothing is sent automatically.</Text>
    </Sheet>
  );
}

function PlanSheet({ visible, onClose, r, onDone }) {
  const [months, setMonths] = useState(1);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      const res = await sa.post('updateSubscription', months === 0 ? { id: r.id, action_type: 'activate', notes } : { id: r.id, action_type: 'custom', duration_months: months, notes });
      feedback.success(res.message || 'Plan activated');
      setNotes('');
      onClose();
      onDone();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Sheet scroll visible={visible} onClose={onClose} title="Activate plan" subtitle="Starts today. Recorded as a manual payment of ₹0.">
      <Text style={styles.label}>Length</Text>
      <Chips
        style={{ marginTop: 8, marginBottom: 16 }}
        value={months}
        onChange={setMonths}
        options={[
          { label: '30 days', value: 0 },
          { label: '3 months', value: 3 },
          { label: '6 months', value: 6 },
          { label: '12 months', value: 12 },
          { label: '24 months', value: 24 },
        ]}
      />
      <Field label="Note (optional)" value={notes} onChangeText={setNotes} placeholder="e.g. Paid by UPI, ref 1234" autoCapitalize="sentences" />
      <Button title="Activate" icon="checkmark-circle" onPress={save} loading={busy} />
    </Sheet>
  );
}

function ExtendSheet({ visible, onClose, r, onDone }) {
  const [days, setDays] = useState('7');
  const [busy, setBusy] = useState(false);
  const save = async () => {
    const d = parseInt(days, 10);
    if (!(d >= 1 && d <= 365)) return feedback.error('Days must be 1 to 365');
    setBusy(true);
    try {
      const res = await pf.post('extendRenewal', { id: r.id, days: d });
      feedback.success(res.message || 'Extended');
      onClose();
      onDone();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Sheet scroll visible={visible} onClose={onClose} title="Extend plan" subtitle="Adds days to the current end date (or today, if already over).">
      <Chips
        style={{ marginBottom: 14 }}
        value={days}
        onChange={setDays}
        options={['3', '7', '15', '30', '60'].map((d) => ({ label: `${d} days`, value: d }))}
      />
      <Field label="Or any number of days" value={days} onChangeText={(t) => setDays(t.replace(/[^\d]/g, ''))} keyboardType="number-pad" />
      <Button title={`Extend by ${days || 0} days`} icon="add-circle" onPress={save} loading={busy} />
    </Sheet>
  );
}

const styles = StyleSheet.create({
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: spacing.lg },
  headerMeta: { fontFamily: font.medium, fontSize: 12, color: 'rgba(255,255,255,0.75)' },
  quick: { width: 36, height: 36, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' },
  planTop: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 14 },
  label: { fontFamily: font.semiBold, fontSize: 12, color: colors.muted, textTransform: 'capitalize' },
  planDate: { fontFamily: font.bold, fontSize: 18, color: colors.ink, marginTop: 2 },
  small: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, marginTop: 2 },
  daysBox: { width: 72, height: 72, borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  daysNum: { fontFamily: font.bold, fontSize: 24 },
  daysLabel: { fontFamily: font.medium, fontSize: 10.5, color: colors.inkSoft, marginTop: -3 },
  btnRow: { flexDirection: 'row', gap: 8 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  listCard: { paddingHorizontal: spacing.lg },
  sheetNote: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: 12, textAlign: 'center' },
});
