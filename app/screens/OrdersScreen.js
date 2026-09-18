import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import DatePickerModal from '../components/DatePickerModal';
import OrderTypeToggles from '../components/OrderTypeToggles';
import ScreenHeader from '../components/ScreenHeader';
import { ErrorState, LoadingState } from '../components/ScreenState';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing, statusStyles } from '../theme';

const NEXT_STATUS = {
  Pending: 'Accepted',
  Accepted: 'Preparing',
  Preparing: 'Ready',
  Ready: 'Served',
  Served: 'Completed',
};

// Three tabs instead of a horizontal Kanban board — mirrors the simple
// Preparing / Ready / Picked-up flow of a delivery-partner app (Swiggy's
// restaurant app), adapted to this app's own richer status set.
const TABS = [
  {
    key: 'preparing',
    label: 'Preparing',
    statuses: ['Scheduled', 'Pending', 'Accepted', 'Preparing'],
    emptyTitle: 'No Orders!',
    emptySubtitle: 'Orders that are getting prepared will be shown here',
  },
  {
    key: 'ready',
    label: 'Ready',
    statuses: ['Ready', 'Served'],
    emptyTitle: 'Nothing ready yet',
    emptySubtitle: 'Orders ready to serve or hand over will be shown here',
  },
  {
    key: 'completed',
    label: 'Completed',
    statuses: ['Completed', 'Cancelled', 'Rejected'],
    emptyTitle: 'No completed orders',
    emptySubtitle: 'Finished, cancelled or rejected orders will be shown here',
  },
];

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

function formatDisplayDate(date, isToday, isYesterday) {
  if (isToday) return 'Today';
  if (isYesterday) return 'Yesterday';
  return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}

