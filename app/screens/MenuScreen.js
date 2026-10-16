import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useFocusEffect } from '@react-navigation/native';
import { memo, useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, RefreshControl, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import AppImage from '../components/ui/AppImage';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet, apiPostForm, imageUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { money } from '../utils/format';
import { haptic } from '../utils/haptics';
import { setMenuChangeListener } from '../utils/menuChangeBus';

const VEG = { Veg: colors.success, 'Non Veg': colors.danger, Egg: colors.warning };

// Switching a dish in/out of stock: the light set_available action, or — on a server that
// doesn't have it yet — the same full update the dish editor sends.
async function setAvailable(item, on) {
  const res = await apiPostForm('/controllers/menu_items_operations_base64.php', { action: 'set_available', menuItemId: item.id, isAvailable: on ? 1 : 0 });
  if (res?.success) return;
  const fields = {
    action: 'update',
    menuItemId: item.id,
    itemNameEn: item.item_name_en || '',
    chooseMenu: item.menu_id,
    subcategoryId: item.subcategory_id || 0,
    itemDescriptionEn: item.item_description_en || '',
    description_format: item.description_format || 'paragraph',
    itemCategory: item.item_category || '',
    itemType: item.item_type || 'Other',
    preparationTime: item.preparation_time || 0,
    calories: item.calories || 0,
    basePrice: item.base_price || 0,
    isAvailable: on ? 1 : 0,
  };
  if (item.has_variations == 1) {
    fields.hasVariations = 1;
    fields.variations = JSON.stringify((item.variations || []).map((v) => ({ variation_name: v.variation_name, price: v.price || 0 })));
  }
  const res2 = await apiPostForm('/controllers/menu_items_operations_base64.php', fields);
  if (!res2?.success) throw new Error(res2?.message || 'Could not update the dish');
}

const MenuRow = memo(function MenuRow({ item, currency, onOpen, onToggle, busy }) {
  const available = item.is_available == 1;
  const hidden = item.is_hidden == 1;
  return (
    <PressableScale style={[styles.card, shadow.sm, !available && styles.cardOff]} onPress={() => onOpen(item)} scale={0.985}>
      <AppImage uri={imageUrl(item.item_image)} style={styles.thumb} icon="fast-food-outline" iconColor={colors.primary} />
      <View style={{ flex: 1 }}>
        <View style={styles.nameRow}>
          {VEG[item.item_type] ? (
            <View style={[styles.veg, { borderColor: VEG[item.item_type] }]}>
              <View style={[styles.vegDot, { backgroundColor: VEG[item.item_type] }]} />
            </View>
          ) : null}
          <Text style={[styles.name, !available && { color: colors.muted }]} numberOfLines={1}>{item.item_name_en}</Text>
        </View>
        <Text style={styles.meta} numberOfLines={1}>
          {money(item.base_price, currency)}
          {item.has_variations == 1 ? ' · sizes' : ''}
          {item.menu_name ? ` · ${item.menu_name}` : ''}
        </Text>
        {hidden ? (
          <View style={styles.hidden}>
            <Ionicons name="eye-off-outline" size={11} color={colors.inkSoft} />
            <Text style={styles.hiddenText}>Hidden</Text>
          </View>
        ) : null}
      </View>
      <View style={styles.stock}>
        <Switch
          value={available}
          onValueChange={(v) => onToggle(item, v)}
          disabled={busy}
          trackColor={{ false: colors.border, true: '#9BE3BF' }}
          thumbColor={available ? colors.success : '#fff'}
          accessibilityLabel={available ? `${item.item_name_en} in stock` : `${item.item_name_en} out of stock`}
        />
        <Text style={[styles.stockText, { color: available ? colors.success : colors.muted }]}>{available ? 'In stock' : 'Sold out'}</Text>
      </View>
    </PressableScale>
  );
});

export default function MenuScreen({ navigation }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const [q, setQ] = useState('');
  const [menu, setMenu] = useState('all');
  const [stockFilter, setStockFilter] = useState('all');
  const [busyId, setBusyId] = useState(null);

  const fetcher = useCallback(() => apiGet('/api/get_menu_items.php?limit=500&include_hidden=1'), []);
  const { data, loading, refreshing, error, refresh, reload, setData } = useApiData(fetcher, { pollInterval: 60000, cacheKey: 'menu' });
  const items = data?.data || [];

  // Quietly reconcile when coming back from the dish editor (which already spliced its
  // change into the list via the change bus below).
  useFocusEffect(
    useCallback(() => {
      reload('background');
    }, [reload])
  );

  const applyChange = useCallback(
    (type, payload) => {
      setData((prev) => {
        const list = prev?.data || [];
        let next = list;
        if (type === 'delete') next = list.filter((it) => String(it.id) !== String(payload.id));
        else if (type === 'update') next = list.map((it) => (String(it.id) === String(payload.id) ? { ...it, ...payload } : it));
        else if (type === 'create') next = [payload, ...list];
        return { ...(prev || {}), data: next };
      });
    },
    [setData]
  );
  useEffect(() => {
    setMenuChangeListener(applyChange);
    return () => setMenuChangeListener(null);
  }, [applyChange]);

  const menus = useMemo(() => {
    const m = new Map();
    items.forEach((it) => {
      const k = String(it.menu_name || '').trim();
      if (k) m.set(k.toLowerCase(), k);
    });
    return [...m.values()];
  }, [items]);

  const soldOut = items.filter((it) => it.is_available != 1).length;

  const visible = useMemo(() => {
    const s = q.trim().toLowerCase();
    return items.filter((it) => {
      if (menu !== 'all' && String(it.menu_name || '').trim().toLowerCase() !== menu.toLowerCase()) return false;
      if (stockFilter === 'out' && it.is_available == 1) return false;
      if (s && !String(it.item_name_en || '').toLowerCase().includes(s) && !String(it.item_category || '').toLowerCase().includes(s)) return false;
      return true;
    });
  }, [items, q, menu, stockFilter]);

  const toggle = useCallback(
    async (item, on) => {
      setBusyId(item.id);
      haptic.select();
      applyChange('update', { id: item.id, is_available: on ? 1 : 0 });
      try {
        await setAvailable(item, on);
        feedback.success(`${item.item_name_en}: ${on ? 'in stock' : 'sold out'}`);
      } catch (e) {
        applyChange('update', { id: item.id, is_available: on ? 0 : 1 });
        feedback.errorFrom(e, 'Could not update the dish');
      } finally {
        setBusyId(null);
      }
    },
    [applyChange]
  );

  const open = useCallback((item) => navigation.navigate('MenuItem', { item }), [navigation]);

  return (
    <View style={styles.fill}>
      <ScreenHeader
        eyebrow={`${items.length} dishes${soldOut ? ` · ${soldOut} sold out` : ''}`}
        title="Menu"
        right={
          <PressableScale style={styles.addButton} onPress={() => navigation.navigate('MenuItem', {})} hitSlop={8} accessibilityLabel="Add a dish">
            <Ionicons name="add" size={24} color={colors.primary} />
          </PressableScale>
        }
      >
        <View style={styles.search}>
          <Ionicons name="search" size={16} color={colors.muted} />
          <TextInput style={styles.searchInput} value={q} onChangeText={setQ} placeholder="Search dishes" placeholderTextColor={colors.muted} returnKeyType="search" />
          {q ? (
            <PressableScale onPress={() => setQ('')} hitSlop={8} accessibilityLabel="Clear search">
              <Ionicons name="close-circle" size={18} color={colors.muted} />
            </PressableScale>
          ) : null}
        </View>
      </ScreenHeader>

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.chipScroll} contentContainerStyle={{ gap: spacing.sm, paddingHorizontal: gutter, paddingVertical: spacing.sm }}>
        {soldOut ? (
          <PressableScale feedback="select" style={[styles.chip, stockFilter === 'out' && styles.chipDanger]} onPress={() => setStockFilter((f) => (f === 'out' ? 'all' : 'out'))}>
            <Text style={[styles.chipText, stockFilter === 'out' && { color: '#fff' }]}>Sold out ({soldOut})</Text>
          </PressableScale>
        ) : null}
        {['all', ...menus].map((m) => {
          const on = menu === m;
          return (
            <PressableScale key={m} feedback="select" style={[styles.chip, on && styles.chipOn]} onPress={() => setMenu(m)}>
              <Text style={[styles.chipText, on && { color: '#fff' }]} numberOfLines={1}>{m === 'all' ? 'All' : m}</Text>
            </PressableScale>
          );
        })}
      </ScrollView>

      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <FlatList
          data={visible}
          keyExtractor={(item, i) => String(item.id ?? i)}
          keyboardShouldPersistTaps="handled"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={
            items.length ? (
              <EmptyState icon="search-outline" title="No dishes match" subtitle="Try another word or menu." />
            ) : (
              <EmptyState icon="restaurant-outline" title="No dishes yet" subtitle="Add your first dish and it appears on the POS and your website." action="Add a dish" onAction={() => navigation.navigate('MenuItem', {})} />
            )
          }
          contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.sm, flexGrow: 1 }}
          initialNumToRender={12}
          windowSize={9}
          renderItem={({ item, index }) => (
            <FadeIn index={index}>
              <MenuRow item={item} currency={currency} onOpen={open} onToggle={toggle} busy={busyId === item.id} />
            </FadeIn>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  addButton: {
    width: 42,
    height: 42,
    borderRadius: 14,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  search: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    marginTop: spacing.md,
  },
  searchInput: {
    flex: 1,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  // a horizontal ScrollView needs a fixed height or it can collapse under the list below
  chipScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 52,
  },
  chip: {
    height: 36,
    maxWidth: 170,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chipOn: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  chipDanger: {
    backgroundColor: colors.danger,
    borderColor: colors.danger,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.md,
  },
  cardOff: {
    backgroundColor: '#FBF9F8',
  },
  thumb: {
    width: 52,
    height: 52,
    borderRadius: 14,
    backgroundColor: colors.primaryLight,
  },
  nameRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  veg: {
    width: 13,
    height: 13,
    borderWidth: 1.5,
    borderRadius: 3,
    alignItems: 'center',
    justifyContent: 'center',
  },
  vegDot: {
    width: 5,
    height: 5,
    borderRadius: 3,
  },
  name: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 2,
  },
  hidden: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 3,
    backgroundColor: colors.border,
    borderRadius: radius.pill,
    paddingHorizontal: 7,
    paddingVertical: 1,
    marginTop: 4,
  },
  hiddenText: {
    fontFamily: font.semiBold,
    fontSize: 10,
    color: colors.inkSoft,
  },
  stock: {
    alignItems: 'center',
    minWidth: 64,
  },
  stockText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
    marginTop: -2,
  },
});
