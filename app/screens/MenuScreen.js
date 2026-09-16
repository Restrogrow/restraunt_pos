import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useEffect } from 'react';
import { FlatList, Image, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { apiGet, imageUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, shadow, spacing } from '../theme';
import { setMenuChangeListener } from '../utils/menuChangeBus';

export default function MenuScreen({ navigation }) {
  const { user } = useAuth();
  const fetcher = useCallback(() => apiGet('/api/get_menu_items.php?limit=200'), []);
  const { data, loading, refreshing, error, refresh, reload, setData } = useApiData(fetcher, { pollInterval: 30000 });
  const currency = user?.currency_symbol || '₹';

  const items = data?.data || [];

  // Reconciles with the server after the detail screen adds/edits/deletes
  // an item — silent ('background' mode touches neither the refresh spinner
  // nor the error state), so returning to this tab never flashes a manual
  // pull-to-refresh indicator. The detail screen also splices its change
  // into `data` immediately via onChange below, so the list is already
  // correct the instant you come back; this just quietly confirms it.
  useFocusEffect(
    useCallback(() => {
      reload('background');
    }, [reload])
  );

  // Optimistic local update so Save/Delete reflect in the list instantly,
  // with no visible network round-trip.
  const applyChange = useCallback((type, payload) => {
    setData((prev) => {
      const list = prev?.data || [];
      let nextList;
      if (type === 'delete') {
        nextList = list.filter((it) => String(it.id) !== String(payload.id));
      } else if (type === 'update') {
        nextList = list.map((it) => (String(it.id) === String(payload.id) ? { ...it, ...payload } : it));
      } else if (type === 'create') {
        nextList = [payload, ...list];
      } else {
        nextList = list;
      }
      return { ...(prev || {}), data: nextList };
    });
  }, [setData]);

  useEffect(() => {
    setMenuChangeListener(applyChange);
    return () => setMenuChangeListener(null);
  }, [applyChange]);

  return (
    <View style={styles.fill}>
      <ScreenHeader
        eyebrow="Your catalogue"
        title="Menu"
        right={
          <Pressable
            style={({ pressed }) => [styles.addButton, pressed && { opacity: 0.85 }]}
            onPress={() => navigation.navigate('MenuItem', {})}
            hitSlop={8}
          >
            <Ionicons name="add" size={22} color={colors.primary} />
          </Pressable>
        }
      />

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
              subtitle="Tap + to add your first item"
            />
          }
          contentContainerStyle={[
            styles.content,
            items.length === 0 && styles.emptyContainer,
          ]}
          showsVerticalScrollIndicator={false}
          renderItem={({ item }) => {
            const available = item.is_available == 1;
            const thumb = imageUrl(item.item_image);
            return (
              <Pressable
                style={({ pressed }) => [styles.card, shadow.sm, pressed && { opacity: 0.85 }]}
                onPress={() => navigation.navigate('MenuItem', { item })}
              >
                <View style={styles.thumb}>
                  {thumb ? (
                    <Image source={{ uri: thumb }} style={styles.thumbImage} />
                  ) : (
                    <Ionicons name="fast-food-outline" size={20} color={colors.primary} />
                  )}
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
                  <Text style={styles.price}>{currency}{item.base_price ?? 0}</Text>
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
                <Ionicons name="chevron-forward" size={18} color={colors.muted} />
              </Pressable>
            );
          }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  addButton: {
    width: 38,
    height: 38,
    borderRadius: 13,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
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
    overflow: 'hidden',
  },
  thumbImage: {
    width: '100%',
    height: '100%',
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
