import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, FlatList, Linking, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import Avatar from '../components/Avatar';
import ScreenHeader from '../components/ScreenHeader';
import SearchField from '../components/SearchField';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import Sheet from '../components/ui/Sheet';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { dayLabel, money } from '../utils/format';

const SORTS = [
  { key: 'spent', label: 'Top spenders', fn: (a, b) => (Number(b.total_spent) || 0) - (Number(a.total_spent) || 0) },
  { key: 'visits', label: 'Most visits', fn: (a, b) => (Number(b.total_visits) || 0) - (Number(a.total_visits) || 0) },
  { key: 'recent', label: 'Recent', fn: (a, b) => String(b.last_visit_date || '').localeCompare(String(a.last_visit_date || '')) },
  { key: 'name', label: 'A–Z', fn: (a, b) => String(a.customer_name || '').localeCompare(String(b.customer_name || '')) },
];

export default function CustomersScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [q, setQ] = useState('');
  const [sort, setSort] = useState('spent');
  const [open, setOpen] = useState(null);

  const fetcher = useCallback(() => apiGet('/api/get_customers.php?limit=200'), []);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { pollInterval: 60000, cacheKey: 'customers', refetchOnFocus: true });
  const all = data?.data || [];

  const list = useMemo(() => {
    const s = q.trim().toLowerCase();
    const digits = s.replace(/\D/g, '');
    const fn = SORTS.find((x) => x.key === sort)?.fn;
    return all
      .filter((c) => !s || String(c.customer_name || '').toLowerCase().includes(s) || (digits && String(c.phone || '').includes(digits)))
      .sort(fn);
  }, [all, q, sort]);

  const wa = (phone) => {
    const p = String(phone || '').replace(/\D/g, '');
    if (!p) return;
    Linking.openURL(`https://wa.me/${p.length === 10 ? `91${p}` : p}`).catch(() => {});
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow={`${all.length} customers`} title="Customers" onBack={() => navigation.goBack()}>
        <SearchField style={{ marginTop: spacing.md }} value={q} onChangeText={setQ} placeholder="Name or phone" />
      </ScreenHeader>

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.sortScroll} contentContainerStyle={{ gap: spacing.sm, paddingHorizontal: gutter, paddingVertical: spacing.sm }}>
        {SORTS.map((s) => (
          <PressableScale key={s.key} feedback="select" style={[styles.chip, sort === s.key && styles.chipOn]} onPress={() => setSort(s.key)}>
            <Text style={[styles.chipText, sort === s.key && { color: '#fff' }]}>{s.label}</Text>
          </PressableScale>
        ))}
      </ScrollView>

      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <FlatList
          data={list}
          keyExtractor={(c) => String(c.id)}
          keyboardShouldPersistTaps="handled"
          initialNumToRender={12}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.sm, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={<EmptyState icon="people-outline" title={q ? 'No match' : 'No customers yet'} subtitle={q ? `Nobody found for "${q}".` : 'Customers appear here after their first order with a phone number.'} />}
          renderItem={({ item: c, index }) => (
            <FadeIn index={index}>
              <PressableScale style={[styles.row, shadow.sm]} onPress={() => setOpen(c)} scale={0.99}>
                <Avatar name={c.customer_name} size={42} bg={colors.primaryLight} color={colors.primary} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>{c.customer_name || 'No name'}</Text>
                  <Text style={styles.meta} numberOfLines={1}>
                    {c.phone || 'No phone'} · {c.total_visits || 0} visit{Number(c.total_visits) === 1 ? '' : 's'}
                  </Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.spent}>{money(c.total_spent, currency)}</Text>
                  {Number(c.loyalty_points_balance) > 0 ? (
                    <View style={styles.pts}>
                      <Ionicons name="star" size={10} color={colors.warning} />
                      <Text style={styles.ptsText}>{Number(c.loyalty_points_balance).toLocaleString('en-IN')} pts</Text>
                    </View>
                  ) : Number(c.stamps) > 0 ? (
                    <View style={styles.pts}>
                      <Ionicons name="ribbon" size={10} color={colors.warning} />
                      <Text style={styles.ptsText}>{c.stamps} stamp{Number(c.stamps) === 1 ? '' : 's'}</Text>
                    </View>
                  ) : c.last_visit_date ? (
                    <Text style={styles.last}>{dayLabel(c.last_visit_date)}</Text>
                  ) : null}
                </View>
              </PressableScale>
            </FadeIn>
          )}
        />
      )}

      <Sheet visible={!!open} onClose={() => setOpen(null)} title={open?.customer_name || 'Customer'} subtitle={open?.phone || ''}>
        {open ? <CustomerDetail key={open.id} c={open} currency={currency} onWhatsApp={wa} /> : null}
      </Sheet>
    </View>
  );
}

