import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import Sheet from '../components/ui/Sheet';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { clock, money } from '../utils/format';
import { useAccess } from '../utils/access';

const TONE = {
  free: { fg: colors.success, bg: colors.successBg, label: 'Free', icon: 'checkmark-circle' },
  occupied: { fg: colors.danger, bg: colors.dangerBg, label: 'Busy', icon: 'restaurant' },
  reserved: { fg: colors.purple, bg: colors.purpleBg, label: 'Booked', icon: 'calendar' },
  off: { fg: colors.muted, bg: colors.border, label: 'Off', icon: 'remove-circle' },
};
const toneOf = (t) => (t.status === 'occupied' ? TONE.occupied : t.status === 'reserved' ? TONE.reserved : t.is_available === false && t.status !== 'free' ? TONE.off : TONE.free);

export default function TablesScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, columns, contentWidth } = useLayout();
  const [filter, setFilter] = useState('all');
  const [open, setOpen] = useState(null);
  const [closing, setClosing] = useState(false);

  const fetcher = useCallback(() => apiGet('/api/get_table_status.php'), []);
  const { data, loading, refreshing, error, refresh, reload } = useApiData(fetcher, { pollInterval: 15000, cacheKey: 'tables', refetchOnFocus: true });
  const tables = data?.data || [];

  const counts = useMemo(() => {
    const c = { all: tables.length, free: 0, occupied: 0, reserved: 0 };
    tables.forEach((t) => {
      const k = t.status === 'occupied' ? 'occupied' : t.status === 'reserved' ? 'reserved' : 'free';
      c[k] += 1;
    });
    return c;
  }, [tables]);

  const areas = useMemo(() => {
    const map = new Map();
    tables
      .filter((t) => filter === 'all' || (filter === 'free' ? !['occupied', 'reserved'].includes(t.status) : t.status === filter))
      .forEach((t) => {
        const a = t.area_name || 'Tables';
        if (!map.has(a)) map.set(a, []);
        map.get(a).push(t);
      });
    return [...map.entries()];
  }, [tables, filter]);

  const cols = columns(96, 6);
  const tileW = Math.floor((contentWidth - gutter * 2 - spacing.sm * (cols - 1)) / cols);

  const canSee = useAccess(); // POS can be switched off in Super Admin (utils/access.js)
  const startOrder = (t) => {
    setOpen(null);
    if (!canSee('POS')) return feedback.error('POS is not switched on for this restaurant.');
    navigation.navigate('POS', { tableId: String(t.id), tableLabel: t.table_number });
  };

  const freeTable = async (t) => {
    const ok = await feedback.confirm({
      title: `Free table ${t.table_number}?`,
      message: 'Marks its open kitchen tickets as done, so the table shows as free. Only do this when the guests have left.',
      confirmText: 'Free the table',
    });
    if (!ok) return;
    setClosing(true);
    let failed = 0;
    for (const k of t.kot_info || []) {
      try {
        const kotId = await findKotId(k.kot_number);
        if (!kotId) { failed += 1; continue; }
        const res = await apiPostForm('/controllers/kot_operations.php', { action: 'update_kot_status', kotId, status: 'Completed' });
        if (!res.success) failed += 1;
      } catch (e) {
        failed += 1;
      }
    }
    setClosing(false);
    setOpen(null);
    if (failed) feedback.error('Some tickets could not be closed. Try from Kitchen.');
    else feedback.success(`Table ${t.table_number} is free`);
    reload('background');
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow={`${counts.occupied} busy · ${counts.free} free`} title="Tables" onBack={() => navigation.goBack()} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterScroll} contentContainerStyle={{ gap: spacing.sm, paddingHorizontal: gutter }}>
        {[
          ['all', 'All'],
          ['free', 'Free'],
          ['occupied', 'Busy'],
          ['reserved', 'Booked'],
        ].map(([k, label]) => (
          <PressableScale key={k} feedback="select" style={[styles.chip, shadow.sm, filter === k && styles.chipOn]} onPress={() => setFilter(k)}>
            <Text style={[styles.chipText, filter === k && { color: '#fff' }]}>{label}</Text>
            <Text style={[styles.chipCount, filter === k && { color: 'rgba(255,255,255,0.8)' }]}>{counts[k] ?? 0}</Text>
          </PressableScale>
        ))}
      </ScrollView>

      {loading && !data ? (
        <LoadingState variant="grid" columns={Math.min(cols, 4)} gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : !tables.length ? (
        <EmptyState icon="grid-outline" title="No tables yet" subtitle="Add your areas and tables on the website dashboard under Tables." />
      ) : (
        <ScrollView
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.lg }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
        >
          {areas.map(([area, list], ai) => (
            <FadeIn key={area} index={ai}>
              <Text style={styles.area}>
                {area} <Text style={styles.areaMeta}>· {list.length} table{list.length > 1 ? 's' : ''}</Text>
              </Text>
              <View style={[styles.grid, { gap: spacing.sm }]}>
                {list.map((t) => {
                  const tone = toneOf(t);
                  return (
                    <PressableScale
                      key={t.id}
                      style={[styles.tile, { width: tileW, backgroundColor: tone.bg, borderColor: tone.fg }]}
                      onPress={() => (t.status === 'occupied' ? setOpen(t) : startOrder(t))}
                      accessibilityLabel={`Table ${t.table_number}, ${tone.label}`}
                    >
                      <Text style={[styles.tableNo, { color: tone.fg }]} numberOfLines={1}>{t.table_number}</Text>
                      <View style={styles.tileMeta}>
                        <Ionicons name="people-outline" size={12} color={tone.fg} />
                        <Text style={[styles.tileMetaText, { color: tone.fg }]}>{t.capacity}</Text>
                      </View>
                      <Text style={[styles.tileState, { color: tone.fg }]} numberOfLines={1}>
                        {t.status === 'occupied' && t.order_timer ? t.order_timer : tone.label}
                      </Text>
                    </PressableScale>
                  );
                })}
              </View>
            </FadeIn>
          ))}
          <Text style={styles.hint}>Tap a free table to start an order for it. Tap a busy table to see or close its tickets.</Text>
        </ScrollView>
      )}

      <Sheet
        visible={!!open}
        onClose={() => setOpen(null)}
        title={open ? `Table ${open.table_number}` : ''}
        subtitle={open ? `${open.area_name || ''} · seats ${open.capacity}${open.order_timer ? ` · busy for ${open.order_timer}` : ''}` : ''}
      >
        {open ? (
          <View style={{ gap: spacing.sm }}>
            {(open.kot_info || []).map((k) => (
              <View key={k.kot_number} style={styles.kotRow}>
                <Ionicons name="receipt-outline" size={16} color={colors.primary} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.kotNo}>{k.kot_number}</Text>
                  <Text style={styles.kotMeta}>
                    {k.kot_status} · since {clock(k.created_at)}
                  </Text>
                </View>
                <Text style={styles.kotTotal}>{money(k.total, currency)}</Text>
              </View>
            ))}
            <View style={styles.sheetBtns}>
              <PressableScale style={[styles.sheetBtn, styles.sheetBtnGhost]} onPress={() => freeTable(open)} disabled={closing}>
                <Ionicons name="exit-outline" size={17} color={colors.inkSoft} />
                <Text style={styles.sheetBtnGhostText}>{closing ? 'Freeing…' : 'Guests left'}</Text>
              </PressableScale>
              <PressableScale style={styles.sheetBtn} onPress={() => startOrder(open)}>
                <Ionicons name="add-circle-outline" size={17} color="#fff" />
                <Text style={styles.sheetBtnText}>Add items</Text>
              </PressableScale>
            </View>
          </View>
        ) : null}
      </Sheet>
    </View>
  );
}

