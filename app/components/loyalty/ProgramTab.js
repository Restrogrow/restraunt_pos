import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiGet, apiPostJson } from '../../config/api';
import { useApiData } from '../../hooks/useApiData';
import { colors, font, radius, spacing } from '../../theme';
import { money } from '../../utils/format';
import { ErrorState, LoadingState } from '../ScreenState';
import SelectField from '../SelectField';
import FadeIn from '../ui/FadeIn';
import { feedback } from '../ui/Feedback';
import PressableScale from '../ui/PressableScale';
import { Button, Card, Field, FieldLabel, LOYALTY_API, Note, Nums, SectionTitle, Toggle } from './LoyaltyUi';

// Same three program types and wording as the website's Loyalty Studio (loyalty-studio.js)
const TYPES = [
  { key: 'cashback', label: 'Cashback', icon: 'wallet', line: 'A % of every order back in their wallet', why: 'Simple and loved in India. Customers spend it on their next order.', badge: 'Recommended' },
  { key: 'points', label: 'Points', icon: 'star', line: 'Points per ₹ spent, swapped for rewards', why: 'Flexible: discounts, free dishes and levels.' },
  { key: 'stamps', label: 'Stamp card', icon: 'ribbon', line: 'One stamp per order, a reward when the card is full', why: 'Best for frequent visits: cafés, quick bites.' },
];
const GOALS = [4, 5, 6, 7, 8, 9, 10, 12];
const VALID = [7, 14, 30, 60, 90];

