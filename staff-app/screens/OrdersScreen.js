import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FlatList, LayoutAnimation, Platform, RefreshControl, ScrollView, StyleSheet, Text, UIManager, View } from 'react-native';
import DatePickerModal from '../components/DatePickerModal';
import OrderCard from '../components/OrderCard';
import OrderTypeToggles from '../components/OrderTypeToggles';
import ScreenHeader from '../components/ScreenHeader';
import SearchField from '../components/SearchField';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { haptic } from '../utils/haptics';

if (Platform.OS === 'android' && UIManager.setLayoutAnimationEnabledExperimental) {
  UIManager.setLayoutAnimationEnabledExperimental(true);
}

// Live tabs come from one request (every open order, whatever day it was placed); Done is
// looked up by day; Deleted is the audit view.
const TABS = [
  { key: 'new', label: 'New', icon: 'notifications-outline', statuses: ['Pending', 'Scheduled'], emptyTitle: 'No new orders', emptySubtitle: 'New website orders appear here to accept or reject. You will hear a ring.' },
  { key: 'cooking', label: 'Cooking', icon: 'flame-outline', statuses: ['Accepted', 'Preparing'], emptyTitle: 'Nothing cooking', emptySubtitle: 'Accepted orders show here while the kitchen makes them.' },
  { key: 'ready', label: 'Ready', icon: 'checkmark-done-outline', statuses: ['Ready', 'Served'], emptyTitle: 'Nothing ready yet', emptySubtitle: 'Orders ready to serve or hand over show here.' },
  { key: 'done', label: 'Done', icon: 'archive-outline', statuses: ['Completed', 'Cancelled', 'Rejected'], byDate: true, emptyTitle: 'No finished orders', emptySubtitle: 'Completed, cancelled and rejected orders for this day show here.' },
  { key: 'deleted', label: 'Deleted', icon: 'trash-outline', deletedOnly: true, emptyTitle: 'No deleted orders', emptySubtitle: 'Orders deleted by the owner or manager are kept here for record.' },
];
const LIVE_STATUSES = ['Pending', 'Scheduled', 'Accepted', 'Preparing', 'Ready', 'Served'];

const CAN_DELETE_ROLES = ['admin', 'administrator', 'manager', 'branch admin'];
const canDeleteRole = (role) => CAN_DELETE_ROLES.includes(String(role || '').toLowerCase());

function toDateKey(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}
function addDays(date, days) {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  return next;
}
const animate = () => LayoutAnimation.configureNext(LayoutAnimation.create(220, 'easeInEaseOut', 'opacity'));

