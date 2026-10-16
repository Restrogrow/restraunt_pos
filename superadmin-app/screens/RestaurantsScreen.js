import { Ionicons } from '@expo/vector-icons';
import { useNavigation, useRoute } from '@react-navigation/native';
import { memo, useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import Screen, { useListPadding } from '../components/Screen';
import SearchField from '../components/SearchField';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { Card, HeaderIcon, Initials, Pill } from '../components/kit';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { sa } from '../config/api';
import { colors, font, radius, shadow, spacing } from '../theme';
import { haptic } from '../utils/haptics';
import { daysLeftLabel, planState, shortDate } from '../utils/format';

const PAGE = 20;
export const STATUS_FILTERS = [
  { value: '', label: 'All', key: 'all' },
  { value: 'paying', label: 'Paying', key: 'paying' },
  { value: 'trial', label: 'Trial', key: 'trial' },
  { value: 'ending', label: 'Ending soon', key: 'ending' },
  { value: 'expired', label: 'Expired', key: 'expired' },
  { value: 'disabled', label: 'Disabled', key: 'disabled' },
];

export default function RestaurantsScreen() {
  const navigation = useNavigation();
  const route = useRoute();
  const { contentContainerStyle, gutter } = useListPadding(true);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState(route.params?.status || '');
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [counts, setCounts] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const seq = useRef(0);
  const list = useRef(null);

  // Overview tiles open this tab pre-filtered
  useEffect(() => {
    if (route.params?.status !== undefined) setStatus(route.params.status);
  }, [route.params?.status, route.params?.at]);

  // mode: 'initial' (skeleton when empty), 'refresh' (pull), 'quiet' (coming back), 'more' (next page)
  const load = useCallback(async (p, query, st, mode = 'initial') => {
    const id = ++seq.current;
    if (mode === 'more') setMore(true);
    else if (mode === 'refresh') setRefreshing(true);
    else if (mode === 'initial') setLoading(true);
    try {
      const r = await sa.get('getRestaurants', { page: p, limit: PAGE, q: query, status: st, with_counts: p === 1 ? 1 : undefined });
      if (id !== seq.current) return;
      setRows((prev) => (p === 1 ? r.restaurants : [...prev, ...r.restaurants.filter((x) => !prev.some((y) => y.id === x.id))]));
      setTotal(r.total);
      if (r.status_counts) setCounts(r.status_counts);
      setPage(p);
      setError('');
    } catch (e) {
      if (id === seq.current && mode !== 'more' && mode !== 'quiet') setError(e.message);
    } finally {
      if (id === seq.current) {
        setLoading(false);
        setMore(false);
        setRefreshing(false);
      }
    }
  }, []);

  // search as you type (a moment after the last key) and on filter change
  useEffect(() => {
    const t = setTimeout(() => {
      load(1, q.trim(), status);
      list.current?.scrollToOffset?.({ offset: 0, animated: false });
    }, q ? 300 : 0);
    return () => clearTimeout(t);
  }, [q, status, load]);

  // back from a restaurant / the create screen: refresh in place, no spinner
  const latest = useRef({ q, status });
  latest.current = { q, status };
  useEffect(
    () =>
      navigation.addListener('focus', () => {
        if (seq.current > 0) load(1, latest.current.q.trim(), latest.current.status, 'quiet');
      }),
    [navigation, load]
  );

  const onEnd = () => {
    if (!more && !loading && rows.length < total) load(page + 1, q.trim(), status, 'more');
  };

  const open = useCallback((r) => navigation.navigate('RestaurantDetail', { id: r.id, name: r.restaurant_name }), [navigation]);
  const pick = (v) => {
    haptic.select();
    setStatus(v);
  };
  const label = STATUS_FILTERS.find((f) => f.value === status)?.label;

  return (
    <Screen
      tabs
      scroll={false}
      eyebrow={q.trim() || status ? `${total} ${status ? label.toLowerCase() : 'found'}` : `${total} on the platform`}
      title="Restaurants"
      right={<HeaderIcon icon="add" label="New restaurant" onPress={() => navigation.navigate('CreateRestaurant')} />}
      headerChildren={<SearchField value={q} onChangeText={setQ} placeholder="Search name, username or ID" style={{ marginTop: spacing.lg }} />}
    >
      <View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={[styles.filters, { paddingHorizontal: gutter }]}>
          {STATUS_FILTERS.map((f) => {
            const on = f.value === status;
            const n = counts ? counts[f.key] : null;
            return (
              <PressableScale key={f.key} onPress={() => pick(f.value)} style={[styles.chip, on && styles.chipOn]}>
                <Text style={[styles.chipText, on && styles.chipTextOn]}>{f.label}</Text>
                {n !== null && n !== undefined ? (
                  <View style={[styles.count, on && styles.countOn]}>
                    <Text style={[styles.countText, on && { color: colors.navy }]}>{n}</Text>
                  </View>
                ) : null}
              </PressableScale>
            );
          })}
        </ScrollView>
      </View>
      {loading && !rows.length ? (
        <LoadingState gutter={gutter} />
      ) : error && !rows.length ? (
        <ErrorState message={error} onRetry={() => load(1, q.trim(), status)} />
      ) : (
        <FlatList
          ref={list}
          data={rows}
          keyExtractor={(r) => String(r.id)}
          contentContainerStyle={[contentContainerStyle, { paddingTop: 4, gap: 10 }]}
          onEndReached={onEnd}
          onEndReachedThreshold={0.4}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          initialNumToRender={8}
          windowSize={7}
          removeClippedSubviews
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(1, q.trim(), status, 'refresh')} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={
            <EmptyState
              icon="storefront-outline"
              title={q || status ? 'Nothing here' : 'No restaurants yet'}
              subtitle={q ? `Nothing found for “${q}”${status ? ` in ${label}` : ''}.` : status ? `No restaurants are ${label.toLowerCase()} right now.` : 'Add the first one to get started.'}
              action={q || status ? 'Show all' : 'Add restaurant'}
              onAction={() => (q || status ? (setQ(''), setStatus('')) : navigation.navigate('CreateRestaurant'))}
            />
          }
          ListFooterComponent={more ? <ActivityIndicator color={colors.primary} style={{ marginVertical: 16 }} /> : null}
          renderItem={({ item, index }) => <Item r={item} index={index} onOpen={open} />}
        />
      )}
      <View style={styles.fabWrap} pointerEvents="box-none">
        <PressableScale onPress={() => navigation.navigate('CreateRestaurant')} style={[styles.fab, shadow.lg]} accessibilityLabel="Add restaurant">
          <Ionicons name="add" size={22} color="#fff" />
          <Text style={styles.fabText}>New</Text>
        </PressableScale>
      </View>
    </Screen>
  );
}

