import { Ionicons } from '@expo/vector-icons';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AddControl, FoodImage, RatingPill, VegMark } from './ui';
import { categoryOpenNow, hoursLabel, CATEGORIES } from '../data/restaurant';
import { useApp } from '../store/AppContext';
import { colors, CONTENT_MAX, font, radius, shadow } from '../theme';
import { money } from '../utils/format';
import { tap } from '../utils/haptics';
import { navigationRef } from '../navigationRef';

export function openItem(item) {
  if (navigationRef.isReady()) navigationRef.navigate('Item', { id: item.id });
}

function useItemControl(item) {
  const { cart, actions } = useApp();
  const lines = cart.filter((l) => l.itemId === item.id);
  const qty = lines.reduce((n, l) => n + l.qty, 0);
  const cat = CATEGORIES.find((c) => c.key === item.categoryKey);
  const available = !cat || categoryOpenNow(cat);
  const hasOptions = item.sizes.length > 0;
  const add = () => (hasOptions ? openItem(item) : actions.addToCart(item));
  // with sizes, +/- act on the most recent line
  const last = lines[lines.length - 1];
  return {
    qty,
    available,
    hasOptions,
    add,
    plus: () => (hasOptions ? openItem(item) : actions.changeQty(last.key, 1)),
    minus: () => last && actions.changeQty(last.key, -1),
  };
}

// A dish in the menu list: text on the left, photo and ADD button on the right
export function MenuRow({ item }) {
  const { favorites } = useApp();
  const c = useItemControl(item);
  const cat = CATEGORIES.find((x) => x.key === item.categoryKey);
  const fav = favorites.includes(item.id);
  return (
    <Pressable onPress={() => { tap(); openItem(item); }} style={styles.row}>
      <View style={{ flex: 1, paddingRight: 12 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          <VegMark kind={item.kind} />
          {fav ? <Ionicons name="heart" size={13} color={colors.primary} /> : null}
        </View>
        <Text style={styles.rowName} numberOfLines={2}>{item.name}</Text>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 }}>
          <Text style={styles.rowPrice}>{item.sizes.length ? 'from ' : ''}{money(item.price)}</Text>
          <RatingPill value={item.rating} small />
        </View>
        <Text style={styles.rowDesc} numberOfLines={2}>{item.desc}</Text>
        {!c.available && cat ? <Text style={styles.rowHours}>Served {hoursLabel(cat)}</Text> : null}
      </View>
      <View style={{ alignItems: 'center' }}>
        <FoodImage source={item.image} style={styles.rowImg} radiusValue={radius.md} />
        <View style={{ marginTop: -19 }}>
          <AddControl qty={c.qty} disabled={!c.available} hasOptions={c.hasOptions} onAdd={c.add} onPlus={c.plus} onMinus={c.minus} />
        </View>
      </View>
    </Pressable>
  );
}

// Compact card for horizontal carousels
export function DishCard({ item, note }) {
  const c = useItemControl(item);
  const price = item.offerPrice || item.price;
  return (
    <Pressable onPress={() => { tap(); openItem(item); }} style={styles.card}>
      <FoodImage source={item.image} style={styles.cardImg} radiusValue={radius.lg}>
        <View style={styles.cardMark}><VegMark kind={item.kind} size={14} /></View>
      </FoodImage>
      <View style={{ paddingHorizontal: 4, paddingTop: 8 }}>
        <Text style={styles.cardName} numberOfLines={1}>{item.name}</Text>
        {note ? <Text style={styles.cardNote} numberOfLines={1}>{note}</Text> : <RatingPill value={item.rating} small />}
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 }}>
          <View>
            <Text style={styles.cardPrice}>{money(price)}</Text>
            {item.wasPrice ? <Text style={styles.cardWas}>{money(item.wasPrice)}</Text> : null}
          </View>
          <CompactAdd qty={c.qty} available={c.available} onAdd={c.add} onPlus={c.plus} onMinus={c.minus} />
        </View>
      </View>
    </Pressable>
  );
}

