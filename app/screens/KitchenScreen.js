import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, LayoutAnimation, RefreshControl, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet, apiPostForm } from '../config/api';
import { getKitchenStation, setKitchenStation } from '../config/kitchenSettings';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { durationLabel, minutesSince, realName } from '../utils/format';

const STAGES = [
  { key: 'Pending', label: 'New', icon: 'receipt-outline', next: 'Preparing', action: 'Start cooking', barAction: 'Start making', actionIcon: 'flame' },
  { key: 'Preparing', label: 'Cooking', icon: 'flame-outline', next: 'Ready', action: 'Ready', actionIcon: 'checkmark-done' },
  { key: 'Ready', label: 'Ready', icon: 'checkmark-done-outline', next: 'Completed', action: 'Picked up', actionIcon: 'checkmark-circle' },
];
const STALE_MIN = 6 * 60;
// Drinks made at the bar come as their own tickets (BOT, station 'bar'). The filter and the
// KOT / BOT labels show only for a restaurant that has a bar (get_kot's has_bar).
const STATIONS = [
  { key: 'all', label: 'All' },
  { key: 'kitchen', label: 'Kitchen', icon: 'restaurant-outline' },
  { key: 'bar', label: 'Bar', icon: 'wine-outline' },
];
const isBar = (k) => k.station === 'bar';

function ageTone(min) {
  if (min < 15) return { fg: colors.success, bg: colors.successBg };
  if (min < 30) return { fg: '#B7791F', bg: colors.warningBg };
  return { fg: colors.danger, bg: colors.dangerBg };
}

