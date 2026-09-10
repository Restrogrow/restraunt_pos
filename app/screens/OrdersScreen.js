import { Ionicons } from '@expo/vector-icons';
import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import Badge from '../components/Badge';
import DatePickerModal from '../components/DatePickerModal';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';

const NEXT_STATUS = {
  Pending: 'Accepted',
  Accepted: 'Preparing',
  Preparing: 'Ready',
  Ready: 'Served',
  Served: 'Completed',
};

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

export default function OrdersScreen() {
  const { user } = useAuth();
  const [selectedDate, setSelectedDate] = useState(() => new Date());
  const currency = user?.currency_symbol || '₹';

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
  const { data, loading, refreshing, error, refresh, reload } = useApiData(fetcher, {
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
      reload('manual');
    } catch (e) {
      Alert.alert('Could not update order', e.message);
    } finally {
      setUpdatingId(null);
    }
  };

  const orders = data?.orders || [];

  const dateLabel = useMemo(
    () => formatDisplayDate(selectedDate, isToday, isYesterday),
    [selectedDate, isToday, isYesterday]
  );

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Live" title="Orders" />

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

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : (
        <FlatList
          style={styles.fill}
          data={orders}
          keyExtractor={(item, i) => String(item.id ?? i)}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
          ListHeaderComponent={
            orders.length > 0 ? (
              <Text style={styles.count}>{orders.length} order{orders.length === 1 ? '' : 's'}</Text>
            ) : null
          }
          ListEmptyComponent={
            <EmptyState
              icon="receipt-outline"
              title={isToday ? 'No orders today' : 'No orders on this day'}
              subtitle={isToday ? 'New orders will appear here' : 'Try a different date'}
            />
          }
          contentContainerStyle={[styles.content, orders.length === 0 && styles.emptyContainer]}
          showsVerticalScrollIndicator={false}
          renderItem={({ item }) => {
            const next = isToday ? NEXT_STATUS[item.order_status] : null;
            return (
              <View style={[styles.card, shadow.sm]}>
                <View style={styles.cardTop}>
                  <View>
                    <Text style={styles.orderNumber}>{item.order_number || `#${item.id}`}</Text>
                    <Text style={styles.meta}>
                      {item.customer_name || 'Walk-in'} · {item.order_type}
                    </Text>
                  </View>
                  <Badge label={item.order_status} />
                </View>

                <View style={styles.divider} />

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
                          <Ionicons name="arrow-forward" size={14} color="#fff" />
                        </>
                      )}
                    </Pressable>
                  ) : (
                    <View style={styles.doneChip}>
                      <Ionicons name="checkmark-circle" size={14} color={colors.success} />
                      <Text style={styles.doneText}>{isToday ? 'Done' : item.order_status}</Text>
                    </View>
                  )}
                </View>
              </View>
            );
          }}
        />
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
  content: {
    padding: spacing.xl,
    paddingTop: spacing.sm,
    paddingBottom: 120,
    gap: spacing.sm,
  },
  emptyContainer: {
    flexGrow: 1,
    justifyContent: 'center',
  },
  count: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.muted,
    marginBottom: spacing.xs,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  cardTop: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
  },
  orderNumber: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 3,
  },
  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginVertical: spacing.md,
  },
  cardBottom: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  total: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  actionButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: colors.primary,
    borderRadius: radius.pill,
    paddingHorizontal: 14,
    paddingVertical: 9,
  },
  actionButtonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 12.5,
  },
  doneChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
  },
  doneText: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.success,
  },
});