// get_table_status gives ticket numbers; the status update needs the ticket id.
async function findKotId(kotNumber) {
  const res = await apiGet('/api/get_kot.php');
  const hit = (res?.kots || []).find((k) => k.kot_number === kotNumber);
  return hit?.id || null;
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  filterScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 48,
    marginTop: -spacing.lg,
  },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 40,
    paddingHorizontal: 16,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
  },
  chipOn: {
    backgroundColor: colors.ink,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.inkSoft,
  },
  chipCount: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.muted,
  },
  area: {
    fontFamily: font.bold,
    fontSize: 15,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  areaMeta: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  tile: {
    height: 92,
    borderRadius: radius.md,
    borderWidth: 1.5,
    padding: 10,
    justifyContent: 'space-between',
  },
  tableNo: {
    fontFamily: font.bold,
    fontSize: 19,
  },
  tileMeta: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 3,
  },
  tileMetaText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
  },
  tileState: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
  },
  hint: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    textAlign: 'center',
    lineHeight: 18,
  },
  kotRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  kotNo: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  kotMeta: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  kotTotal: {
    fontFamily: font.bold,
    fontSize: 14,
    color: colors.ink,
  },
  sheetBtns: {
    flexDirection: 'row',
    gap: spacing.md,
    marginTop: spacing.sm,
  },
  sheetBtn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    height: 50,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
  },
  sheetBtnText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: '#fff',
  },
  sheetBtnGhost: {
    backgroundColor: colors.bg,
  },
  sheetBtnGhostText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.inkSoft,
  },
});
