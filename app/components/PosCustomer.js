import { Ionicons } from '@expo/vector-icons';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { apiGet, apiPostJson } from '../config/api';
import { colors, font, radius, spacing } from '../theme';
import { money } from '../utils/format';
import PressableScale from './ui/PressableScale';
import Sheet from './ui/Sheet';

// The customer on a POS bill, same as the website POS (main/assets/js/pos-customer.js):
// pick an existing customer, add a new one, or carry on without. With a customer the bill
// can use their points, their own reward codes and their wallet, and the sale earns them
// points / stamps / cashback. Data: api/pos_customer.php (GET ?q= / ?id=, POST create).
// The money rules live in posCustomerPricing() below so the cart, the Pay button and the
// printed bill can never disagree.

const initials = (n) => String(n || '?').split(/\s+/).map((w) => w.charAt(0)).join('').slice(0, 2).toUpperCase();

// A reward code only this customer can use, as money off this subtotal.
export function giftDiscount(gift, sub) {
  if (!gift || sub <= 0 || sub < (gift.min_order || 0)) return 0;
  const d = gift.type === 'percent' ? Math.round(sub * gift.value) / 100 : gift.value;
  return Math.min(Math.round(d * 100) / 100, sub);
}

function pointsRule(cust) {
  const L = cust && cust.loyalty;
  return L && L.enabled && L.type === 'points' && L.redeem_value_per_point > 0 ? L : null;
}

// Most points this bill can take (never more than the bill after the reward code).
export function maxPoints(cust, gift, sub) {
  const L = pointsRule(cust);
  if (!L) return 0;
  return Math.max(0, Math.min(L.points, Math.floor((sub - giftDiscount(gift, sub)) / L.redeem_value_per_point + 1e-9)));
}

// What the customer's reward code and points take off the subtotal (before tax).
export function posCustomerPricing({ cust, gift, ptsOn, pts }, sub) {
  const out = { coupon: 0, couponCode: '', points: 0, pointsDiscount: 0 };
  if (!cust) return out;
  out.coupon = giftDiscount(gift, sub);
  if (out.coupon > 0) out.couponCode = gift.code;
  const L = pointsRule(cust);
  if (L && ptsOn) {
    const p = Math.min(pts, maxPoints(cust, gift, sub));
    if (p >= L.min_redeem && p > 0) {
      out.points = p;
      out.pointsDiscount = Math.round(p * L.redeem_value_per_point * 100) / 100;
    }
  }
  return out;
}

export function walletFor({ cust, walletOn }, total) {
  return cust && walletOn && cust.wallet?.enabled ? Math.round(Math.min(cust.wallet.balance, total) * 100) / 100 : 0;
}

export async function loadPosCustomer(id) {
  const d = await apiGet(`/api/pos_customer.php?id=${encodeURIComponent(id)}`);
  if (!d.success) throw new Error(d.message || 'Could not load the customer');
  return d.customer;
}

// "You earned 12 points · Balance 240 points" — the line on the bill and in the toast.
export function loyaltyLine(loyalty, currency) {
  if (!loyalty || !loyalty.customer) return '';
  const e = loyalty.earned || {};
  const c = loyalty.customer;
  const bits = [];
  if (e.points) bits.push(`You earned ${e.points} points`);
  if (e.stamp) bits.push(`Stamp added: ${c.loyalty.stamps} of ${c.loyalty.stamp_goal}`);
  if (e.cashback) bits.push(`Cashback ${currency}${Number(e.cashback).toFixed(2)}`);
  if (e.reward) bits.push(`Reward: ${e.reward.label} (code ${e.reward.code})`);
  if (c.loyalty.enabled && c.loyalty.type === 'points') bits.push(`Balance ${c.loyalty.points} points`);
  return bits.join(' · ');
}