function CompactAdd({ qty, available, onAdd, onPlus, onMinus }) {
  if (!available) return <Text style={{ fontFamily: font.medium, fontSize: 10, color: colors.muted }}>Not now</Text>;
  if (!qty) {
    return (
      <Pressable onPress={() => { tap(); onAdd(); }} style={styles.plusBtn} accessibilityLabel="Add">
        <Ionicons name="add" size={20} color={colors.onPrimary} />
      </Pressable>
    );
  }
  return (
    <View style={styles.miniStepper}>
      <Pressable onPress={() => { tap(); onMinus(); }} hitSlop={6}><Ionicons name="remove" size={16} color={colors.onPrimary} /></Pressable>
      <Text style={styles.miniQty}>{qty}</Text>
      <Pressable onPress={() => { tap(); onPlus(); }} hitSlop={6}><Ionicons name="add" size={16} color={colors.onPrimary} /></Pressable>
    </View>
  );
}

// "View cart" bar that floats above the tab bar
export function CartBar({ onPress, bottom }) {
  const { cartCount, bill } = useApp();
  if (!cartCount) return null;
  return (
    <View pointerEvents="box-none" style={[styles.cartWrap, { bottom }]}>
      <Pressable onPress={() => { tap(); onPress(); }} style={({ pressed }) => [styles.cartBar, pressed && { opacity: 0.92 }]}>
        <View style={{ flex: 1 }}>
          <Text style={styles.cartTop}>{cartCount} {cartCount === 1 ? 'item' : 'items'}</Text>
          <Text style={styles.cartSub}>{money(bill.subtotal)} + taxes</Text>
        </View>
        <Text style={styles.cartCta}>View cart</Text>
        <Ionicons name="chevron-forward" size={18} color={colors.onPrimary} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', paddingHorizontal: 16, paddingVertical: 16, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.surface },
  rowName: { fontFamily: font.semiBold, fontSize: 15.5, color: colors.ink, marginTop: 6 },
  rowPrice: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  rowDesc: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, lineHeight: 18, marginTop: 6 },
  rowHours: { fontFamily: font.medium, fontSize: 11.5, color: colors.warning, marginTop: 6 },
  rowImg: { width: 118, height: 108 },

  card: { width: 168 },
  cardImg: { width: 168, height: 128 },
  cardMark: { position: 'absolute', top: 8, left: 8, backgroundColor: '#fff', borderRadius: 4, padding: 1 },
  cardName: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink, marginBottom: 2 },
  cardNote: { fontFamily: font.regular, fontSize: 11.5, color: colors.primary },
  cardPrice: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  cardWas: { fontFamily: font.regular, fontSize: 11, color: colors.muted, textDecorationLine: 'line-through', marginTop: -2 },
  plusBtn: { width: 34, height: 34, borderRadius: 17, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', ...shadow.sm },
  miniStepper: { flexDirection: 'row', alignItems: 'center', gap: 8, height: 34, paddingHorizontal: 9, borderRadius: 17, backgroundColor: colors.primary },
  miniQty: { fontFamily: font.bold, fontSize: 13, color: colors.onPrimary, minWidth: 14, textAlign: 'center' },

  cartWrap: { position: 'absolute', left: 0, right: 0, alignItems: 'center', paddingHorizontal: 14 },
  cartBar: { width: '100%', maxWidth: CONTENT_MAX - 28, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.primary, borderRadius: radius.lg, paddingVertical: 12, paddingHorizontal: 18, ...shadow.lg },
  cartTop: { fontFamily: font.bold, fontSize: 14, color: colors.onPrimary },
  cartSub: { fontFamily: font.regular, fontSize: 11.5, color: colors.onPrimary, opacity: 0.85 },
  cartCta: { fontFamily: font.bold, fontSize: 14.5, color: colors.onPrimary },
});
