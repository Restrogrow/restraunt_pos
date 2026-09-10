import { Ionicons } from '@expo/vector-icons';
import { useCallback } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import StatCard from '../components/StatCard';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';

export default function ReportsScreen() {
  const { user } = useAuth();
  const fetcher = useCallback(() => apiGet('/api/get_sales_report.php?period=today'), []);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { pollInterval: 30000 });
  const currency = user?.currency_symbol || '₹';

  const summary = data?.summary || {};
  const topItems = data?.top_items || [];

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Performance" title="Today's Report" />

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
              label="Total Sales"
              value={`${currency}${summary.total_sales ?? 0}`}
              icon="trending-up-outline"
              tint={colors.success}
              tintBg={colors.successBg}
            />
            <StatCard
              label="Orders"
              value={summary.total_orders ?? 0}
              icon="receipt-outline"
              tint={colors.info}
              tintBg={colors.infoBg}
            />
            <StatCard
              label="Net Profit"
              value={`${currency}${summary.net_profit ?? 0}`}
              icon="wallet-outline"
              tint={colors.teal}
              tintBg={colors.tealBg}
            />
            <StatCard
              label="Customers"
              value={summary.total_customers ?? 0}
              icon="people-outline"
              tint={colors.purple}
              tintBg={colors.purpleBg}
            />
          </View>

          <View style={styles.sectionHead}>
            <Text style={styles.sectionTitle}>Top Items</Text>
          </View>

          {topItems.length === 0 ? (
            <EmptyState icon="podium-outline" title="No sales yet" subtitle="Best sellers will show up here" />
          ) : (
            <View style={styles.list}>
              {topItems.map((item, i) => (
                <View key={item.id ?? i} style={[styles.itemRow, shadow.sm]}>
                  <View style={styles.rankWrap}>
                    <Text style={styles.rank}>{i + 1}</Text>
                  </View>
                  <Text style={styles.itemName} numberOfLines={1}>{item.item_name_en || item.name}</Text>
                  <View style={styles.soldChip}>
                    <Ionicons name="flame" size={12} color={colors.primary} />
                    <Text style={styles.soldText}>{item.total_quantity ?? item.quantity_sold ?? 0} sold</Text>
                  </View>
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
  itemRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.md,
  },
  rankWrap: {
    width: 28,
    height: 28,
    borderRadius: 10,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rank: {
    fontFamily: font.bold,
    fontSize: 12.5,
    color: colors.primary,
  },
  itemName: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  soldChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: colors.bg,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
  soldText: {
    fontFamily: font.medium,
    fontSize: 11,
    color: colors.inkSoft,
  },
});
