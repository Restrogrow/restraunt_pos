import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, LayoutAnimation, RefreshControl, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet, apiPostForm } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { durationLabel, minutesSince } from '../utils/format';
import { setLiveCount } from '../utils/liveCounts';

const TYPE_ICON = { Bill: 'receipt-outline', Cutlery: 'restaurant-outline', Water: 'water-outline', 'Clean Table': 'sparkles-outline', Order: 'restaurant-outline', General: 'hand-left-outline' };

export default function WaiterCallsScreen({ navigation }) {
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [busy, setBusy] = useState(null);
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 20000);
    return () => clearInterval(id);
  }, []);

  const fetcher = useCallback(() => apiGet('/api/get_waiter_requests.php'), []);
  const { data, loading, refreshing, error, refresh, setData, reload } = useApiData(fetcher, { pollInterval: 8000, cacheKey: 'waiter-calls', refetchOnFocus: true });
  const list = data?.requests || [];
  useEffect(() => setLiveCount('calls', list.length), [list.length]);

  const attend = async (r) => {
    setBusy(r.id);
    try {
      const res = await apiPostForm('/controllers/waiter_request_operations.php', { action: 'mark_attended', requestId: r.id });
      if (!res.success) throw new Error(res.message || 'Could not update');
      LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
      setData((prev) => (prev ? { ...prev, requests: (prev.requests || []).filter((x) => x.id !== r.id) } : prev));
      feedback.success(`Table ${r.table_number} looked after`);
      reload('background');
    } catch (e) {
      feedback.errorFrom(e, 'Could not update the call');
    } finally {
      setBusy(null);
    }
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow={list.length ? `${list.length} waiting` : 'All calm'} title="Waiter calls" onBack={() => navigation.goBack()} />
      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <FlatList
          data={list}
          keyExtractor={(r) => String(r.id)}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.md, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={<EmptyState icon="checkmark-done-outline" title="All tables are looked after" subtitle="When a guest taps “Call waiter” on the table QR, it shows here with a timer." />}
          renderItem={({ item: r, index }) => {
            const min = minutesSince(r.created_at, now);
            const late = min >= 5;
            return (
              <FadeIn index={index}>
                <View style={[styles.card, shadow.sm, late && styles.cardLate]}>
                  <View style={[styles.table, late && { backgroundColor: colors.danger }]}>
                    <Text style={styles.tableText} numberOfLines={1} adjustsFontSizeToFit>{r.table_number}</Text>
                  </View>
                  <View style={{ flex: 1 }}>
                    <View style={styles.typeRow}>
                      <Ionicons name={TYPE_ICON[r.request_type] || TYPE_ICON.General} size={15} color={colors.ink} />
                      <Text style={styles.type}>{r.request_type === 'General' ? 'Needs a waiter' : r.request_type}</Text>
                    </View>
                    <Text style={[styles.meta, late && { color: colors.danger }]}>
                      {r.area_name ? `${r.area_name} · ` : ''}waiting {durationLabel(min)}
                    </Text>
                    {r.notes ? <Text style={styles.notes}>“{r.notes}”</Text> : null}
                  </View>
                  <PressableScale style={styles.done} onPress={() => attend(r)} disabled={busy === r.id} feedback="success">
                    <Ionicons name="checkmark" size={18} color="#fff" />
                    <Text style={styles.doneText}>{busy === r.id ? '…' : 'Done'}</Text>
                  </PressableScale>
                </View>
              </FadeIn>
            );
          }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  cardLate: {
    borderWidth: 1.5,
    borderColor: colors.danger,
  },
  table: {
    width: 56,
    height: 56,
    borderRadius: 16,
    backgroundColor: colors.warning,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
  },
  tableText: {
    fontFamily: font.bold,
    fontSize: 18,
    color: '#fff',
  },
  typeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  type: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 2,
  },
  notes: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.inkSoft,
    marginTop: 4,
  },
  done: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    height: 44,
    paddingHorizontal: 14,
    borderRadius: radius.md,
    backgroundColor: colors.success,
  },
  doneText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: '#fff',
  },
});
