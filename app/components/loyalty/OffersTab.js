import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiGet, apiPostForm, apiPostJson } from '../../config/api';
import { useApiData } from '../../hooks/useApiData';
import { colors, font, radius, spacing } from '../../theme';
import { money, timeAgo } from '../../utils/format';
import CouponsModal from '../CouponsModal';
import { LoadingState } from '../ScreenState';
import FadeIn from '../ui/FadeIn';
import { feedback } from '../ui/Feedback';
import { Button, Card, IconBox, LOYALTY_API, Note, Nums, Pill, SectionTitle, Toggle } from './LoyaltyUi';

const LENGTHS = { 30: 'month', 90: '3 months', 180: '6 months', 365: 'year' };
const CH_KINDS = { visits: ['repeat', 'Visits'], spend: ['cash', 'Spend'], dish: ['restaurant', 'Try a dish'], streak: ['flame', 'Weekly streak'] };

function SectionError({ message, onRetry }) {
  return (
    <Card style={{ alignItems: 'center', gap: spacing.sm }}>
      <Text style={styles.muted}>{message}</Text>
      <Button tone="soft" icon="refresh" label="Try again" onPress={onRetry} style={{ height: 40 }} />
    </Card>
  );
}

export default function OffersTab({ currency, contentStyle }) {
  const couponsF = useCallback(() => apiGet('/api/get_admin_coupons.php'), []);
  const vipF = useCallback(() => apiGet(`${LOYALTY_API}?section=vip`), []);
  const gamesF = useCallback(() => apiGet(`${LOYALTY_API}?section=games`), []);
  const coupons = useApiData(couponsF, { cacheKey: 'loyalty:coupons' });
  const vip = useApiData(vipF, { cacheKey: 'loyalty:vip' });
  const games = useApiData(gamesF, { cacheKey: 'loyalty:games' });
  const [busy, setBusy] = useState(null);
  const [manage, setManage] = useState(false);
  const cur = vip.data?.currency || games.data?.currency || currency;
  const m = (v) => money(v, cur);

  const refreshAll = () => {
    coupons.refresh();
    vip.refresh();
    games.refresh();
  };

  // one place for every switch on this tab: optimistic, put back if the server says no
  const flip = async (key, apply, request, okMsg) => {
    setBusy(key);
    apply(true);
    try {
      const r = await request();
      if (!r.success) throw new Error(r.message || 'Could not save');
      feedback.success(okMsg);
    } catch (e) {
      apply(false);
      feedback.error(e.message || 'Could not save');
    } finally {
      setBusy(null);
    }
  };

  const toggleCoupon = (c, on) =>
    flip(
      `c${c.id}`,
      (fwd) => coupons.setData((d) => ({ ...d, coupons: d.coupons.map((x) => (x.id === c.id ? { ...x, is_active: fwd ? (on ? 1 : 0) : c.is_active } : x)) })),
      () => apiPostForm('/controllers/coupon_operations.php', { action: 'toggle', id: c.id, is_active: on ? 1 : 0 }),
      `${c.coupon_code} is ${on ? 'active' : 'paused'}`
    );

  const toggleClub = (on) => {
    const st = vip.data.settings;
    return flip(
      'club',
      (fwd) => vip.setData((d) => ({ ...d, settings: { ...d.settings, enabled: fwd ? (on ? 1 : 0) : st.enabled } })),
      () => apiPostJson(LOYALTY_API, { section: 'vip', action: 'settings', enabled: on ? 1 : 0, name: st.name || '', tagline: st.tagline || '' }),
      `Membership is ${on ? 'on' : 'off'}`
    );
  };
  const togglePlan = (p, on) =>
    flip(
      `p${p.id}`,
      (fwd) => vip.setData((d) => ({ ...d, plans: d.plans.map((x) => (x.id === p.id ? { ...x, active: fwd ? (on ? 1 : 0) : p.active } : x)) })),
      () => apiPostJson(LOYALTY_API, { section: 'vip', action: 'toggle', id: p.id, active: on ? 1 : 0 }),
      `${p.name} is ${on ? 'on sale' : 'hidden'}`
    );
  const toggleChallenge = (c, on) =>
    flip(
      `g${c.id}`,
      (fwd) => games.setData((d) => ({ ...d, challenges: d.challenges.map((x) => (x.id === c.id ? { ...x, active: fwd ? (on ? 1 : 0) : c.active } : x)) })),
      () => apiPostJson(LOYALTY_API, { section: 'games', action: 'toggle', id: c.id, active: on ? 1 : 0 }),
      `Challenge ${on ? 'started' : 'paused'}`
    );

  const offText = (c) => (c.discount_type === 'percent' ? `${Number(c.discount_value)}% off` : `${m(Number(c.discount_value))} off`);
  const perks = (p, prog) => {
    const out = [];
    if (Number(p.discount_pct) > 0) out.push(`${Number(p.discount_pct)}% off every order${Number(p.discount_max) > 0 ? ` (up to ${m(p.discount_max)})` : ''}`);
    if (Number(p.free_delivery)) out.push(`Free delivery${Number(p.free_delivery_min) > 0 ? ` above ${m(p.free_delivery_min)}` : ''}`);
    if (Number(p.earn_boost) > 1 && prog !== 'stamps') out.push(`${Number(p.earn_boost)}x ${prog === 'cashback' ? 'cashback' : 'points'}`);
    if (p.gift) out.push(`Welcome gift: ${p.gift}`);
    return out;
  };

  const refreshing = coupons.refreshing || vip.refreshing || games.refreshing;
  const list = coupons.data?.coupons || [];
  const V = vip.data;
  const G = games.data;

  return (
    <ScrollView
      contentContainerStyle={contentStyle}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refreshAll} tintColor={colors.primary} colors={[colors.primary]} />}
      showsVerticalScrollIndicator={false}
    >
      {/* ── coupons ── */}
      <FadeIn>
        <SectionTitle sub="Codes anyone can use at checkout." right={<Button tone="soft" icon="create-outline" label="Manage" onPress={() => setManage(true)} style={{ height: 36, paddingHorizontal: 12 }} />}>
          Coupons
        </SectionTitle>
        {coupons.loading && !coupons.data ? (
          <LoadingState rows={2} />
        ) : coupons.error && !coupons.data ? (
          <SectionError message={coupons.error} onRetry={coupons.refresh} />
        ) : !coupons.data?.success ? (
          <SectionError message={coupons.data?.message || 'Could not load coupons.'} onRetry={coupons.refresh} />
        ) : (
          <Card style={{ paddingVertical: 4 }}>
            {list.length ? (
              list.map((c, i) => {
                const on = Number(c.is_active) === 1;
                const expired = c.valid_until && new Date(`${c.valid_until}T23:59:59`) < new Date();
                const usedUp = Number(c.max_uses) > 0 && Number(c.current_uses) >= Number(c.max_uses);
                return (
                  <View key={c.id} style={[styles.row, i > 0 && styles.rowLine]}>
                    <View style={[styles.ticket, !on && { opacity: 0.5 }]}>
                      <Text style={styles.ticketText} numberOfLines={1} adjustsFontSizeToFit>{offText(c)}</Text>
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.code} numberOfLines={1}>{c.coupon_code}</Text>
                      <Text style={styles.sub} numberOfLines={2}>
                        {Number(c.minimum_order_amount) > 0 ? `Above ${m(c.minimum_order_amount)}` : 'Any order'} · used {c.current_uses || 0}
                        {Number(c.max_uses) > 0 ? `/${c.max_uses}` : ''}
                        {c.valid_until ? ` · till ${c.valid_until}` : ''}
                      </Text>
                      {expired || usedUp ? <Text style={styles.warn}>{expired ? 'Expired' : 'Used up'}</Text> : null}
                    </View>
                    <Toggle value={on} busy={busy === `c${c.id}`} onChange={(v) => toggleCoupon(c, v)} label={`${c.coupon_code} on or off`} />
                  </View>
                );
              })
            ) : (
              <Text style={styles.empty}>No coupons yet. Tap Manage to add one.</Text>
            )}
          </Card>
        )}
      </FadeIn>

      {/* ── VIP membership ── */}
      <FadeIn index={1}>
        <SectionTitle sub="Regulars pay a small fee and get perks on every order.">VIP membership</SectionTitle>
        {vip.loading && !V ? (
          <LoadingState rows={2} />
        ) : vip.error && !V ? (
          <SectionError message={vip.error} onRetry={vip.refresh} />
        ) : !V?.success ? (
          <SectionError message={V?.message || 'Could not load membership.'} onRetry={vip.refresh} />
        ) : (
          <View style={{ gap: spacing.sm }}>
            <Card>
              <View style={styles.head}>
                <IconBox name="diamond" tint={colors.purple} bg={colors.purpleBg} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.title}>{V.settings?.name || 'VIP Club'}</Text>
                  <Text style={styles.sub}>Membership is {Number(V.settings?.enabled) ? 'on' : 'off'}</Text>
                </View>
                <Toggle value={!!Number(V.settings?.enabled)} busy={busy === 'club'} onChange={toggleClub} label="Membership on or off" />
              </View>
              <Nums items={[[String(V.kpis?.members || 0), 'Members'], [m(V.kpis?.revenue_month || 0), 'Sold this month'], [String(V.kpis?.renewing_soon || 0), 'Renewing in 7 days']]} />
            </Card>
            {(V.plans || []).map((p) => {
              const on = !!Number(p.active);
              const pk = perks(p, V.settings?.program_type);
              return (
                <Card key={p.id} style={[{ padding: spacing.md, gap: 6 }, !on && { opacity: 0.75 }]}>
                  <View style={styles.head}>
                    <View style={{ flex: 1 }}>
                      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                        <Text style={styles.title}>{p.name}</Text>
                        {Number(p.highlight) ? <Pill label="Most popular" tone="info" /> : null}
                      </View>
                      <Text style={styles.price}>
                        {m(p.price)} <Text style={styles.sub}>/ {LENGTHS[p.duration_days] || `${p.duration_days} days`}</Text>
                      </Text>
                    </View>
                    <View style={{ alignItems: 'center' }}>
                      <Toggle value={on} busy={busy === `p${p.id}`} onChange={(v) => togglePlan(p, v)} label={`${p.name} on sale or hidden`} />
                      <Text style={styles.tiny}>{on ? 'On sale' : 'Hidden'}</Text>
                    </View>
                  </View>
                  {pk.map((x) => (
                    <View key={x} style={styles.perk}>
                      <Ionicons name="checkmark-circle" size={15} color={colors.purple} />
                      <Text style={styles.perkText}>{x}</Text>
                    </View>
                  ))}
                  <Text style={styles.sub}>{p.members} active · {p.sold} sold · {m(p.revenue)} revenue</Text>
                </Card>
              );
            })}
            {!(V.plans || []).length ? <Note icon="diamond-outline" tone="muted">No plans yet. Create plans on the website: Growth › Loyalty & Rewards › VIP membership.</Note> : null}
            {(V.recent || []).length ? (
              <Card style={{ paddingVertical: 4 }}>
                {V.recent.slice(0, 5).map((r, i) => (
                  <View key={`${r.phone}_${i}`} style={[styles.row, i > 0 && styles.rowLine]}>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.code} numberOfLines={1}>{r.name} <Text style={styles.sub}>{r.phone}</Text></Text>
                      <Text style={styles.sub} numberOfLines={1}>{r.plan} · {timeAgo(r.at)}</Text>
                    </View>
                    <Text style={styles.amount}>{m(r.amount)}</Text>
                  </View>
                ))}
              </Card>
            ) : null}
          </View>
        )}
      </FadeIn>

      {/* ── games & challenges ── */}
      <FadeIn index={2}>
        <SectionTitle sub="The after-checkout game and goals customers reach by ordering.">Games and challenges</SectionTitle>
        {games.loading && !G ? (
          <LoadingState rows={2} />
        ) : games.error && !G ? (
          <SectionError message={games.error} onRetry={games.refresh} />
        ) : !G?.success ? (
          <SectionError message={G?.message || 'Could not load games.'} onRetry={games.refresh} />
        ) : (
          <View style={{ gap: spacing.sm }}>
            <Card>
              <View style={styles.head}>
                <IconBox name={G.game?.style === 'wheel' ? 'aperture' : 'dice'} tint={colors.warning} bg={colors.warningBg} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.title}>{G.game?.style === 'wheel' ? 'Spin the wheel' : 'Scratch card'}</Text>
                  <Text style={styles.sub}>
                    {Number(G.game?.enabled) ? `${(G.game.prizes || []).length} prizes · shown after checkout` : 'Off. Turn it on and set prizes on the website (Offers › Scratch card).'}
                  </Text>
                </View>
                <Pill label={Number(G.game?.enabled) ? 'On' : 'Off'} tone={Number(G.game?.enabled) ? 'ok' : 'off'} />
              </View>
              {Number(G.game?.enabled) ? (
                <Nums items={[[String(G.game.played || 0), 'Played this month'], [String(G.game.won || 0), 'Won'], [`${m(G.game.spent || 0)}${G.game.budget > 0 ? ` of ${m(G.game.budget)}` : ''}`, 'Given this month']]} />
              ) : null}
            </Card>
            {(G.challenges || []).map((c) => {
              const k = CH_KINDS[c.kind] || ['flag', c.kind];
              const on = !!Number(c.active);
              const st = c.stats || {};
              return (
                <Card key={c.id} style={{ padding: spacing.md, gap: 8 }}>
                  <View style={styles.head}>
                    <IconBox name={k[0]} tint={on ? '#fff' : colors.primary} bg={on ? colors.primary : colors.primaryLight} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.title} numberOfLines={2}>{c.display_title}</Text>
                      <Text style={styles.sub}>
                        {k[1]} · <Text style={{ color: colors.primary, fontFamily: font.semiBold }}>{c.reward}</Text>
                        {Number(c.min_order) > 0 ? ` above ${m(c.min_order)}` : ''}
                      </Text>
                    </View>
                    <Toggle value={on} busy={busy === `g${c.id}`} onChange={(v) => toggleChallenge(c, v)} label={`${c.display_title} on or off`} />
                  </View>
                  <View style={styles.nums}>
                    <Text style={styles.num}><Text style={styles.numB}>{st.taking_part || 0}</Text> active</Text>
                    <Text style={styles.num}><Text style={styles.numB}>{st.completed || 0}</Text> completed</Text>
                    <Text style={styles.num}><Text style={styles.numB}>{st.used || 0}</Text> rewards used</Text>
                  </View>
                </Card>
              );
            })}
            {!(G.challenges || []).length ? <Note icon="flag-outline" tone="muted">No challenges yet. Start one on the website: Growth › Loyalty & Rewards › Games.</Note> : null}
          </View>
        )}
      </FadeIn>

      <Text style={styles.fine}>New VIP plans, prizes and challenges are set up on the website. Here you can see how they do and switch them on or off.</Text>

      <CouponsModal
        visible={manage}
        onClose={() => {
          setManage(false);
          coupons.refresh();
        }}
      />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: 11,
  },
  rowLine: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  ticket: {
    width: 74,
    height: 44,
    borderRadius: radius.sm,
    backgroundColor: colors.primaryLight,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
  },
  ticketText: {
    fontFamily: font.bold,
    fontSize: 13,
    color: colors.primary,
  },
  code: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
    letterSpacing: 0.3,
  },
  sub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  warn: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.danger,
  },
  muted: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
  },
  empty: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
    paddingVertical: spacing.lg,
  },
  head: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  title: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  price: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
    marginTop: 1,
  },
  tiny: {
    fontFamily: font.medium,
    fontSize: 10.5,
    color: colors.muted,
  },
  perk: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  perkText: {
    flex: 1,
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  amount: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  nums: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    columnGap: spacing.md,
    backgroundColor: colors.bg,
    borderRadius: radius.sm,
    paddingHorizontal: 10,
    paddingVertical: 7,
  },
  num: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  numB: {
    fontFamily: font.bold,
    color: colors.ink,
  },
  fine: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    lineHeight: 17,
  },
});
