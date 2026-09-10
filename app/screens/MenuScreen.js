import { Ionicons } from '@expo/vector-icons';
import { useCallback } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';

export default function MenuScreen() {
  const { user } = useAuth();
  const fetcher = useCallback(() => apiGet('/api/get_menu_items.php?limit=200'), []);
  const { data, loading, refreshing, error, refresh } = useApiData(fetcher, { pollInterval: 30000 });
  const currency = user?.currency_symbol || '₹';

  const items = data?.data || [];

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Your catalogue" title="Menu" />

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : (
        <FlatList
          style={styles.fill}
          data={items}
          keyExtractor={(item, i) => String(item.id ?? i)}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
          ListHeaderComponent={
            items.length > 0 ? (
              <Text style={styles.count}>{items.length} item{items.length === 1 ? '' : 's'}</Text>
            ) : null
          }
          ListEmptyComponent={
            <EmptyState
              icon="restaurant-outline"
              title="No menu items yet"
              subtitle="Items you add will appear here"
            />
          }
          contentContainerStyle={[
            styles.content,
            items.length === 0 && styles.emptyContainer,
          ]}
          showsVerticalScrollIndicator={false}
          renderItem={({ item }) => {
            const available = item.is_available == 1;
            return (
              <View style={[styles.card, shadow.sm]}>
                <View style={styles.thumb}>
                  <Ionicons name="fast-food-outline" size={20} color={colors.primary} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>{item.item_name_en}</Text>
                  {item.item_category ? (
                    <View style={styles.categoryChip}>
                      <Text style={styles.categoryText}>{item.item_category}</Text>
                    </View>
                  ) : null}
                </View>
                <View style={styles.trailing}>
                  <Text style={styles.price}>{currency}{item.base_price}</Text>
                  <View style={styles.availabilityRow}>
                    <View
                      style={[
                        styles.dot,
                        { backgroundColor: available ? colors.success : colors.danger },
                      ]}
                    />
                    <Text
                      style={[
                        styles.availabilityText,
                        { color: available ? colors.success : colors.danger },
                      ]}
                    >
                      {available ? 'Available' : 'Off'}
                    </Text>
                  </View>
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
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.md,
  },
  thumb: {
    width: 46,
    height: 46,
    borderRadius: 14,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  name: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  categoryChip: {
    alignSelf: 'flex-start',
    backgroundColor: colors.bg,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 3,
    marginTop: 5,
  },
  categoryText: {
    fontFamily: font.medium,
    fontSize: 11,
    color: colors.inkSoft,
  },
  trailing: {
    alignItems: 'flex-end',
  },
  price: {
    fontFamily: font.bold,
    fontSize: 14.5,
    color: colors.ink,
  },
  availabilityRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    marginTop: 6,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  availabilityText: {
    fontFamily: font.medium,
    fontSize: 11,
  },
});