export default function OrdersScreen({ navigation }) {
  const { user } = useAuth();
  const [selectedDate, setSelectedDate] = useState(() => new Date());
  const [activeTab, setActiveTab] = useState('preparing');
  const currency = user?.currency_symbol || '₹';
  // The floating tab bar overlays screen content instead of react-navigation
  // reserving docked space for it, so list items would otherwise render (and
  // be un-tappable/un-scrollable-into) underneath it once there are enough
  // orders to reach that strip — most visible on tabs that accumulate more
  // orders through the day, like Completed.
  const tabBarHeight = useBottomTabBarHeight();

  const todayKey = toDateKey(new Date());
  const dateKey = toDateKey(selectedDate);
  const isToday = dateKey === todayKey;
  const isYesterday = dateKey === toDateKey(addDays(new Date(), -1));
  const isFutureBlocked = dateKey > todayKey;

  const fetcher = useCallback(
    () => apiGet('/api/get_orders.php?limit=50&date=' + encodeURIComponent(dateKey)),
    [dateKey]
  );
  // Only poll when looking at today — a past day's orders won't change.
  const { data, loading, refreshing, error, refresh, reload, setData } = useApiData(fetcher, {
    pollInterval: isToday ? 10000 : 0,
  });
  const [updatingId, setUpdatingId] = useState(null);
  const [pickerVisible, setPickerVisible] = useState(false);
  const [pickerViewDate, setPickerViewDate] = useState(null);

  const goToPreviousDay = () => setSelectedDate((d) => addDays(d, -1));
  const goToNextDay = () => setSelectedDate((d) => (isFutureBlocked ? d : addDays(d, 1)));
  const goToToday = () => setSelectedDate(new Date());

  const openPicker = () => {
    setPickerViewDate(selectedDate);
    setPickerVisible(true);
  };
  const changePickerMonth = (delta) => {
    setPickerViewDate((d) => {
      const next = new Date(d || selectedDate);
      next.setDate(1);
      next.setMonth(next.getMonth() + delta);
      return next;
    });
  };
  const selectPickerDay = (day) => {
    setSelectedDate(day);
    setPickerVisible(false);
  };

  const advanceOrder = async (order) => {
    const next = NEXT_STATUS[order.order_status];
    if (!next) return;
    setUpdatingId(order.id);
    try {
      const res = await apiPostForm('/api/update_order_status.php', {
        orderId: order.id,
        status: next,
      });
      if (!res.success) throw new Error(res.message || 'Update failed');
      // Move the card to its next tab immediately instead of waiting on a
      // refetch — then reconcile with the server silently in the background.
      setData((prev) => (prev ? { ...prev, orders: (prev.orders || []).map((o) => (o.id === order.id ? { ...o, order_status: next } : o)) } : prev));
      reload('background');
    } catch (e) {
      Alert.alert('Could not update order', e.message);
    } finally {
      setUpdatingId(null);
    }
  };

  const orders = data?.orders || [];

  const tabCounts = useMemo(() => {
    const counts = {};
    TABS.forEach((t) => { counts[t.key] = 0; });
    orders.forEach((o) => {
      const tab = TABS.find((t) => t.statuses.includes(o.order_status));
      if (tab) counts[tab.key] += 1;
    });
    return counts;
  }, [orders]);

  const currentTab = TABS.find((t) => t.key === activeTab) || TABS[0];
  const visibleOrders = useMemo(
    () => orders.filter((o) => currentTab.statuses.includes(o.order_status)),
    [orders, currentTab]
  );

  const dateLabel = useMemo(
    () => formatDisplayDate(selectedDate, isToday, isYesterday),
    [selectedDate, isToday, isYesterday]
  );

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Live" title="Orders" right={<OrderTypeToggles />} />

      <View style={styles.dateNav}>
        <Pressable style={styles.dateNavButton} onPress={goToPreviousDay} hitSlop={8}>
          <Ionicons name="chevron-back" size={18} color={colors.ink} />
        </Pressable>

        <Pressable style={styles.dateNavCenter} onPress={openPicker} hitSlop={6}>
          <View style={styles.dateNavLabelRow}>
            <Text style={styles.dateNavLabel}>{dateLabel}</Text>
            <Ionicons name="calendar-outline" size={14} color={colors.muted} style={{ marginLeft: 6 }} />
          </View>
          {!isToday ? (
            <Pressable onPress={goToToday} hitSlop={6}>
              <Text style={styles.todayLink}>Jump to Today</Text>
            </Pressable>
          ) : null}
        </Pressable>

        <Pressable
          style={[styles.dateNavButton, isFutureBlocked && styles.dateNavButtonDisabled]}
          onPress={goToNextDay}
          disabled={isFutureBlocked}
          hitSlop={8}
        >
          <Ionicons name="chevron-forward" size={18} color={isFutureBlocked ? colors.border : colors.ink} />
        </Pressable>
      </View>

      <DatePickerModal
        visible={pickerVisible}
        viewDate={pickerViewDate}
        selectedDate={selectedDate}
        onChangeMonth={changePickerMonth}
        onSelectDay={selectPickerDay}
        onClose={() => setPickerVisible(false)}
        maxDate={new Date()}
      />

      <View style={styles.tabRow}>
        {TABS.map((tab) => {
          const selected = tab.key === activeTab;
          return (
            <Pressable key={tab.key} style={styles.tab} onPress={() => setActiveTab(tab.key)}>
              <View style={styles.tabLabelRow}>
                <Text style={[styles.tabText, selected && styles.tabTextActive]}>{tab.label}</Text>
                {tabCounts[tab.key] > 0 ? (
                  <View style={[styles.tabBadge, selected && styles.tabBadgeActive]}>
                    <Text style={[styles.tabBadgeText, selected && styles.tabBadgeTextActive]}>{tabCounts[tab.key]}</Text>
                  </View>
                ) : null}
              </View>
              <View style={[styles.tabUnderline, selected && styles.tabUnderlineActive]} />
            </Pressable>
          );
        })}
      </View>

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : visibleOrders.length === 0 ? (
        <ScrollView
          contentContainerStyle={[styles.emptyScroll, { paddingBottom: tabBarHeight + spacing.xxl }]}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
        >
          <View style={styles.emptyIllustration}>
            <Ionicons name="restaurant-outline" size={48} color={colors.muted} />
          </View>
          <Text style={styles.emptyTitle}>{currentTab.emptyTitle}</Text>
          <Text style={styles.emptySubtitle}>{currentTab.emptySubtitle}</Text>
        </ScrollView>
      ) : (
        <ScrollView
          style={styles.fill}
          contentContainerStyle={[styles.list, { paddingBottom: tabBarHeight + spacing.xxl }]}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
        >
          {visibleOrders.map((item) => {
            const next = isToday ? NEXT_STATUS[item.order_status] : null;
            const statusStyle = statusStyles[item.order_status] || {};
            return (
              <Pressable
                key={item.id}
                style={({ pressed }) => [styles.card, shadow.sm, pressed && { opacity: 0.97 }]}
                onPress={() => navigation.navigate('OrderDetail', { order: item })}
              >
                <View style={styles.cardTop}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.orderNumber} numberOfLines={1}>{item.order_number || `#${item.id}`}</Text>
                    <Text style={styles.meta} numberOfLines={1}>
                      {item.customer_name || 'Walk-in'} · {item.order_type}
                    </Text>
                  </View>
                  <View style={[styles.statusChip, { backgroundColor: statusStyle.bg || colors.border }]}>
                    <Text style={[styles.statusChipText, { color: statusStyle.fg || colors.inkSoft }]}>
                      {item.order_status}
                    </Text>
                  </View>
                </View>

                <View style={styles.cardBottom}>
                  <Text style={styles.total}>{currency}{item.total ?? 0}</Text>
                  {next ? (
                    <Pressable
                      style={({ pressed }) => [styles.actionButton, pressed && { opacity: 0.9 }]}
                      disabled={updatingId === item.id}
                      onPress={() => advanceOrder(item)}
                    >
                      {updatingId === item.id ? (
                        <ActivityIndicator color="#fff" size="small" />
                      ) : (
                        <>
                          <Text style={styles.actionButtonText}>Mark {next}</Text>
                          <Ionicons name="arrow-forward" size={13} color="#fff" />
                        </>
                      )}
                    </Pressable>
                  ) : (
                    <View style={styles.doneChip}>
                      <Ionicons name="checkmark-circle" size={13} color={colors.success} />
                      <Text style={styles.doneText}>{isToday ? 'Done' : item.order_status}</Text>
                    </View>
                  )}
                </View>
              </Pressable>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  dateNav: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    marginHorizontal: spacing.xl,
    marginTop: -spacing.lg,
    marginBottom: spacing.sm,
    borderRadius: radius.lg,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.sm,
    ...shadow.sm,
  },
  dateNavButton: {
    width: 34,
    height: 34,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  dateNavButtonDisabled: {
    opacity: 0.5,
  },
  dateNavCenter: {
    alignItems: 'center',
  },
  dateNavLabelRow: {
    flexDirection: 'row',
    alignItems: 'center',
  },
  dateNavLabel: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  todayLink: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.primary,
    marginTop: 2,
  },
  // Segmented status tabs — Preparing / Ready / Completed — with an
  // orange underline on the active tab, mirroring the delivery-partner
  // app reference (Preparing / Ready / Picked up).
  tabRow: {
    flexDirection: 'row',
    backgroundColor: colors.surface,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    paddingHorizontal: spacing.md,
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    paddingTop: spacing.sm,
  },
  tabLabelRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingBottom: spacing.sm,
  },
  tabText: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.muted,
  },
  tabTextActive: {
    color: colors.primary,
  },
  tabBadge: {
    backgroundColor: colors.border,
    borderRadius: radius.pill,
    minWidth: 18,
    paddingHorizontal: 5,
    alignItems: 'center',
  },
  tabBadgeActive: {
    backgroundColor: colors.primaryLight,
  },
  tabBadgeText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
    color: colors.inkSoft,
  },
  tabBadgeTextActive: {
    color: colors.primary,
  },
  tabUnderline: {
    height: 3,
    width: '100%',
    borderRadius: 2,
    backgroundColor: 'transparent',
  },
  tabUnderlineActive: {
    backgroundColor: colors.primary,
  },
  // Big centered illustration-style empty state, one per tab.
  emptyScroll: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xxl,
    paddingVertical: spacing.xxl,
  },
  emptyIllustration: {
    width: 120,
    height: 120,
    borderRadius: 60,
    backgroundColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xl,
  },
  emptyTitle: {
    fontFamily: font.bold,
    fontSize: 19,
    color: colors.ink,
    marginBottom: spacing.xs,
    textAlign: 'center',
  },
  emptySubtitle: {
    fontFamily: font.regular,
    fontSize: 13.5,
    color: colors.muted,
    textAlign: 'center',
    lineHeight: 19,
  },
  list: {
    padding: spacing.xl,
    paddingTop: spacing.lg,
    gap: spacing.md,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.sm,
  },
  cardTop: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: spacing.sm,
  },
  orderNumber: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 2,
  },
  statusChip: {
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  statusChipText: {
    fontFamily: font.semiBold,
    fontSize: 11,
  },
  cardBottom: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingTop: spacing.xs,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  total: {
    fontFamily: font.bold,
    fontSize: 15,
    color: colors.ink,
  },
  actionButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.primary,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: 8,
  },
  actionButtonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 12,
  },
  doneChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
  },
  doneText: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.success,
  },
});
