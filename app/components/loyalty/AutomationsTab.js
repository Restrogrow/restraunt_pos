import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { apiGet, apiPostJson } from '../../config/api';
import { useApiData } from '../../hooks/useApiData';
import { useLayout } from '../../hooks/useLayout';
import { colors, font, radius, spacing } from '../../theme';
import { money, timeAgo } from '../../utils/format';
import { ErrorState, LoadingState } from '../ScreenState';
import FadeIn from '../ui/FadeIn';
import { feedback } from '../ui/Feedback';
import { Button, Card, IconBox, LOYALTY_API, Note, Nums, Pill, SectionTitle, Toggle, plural } from './LoyaltyUi';

// Same automations, order and wording as the website's Autopilot tab (loyalty-studio-autopilot.js)
const META = {
  welcome: ['hand-right', 'Welcome gift', 'After a customer’s first completed order', 'Turns a first visit into a second one.'],
  birthday: ['gift', 'Birthday treat', 'Days before the birthday', 'The highest-redeeming gift there is.'],
  anniversary: ['heart', 'Anniversary', 'Days before the anniversary', 'Couples celebrate out: a natural big order.'],
  winback: ['time', 'We miss you', 'When a regular goes quiet', 'Brings back customers before they forget you.'],
  tier_up: ['medal', 'Level up', 'When a customer reaches a new level', 'Celebrates progress so they keep climbing.'],
  milestone: ['trophy', 'Order milestone', 'Every Nth completed order', 'A thank-you for your most loyal regulars.'],
  referral: ['people', 'Referral gift', 'When an invited friend completes a first order', 'A gift for both friends.'],
};
const ORDER = ['welcome', 'birthday', 'winback', 'milestone', 'tier_up', 'referral', 'anniversary'];
const SOURCE_LABEL = { stamps: 'Stamp card', scratch: 'Scratch card', challenge: 'Challenge', streak: 'Weekly streak', vip: 'Member gift' };

function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

