import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Platform, Share, StyleSheet, Text, View } from 'react-native';
import RestaurantPicker, { useRestaurantOptions } from '../components/RestaurantPicker';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Chips, Field, HeaderIcon, Initials, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { pf, sa } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { dateTime, money } from '../utils/format';

const TONE = { Pending: 'warning', Success: 'success', Failed: 'danger' };

// Subscription payment links: create one for a restaurant (PhonePe), send it on WhatsApp, and
// check whether it was paid.
export default function PaymentLinksScreen() {
  const [status, setStatus] = useState('');
  const fetcher = useCallback(() => sa.get('getPaymentLinks', { status, limit: 50 }), [status]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `plinks:${status}`, refetchOnFocus: true });
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState(0);
  const rows = data?.links || [];

  const check = async (l) => {
    setBusy(l.id);
    try {
      const r = await sa.get('refreshPaymentLinkStatus', { id: l.id });
      feedback.success(r.message || `Status: ${r.status || r.payment_status || 'checked'}`);
      refresh();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(0);
    }
  };

  const mark = async (l) => {
    const v = await feedback.choose({
      title: 'Mark this link as',
      message: `${money(l.amount)} · ${l.restaurant_name || l.restaurant_id}`,
      options: [
        { label: 'Paid', value: 'Success', icon: 'checkmark-circle-outline' },
        { label: 'Failed', value: 'Failed', icon: 'close-circle-outline' },
      ],
    });
    if (!v) return;
    try {
      const r = await sa.post('updatePaymentLinkStatus', { id: l.id, status: v });
      feedback.success(r.message);
      refresh();
    } catch (e) {
      feedback.errorFrom(e);
    }
  };

  return (
    <Screen
      back
      eyebrow="Collect subscription payments"
      title="Payment links"
      right={<HeaderIcon icon="add" label="New payment link" onPress={() => setCreating(true)} />}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      <Chips
        value={status}
        onChange={setStatus}
        style={{ marginBottom: spacing.lg }}
        options={[
          { value: '', label: 'All' },
          { value: 'Pending', label: 'Pending' },
          { value: 'Success', label: 'Paid' },
          { value: 'Failed', label: 'Failed' },
        ]}
      />
      {data && !rows.length ? <EmptyState icon="link-outline" title="No payment links" subtitle="Create one to collect a restaurant's subscription on PhonePe." action="New payment link" onAction={() => setCreating(true)} /> : null}
      {rows.map((l, i) => (
        <FadeIn key={l.id} index={Math.min(i, 8)}>
          <Card style={{ padding: 14, marginBottom: 10 }}>
            <View style={styles.top}>
              <Initials name={l.restaurant_name || l.restaurant_id} size={38} tone={TONE[l.payment_status] === 'success' ? 'success' : 'primary'} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {l.restaurant_name || l.restaurant_id}
                </Text>
                <Text style={styles.meta} numberOfLines={1}>
                  {dateTime(l.created_at)}
                  {l.notes ? ` · ${l.notes}` : ''}
                </Text>
              </View>
              <View style={{ alignItems: 'flex-end', gap: 4 }}>
                <Text style={styles.amount}>{money(l.amount)}</Text>
                <Pill small tone={TONE[l.payment_status] || 'muted'} label={l.payment_status === 'Success' ? 'Paid' : l.payment_status} />
              </View>
            </View>
            {l.payment_status === 'Pending' ? (
              <View style={styles.actions}>
                {l.payment_url ? <Button small title="Send" icon="share-social-outline" variant="soft" tone="success" onPress={() => sendLink(l)} style={{ flex: 1 }} /> : null}
                <Button small title="Check" icon="refresh" variant="soft" tone="info" loading={busy === l.id} onPress={() => check(l)} style={{ flex: 1 }} />
                <Button small title="Mark" icon="create-outline" variant="soft" tone="muted" onPress={() => mark(l)} style={{ flex: 1 }} />
              </View>
            ) : null}
          </Card>
        </FadeIn>
      ))}
      <CreateSheet visible={creating} onClose={() => setCreating(false)} onCreated={refresh} />
    </Screen>
  );
}

