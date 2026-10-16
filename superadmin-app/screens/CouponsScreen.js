import { useCallback, useMemo, useState } from 'react';
import { StyleSheet, Switch, Text, View } from 'react-native';
import RestaurantPicker from '../components/RestaurantPicker';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import SearchField from '../components/SearchField';
import { Button, Card, Chips, Field, HeaderIcon, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { sa } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, spacing } from '../theme';
import { haptic } from '../utils/haptics';
import { money, shortDate } from '../utils/format';

const isoToday = () => new Date().toISOString().slice(0, 10);

function couponState(c) {
  if (!Number(c.is_active)) return { label: 'Off', tone: 'muted' };
  if (c.valid_until && c.valid_until < isoToday()) return { label: 'Ended', tone: 'danger' };
  if (c.valid_from && c.valid_from > isoToday()) return { label: 'Starts later', tone: 'info' };
  if (Number(c.max_uses) > 0 && Number(c.current_uses) >= Number(c.max_uses)) return { label: 'Used up', tone: 'warning' };
  return { label: 'Live', tone: 'success' };
}

export default function CouponsScreen() {
  const [rid, setRid] = useState('');
  const [q, setQ] = useState('');
  const fetcher = useCallback(() => sa.get('getCoupons', { restaurant_id: rid }), [rid]);
  const { data, loading, error, refresh, refreshing, setData } = useApiData(fetcher, { cacheKey: `coupons:${rid}` });
  const [edit, setEdit] = useState(null);

  const rows = useMemo(() => {
    const t = q.trim().toLowerCase();
    return (data?.coupons || []).filter((c) => !t || `${c.coupon_code} ${c.restaurant_name} ${c.description}`.toLowerCase().includes(t));
  }, [data, q]);

  const toggle = async (c, on) => {
    haptic.select();
    setData((d) => ({ ...d, coupons: d.coupons.map((x) => (x.id === c.id ? { ...x, is_active: on ? 1 : 0 } : x)) }));
    try {
      await sa.post('toggleCoupon', { id: c.id, is_active: on ? 1 : 0 });
    } catch (e) {
      feedback.errorFrom(e);
      refresh();
    }
  };

  return (
    <Screen
      back
      eyebrow="Discount codes for restaurants"
      title="Coupons"
      right={<HeaderIcon icon="add" label="New coupon" onPress={() => setEdit({ restaurant_id: rid })} />}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={<SearchField value={q} onChangeText={setQ} placeholder="Search code or restaurant" style={{ marginTop: spacing.lg }} />}
    >
      <RestaurantPicker label="" value={rid} onChange={setRid} allowAll />
      {data && !rows.length ? (
        <EmptyState icon="pricetags-outline" title="No coupons" subtitle={q ? 'Nothing matches.' : 'Create a code a restaurant’s customers can use at checkout.'} action="New coupon" onAction={() => setEdit({ restaurant_id: rid })} />
      ) : null}
      {rows.map((c, i) => {
        const st = couponState(c);
        return (
          <FadeIn key={c.id} index={Math.min(i, 8)}>
            <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => setEdit(c)}>
              <View style={styles.top}>
                <View style={styles.code}>
                  <Text style={styles.codeText}>{c.coupon_code}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.value}>{c.discount_type === 'percent' ? `${Number(c.discount_value)}% off` : `${money(c.discount_value)} off`}</Text>
                  <Text style={styles.meta} numberOfLines={1}>
                    {c.restaurant_name || c.restaurant_id}
                  </Text>
                </View>
                <Switch value={!!Number(c.is_active)} onValueChange={(on) => toggle(c, on)} trackColor={{ false: '#DDD8D4', true: '#FFB4A3' }} thumbColor={Number(c.is_active) ? colors.primary : '#fff'} />
              </View>
              {c.description ? <Text style={styles.desc}>{c.description}</Text> : null}
              <View style={styles.bottom}>
                <Pill small tone={st.tone} label={st.label} />
                {Number(c.minimum_order_amount) > 0 ? <Pill small tone="muted" label={`Min ${money(c.minimum_order_amount)}`} /> : null}
                <Text style={styles.small}>
                  Used {c.current_uses || 0}
                  {Number(c.max_uses) > 0 ? ` / ${c.max_uses}` : ''}
                  {c.valid_until ? ` · until ${shortDate(c.valid_until)}` : ''}
                </Text>
              </View>
            </Card>
          </FadeIn>
        );
      })}
      <CouponSheet item={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </Screen>
  );
}