export default function OrdersScreen({ navigation, route }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [activeTab, setActiveTab] = useState(route?.params?.tab || 'new');
  const autoPicked = useRef(!!route?.params?.tab);
  const [selectedDate, setSelectedDate] = useState(() => new Date());
  const [pickerVisible, setPickerVisible] = useState(false);
  const [pickerViewDate, setPickerViewDate] = useState(null);
  const [updatingId, setUpdatingId] = useState(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [selecting, setSelecting] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const canDelete = canDeleteRole(user?.role);

  // Home's "needs attention" can send us straight to a tab.
  useEffect(() => {
    if (route?.params?.tab) {
      setActiveTab(route.params.tab);
      autoPicked.current = true;
    }
  }, [route?.params?.tab]);

  const tab = TABS.find((t) => t.key === activeTab) || TABS[0];
  const todayKey = toDateKey(new Date());
  const dateKey = toDateKey(selectedDate);
  const isToday = dateKey === todayKey;

  // ── live (New / Cooking / Ready): one shared list ──
  const liveFetcher = useCallback(() => apiGet(`/api/get_orders.php?limit=100&active=1&date=${toDateKey(new Date())}`), []);
  const live = useApiData(liveFetcher, { pollInterval: 10000, cacheKey: 'orders:live', refetchOnFocus: true });

  // ── Done (by day) / Deleted ──
  const otherKey = tab.deletedOnly ? 'orders:deleted' : tab.byDate ? `orders:day:${dateKey}` : null;
  const otherFetcher = useCallback(() => {
    if (tab.deletedOnly) return apiGet('/api/get_orders.php?limit=100&include_deleted=1');
    if (tab.byDate) return apiGet(`/api/get_orders.php?limit=100&date=${dateKey}`);
    return Promise.resolve(null);
  }, [tab.deletedOnly, tab.byDate, dateKey]);
  const other = useApiData(otherFetcher, { pollInterval: tab.byDate && isToday ? 20000 : 0, cacheKey: otherKey, refetchOnFocus: true });

  // ── search (all days) ──
  useEffect(() => {
    const id = setTimeout(() => setDebounced(query.trim()), 350);
    return () => clearTimeout(id);
  }, [query]);
  const searchFetcher = useCallback(
    () => (debounced.length >= 2 ? apiGet(`/api/get_orders.php?limit=50&any_date=1&search=${encodeURIComponent(debounced)}`) : Promise.resolve(null)),
    [debounced]
  );
  const search = useApiData(searchFetcher, { cacheKey: debounced.length >= 2 ? `orders:search:${debounced}` : null });

  const liveOrders = useMemo(() => (live.data?.orders || []).filter((o) => LIVE_STATUSES.includes(o.order_status)), [live.data]);
  const counts = useMemo(() => {
    const c = { new: 0, cooking: 0, ready: 0 };
    liveOrders.forEach((o) => {
      const t = TABS.find((x) => x.statuses?.includes(o.order_status));
      if (t && c[t.key] !== undefined) c[t.key] += 1;
    });
    return c;
  }, [liveOrders]);

  // Open on the tab that has work: New if anything is waiting, else Cooking, else Ready.
  useEffect(() => {
    if (autoPicked.current || !live.data) return;
    autoPicked.current = true;
    setActiveTab(counts.new ? 'new' : counts.cooking ? 'cooking' : counts.ready ? 'ready' : 'new');
  }, [live.data, counts]);

  const source = tab.deletedOnly || tab.byDate ? other : live;
  const visible = useMemo(() => {
    if (tab.deletedOnly) return other.data?.orders || [];
    if (tab.byDate) return (other.data?.orders || []).filter((o) => tab.statuses.includes(o.order_status));
    return liveOrders.filter((o) => tab.statuses.includes(o.order_status));
  }, [tab, other.data, liveOrders]);

  useEffect(() => {
    setSelecting(false);
    setSelectedIds(new Set());
  }, [activeTab, dateKey]);

  const openOrder = useCallback((order) => navigation.navigate('OrderDetail', { order }), [navigation]);

  const advance = useCallback(
    async (order, to) => {
      setUpdatingId(order.id);
      try {
        const res = await apiPostForm('/api/update_order_status.php', { orderId: order.id, status: to });
        if (!res.success) throw new Error(res.message || 'Update failed');
        animate();
        live.setData((prev) => (prev ? { ...prev, orders: (prev.orders || []).map((o) => (o.id === order.id ? { ...o, order_status: to } : o)) } : prev));
        feedback.success(`${order.order_number} is ${to.toLowerCase()}`);
        live.reload('background');
      } catch (e) {
        feedback.errorFrom(e, 'Could not update the order');
        live.reload('background');
      } finally {
        setUpdatingId(null);
      }
    },
    [live]
  );

  const deleteOne = useCallback(
    async (order) => {
      const ok = await feedback.confirm({
        title: `Delete ${order.order_number}?`,
        message: 'It disappears from orders and reports. A copy stays under Deleted for your records.',
        confirmText: 'Delete',
        danger: true,
      });
      if (!ok) return;
      try {
        const res = await apiPostForm('/api/delete_order.php', { orderId: order.id, reason: 'Deleted from app' });
        if (!res.success) throw new Error(res.message || 'Delete failed');
        animate();
        const drop = (prev) => (prev ? { ...prev, orders: (prev.orders || []).filter((o) => o.id !== order.id) } : prev);
        live.setData(drop);
        other.setData(drop);
        feedback.success('Order deleted');
      } catch (e) {
        feedback.errorFrom(e, 'Could not delete the order');
      }
    },
    [live, other]
  );

  const deletableOrder = (o) => canDelete && !tab.deletedOnly && o.payment_status !== 'Paid' && o.order_status !== 'Completed';

  const bulkDelete = async () => {
    const ids = [...selectedIds];
    if (!ids.length) return;
    const ok = await feedback.confirm({
      title: `Delete ${ids.length} order${ids.length > 1 ? 's' : ''}?`,
      message: 'Paid and completed orders are skipped. Deleted orders stay under Deleted for your records.',
      confirmText: `Delete ${ids.length}`,
      danger: true,
    });
    if (!ok) return;
    let done = 0;
    let failed = 0;
    for (let i = 0; i < ids.length; i += 5) {
      const results = await Promise.allSettled(ids.slice(i, i + 5).map((id) => apiPostForm('/api/delete_order.php', { orderId: id, reason: 'Bulk delete via app' })));
      results.forEach((r) => (r.status === 'fulfilled' && r.value?.success ? (done += 1) : (failed += 1)));
    }
    setSelecting(false);
    setSelectedIds(new Set());
    animate();
    if (failed) feedback.info(`${done} deleted, ${failed} skipped (paid or completed orders are protected)`);
    else feedback.success(`${done} order${done > 1 ? 's' : ''} deleted`);
    live.reload('background');
    other.reload('background');
  };

  const toggleSelect = (id) => {
    haptic.select();
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const renderOrder = ({ item, index }) => {
    const deletable = deletableOrder(item);
    return (
      <FadeIn index={index}>
        <OrderCard
          order={item}
          currency={currency}
          busy={updatingId === item.id}
          deleted={tab.deletedOnly}
          showStatus={tab.byDate || tab.deletedOnly || searchOpen || item.order_status === 'Scheduled' || item.order_status === 'Accepted' || item.order_status === 'Served'}
          selecting={selecting && deletable}
          selected={selectedIds.has(item.id)}
          onPress={() => (selecting ? deletable && toggleSelect(item.id) : openOrder(item))}
          onLongPress={deletable && !selecting ? () => { haptic.press(); setSelecting(true); setSelectedIds(new Set([item.id])); } : undefined}
          onAction={advance}
          onDelete={deletable && !selecting ? () => deleteOne(item) : undefined}
        />
      </FadeIn>
    );
  };

  const listPad = { paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.md, flexGrow: 1 };

  const header = (
    <ScreenHeader
      eyebrow={searchOpen ? 'Search all days' : 'Live'}
      title="Orders"
      right={
        <View style={styles.headerRight}>
          {!searchOpen ? <OrderTypeToggles /> : null}
          <PressableScale
            style={styles.headerBtn}
            onPress={() => {
              setSearchOpen((s) => !s);
              setQuery('');
            }}
            accessibilityLabel={searchOpen ? 'Close search' : 'Search orders'}
          >
            <Ionicons name={searchOpen ? 'close' : 'search'} size={19} color="#fff" />
          </PressableScale>
        </View>
      }
    >
      {searchOpen ? (
        <SearchField style={{ marginTop: spacing.md }} value={query} onChangeText={setQuery} placeholder="Order no., name, phone or table" autoFocus />
      ) : null}
    </ScreenHeader>
  );

  if (searchOpen) {
    const results = search.data?.orders || [];
    return (
      <View style={styles.fill}>
        {header}
        {debounced.length < 2 ? (
          <EmptyState icon="search-outline" title="Find any order" subtitle="Type at least 2 letters or digits of the order number, customer name, phone or table." />
        ) : search.loading ? (
          <LoadingState gutter={gutter} />
        ) : (
          <FlatList
            data={results}
            keyExtractor={(o) => String(o.id)}
            renderItem={renderOrder}
            contentContainerStyle={listPad}
            keyboardShouldPersistTaps="handled"
            ListEmptyComponent={<EmptyState icon="search-outline" title="No matching orders" subtitle={`Nothing found for "${debounced}".`} />}
          />
        )}
      </View>
    );
  }

  return (
    <View style={styles.fill}>
      {header}

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.tabScroll} contentContainerStyle={[styles.tabRow, { paddingHorizontal: gutter }]}>
        {TABS.filter((t) => !t.deletedOnly || canDelete).map((t) => {
          const on = t.key === activeTab;
          const n = counts[t.key] || 0;
          return (
            <PressableScale key={t.key} feedback="select" style={[styles.tab, shadow.sm, on && styles.tabOn]} onPress={() => setActiveTab(t.key)}>
              <Ionicons name={t.icon} size={15} color={on ? '#fff' : colors.inkSoft} />
              <Text style={[styles.tabText, on && styles.tabTextOn]}>{t.label}</Text>
              {n > 0 ? (
                <View style={[styles.count, on && styles.countOn, t.key === 'new' && !on && styles.countHot]}>
                  <Text style={[styles.countText, (on || t.key === 'new') && { color: on ? colors.primary : '#fff' }]}>{n}</Text>
                </View>
              ) : null}
            </PressableScale>
          );
        })}
      </ScrollView>

      {tab.byDate ? (
        <View style={[styles.dateNav, { marginHorizontal: gutter }]}>
          <PressableScale style={styles.dateBtn} onPress={() => setSelectedDate((d) => addDays(d, -1))} hitSlop={8} accessibilityLabel="Previous day">
            <Ionicons name="chevron-back" size={18} color={colors.ink} />
          </PressableScale>
          <PressableScale
            style={styles.dateCenter}
            onPress={() => {
              setPickerViewDate(selectedDate);
              setPickerVisible(true);
            }}
          >
            <Text style={styles.dateLabel}>
              {isToday ? 'Today' : dateKey === toDateKey(addDays(new Date(), -1)) ? 'Yesterday' : selectedDate.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}
            </Text>
            <Ionicons name="calendar-outline" size={14} color={colors.muted} />
          </PressableScale>
          <PressableScale
            style={styles.dateBtn}
            onPress={() => setSelectedDate((d) => (isToday ? d : addDays(d, 1)))}
            disabled={isToday}
            hitSlop={8}
            accessibilityLabel="Next day"
          >
            <Ionicons name="chevron-forward" size={18} color={colors.ink} />
          </PressableScale>
        </View>
      ) : null}

      {selecting ? (
        <View style={[styles.selectBar, { marginHorizontal: gutter }]}>
          <Text style={styles.selectText}>{selectedIds.size} selected</Text>
          <PressableScale style={styles.selectGhost} onPress={() => setSelectedIds(new Set(visible.filter(deletableOrder).map((o) => o.id)))}>
            <Text style={styles.selectGhostText}>All</Text>
          </PressableScale>
          <PressableScale style={styles.selectGhost} onPress={() => { setSelecting(false); setSelectedIds(new Set()); }}>
            <Text style={styles.selectGhostText}>Cancel</Text>
          </PressableScale>
          <PressableScale style={styles.selectDelete} onPress={bulkDelete} disabled={!selectedIds.size} feedback="warning">
            <Ionicons name="trash-outline" size={14} color="#fff" />
            <Text style={styles.selectDeleteText}>Delete</Text>
          </PressableScale>
        </View>
      ) : null}

      {source.loading && !source.data ? (
        <LoadingState gutter={gutter} />
      ) : source.error && !source.data ? (
        <ErrorState message={source.error} onRetry={source.refresh} />
      ) : (
        <FlatList
          data={visible}
          keyExtractor={(o) => String(o.id)}
          renderItem={renderOrder}
          contentContainerStyle={listPad}
          refreshControl={<RefreshControl refreshing={source.refreshing} onRefresh={source.refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={<EmptyState icon={tab.icon} title={tab.emptyTitle} subtitle={tab.emptySubtitle} />}
          initialNumToRender={8}
          windowSize={7}
          removeClippedSubviews={Platform.OS === 'android'}
        />
      )}

      <DatePickerModal
        visible={pickerVisible}
        viewDate={pickerViewDate}
        selectedDate={selectedDate}
        onChangeMonth={(delta) =>
          setPickerViewDate((d) => {
            const next = new Date(d || selectedDate);
            next.setDate(1);
            next.setMonth(next.getMonth() + delta);
            return next;
          })
        }
        onSelectDay={(day) => {
          setSelectedDate(day);
          setPickerVisible(false);
        }}
        onClose={() => setPickerVisible(false)}
        maxDate={new Date()}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  headerRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  headerBtn: {
    width: 38,
    height: 38,
    borderRadius: 13,
    backgroundColor: 'rgba(255,255,255,0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  tabScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 48,
    marginTop: -spacing.lg,
  },
  tabRow: {
    gap: spacing.sm,
    paddingVertical: 4,
  },
  tab: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 40,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
  },
  tabOn: {
    backgroundColor: colors.ink,
  },
  tabText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.inkSoft,
  },
  tabTextOn: {
    color: '#fff',
  },
  count: {
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    paddingHorizontal: 5,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.border,
  },
  countOn: {
    backgroundColor: '#fff',
  },
  countHot: {
    backgroundColor: colors.danger,
  },
  countText: {
    fontFamily: font.bold,
    fontSize: 11,
    color: colors.inkSoft,
  },
  dateNav: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: 6,
    marginTop: spacing.md,
  },
  dateBtn: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  dateCenter: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: spacing.lg,
    paddingVertical: 8,
  },
  dateLabel: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  selectBar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: 8,
    paddingLeft: spacing.lg,
    marginTop: spacing.md,
    ...shadow.sm,
  },
  selectText: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  selectGhost: {
    height: 36,
    paddingHorizontal: 12,
    borderRadius: 11,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  selectGhostText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  selectDelete: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    height: 36,
    paddingHorizontal: 14,
    borderRadius: 11,
    backgroundColor: colors.danger,
  },
  selectDeleteText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: '#fff',
  },
});