async function sendLink(l, phone) {
  const text = `Hi${l.restaurant_name ? ` ${l.restaurant_name}` : ''}, here is your RestroGrow payment link for ${money(l.amount)}${l.notes ? ` (${l.notes})` : ''}:\n${l.payment_url}\n\nThank you!`;
  const digits = String(phone || '').replace(/[^\d]/g, '');
  if (digits) {
    Linking.openURL(`https://wa.me/${digits.length === 10 ? '91' + digits : digits}?text=${encodeURIComponent(text)}`);
    return;
  }
  try {
    if (Platform.OS === 'web') {
      await navigator.clipboard.writeText(text);
      feedback.success('Link copied');
    } else await Share.share({ message: text });
  } catch (e) {
    // share sheet closed
  }
}

function CreateSheet({ visible, onClose, onCreated }) {
  const [rid, setRid] = useState('');
  const [amount, setAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [price, setPrice] = useState(null);
  const options = useRestaurantOptions();

  useEffect(() => {
    if (!visible) return;
    setRid('');
    setNotes('');
    pf.get('getSettings')
      .then((r) => {
        setPrice({ m: Number(r.settings.plan_price), y: Number(r.settings.plan_price_yearly) });
        setAmount(String(Number(r.settings.plan_price) || ''));
      })
      .catch(() => {});
  }, [visible]);

  const create = async () => {
    if (!rid) return feedback.error('Choose the restaurant');
    if (!(Number(amount) > 0)) return feedback.error('Enter the amount');
    setBusy(true);
    try {
      const r = await sa.form('createPaymentLink', { restaurant_id: rid, amount, notes });
      const name = (options || []).find((o) => o.restaurant_id === rid)?.restaurant_name;
      feedback.success(r.demo_mode ? 'Link created (PhonePe demo mode)' : 'Payment link created');
      onClose();
      onCreated();
      // straight to sharing: the owner's phone if we have it, otherwise the share sheet
      let phone = '';
      try {
        const list = await sa.get('getRestaurants', { q: rid, limit: 5 });
        phone = (list.restaurants || []).find((x) => x.restaurant_id === rid)?.phone || '';
      } catch (e) {
        // share sheet instead
      }
      sendLink({ restaurant_name: name, amount, notes, payment_url: r.payment_url }, phone);
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet scroll visible={visible} onClose={onClose} title="New payment link" subtitle="A PhonePe link the owner can pay with UPI or card.">
      <RestaurantPicker value={rid} onChange={setRid} />
      <Field label="Amount (₹)" value={amount} onChangeText={(v) => setAmount(v.replace(/[^\d.]/g, ''))} keyboardType="decimal-pad" />
      {price ? (
        <Chips
          value={amount}
          onChange={(v) => {
            setAmount(v);
            setNotes(v === String(price.y) ? 'Yearly plan' : v === String(price.m * 3) ? '3 months' : 'Monthly plan');
          }}
          options={[
            { value: String(price.m), label: `Month ${money(price.m)}` },
            { value: String(price.m * 3), label: `3 months ${money(price.m * 3)}` },
            { value: String(price.y), label: `Year ${money(price.y)}` },
          ]}
          style={{ marginBottom: 12 }}
        />
      ) : null}
      <Field label="Note" value={notes} onChangeText={setNotes} autoCapitalize="sentences" placeholder="e.g. Yearly plan" />
      <Button title="Create & send" icon="send" onPress={create} loading={busy} />
      <View style={styles.hintRow}>
        <Ionicons name="information-circle-outline" size={14} color={colors.muted} />
        <Text style={styles.hint}>When it's paid, mark the plan active on the restaurant's page.</Text>
      </View>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  amount: { fontFamily: font.bold, fontSize: 16, color: colors.ink },
  actions: { flexDirection: 'row', gap: 8, marginTop: 12 },
  hintRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 12, justifyContent: 'center' },
  hint: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
});
