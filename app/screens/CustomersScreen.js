import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo, useState } from 'react';
import { FlatList, Linking, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import Avatar from '../components/Avatar';
import ScreenHeader from '../components/ScreenHeader';
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
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { cacheKey: 'customers', refetchOnFocus: true });
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
        <View style={styles.search}>
          <Ionicons name="search" size={16} color={colors.muted} />
          <TextInput style={styles.searchInput} value={q} onChangeText={setQ} placeholder="Name or phone" placeholderTextColor={colors.muted} returnKeyType="search" />
          {q ? (
            <PressableScale onPress={() => setQ('')} hitSlop={8} accessibilityLabel="Clear search">
              <Ionicons name="close-circle" size={18} color={colors.muted} />
            </PressableScale>
          ) : null}
        </View>
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
                  {c.last_visit_date ? <Text style={styles.last}>{dayLabel(c.last_visit_date)}</Text> : null}
                </View>
              </PressableScale>
            </FadeIn>
          )}
        />
      )}

      <Sheet visible={!!open} onClose={() => setOpen(null)} title={open?.customer_name || 'Customer'} subtitle={open?.phone || ''}>
        {open ? (
          <View style={{ gap: spacing.md }}>
            <View style={styles.stats}>
              {[
                ['Spent', money(open.total_spent, currency)],
                ['Visits', String(open.total_visits || 0)],
                ['Points', String(open.loyalty_points_balance || 0)],
              ].map(([l, v]) => (
                <View key={l} style={styles.stat}>
                  <Text style={styles.statValue} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
                  <Text style={styles.statLabel}>{l}</Text>
                </View>
              ))}
            </View>
            {open.last_visit_date ? <Text style={styles.metaLine}>Last visit: {dayLabel(open.last_visit_date)}</Text> : null}
            {open.birthday ? <Text style={styles.metaLine}>Birthday: {open.birthday}</Text> : null}
            {open.email ? <Text style={styles.metaLine}>{open.email}</Text> : null}
            {open.phone ? (
              <View style={styles.btns}>
                <PressableScale style={[styles.btn, { backgroundColor: colors.primaryLight }]} onPress={() => Linking.openURL(`tel:${open.phone}`).catch(() => {})}>
                  <Ionicons name="call" size={17} color={colors.primary} />
                  <Text style={[styles.btnText, { color: colors.primary }]}>Call</Text>
                </PressableScale>
                <PressableScale style={[styles.btn, { backgroundColor: '#25D366' }]} onPress={() => wa(open.phone)}>
                  <Ionicons name="logo-whatsapp" size={17} color="#fff" />
                  <Text style={[styles.btnText, { color: '#fff' }]}>WhatsApp</Text>
                </PressableScale>
              </View>
            ) : null}
          </View>
        ) : null}
      </Sheet>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  search: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    marginTop: spacing.md,
  },
  searchInput: {
    flex: 1,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
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