// ── the box in the cart ──
export function CustomerBox({ state, setState, subtotal, currency, onOpenPicker }) {
  const { cust, gift, ptsOn, pts, walletOn } = state;
  if (!cust) {
    return (
      <PressableScale style={styles.addBox} onPress={onOpenPicker} accessibilityLabel="Add customer">
        <View style={styles.addIcon}>
          <Ionicons name="person-add" size={18} color={colors.primary} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.addTitle}>Add customer</Text>
          <Text style={styles.addSub}>Points, rewards and the bill on their name</Text>
        </View>
        <Ionicons name="chevron-forward" size={18} color={colors.muted} />
      </PressableScale>
    );
  }
  const L = cust.loyalty;
  const mx = maxPoints(cust, gift, subtotal);
  const canUsePoints = L.enabled && L.type === 'points' && L.points >= L.min_redeem && mx >= L.min_redeem;
  const set = (patch) => setState((s) => ({ ...s, ...patch }));
  const step = Math.max(1, Math.round(10 / (L.redeem_value_per_point || 1)));
  const usePts = Math.min(pts || mx, mx);

  return (
    <View style={styles.card}>
      <View style={styles.top}>
        <View style={styles.av}><Text style={styles.avText}>{initials(cust.name)}</Text></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.name} numberOfLines={1}>{cust.name}</Text>
          <Text style={styles.meta} numberOfLines={1}>
            {cust.phone} · {cust.visits} visit{cust.visits === 1 ? '' : 's'}{L.tier ? ` · ${L.tier}` : ''}
          </Text>
        </View>
        <Pressable style={styles.ib} onPress={onOpenPicker} hitSlop={6} accessibilityLabel="Change customer">
          <Ionicons name="swap-horizontal" size={17} color={colors.inkSoft} />
        </Pressable>
        <Pressable style={styles.ib} onPress={() => setState({ cust: null, gift: null, ptsOn: false, pts: 0, walletOn: false })} hitSlop={6} accessibilityLabel="Remove customer">
          <Ionicons name="close" size={18} color={colors.inkSoft} />
        </Pressable>
      </View>

      {L.enabled && L.type === 'points' ? (
        <>
          <View style={styles.row}>
            <View style={styles.pill}>
              <Ionicons name="star" size={13} color="#B45309" />
              <Text style={styles.pillText}>{Number(L.points).toLocaleString('en-IN')} points</Text>
            </View>
            <Text style={styles.soft}>worth {money(L.points_value, currency)}</Text>
          </View>
          {canUsePoints ? (
            <>
              <View style={styles.swRow}>
                <Text style={styles.swLabel}>Use points</Text>
                {ptsOn ? <Text style={styles.minus}>−{money(Math.round(usePts * L.redeem_value_per_point * 100) / 100, currency)}</Text> : null}
                <Switch
                  value={ptsOn}
                  onValueChange={(v) => set({ ptsOn: v, pts: mx })}
                  trackColor={{ true: colors.success, false: colors.border }}
                  thumbColor="#fff"
                />
              </View>
              {ptsOn ? (
                <View style={styles.stepRow}>
                  <Pressable style={styles.stepBtn} onPress={() => set({ pts: Math.max(L.min_redeem, usePts - step) })} accessibilityLabel="Fewer points">
                    <Ionicons name="remove" size={16} color={colors.ink} />
                  </Pressable>
                  <TextInput
                    style={styles.stepInput}
                    keyboardType="number-pad"
                    value={String(usePts)}
                    onChangeText={(v) => set({ pts: Math.max(0, Math.min(mx, parseInt(v, 10) || 0)) })}
                  />
                  <Pressable style={styles.stepBtn} onPress={() => set({ pts: Math.min(mx, usePts + step) })} accessibilityLabel="More points">
                    <Ionicons name="add" size={16} color={colors.ink} />
                  </Pressable>
                  <Pressable style={styles.maxBtn} onPress={() => set({ pts: mx })}>
                    <Text style={styles.maxText}>Max {mx}</Text>
                  </Pressable>
                </View>
              ) : null}
            </>
          ) : (
            <Text style={styles.hint}>
              {L.points < L.min_redeem ? `Points can be used from ${L.min_redeem}.` : 'Add more to the bill to use points.'} This bill earns about{' '}
              {Math.floor(subtotal / (L.earn_per || 100)) * L.earn_points} points.
            </Text>
          )}
        </>
      ) : L.enabled && L.type === 'stamps' ? (
        <View style={styles.row}>
          <View style={styles.stamps}>
            {Array.from({ length: Math.min(L.stamp_goal, 12) }).map((_, i) => (
              <View key={i} style={[styles.stamp, i < L.stamps && styles.stampOn]}>
                {i < L.stamps ? <Ionicons name="checkmark" size={10} color="#fff" /> : null}
              </View>
            ))}
          </View>
          <Text style={styles.soft} numberOfLines={2}>
            {L.stamps} of {L.stamp_goal}{L.stamp_reward ? ` · then ${L.stamp_reward}` : ''}
          </Text>
        </View>
      ) : L.enabled && L.type === 'cashback' ? (
        <Text style={styles.hint}>Earns {L.cashback_pct}% cashback into their wallet when paid.</Text>
      ) : !L.enabled ? (
        <Text style={styles.hint}>Loyalty is off. Turn it on in More › Loyalty to give points.</Text>
      ) : null}

      {cust.wallet?.enabled && cust.wallet.balance > 0 ? (
        <View style={styles.swRow}>
          <Text style={styles.swLabel}>Pay from wallet</Text>
          <Text style={styles.soft}>{money(cust.wallet.balance, currency)} balance</Text>
          <Switch value={walletOn} onValueChange={(v) => set({ walletOn: v })} trackColor={{ true: colors.success, false: colors.border }} thumbColor="#fff" />
        </View>
      ) : null}

      {cust.gifts?.length ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.gifts}>
          {cust.gifts.map((g) => {
            const on = gift && gift.code === g.code;
            const short = subtotal > 0 && subtotal < g.min_order;
            const label = g.label || (g.type === 'percent' ? `${g.value}% off` : `${money(g.value, currency)} off`);
            return (
              <PressableScale
                key={g.code}
                style={[styles.gift, on && styles.giftOn, short && styles.giftShort]}
                feedback="select"
                onPress={() => set({ gift: on ? null : g })}
                accessibilityLabel={`Reward ${label}${g.min_order ? `, minimum ${money(g.min_order, currency)}` : ''}`}
              >
                <Ionicons name={on ? 'checkmark-circle' : 'gift'} size={14} color={on ? '#fff' : colors.success} />
                <Text style={[styles.giftText, on && { color: '#fff' }]} numberOfLines={1}>{label}</Text>
                {short ? <Text style={styles.giftMin}>min {money(g.min_order, currency)}</Text> : null}
              </PressableScale>
            );
          })}
        </ScrollView>
      ) : null}
    </View>
  );
}

