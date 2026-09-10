import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import Badge from '../components/Badge';
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

export default function OrdersScreen() {
  const { user } = useAuth();
  const fetcher = useCallback(() => apiGet('/api/get_orders.php?limit=50'), []);
  const { data, loading, refreshing, error, refresh, reload } = useApiData(fetcher, { pollInterval: 10000 });
  const [updatingId, setUpdatingId] = useState(null);
  const currency = user?.currency_symbol || '₹';

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

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Live" title="Orders" />

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
              <Text style={styles.count}>{orders.length} order{orders.length === 1 ? '' : 's'} today</Text>
            ) : null
          }
          ListEmptyComponent={
            <EmptyState icon="receipt-outline" title="No orders today" subtitle="New orders will appear here" />
          }
          contentContainerStyle={[styles.content, orders.length === 0 && styles.emptyContainer]}
          showsVerticalScrollIndicator={false}
          renderItem={({ item }) => {
            const next = NEXT_STATUS[item.order_status];
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
                      <Text style={styles.doneText}>Done</Text>
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
  content: {
    padding: spacing.xl,
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