const Item = memo(function Item({ r, index, onOpen }) {
  return (
    <FadeIn index={Math.min(index, 6)}>
      <RestaurantCard r={r} onPress={() => onOpen(r)} />
    </FadeIn>
  );
});

export function RestaurantCard({ r, onPress }) {
  const plan = planState(r);
  const features = [
    Number(r.whatsapp_orders) ? 'WhatsApp' : null,
    Number(r.show_install_app) ? 'App' : null,
    r.payment_gateway_type === 'cash_and_gateway' ? 'Online pay' : null,
  ].filter(Boolean);
  return (
    <Card onPress={onPress} style={{ padding: 14 }}>
      <View style={styles.top}>
        <Initials name={r.restaurant_name} tone={plan.tone === 'danger' ? 'danger' : 'primary'} />
        <View style={{ flex: 1 }}>
          <Text style={styles.name} numberOfLines={1}>
            {r.restaurant_name || '(no name)'}
          </Text>
          <Text style={styles.meta} numberOfLines={1}>
            {r.restaurant_id} · @{r.username}
          </Text>
        </View>
        <Pill label={plan.label} tone={plan.tone} small />
      </View>
      <View style={styles.bottom}>
        <View style={styles.metaItem}>
          <Ionicons name="time-outline" size={13} color={colors.muted} />
          <Text style={styles.metaText}>{plan.label === 'Expired' || plan.label === 'Disabled' ? plan.label : daysLeftLabel(r.days_left) || '—'}</Text>
        </View>
        <View style={styles.metaItem}>
          <Ionicons name="calendar-outline" size={13} color={colors.muted} />
          <Text style={styles.metaText}>Joined {shortDate(r.created_at)}</Text>
        </View>
        {features.length ? (
          <Text style={[styles.metaText, { flex: 1, textAlign: 'right' }]} numberOfLines={1}>
            {features.join(' · ')}
          </Text>
        ) : null}
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  filters: { gap: 8, paddingTop: 14, paddingBottom: 10 },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingLeft: 13,
    paddingRight: 8,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  chipOn: { backgroundColor: colors.navy, borderColor: colors.navy },
  chipText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
  chipTextOn: { color: '#fff' },
  count: { minWidth: 22, height: 22, borderRadius: 11, paddingHorizontal: 6, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  countOn: { backgroundColor: '#fff' },
  countText: { fontFamily: font.bold, fontSize: 11, color: colors.inkSoft },
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 15, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: 1 },
  bottom: { flexDirection: 'row', alignItems: 'center', gap: 14, marginTop: 12, paddingTop: 10, borderTopWidth: 1, borderTopColor: colors.border },
  metaItem: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  metaText: { fontFamily: font.medium, fontSize: 11.5, color: colors.inkSoft },
  fabWrap: { position: 'absolute', right: 20, bottom: 104 },
  fab: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: colors.primary,
    borderRadius: 999,
    paddingHorizontal: 18,
    height: 50,
  },
  fabText: { fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
});