function CouponSheet({ item, onClose, onSaved }) {
  const [f, setF] = useState({});
  const [last, setLast] = useState(null);
  const [busy, setBusy] = useState(false);
  if (item !== last) {
    setLast(item);
    setF(
      item
        ? {
            id: item.id,
            restaurant_id: item.restaurant_id || '',
            coupon_code: item.coupon_code || '',
            discount_type: item.discount_type || 'percent',
            discount_value: item.discount_value ? String(Number(item.discount_value)) : '',
            minimum_order_amount: Number(item.minimum_order_amount) ? String(Number(item.minimum_order_amount)) : '',
            max_uses: Number(item.max_uses) ? String(item.max_uses) : '',
            valid_from: item.valid_from || '',
            valid_until: item.valid_until || '',
            description: item.description || '',
          }
        : {}
    );
  }
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
  const date = (v) => !v || /^\d{4}-\d{2}-\d{2}$/.test(v);

  const save = async () => {
    if (!f.restaurant_id) return feedback.error('Choose the restaurant');
    if (!f.coupon_code.trim()) return feedback.error('Enter a code');
    if (!(Number(f.discount_value) > 0)) return feedback.error('Enter the discount');
    if (f.discount_type === 'percent' && Number(f.discount_value) > 100) return feedback.error('A percent discount can be at most 100');
    if (!date(f.valid_from) || !date(f.valid_until)) return feedback.error('Dates look like 2026-10-31');
    setBusy(true);
    const body = {
      ...f,
      coupon_code: f.coupon_code.trim().toUpperCase(),
      discount_value: Number(f.discount_value),
      minimum_order_amount: Number(f.minimum_order_amount) || 0,
      max_uses: parseInt(f.max_uses, 10) || 0,
      valid_from: f.valid_from || null,
      valid_until: f.valid_until || null,
    };
    try {
      await sa.post(f.id ? 'updateCoupon' : 'createCoupon', body);
      feedback.success(f.id ? 'Coupon updated' : 'Coupon created');
      onClose();
      onSaved();
    } catch (e) {
      feedback.error(/Duplicate/i.test(e.message) ? 'That code already exists for this restaurant' : e.message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!(await feedback.confirm({ title: `Delete ${f.coupon_code}?`, message: 'Customers can no longer use it.', confirmText: 'Delete', danger: true }))) return;
    try {
      await sa.post('deleteCoupon', { id: f.id });
      feedback.success('Coupon deleted');
      onClose();
      onSaved();
    } catch (e) {
      feedback.errorFrom(e);
    }
  };

  const plus = (days) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  };

  return (
    <Sheet scroll visible={!!item} onClose={onClose} title={f.id ? `Edit ${f.coupon_code}` : 'New coupon'}>
      {!f.id ? <RestaurantPicker value={f.restaurant_id} onChange={set('restaurant_id')} /> : null}
      <Field label="Code" value={f.coupon_code} onChangeText={(v) => set('coupon_code')(v.toUpperCase().replace(/\s/g, ''))} placeholder="e.g. WELCOME20" autoCapitalize="characters" />
      <Text style={styles.label}>Discount</Text>
      <Chips
        value={f.discount_type}
        onChange={set('discount_type')}
        options={[
          { value: 'percent', label: 'Percent %' },
          { value: 'flat', label: 'Flat ₹' },
        ]}
        style={{ marginTop: 8, marginBottom: 10 }}
      />
      <View style={styles.two}>
        <Field style={{ flex: 1 }} label={f.discount_type === 'percent' ? 'Percent off' : 'Amount off (₹)'} value={f.discount_value} onChangeText={set('discount_value')} keyboardType="decimal-pad" />
        <Field style={{ flex: 1 }} label="Min. order (₹)" value={f.minimum_order_amount} onChangeText={set('minimum_order_amount')} keyboardType="decimal-pad" placeholder="None" />
      </View>
      <Field label="Max. uses" value={f.max_uses} onChangeText={(v) => set('max_uses')(v.replace(/[^\d]/g, ''))} keyboardType="number-pad" placeholder="Unlimited" />
      <View style={styles.two}>
        <Field style={{ flex: 1 }} label="Starts" value={f.valid_from} onChangeText={set('valid_from')} placeholder="YYYY-MM-DD" />
        <Field style={{ flex: 1 }} label="Ends" value={f.valid_until} onChangeText={set('valid_until')} placeholder="YYYY-MM-DD" />
      </View>
      <Chips
        value={f.valid_until}
        onChange={set('valid_until')}
        options={[
          { value: '', label: 'No end' },
          { value: plus(7), label: '1 week' },
          { value: plus(30), label: '1 month' },
          { value: plus(90), label: '3 months' },
        ]}
        style={{ marginBottom: 12 }}
      />
      <Field label="Note for customers" value={f.description} onChangeText={set('description')} autoCapitalize="sentences" placeholder="e.g. 20% off your first order" />
      <Button title={f.id ? 'Save changes' : 'Create coupon'} icon="checkmark" onPress={save} loading={busy} />
      {f.id ? <Button title="Delete coupon" icon="trash-outline" variant="ghost" tone="danger" onPress={remove} style={{ marginTop: 6 }} /> : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  code: { paddingHorizontal: 10, paddingVertical: 8, borderRadius: radius.sm, borderWidth: 1.5, borderStyle: 'dashed', borderColor: colors.primary, backgroundColor: colors.primaryLight },
  codeText: { fontFamily: font.bold, fontSize: 13, color: colors.primary, letterSpacing: 0.8 },
  value: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  desc: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 8 },
  bottom: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6, marginTop: 10 },
  small: { fontFamily: font.medium, fontSize: 11.5, color: colors.muted },
  label: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
  two: { flexDirection: 'row', gap: 10 },
});