// Kitchen display: every open ticket as a big readable card, moved along New → Cooking →
// Ready with one tap. Timers turn amber at 15 min and red at 30.
export default function KitchenScreen({ navigation }) {
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, columns, contentWidth } = useLayout();
  const [stage, setStage] = useState('Pending');
  const [busy, setBusy] = useState(null);
  const [now, setNow] = useState(Date.now());
  // All / Kitchen / Bar, remembered on this device (a phone at the bar stays on Bar)
  const [station, setStation] = useState('all');
  useEffect(() => {
    getKitchenStation().then(setStation);
  }, []);
  const pickStation = (key) => {
    LayoutAnimation.configureNext(LayoutAnimation.create(180, 'easeInEaseOut', 'opacity'));
    setStation(key);
    setKitchenStation(key).catch(() => {});
  };

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 30000);
    return () => clearInterval(id);
  }, []);

  const fetcher = useCallback(() => apiGet('/api/get_kot.php'), []);
  const { data, loading, refreshing, error, refresh, setData, reload } = useApiData(fetcher, { pollInterval: 10000, cacheKey: 'kitchen', refetchOnFocus: true });
  const kots = data?.kots || [];
  const hasBar = !!data?.has_bar || kots.some(isBar);
  const shown = useMemo(() => (hasBar && station !== 'all' ? kots.filter((k) => (isBar(k) ? 'bar' : 'kitchen') === station) : kots), [kots, hasBar, station]);

  const counts = useMemo(() => {
    const c = { Pending: 0, Preparing: 0, Ready: 0 };
    shown.forEach((k) => {
      if (c[k.kot_status] !== undefined) c[k.kot_status] += 1;
    });
    return c;
  }, [shown]);
  const stale = useMemo(() => shown.filter((k) => minutesSince(k.created_at, now) >= STALE_MIN), [shown, now]);
  const visible = useMemo(
    () => shown.filter((k) => k.kot_status === stage).sort((a, b) => String(a.created_at).localeCompare(String(b.created_at))),
    [shown, stage]
  );
  const cur = STAGES.find((s) => s.key === stage);

  const move = async (kot, to) => {
    setBusy(kot.id);
    try {
      const res = await apiPostForm('/controllers/kot_operations.php', { action: 'update_kot_status', kotId: kot.id, status: to });
      if (!res.success) throw new Error(res.message || 'Could not update the ticket');
      LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
      setData((prev) => (prev ? { ...prev, kots: (prev.kots || []).map((k) => (k.id === kot.id ? { ...k, kot_status: to } : k)).filter((k) => k.kot_status !== 'Completed') } : prev));
      feedback.success(to === 'Completed' ? 'Ticket closed' : `Ticket ${to === 'Preparing' ? (isBar(kot) ? 'being made' : 'cooking') : 'ready'}`);
      reload('background');
    } catch (e) {
      feedback.errorFrom(e, 'Could not update the ticket');
    } finally {
      setBusy(null);
    }
  };

  const closeStale = async () => {
    const ok = await feedback.confirm({
      title: `Close ${stale.length} old ticket${stale.length > 1 ? 's' : ''}?`,
      message: 'These have been open for more than 6 hours. Closing them marks them done, so their tables show as free again.',
      confirmText: 'Close them',
    });
    if (!ok) return;
    let failed = 0;
    for (const k of stale) {
      try {
        const res = await apiPostForm('/controllers/kot_operations.php', { action: 'update_kot_status', kotId: k.id, status: 'Completed' });
        if (!res.success) failed += 1;
      } catch (e) {
        failed += 1;
      }
    }
    if (failed) feedback.error(`${failed} ticket${failed > 1 ? 's' : ''} could not be closed`);
    else feedback.success('Old tickets closed');
    reload('background');
  };

  const cols = columns(300, 3);
  const cardW = Math.floor((contentWidth - gutter * 2 - spacing.md * (cols - 1)) / cols);

  const renderKot = ({ item: k, index }) => {
    const min = minutesSince(k.created_at, now);
    const tone = ageTone(min);
    const short = String(k.kot_number || '').split('-').pop();
    const name = realName(k.customer_name);
    const bar = isBar(k);
    return (
      <FadeIn index={index} style={{ width: cols > 1 ? cardW : '100%' }}>
        <View style={[styles.card, shadow.sm, { borderTopColor: tone.fg }]}>
          <View style={styles.cardTop}>
            <View style={[styles.ticketNo, bar && styles.ticketNoBar]}>
              <Text style={styles.ticketNoText}>{hasBar ? `${bar ? 'BOT' : 'KOT'} ` : ''}#{short}</Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.where} numberOfLines={1}>
                {k.table_number ? `Table ${k.table_number}` : k.order_type || 'Order'}
                {k.area_name ? ` · ${k.area_name}` : ''}
              </Text>
              {name ? <Text style={styles.who} numberOfLines={1}>{name}</Text> : null}
              {bar ? (
                <View style={styles.barChip}>
                  <Ionicons name="wine-outline" size={12} color={colors.teal} />
                  <Text style={styles.barChipText}>Bar</Text>
                </View>
              ) : null}
            </View>
            <View style={[styles.age, { backgroundColor: tone.bg }]}>
              <Ionicons name="time-outline" size={13} color={tone.fg} />
              <Text style={[styles.ageText, { color: tone.fg }]}>{durationLabel(min)}</Text>
            </View>
          </View>
          <View style={styles.items}>
            {(k.items || []).map((it, i) => (
              <View key={i} style={styles.itemRow}>
                <Text style={styles.qty}>{Number(it.quantity) || 1}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.itemName}>{it.item_name}</Text>
                  {it.addon_name || (it.addons || []).length ? (
                    <Text style={styles.itemNote}>+ {it.addon_name || (it.addons || []).map((a) => a.name || a).join(', ')}</Text>
                  ) : null}
                  {it.notes ? <Text style={styles.itemNote}>{it.notes}</Text> : null}
                </View>
              </View>
            ))}
          </View>
          {k.notes ? (
            <View style={styles.note}>
              <Ionicons name="document-text-outline" size={14} color="#92400E" />
              <Text style={styles.noteText}>{k.notes}</Text>
            </View>
          ) : null}
          <PressableScale style={[styles.go, stage === 'Ready' && { backgroundColor: colors.ink }]} onPress={() => move(k, cur.next)} disabled={busy === k.id} feedback="press">
            <Ionicons name={cur.actionIcon} size={17} color="#fff" />
            <Text style={styles.goText}>{busy === k.id ? 'Saving…' : (bar && cur.barAction) || cur.action}</Text>
          </PressableScale>
        </View>
      </FadeIn>
    );
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Kitchen display" title="Kitchen" onBack={() => navigation.goBack()} />
      <View style={[styles.stages, { marginHorizontal: gutter }, shadow.sm]}>
        {STAGES.map((s) => {
          const on = s.key === stage;
          return (
            <PressableScale key={s.key} feedback="select" style={[styles.stage, on && styles.stageOn]} onPress={() => setStage(s.key)}>
              <Ionicons name={s.icon} size={16} color={on ? '#fff' : colors.inkSoft} />
              <Text style={[styles.stageText, on && { color: '#fff' }]}>{s.label}</Text>
              <View style={[styles.stageCount, on && { backgroundColor: 'rgba(255,255,255,0.25)' }]}>
                <Text style={[styles.stageCountText, on && { color: '#fff' }]}>{counts[s.key]}</Text>
              </View>
            </PressableScale>
          );
        })}
      </View>

      {hasBar ? (
        <View style={[styles.stations, { marginHorizontal: gutter }]}>
          {STATIONS.map((s) => {
            const on = s.key === station;
            return (
              <PressableScale key={s.key} feedback="select" style={[styles.stationChip, on && styles.stationChipOn]} onPress={() => pickStation(s.key)} accessibilityLabel={`Show ${s.key === 'all' ? 'all' : s.label.toLowerCase()} tickets`}>
                {s.icon ? <Ionicons name={s.icon} size={14} color={on ? '#fff' : colors.inkSoft} /> : null}
                <Text style={[styles.stationText, on && { color: '#fff' }]}>{s.label}</Text>
              </PressableScale>
            );
          })}
        </View>
      ) : null}

      {stale.length ? (
        <PressableScale style={[styles.stale, { marginHorizontal: gutter }]} onPress={closeStale} scale={0.99}>
          <Ionicons name="alert-circle" size={18} color="#B7791F" />
          <Text style={styles.staleText}>
            {stale.length} ticket{stale.length > 1 ? 's have' : ' has'} been open over 6 hours. Tap to close.
          </Text>
        </PressableScale>
      ) : null}

      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <FlatList
          key={cols}
          data={visible}
          keyExtractor={(k) => String(k.id)}
          renderItem={renderKot}
          numColumns={cols}
          columnWrapperStyle={cols > 1 ? { gap: spacing.md } : undefined}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.md, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={
            <EmptyState
              icon={cur.icon}
              title={stage === 'Pending' ? 'No new tickets' : stage === 'Preparing' ? (hasBar && station === 'bar' ? 'Nothing being made' : 'Nothing cooking') : 'Nothing waiting to go out'}
              subtitle="Tickets from the POS and accepted orders show up here by themselves."
            />
          }
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  stages: {
    flexDirection: 'row',
    gap: 6,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: 6,
    marginTop: -spacing.lg,
  },
  stage: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    height: 42,
    borderRadius: 12,
  },
  stageOn: {
    backgroundColor: colors.primary,
  },
  stageText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.inkSoft,
  },
  stageCount: {
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    paddingHorizontal: 5,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  stageCountText: {
    fontFamily: font.bold,
    fontSize: 11,
    color: colors.inkSoft,
  },
  stations: {
    flexDirection: 'row',
    gap: 8,
    marginTop: spacing.md,
  },
  stationChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    height: 34,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  stationChipOn: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  stationText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  stale: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.warningBg,
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.md,
  },
  staleText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 12.5,
    color: '#92400E',
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    borderTopWidth: 4,
    gap: spacing.md,
  },
  cardTop: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  ticketNo: {
    minWidth: 44,
    height: 36,
    paddingHorizontal: 8,
    borderRadius: 11,
    backgroundColor: colors.ink,
    alignItems: 'center',
    justifyContent: 'center',
  },
  ticketNoBar: {
    backgroundColor: colors.teal,
  },
  ticketNoText: {
    fontFamily: font.bold,
    fontSize: 13,
    color: '#fff',
  },
  where: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  who: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  barChip: {
    flexDirection: 'row',
    alignSelf: 'flex-start',
    alignItems: 'center',
    gap: 4,
    marginTop: 3,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 2,
    backgroundColor: colors.tealBg,
  },
  barChipText: {
    fontFamily: font.bold,
    fontSize: 11,
    color: colors.teal,
  },
  age: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 4,
  },
  ageText: {
    fontFamily: font.bold,
    fontSize: 12,
  },
  items: {
    gap: 8,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.md,
  },
  qty: {
    minWidth: 30,
    height: 30,
    lineHeight: 30,
    borderRadius: 9,
    overflow: 'hidden',
    textAlign: 'center',
    backgroundColor: colors.primary,
    color: '#fff',
    fontFamily: font.bold,
    fontSize: 15,
  },
  itemName: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
    lineHeight: 22,
    paddingTop: 3,
  },
  itemNote: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.primary,
    marginTop: 1,
  },
  note: {
    flexDirection: 'row',
    gap: 6,
    backgroundColor: '#FEF7E6',
    borderRadius: radius.md,
    padding: spacing.sm,
  },
  noteText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 12.5,
    color: '#92400E',
  },
  go: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    height: 50,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
  },
  goText: {
    fontFamily: font.bold,
    fontSize: 15,
    color: '#fff',
  },
});