export default function AutomationsTab({ currency, contentStyle }) {
  const { columns } = useLayout();
  const fetcher = useCallback(() => apiGet(`${LOYALTY_API}?section=autopilot`), []);
  const { data, loading, refreshing, error, refresh, setData } = useApiData(fetcher, { cacheKey: 'loyalty:autopilot' });
  const [busy, setBusy] = useState(null);
  const [running, setRunning] = useState(false);
  const cur = data?.currency || currency;
  const m = (v) => money(v, cur);

  if (loading && !data) return <LoadingState />;
  if (error && !data) return <ErrorState message={error} onRetry={refresh} />;
  if (!data?.success) return <ErrorState message={data?.message || 'Could not load the automations.'} onRetry={refresh} />;

  const D = data;
  const items = D.items || [];
  const up = D.upcoming || {};
  const stats = D.stats || {};
  const autos = ORDER.map((t) => (D.automations || []).find((a) => a.type === t)).filter(Boolean);

  const rewardText = (a) => {
    if (a.reward_kind === 'percent') return `${Number(a.reward_value) || 0}% off`;
    if (a.reward_kind === 'item') {
      const it = items.find((i) => i.id === Number(a.reward_item_id));
      return it ? `Free ${it.name}` : 'A free dish';
    }
    return `${m(Number(a.reward_value) || 0)} off`;
  };
  const trigger = (a) => {
    if (a.type === 'birthday' || a.type === 'anniversary') return a.param ? `${a.param} days before the ${a.type}` : 'On the day';
    if (a.type === 'winback') return `No order for ${a.param} days`;
    if (a.type === 'milestone') return `Every ${ordinal(Number(a.param) || 10)} order`;
    return META[a.type][2];
  };
  const note = (a) => {
    if (a.type === 'referral' && D.program_type === 'points') return 'Your program is Points, so referrals earn points. This gift applies to Cashback and Stamp card programs.';
    if (a.type === 'referral' && !D.referral_enabled) return 'Referrals are off. Turn them on on the website (Program tab).';
    if (a.type === 'birthday') return `${plural(up.birthdays_known || 0, 'customer has', 'customers have')} shared a birthday · ${up.birthday || 0} coming up in the next 30 days`;
    if (a.type === 'anniversary') return `${plural(up.anniversary || 0, 'anniversary', 'anniversaries')} in the next 30 days`;
    if (a.type === 'winback') return `${plural(up.winback || 0, 'customer has', 'customers have')} not ordered for ${a.param}+ days`;
    if (a.type === 'tier_up' && D.program_type !== 'points') return 'Levels follow total spend, whatever the program type.';
    return '';
  };

  const toggle = async (a, on) => {
    setBusy(a.type);
    try {
      const { saved, ...fields } = a;
      const r = await apiPostJson(LOYALTY_API, { section: 'autopilot', ...fields, enabled: on ? 1 : 0 });
      if (!r.success) throw new Error(r.message || 'Could not save');
      setData((d) => ({ ...d, automations: d.automations.map((x) => (x.type === a.type ? { ...x, enabled: on ? 1 : 0, saved: true } : x)) }));
      feedback.success(`${META[a.type][1]} is ${on ? 'on' : 'off'}`);
    } catch (e) {
      const needsDish = on && a.reward_kind === 'item' && !a.reward_item_id;
      feedback.error(needsDish ? 'Pick the free dish for this gift on the website first (Growth › Loyalty & Rewards › Autopilot).' : e.message || 'Could not save');
    } finally {
      setBusy(null);
    }
  };

  const runNow = async () => {
    const ok = await feedback.confirm({ title: 'Check now?', message: 'Looks for birthdays, anniversaries and quiet customers right now and sends any gifts that are due.', confirmText: 'Check now' });
    if (!ok) return;
    setRunning(true);
    try {
      const r = await apiPostJson(LOYALTY_API, { section: 'autopilot', action: 'run' });
      if (!r.success) throw new Error(r.message || 'Could not run');
      feedback.success(r.issued ? `${r.issued} new gift${r.issued > 1 ? 's' : ''} sent` : 'All caught up: no new gifts due');
      refresh();
    } catch (e) {
      feedback.error(e.message || 'Could not run');
    } finally {
      setRunning(false);
    }
  };

  const running7 = autos.filter((a) => Number(a.enabled)).length;
  const tot = { issued: 0, used: 0, revenue: 0 };
  Object.keys(stats).forEach((k) => {
    if (META[k]) {
      tot.issued += stats[k].issued;
      tot.used += stats[k].used;
      tot.revenue += stats[k].revenue;
    }
  });
  const cols = columns(330, 2);
  const recent = D.recent || [];

  return (
    <ScrollView
      contentContainerStyle={contentStyle}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
      showsVerticalScrollIndicator={false}
    >
      <FadeIn>
        <Card>
          <Text style={styles.heroTitle}>Rewards on autopilot</Text>
          <Text style={styles.heroSub}>Set each gift once. It goes out to the right customer at the right moment.</Text>
          <Nums items={[[`${running7}/${autos.length}`, 'Running'], [String(tot.issued), 'Gifts (90 days)'], [m(Math.round(tot.revenue)), 'Orders brought in']]} />
        </Card>
      </FadeIn>

      {!Number(D.loyalty_enabled) ? (
        <Note icon="warning" tone="warn">The loyalty program is off, so nothing is sent. Turn it on in the Program tab.</Note>
      ) : null}

      <View style={[styles.grid, { gap: spacing.sm }]}>
        {autos.map((a, i) => {
          const mt = META[a.type];
          const st = stats[a.type] || { issued: 0, used: 0, revenue: 0 };
          const on = !!Number(a.enabled);
          const n = note(a);
          return (
            <FadeIn key={a.type} index={i + 1} style={{ width: cols > 1 ? '49.3%' : '100%' }}>
              <Card style={[styles.auto, on && styles.autoOn]}>
                <View style={styles.autoTop}>
                  <IconBox name={mt[0]} tint={on ? '#fff' : colors.primary} bg={on ? colors.primary : colors.primaryLight} />
                  <View style={{ flex: 1 }}>
                    <Text style={styles.autoTitle}>{mt[1]}</Text>
                    <Text style={styles.autoSub} numberOfLines={2}>
                      {trigger(a)} · <Text style={styles.reward}>{rewardText(a)}{Number(a.min_order) > 0 ? ` above ${m(a.min_order)}` : ''}</Text>
                    </Text>
                  </View>
                  <Toggle value={on} busy={busy === a.type} onChange={(v) => toggle(a, v)} label={`${mt[1]} on or off`} />
                </View>
                <Text style={styles.why}>{mt[3]}</Text>
                <View style={styles.nums}>
                  <Text style={styles.num}><Text style={styles.numB}>{st.issued}</Text> sent</Text>
                  <Text style={styles.num}><Text style={styles.numB}>{st.used}</Text> used{st.issued ? ` · ${Math.round((st.used * 100) / st.issued)}%` : ''}</Text>
                  <Text style={styles.num}><Text style={styles.numB}>{m(Math.round(st.revenue))}</Text> brought in</Text>
                </View>
                {n ? <Text style={styles.note}>{n}</Text> : null}
              </Card>
            </FadeIn>
          );
        })}
      </View>
      <Text style={styles.fine}>Change a gift, its amount or its WhatsApp message on the website: Growth › Loyalty & Rewards › Autopilot.</Text>

      <FadeIn index={8}>
        <SectionTitle sub="Codes are personal: only that phone number can use them, once.">Latest gifts</SectionTitle>
        <Card style={{ paddingVertical: 4 }}>
          {recent.length ? (
            recent.map((r, i) => (
              <View key={`${r.code}_${i}`} style={[styles.row, i > 0 && styles.rowLine]}>
                <IconBox name={(META[r.source] || ['gift'])[0]} size={34} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.rowTitle} numberOfLines={1}>{r.name}{r.phone ? <Text style={styles.phone}>  {r.phone}</Text> : null}</Text>
                  <Text style={styles.rowSub} numberOfLines={1}>{(META[r.source] || [null, SOURCE_LABEL[r.source] || r.source])[1]} · {r.label}</Text>
                  <Text style={styles.code} numberOfLines={1}>{r.code}{r.at ? `  ·  ${timeAgo(r.at)}` : ''}</Text>
                </View>
                <Pill label={Number(r.used) ? 'Used' : 'Ready'} tone={Number(r.used) ? 'off' : 'ok'} />
              </View>
            ))
          ) : (
            <Text style={styles.empty}>No gifts yet. Turn on an automation and gifts appear here as customers qualify.</Text>
          )}
        </Card>
      </FadeIn>

      <Button tone="soft" icon="refresh" label="Check birthdays and quiet customers now" onPress={runNow} busy={running} />
      <Text style={styles.fine}>
        Each customer gets a gift once per occasion: one welcome, one birthday a year, one win-back per quiet spell.{D.last_run ? ` Last checked ${timeAgo(D.last_run).toLowerCase()}.` : ''}
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  heroTitle: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  heroSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 2,
    lineHeight: 18,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
  },
  auto: {
    padding: spacing.md,
    gap: 8,
    borderWidth: 1.5,
    borderColor: 'transparent',
  },
  autoOn: {
    borderColor: colors.primaryLight,
  },
  autoTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  autoTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  autoSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
  },
  reward: {
    fontFamily: font.semiBold,
    color: colors.primary,
  },
  why: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.inkSoft,
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
  note: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.info,
    lineHeight: 16,
  },
  fine: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    lineHeight: 17,
  },
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
  rowTitle: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  phone: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  rowSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.inkSoft,
  },
  code: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.muted,
    letterSpacing: 0.3,
  },
  empty: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
    paddingVertical: spacing.lg,
  },
});