// The customer's loyalty picture, from the same summary the POS shows (api/pos_customer.php?id=).
// The list row's numbers show at once; points, wallet, level and reward codes fill in when they arrive.
function CustomerDetail({ c, currency, onWhatsApp }) {
  const [d, setD] = useState(null);
  const [err, setErr] = useState('');
  const load = useCallback(() => {
    setErr('');
    apiGet(`/api/pos_customer.php?id=${encodeURIComponent(c.id)}`)
      .then((r) => {
        if (!r.success) throw new Error(r.message || 'Could not load rewards');
        setD(r.customer);
      })
      .catch((e) => setErr(e.message || 'Could not load rewards'));
  }, [c.id]);
  useEffect(() => {
    load();
  }, [load]);

  const L = d?.loyalty;
  const phone = d?.phone || c.phone;
  const tiles = [
    ['Spent', money(d ? d.spent : c.total_spent, currency)],
    ['Visits', String(d ? d.visits : c.total_visits || 0)],
  ];
  if (!L || L.type === 'points' || L.points > 0) tiles.push(['Points', String(L ? L.points : c.loyalty_points_balance || 0)]);
  if (L?.type === 'stamps') tiles.push(['Stamps', `${L.stamps}/${L.stamp_goal}`]);
  if (d?.wallet?.enabled) tiles.push(['Wallet', money(d.wallet.balance, currency)]);
  const giftText = (g) =>
    /^free/i.test(g.label || '') || !(Number(g.value) > 0) ? 'Free' : g.type === 'percent' ? `${Number(g.value)}% off` : `${money(g.value, currency)} off`;
  const lastVisit = d ? d.last_visit : c.last_visit_date;
  const bday = d ? d.birthday : c.birthday;

  return (
    <ScrollView style={{ maxHeight: 560 }} contentContainerStyle={{ gap: spacing.md, paddingBottom: spacing.sm }} showsVerticalScrollIndicator={false}>
      <View style={styles.stats}>
        {tiles.map(([l, v]) => (
          <View key={l} style={styles.stat}>
            <Text style={styles.statValue} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
            <Text style={styles.statLabel}>{l}</Text>
          </View>
        ))}
      </View>

      {!d && !err ? <ActivityIndicator color={colors.primary} /> : null}
      {err ? (
        <PressableScale style={styles.errRow} onPress={load}>
          <Ionicons name="refresh" size={15} color={colors.danger} />
          <Text style={styles.errText}>{err} · Tap to try again</Text>
        </PressableScale>
      ) : null}

      {L ? (
        <View style={styles.loyal}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <Ionicons name="ribbon" size={16} color={colors.primary} />
            <Text style={styles.loyalTitle}>{L.tier ? `${L.tier} level` : 'Rewards'}</Text>
            {d.has_account ? <Text style={styles.tag}>Has account</Text> : null}
            {!L.enabled ? <Text style={[styles.tag, { color: colors.inkSoft, backgroundColor: colors.border }]}>Program off</Text> : null}
          </View>
          {L.type === 'points' ? (
            <Text style={styles.loyalLine}>
              {Number(L.points).toLocaleString('en-IN')} points, worth {money(L.points_value, currency)}.{L.min_redeem ? ` Can redeem from ${L.min_redeem} points.` : ''}
            </Text>
          ) : L.type === 'stamps' ? (
            <>
              <View style={styles.stampRow}>
                {Array.from({ length: Math.min(L.stamp_goal, 12) }).map((_, i) => (
                  <View key={i} style={[styles.stampDot, i < L.stamps && styles.stampDotOn]}>
                    {i < L.stamps ? <Ionicons name="checkmark" size={11} color="#fff" /> : i === L.stamp_goal - 1 ? <Ionicons name="gift" size={11} color={colors.primary} /> : null}
                  </View>
                ))}
              </View>
              <Text style={styles.loyalLine}>
                {L.stamps} of {L.stamp_goal} stamps{L.stamp_reward ? ` · full card: ${L.stamp_reward}` : ''}
              </Text>
            </>
          ) : (
            <Text style={styles.loyalLine}>
              {L.cashback_pct}% cashback on every order{d.wallet?.enabled ? ` · ${money(d.wallet.balance, currency)} in their wallet` : ''}.
            </Text>
          )}
        </View>
      ) : null}

      {d?.gifts?.length ? (
        <View style={{ gap: spacing.sm }}>
          <Text style={styles.giftHead}>Their reward codes</Text>
          {d.gifts.map((g) => (
            <View key={g.code} style={styles.gift}>
              <View style={styles.giftOff}>
                <Text style={styles.giftOffText} numberOfLines={1} adjustsFontSizeToFit>{giftText(g)}</Text>
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.giftCode} numberOfLines={1}>{g.code}</Text>
                <Text style={styles.giftSub} numberOfLines={2}>
                  {g.label || 'Personal reward'}
                  {g.min_order > 0 ? ` · above ${money(g.min_order, currency)}` : ''}
                  {g.valid_until ? ` · till ${g.valid_until}` : ''}
                </Text>
              </View>
            </View>
          ))}
        </View>
      ) : d ? (
        <Text style={styles.metaLine}>No reward codes waiting for them.</Text>
      ) : null}

      {lastVisit ? <Text style={styles.metaLine}>Last visit: {dayLabel(lastVisit)}</Text> : null}
      {bday ? <Text style={styles.metaLine}>Birthday: {bday}</Text> : null}
      {c.email ? <Text style={styles.metaLine}>{c.email}</Text> : null}
      {phone ? (
        <View style={styles.btns}>
          <PressableScale style={[styles.btn, { backgroundColor: colors.primaryLight }]} onPress={() => Linking.openURL(`tel:${phone}`).catch(() => {})}>
            <Ionicons name="call" size={17} color={colors.primary} />
            <Text style={[styles.btnText, { color: colors.primary }]}>Call</Text>
          </PressableScale>
          <PressableScale style={[styles.btn, { backgroundColor: '#25D366' }]} onPress={() => onWhatsApp(phone)}>
            <Ionicons name="logo-whatsapp" size={17} color="#fff" />
            <Text style={[styles.btnText, { color: '#fff' }]}>WhatsApp</Text>
          </PressableScale>
        </View>
      ) : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  pts: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
    marginTop: 2,
    backgroundColor: colors.warningBg,
    borderRadius: radius.pill,
    paddingHorizontal: 7,
    paddingVertical: 1,
  },
  ptsText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
    color: '#B7791F',
  },
  errRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: colors.dangerBg,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  errText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.danger,
  },
  loyal: {
    backgroundColor: '#FFF8F6',
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.primaryLight,
    padding: spacing.md,
    gap: 6,
  },
  loyalTitle: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  tag: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
    color: colors.success,
    backgroundColor: colors.successBg,
    borderRadius: radius.pill,
    paddingHorizontal: 7,
    paddingVertical: 1,
    overflow: 'hidden',
  },
  loyalLine: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.inkSoft,
    lineHeight: 19,
  },
  stampRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 5,
  },
  stampDot: {
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 1.5,
    borderColor: colors.primaryLight,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stampDotOn: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  giftHead: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
  },
  gift: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: 10,
  },
  giftOff: {
    width: 70,
    height: 40,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: colors.primary,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
  },
  giftOffText: {
    fontFamily: font.bold,
    fontSize: 12.5,
    color: colors.primary,
  },
  giftCode: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
    letterSpacing: 0.3,
  },
  giftSub: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
  },
  fill: { flex: 1, backgroundColor: colors.bg },
  sortScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 52,
  },
  chip: {
    height: 36,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: colors.border,
  },
  chipOn: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  name: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 1,
  },
  spent: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  last: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    marginTop: 1,
  },
  stats: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  stat: {
    flex: 1,
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: spacing.md,
    alignItems: 'center',
  },
  statValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  statLabel: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 2,
  },
  metaLine: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.inkSoft,
  },
  btns: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  btn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    height: 50,
    borderRadius: radius.md,
  },
  btnText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
  },
});
