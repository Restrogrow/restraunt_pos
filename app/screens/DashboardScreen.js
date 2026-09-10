import { Ionicons } from '@expo/vector-icons';
import { useCallback } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import Avatar from '../components/Avatar';
import Badge from '../components/Badge';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import StatCard from '../components/StatCard';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';

export default function DashboardScreen() {
  const { user } = useAuth();
  const fetcher = useCallback(() => apiGet('/api/get_dashboard_stats.php'), []);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { pollInterval: 20000 });

  const stats = data?.stats || {};
  const recentOrders = data?.recentOrders || [];
  const currency = user?.currency_symbol || '₹';

  return (
    <View style={styles.fill}>
      <ScreenHeader
        eyebrow="Welcome back"
        title={user?.restaurant_name || 'Your Restaurant'}
        right={<Avatar name={user?.username || user?.restaurant_name} />}
      />

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : (
        <ScrollView
          style={styles.fill}
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.grid}>
            <StatCard
              label="Today's Revenue"
              value={`${currency}${stats.todayRevenue ?? 0}`}
              icon="cash-outline"
              tint={colors.success}
              tintBg={colors.successBg}
            />
            <StatCard
              label="Today's Orders"
              value={stats.todayOrders ?? 0}
              icon="receipt-outline"
              tint={colors.info}
              tintBg={colors.infoBg}
            />
            <StatCard
              label="Pending Orders"
              value={stats.pendingOrders ?? 0}
              icon="time-outline"
              tint={colors.warning}
              tintBg={colors.warningBg}
            />
            <StatCard
              label="Menu Items"
              value={stats.totalItems ?? 0}
              icon="restaurant-outline"
              tint={colors.purple}
              tintBg={colors.purpleBg}
            />
          </View>

          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle}>Recent Orders</Text>
          </View>

          {recentOrders.length === 0 ? (
            <EmptyState
              icon="receipt-outline"
              title="No recent orders"
              subtitle="New orders will show up here as they come in"
            />
          ) : (
            <View style={styles.list}>
              {recentOrders.map((order, i) => (
                <View key={order.id ?? i} style={[styles.orderCard, shadow.sm]}>
                  <View style={styles.orderIconWrap}>
                    <Ionicons name="fast-food-outline" size={17} color={colors.primary} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.orderNumber}>{order.order_number || `#${order.id}`}</Text>
                    <Text style={styles.orderMeta}>{order.customer_name || 'Walk-in customer'}</Text>
                  </View>
                  <Badge label={order.order_status} />
                </View>
              ))}
            </View>
          )}
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  content: {
    padding: spacing.xl,
    paddingBottom: 120,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
    marginTop: -spacing.xl,
    marginBottom: spacing.lg,
  },
  sectionHead: {
    marginTop: spacing.md,
    marginBottom: spacing.md,
  },
  sectionTitle: {
    fontFamily: font.semiBold,
    fontSize: 17,
    color: colors.ink,
  },
  list: {
    gap: spacing.sm,
  },
  orderCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.md,
  },
  orderIconWrap: {
    width: 38,
    height: 38,
    borderRadius: 12,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  orderNumber: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  orderMeta: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 2,
  },
});