// ── pick / add a customer ──
export function CustomerPicker({ visible, onClose, onPicked, onSkip, currency, inline }) {
  const [q, setQ] = useState('');
  const [list, setList] = useState([]);
  const [searching, setSearching] = useState(false);
  const [newOpen, setNewOpen] = useState(false);
  const [form, setForm] = useState({ name: '', phone: '', email: '', birthday: '' });
  const [saving, setSaving] = useState(false);
  const [loadingId, setLoadingId] = useState(null);
  const [err, setErr] = useState('');
  const timer = useRef(null);

  useEffect(() => {
    if (!visible) return;
    setQ(''); setList([]); setNewOpen(false); setErr('');
    setForm({ name: '', phone: '', email: '', birthday: '' });
  }, [visible]);

  const search = (v) => {
    setQ(v);
    setErr('');
    clearTimeout(timer.current);
    if (v.trim().length < 2) { setList([]); setSearching(false); return; }
    setSearching(true);
    timer.current = setTimeout(() => {
      apiGet(`/api/pos_customer.php?q=${encodeURIComponent(v.trim())}`)
        .then((d) => setList((d && d.customers) || []))
        .catch(() => setList([]))
        .finally(() => setSearching(false));
    }, 220);
  };

  const showNew = () => {
    const v = q.trim();
    const digits = v.replace(/\D/g, '');
    setForm((f) => ({ ...f, phone: digits.length >= 6 ? digits : f.phone, name: v && !digits ? v : f.name }));
    setNewOpen(true);
  };

  const pick = async (c) => {
    setLoadingId(c.id);
    try {
      onPicked(await loadPosCustomer(c.id));
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoadingId(null);
    }
  };

  const save = async () => {
    if (!form.name.trim() || form.phone.replace(/\D/g, '').length < 6) { setErr('Enter a name and a phone number.'); return; }
    if (form.birthday && !/^\d{4}-\d{2}-\d{2}$/.test(form.birthday)) { setErr('Birthday as YYYY-MM-DD, or leave it empty.'); return; }
    setSaving(true);
    setErr('');
    try {
      const d = await apiPostJson('/api/pos_customer.php', { action: 'create', ...form });
      if (!d.success) throw new Error(d.message || 'Could not save');
      onPicked(d.customer, d.existed ? `This phone already belongs to ${d.customer.name}, added to the bill` : 'Customer added');
    } catch (e) {
      setErr(e.message);
    } finally {
      setSaving(false);
    }
  };

  const field = (key, label, props = {}) => (
    <View style={{ flex: 1 }}>
      <Text style={styles.fLabel}>{label}</Text>
      <TextInput
        style={styles.fInput}
        value={form[key]}
        onChangeText={(v) => setForm((f) => ({ ...f, [key]: v }))}
        placeholderTextColor={colors.muted}
        {...props}
      />
    </View>
  );

  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      inline={inline}
      title="Customer for this bill"
      subtitle="Search, add a new one, or carry on without"
      footer={
        <View style={styles.footer}>
          {!newOpen ? (
            <PressableScale style={[styles.fBtn, styles.fBtnGhost]} onPress={showNew}>
              <Ionicons name="person-add-outline" size={16} color={colors.ink} />
              <Text style={styles.fBtnGhostText}>New customer</Text>
            </PressableScale>
          ) : null}
          <PressableScale style={[styles.fBtn, styles.fBtnGhost]} onPress={onSkip}>
            <Text style={styles.fBtnGhostText}>Continue without customer</Text>
          </PressableScale>
        </View>
      }
    >
      <View style={styles.search}>
        <Ionicons name="search" size={17} color={colors.muted} />
        <TextInput
          style={styles.searchInput}
          value={q}
          onChangeText={search}
          placeholder="Search name or phone"
          placeholderTextColor={colors.muted}
          autoFocus={!newOpen}
          returnKeyType="search"
        />
        {searching ? <ActivityIndicator size="small" color={colors.primary} /> : null}
      </View>

      {!newOpen ? (
        q.trim().length < 2 ? (
          <Text style={styles.hint}>Type at least 2 letters or digits.</Text>
        ) : list.length ? (
          <View style={{ gap: 6, marginTop: spacing.sm }}>
            {list.map((c) => (
              <PressableScale key={c.id} style={styles.res} onPress={() => pick(c)} disabled={!!loadingId}>
                <View style={[styles.av, styles.avSm]}><Text style={styles.avTextSm}>{initials(c.name)}</Text></View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>{c.name}</Text>
                  <Text style={styles.meta} numberOfLines={1}>
                    {c.phone || 'no phone'} · {c.visits} visits · {money(c.spent, currency)}
                  </Text>
                </View>
                {c.points ? (
                  <View style={styles.pill}>
                    <Ionicons name="star" size={11} color="#B45309" />
                    <Text style={styles.pillText}>{c.points}</Text>
                  </View>
                ) : null}
                {c.has_account ? <Text style={styles.tag}>Online</Text> : null}
                {loadingId === c.id ? <ActivityIndicator size="small" color={colors.primary} /> : null}
              </PressableScale>
            ))}
          </View>
        ) : !searching ? (
          <View style={{ marginTop: spacing.sm }}>
            <Text style={styles.hint}>No customer matches “{q.trim()}”.</Text>
            <PressableScale style={[styles.fBtn, { marginTop: spacing.sm }]} onPress={showNew}>
              <Ionicons name="person-add" size={16} color="#fff" />
              <Text style={styles.fBtnText} numberOfLines={1}>Add “{q.trim()}” as a new customer</Text>
            </PressableScale>
          </View>
        ) : null
      ) : (
        <View style={styles.newBox}>
          <View style={styles.newHead}>
            <Ionicons name="person-add" size={16} color={colors.primary} />
            <Text style={styles.newTitle}>New customer</Text>
          </View>
          {field('name', 'Name', { maxLength: 100, autoCapitalize: 'words', autoFocus: !form.name })}
          {field('phone', 'Phone', { keyboardType: 'phone-pad', maxLength: 15, autoFocus: !!form.name && !form.phone })}
          <View style={{ flexDirection: 'row', gap: spacing.sm }}>
            {field('email', 'Email (optional)', { keyboardType: 'email-address', autoCapitalize: 'none' })}
            {field('birthday', 'Birthday (optional)', { placeholder: 'YYYY-MM-DD', maxLength: 10 })}
          </View>
          <PressableScale style={[styles.fBtn, { marginTop: spacing.sm }]} onPress={save} disabled={saving}>
            {saving ? <ActivityIndicator color="#fff" size="small" /> : <Ionicons name="checkmark" size={17} color="#fff" />}
            <Text style={styles.fBtnText}>Save and add to bill</Text>
          </PressableScale>
        </View>
      )}
      {err ? <Text style={styles.err}>{err}</Text> : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  addBox: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, padding: spacing.md, borderRadius: radius.md, borderWidth: 1.5, borderStyle: 'dashed', borderColor: colors.primary + '66', backgroundColor: '#FFF8F5', marginTop: spacing.md },
  addIcon: { width: 38, height: 38, borderRadius: 12, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center' },
  addTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  addSub: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 1 },
  card: { marginTop: spacing.md, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, gap: spacing.sm },
  top: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  av: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  avText: { fontFamily: font.bold, fontSize: 14, color: '#fff' },
  avSm: { width: 34, height: 34, borderRadius: 17 },
  avTextSm: { fontFamily: font.bold, fontSize: 12, color: '#fff' },
  name: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft },
  ib: { width: 34, height: 34, borderRadius: 17, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, flexWrap: 'wrap' },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 9, paddingVertical: 4, borderRadius: radius.pill, backgroundColor: '#FEF3C7' },
  pillText: { fontFamily: font.semiBold, fontSize: 12, color: '#92400E' },
  soft: { flexShrink: 1, fontFamily: font.regular, fontSize: 12, color: colors.inkSoft },
  swRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: 40 },
  swLabel: { flex: 1, fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink },
  minus: { fontFamily: font.semiBold, fontSize: 13, color: colors.success },
  stepRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  stepBtn: { width: 38, height: 38, borderRadius: 10, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  stepInput: { width: 70, height: 38, borderRadius: 10, borderWidth: 1, borderColor: colors.border, textAlign: 'center', fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  maxBtn: { marginLeft: 'auto', paddingHorizontal: 12, height: 38, borderRadius: 10, backgroundColor: colors.successBg, justifyContent: 'center' },
  maxText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.success },
  hint: { fontFamily: font.regular, fontSize: 12, lineHeight: 17, color: colors.inkSoft, marginTop: 4 },
  stamps: { flexDirection: 'row', gap: 4, flexWrap: 'wrap' },
  stamp: { width: 18, height: 18, borderRadius: 9, borderWidth: 1.5, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  stampOn: { backgroundColor: colors.success, borderColor: colors.success },
  gifts: { gap: 6, paddingVertical: 2 },
  gift: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 11, height: 34, borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.success + '55', backgroundColor: colors.successBg },
  giftOn: { backgroundColor: colors.success, borderColor: colors.success },
  giftShort: { opacity: 0.55 },
  giftText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.success, maxWidth: 160 },
  giftMin: { fontFamily: font.regular, fontSize: 10.5, color: colors.inkSoft },
  search: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, height: 48, paddingHorizontal: spacing.md, borderRadius: radius.md, backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border },
  searchInput: { flex: 1, height: '100%', fontFamily: font.medium, fontSize: 15, color: colors.ink },
  res: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, padding: spacing.sm, borderRadius: radius.md, backgroundColor: colors.bg },
  tag: { fontFamily: font.semiBold, fontSize: 10.5, color: colors.info, backgroundColor: colors.infoBg, paddingHorizontal: 7, paddingVertical: 2, borderRadius: radius.pill, overflow: 'hidden' },
  newBox: { marginTop: spacing.md, gap: spacing.sm },
  newHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  newTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  fLabel: { fontFamily: font.medium, fontSize: 12, color: colors.inkSoft, marginBottom: 4 },
  fInput: { height: 46, paddingHorizontal: spacing.md, borderRadius: radius.sm, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, fontFamily: font.regular, fontSize: 15, color: colors.ink },
  footer: { gap: spacing.sm },
  fBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, height: 48, paddingHorizontal: spacing.md, borderRadius: radius.md, backgroundColor: colors.primary },
  fBtnText: { flexShrink: 1, fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
  fBtnGhost: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border },
  fBtnGhostText: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  err: { marginTop: spacing.sm, fontFamily: font.medium, fontSize: 12.5, color: colors.danger },
});