export default function ProgramTab({ currency, contentStyle }) {
  const fetcher = useCallback(() => apiGet(`${LOYALTY_API}?section=program`), []);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { cacheKey: 'loyalty:program' });
  const [p, setP] = useState(null);
  const [saving, setSaving] = useState(false);
  const cur = data?.currency || currency;
  const m = (v) => money(v, cur);

  // the draft follows the server until the owner changes something
  useEffect(() => {
    if (data?.program) setP({ ...data.program });
  }, [data]);

  const dirty = useMemo(() => {
    if (!p || !data?.program) return false;
    return Object.keys(p).some((k) => String(p[k] ?? '') !== String(data.program[k] ?? ''));
  }, [p, data]);

  if (loading && !data) return <LoadingState />;
  if (error && !data) return <ErrorState message={error} onRetry={refresh} />;
  if (data && !data.success) return <ErrorState message={data.message || 'Could not load the loyalty program.'} onRetry={refresh} />;
  if (!p) return <LoadingState />;

  const set = (k) => (v) => setP((x) => ({ ...x, [k]: v }));
  const mem = data.members || {};
  const items = data.items || [];
  const itemOpts = items.map((it) => ({ value: it.id, label: `${it.name} · ${m(it.price)}` }));
  const cb = Number(p.cashback_pct) || 0;
  const cbMax = Number(p.cashback_max) || 0;

  const save = async () => {
    setSaving(true);
    try {
      const body = {
        section: 'program',
        ...p,
        cashback_pct: Number(p.cashback_pct) || 0,
        cashback_max: Number(p.cashback_max) || 0,
        stamp_min_order: Number(p.stamp_min_order) || 0,
        stamp_reward_value: Number(p.stamp_reward_value) || 0,
      };
      const r = await apiPostJson(LOYALTY_API, body);
      if (!r.success) throw new Error(r.message || 'Could not save');
      feedback.success('Loyalty program saved');
      refresh();
    } catch (e) {
      feedback.error(e.message || 'Could not save');
    } finally {
      setSaving(false);
    }
  };

  const stampsShown = Math.min(Number(p.stamp_goal) || 6, 12);

  return (
    <ScrollView
      contentContainerStyle={contentStyle}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
      showsVerticalScrollIndicator={false}
      keyboardShouldPersistTaps="handled"
    >
      <FadeIn>
        <LinearGradient colors={['#2A2735', '#1D1B26']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
          <View style={styles.heroTop}>
            <View style={{ flex: 1 }}>
              <Text style={styles.heroTitle}>Loyalty program is {p.loyalty_enabled ? 'on' : 'off'}</Text>
              <Text style={styles.heroSub}>Every completed order with a phone number earns, on the website, POS and table orders alike.</Text>
            </View>
            <Toggle value={!!Number(p.loyalty_enabled)} onChange={(v) => set('loyalty_enabled')(v ? 1 : 0)} label="Loyalty program on or off" />
          </View>
          <Nums dark items={[[String(mem.total || 0), 'Members'], [String(mem.with_birthday || 0), 'Birthdays known'], [String(mem.opted_in || 0), 'WhatsApp opt-ins']]} />
        </LinearGradient>
      </FadeIn>

      <FadeIn index={1}>
        <SectionTitle sub="Pick one way for customers to earn.">How customers earn</SectionTitle>
        <View style={{ gap: spacing.sm }}>
          {TYPES.map((t) => {
            const on = p.program_type === t.key;
            return (
              <PressableScale key={t.key} feedback="select" scale={0.99} style={[styles.type, on && styles.typeOn]} onPress={() => set('program_type')(t.key)} accessibilityLabel={t.label}>
                <View style={[styles.typeIcon, on && { backgroundColor: colors.primary }]}>
                  <Ionicons name={t.icon} size={19} color={on ? '#fff' : colors.primary} />
                </View>
                <View style={{ flex: 1 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                    <Text style={styles.typeTitle}>{t.label}</Text>
                    {t.badge ? <Text style={styles.badge}>{t.badge}</Text> : null}
                  </View>
                  <Text style={styles.typeLine}>{t.line}</Text>
                  <Text style={styles.typeWhy}>{t.why}</Text>
                </View>
                <Ionicons name={on ? 'radio-button-on' : 'radio-button-off'} size={20} color={on ? colors.primary : colors.muted} />
              </PressableScale>
            );
          })}
        </View>
      </FadeIn>

      <FadeIn index={2}>
        <Card style={{ gap: spacing.md }}>
          {p.program_type === 'cashback' ? (
            <>
              <View style={styles.grid}>
                <Field label="Cashback on every order" suffix="%" value={p.cashback_pct} onChangeText={(v) => set('cashback_pct')(v.replace(/[^\d.]/g, ''))} />
                <Field label={`Most cashback per order (${cur})`} placeholder="No limit" value={p.cashback_max || ''} onChangeText={(v) => set('cashback_max')(v.replace(/[^\d.]/g, ''))} />
              </View>
              <Note icon="bulb">
                A {m(600)} order gives {m(Math.min((600 * cb) / 100, cbMax > 0 ? cbMax : 1e9))} back, ready for the next meal. Cashback goes into the customer’s wallet and comes off if the order is cancelled.
                {data.phonepe_ready ? '' : ' Customers can spend cashback without PhonePe; adding money to the wallet needs your PhonePe keys.'}
              </Note>
            </>
          ) : p.program_type === 'stamps' ? (
            <>
              <View style={styles.stampsWrap}>
                <View style={styles.stamps}>
                  {Array.from({ length: stampsShown }).map((_, i) => {
                    const filled = i < Math.min(3, stampsShown - 1);
                    const gift = i === stampsShown - 1;
                    return (
                      <View key={i} style={[styles.stamp, filled && styles.stampOn, gift && styles.stampGift]}>
                        {filled ? <Ionicons name="checkmark" size={14} color="#fff" /> : gift ? <Ionicons name="gift" size={14} color={colors.primary} /> : <Text style={styles.stampNum}>{i + 1}</Text>}
                      </View>
                    );
                  })}
                </View>
                <Text style={styles.stampCap}>What customers see on their rewards page</Text>
              </View>
              <View style={styles.grid}>
                <View style={{ flex: 1, minWidth: 140 }}>
                  <FieldLabel>Stamps to fill the card</FieldLabel>
                  <SelectField title="Stamps to fill the card" options={GOALS.map((n) => ({ value: n, label: `${n} stamps` }))} value={p.stamp_goal} onChange={(v) => set('stamp_goal')(Number(v))} />
                </View>
                <Field label={`Orders above (${cur})`} placeholder="Any order" value={Number(p.stamp_min_order) ? p.stamp_min_order : ''} onChangeText={(v) => set('stamp_min_order')(v.replace(/[^\d.]/g, ''))} />
              </View>
              <View style={styles.grid}>
                <View style={{ flex: 1, minWidth: 140 }}>
                  <FieldLabel>Reward for a full card</FieldLabel>
                  <SelectField
                    title="Reward for a full card"
                    options={[{ value: 'item', label: 'A free dish' }, { value: 'flat', label: `${cur} off` }, { value: 'percent', label: '% off' }]}
                    value={p.stamp_reward_kind}
                    onChange={set('stamp_reward_kind')}
                  />
                </View>
                {p.stamp_reward_kind === 'item' ? (
                  <View style={{ flex: 1, minWidth: 140 }}>
                    <FieldLabel>Free dish</FieldLabel>
                    <SelectField title="Free dish" placeholder="Pick a dish" options={itemOpts} value={p.stamp_reward_item_id} onChange={(v) => set('stamp_reward_item_id')(Number(v))} />
                  </View>
                ) : (
                  <Field label="Amount" suffix={p.stamp_reward_kind === 'percent' ? '%' : cur} value={Number(p.stamp_reward_value) ? p.stamp_reward_value : ''} onChangeText={(v) => set('stamp_reward_value')(v.replace(/[^\d.]/g, ''))} />
                )}
              </View>
            </>
          ) : (
            <Note icon="options" tone="muted">
              Customers earn {p.earn_points_per_amount} point{Number(p.earn_points_per_amount) === 1 ? '' : 's'} per {m(p.earn_amount_threshold)}, each worth {m(p.redeem_value_per_point)}. Points rates, levels, free-dish rewards and referrals are edited on the website (Growth › Loyalty & Rewards).
            </Note>
          )}
          <View style={{ maxWidth: 260 }}>
            <FieldLabel>Gift codes stay valid for</FieldLabel>
            <SelectField title="Gift codes stay valid for" options={VALID.map((n) => ({ value: n, label: `${n} days` }))} value={p.reward_valid_days} onChange={(v) => set('reward_valid_days')(Number(v))} />
          </View>
        </Card>
      </FadeIn>

      <FadeIn index={3}>
        <Button icon="save" label={dirty ? 'Save program' : 'Saved'} onPress={save} busy={saving} disabled={!dirty} />
        {dirty ? <Text style={styles.unsaved}>You have changes that aren’t saved yet.</Text> : null}
      </FadeIn>
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
    gap: spacing.md,
  },
  heroTitle: {
    fontFamily: font.bold,
    fontSize: 17,
    color: '#fff',
  },
  heroSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: 'rgba(255,255,255,0.7)',
    marginTop: 2,
    lineHeight: 18,
  },
  type: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    borderWidth: 1.5,
    borderColor: colors.border,
  },
  typeOn: {
    borderColor: colors.primary,
    backgroundColor: '#FFF8F6',
  },
  typeIcon: {
    width: 40,
    height: 40,
    borderRadius: 13,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  typeTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  badge: {
    fontFamily: font.semiBold,
    fontSize: 10,
    color: colors.success,
    backgroundColor: colors.successBg,
    borderRadius: radius.pill,
    paddingHorizontal: 7,
    paddingVertical: 1,
    overflow: 'hidden',
  },
  typeLine: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
    marginTop: 1,
  },
  typeWhy: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
  },
  stampsWrap: {
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  stamps: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: 6,
  },
  stamp: {
    width: 30,
    height: 30,
    borderRadius: 15,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderStyle: 'dashed',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
  },
  stampOn: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
    borderStyle: 'solid',
  },
  stampGift: {
    borderColor: colors.primary,
    backgroundColor: colors.primaryLight,
  },
  stampNum: {
    fontFamily: font.semiBold,
    fontSize: 11,
    color: colors.muted,
  },
  stampCap: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 8,
  },
  unsaved: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.warning,
    textAlign: 'center',
    marginTop: spacing.sm,
  },
});
