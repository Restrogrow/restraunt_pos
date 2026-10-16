import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { DishCard } from '../components/food';
import { Button, FoodImage, RatingPill, Stepper, VegMark } from '../components/ui';
import { CATEGORIES, categoryOpenNow, hoursLabel, itemById } from '../data/restaurant';
import { useApp } from '../store/AppContext';
import { colors, CONTENT_MAX, font, radius } from '../theme';
import { money } from '../utils/format';
import { success, tap } from '../utils/haptics';

const KIND_LABEL = { veg: 'Vegetarian', nonveg: 'Non-vegetarian', egg: 'Contains egg', drink: 'Beverage' };

export default function ItemScreen({ route, navigation }) {
  const item = itemById(route.params.id);
  const { favorites, actions, showToast } = useApp();
  const insets = useSafeAreaInsets();
  const [size, setSize] = useState(item && item.sizes.length ? item.sizes[0].name : null);
  const [qty, setQty] = useState(1);
  const [note, setNote] = useState('');
  if (!item) return null;

  const cat = CATEGORIES.find((c) => c.key === item.categoryKey);
  const open = !cat || categoryOpenNow(cat);
  const unit = size ? item.sizes.find((s) => s.name === size).price : item.price;
  const fav = favorites.includes(item.id);
  const more = (cat ? cat.items : []).filter((i) => i.id !== item.id).slice(0, 4);

  const add = () => {
    actions.addToCart(item, { size, qty, note: note.trim() });
    success();
    showToast(`${qty} x ${item.name} added`, 'success');
    navigation.goBack();
  };

  return (
    <View style={styles.backdrop}>
      <Pressable style={StyleSheet.absoluteFill} onPress={() => navigation.goBack()} accessibilityLabel="Close" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.wrap} pointerEvents="box-none">
        <View style={styles.sheet}>
          <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: 16 }}>
            <FoodImage source={item.image} style={styles.hero} radiusValue={0} />
            <View style={styles.grab} />
            <Pressable onPress={() => { tap(); navigation.goBack(); }} style={[styles.round, { left: 14 }]} accessibilityLabel="Close">
              <Ionicons name="close" size={20} color={colors.ink} />
            </Pressable>
            <Pressable onPress={() => { tap(); actions.toggleFavorite(item.id); showToast(fav ? 'Removed from favourites' : 'Saved to favourites'); }} style={[styles.round, { right: 14 }]} accessibilityLabel="Favourite">
              <Ionicons name={fav ? 'heart' : 'heart-outline'} size={20} color={fav ? colors.primary : colors.ink} />
            </Pressable>

            <View style={{ padding: 20 }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                <VegMark kind={item.kind} />
                <Text style={styles.kind}>{KIND_LABEL[item.kind]}</Text>
              </View>
              <Text style={styles.name}>{item.name}</Text>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 8 }}>
                <RatingPill value={item.rating} count={item.ratingCount} />
                <Text style={styles.meta}>{item.prep} min</Text>
                <Text style={styles.meta}>{item.calories} kcal</Text>
              </View>
              <Text style={styles.desc}>{item.desc}</Text>

              {item.sizes.length ? (
                <View style={{ marginTop: 20 }}>
                  <Text style={styles.label}>Choose a size</Text>
                  {item.sizes.map((s) => {
                    const on = s.name === size;
                    return (
                      <Pressable key={s.name} onPress={() => { tap(); setSize(s.name); }} style={[styles.size, on && { borderColor: colors.primary, backgroundColor: colors.primarySoft }]}>
                        <View style={[styles.radio, on && { borderColor: colors.primary }]}>{on ? <View style={styles.radioDot} /> : null}</View>
                        <Text style={styles.sizeName}>{s.name}</Text>
                        <Text style={styles.sizePrice}>{money(s.price)}</Text>
                      </Pressable>
                    );
                  })}
                </View>
              ) : null}

              <Text style={[styles.label, { marginTop: 20 }]}>Cooking instructions <Text style={{ fontFamily: font.regular, color: colors.muted }}>(optional)</Text></Text>
              <TextInput
                value={note}
                onChangeText={setNote}
                placeholder="E.g. less spicy, no onion"
                placeholderTextColor={colors.muted}
                style={styles.note}
                maxLength={120}
              />

              {more.length ? (
                <>
                  <Text style={[styles.label, { marginTop: 24 }]}>More from {item.category}</Text>
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 14 }}>
                    {more.map((m) => (
                      <Pressable key={m.id} onPress={() => navigation.replace('Item', { id: m.id })}>
                        <View pointerEvents="none"><DishCard item={m} /></View>
                      </Pressable>
                    ))}
                  </ScrollView>
                </>
              ) : null}
            </View>
          </ScrollView>

          <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 14) }]}>
            {open ? (
              <>
                <Stepper qty={qty} large onMinus={() => setQty(Math.max(1, qty - 1))} onPlus={() => setQty(Math.min(20, qty + 1))} />
                <Button title={`Add item  ${money(unit * qty)}`} onPress={add} style={{ flex: 1 }} />
              </>
            ) : (
              <View style={styles.closed}>
                <Ionicons name="time-outline" size={18} color={colors.warning} />
                <Text style={styles.closedText}>Served {cat ? hoursLabel(cat) : ''}</Text>
              </View>
            )}
          </View>
        </View>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: colors.overlay, alignItems: 'center', justifyContent: 'flex-end' },
  wrap: { width: '100%', maxWidth: CONTENT_MAX, maxHeight: '92%' },
  sheet: { backgroundColor: colors.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, overflow: 'hidden', maxHeight: '100%', flexShrink: 1 },
  hero: { width: '100%', height: 240 },
  grab: { position: 'absolute', top: 8, alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: 'rgba(255,255,255,0.8)' },
  round: { position: 'absolute', top: 18, width: 38, height: 38, borderRadius: 19, backgroundColor: 'rgba(255,255,255,0.95)', alignItems: 'center', justifyContent: 'center' },
  kind: { fontFamily: font.medium, fontSize: 12, color: colors.inkSoft },
  name: { fontFamily: font.bold, fontSize: 24, color: colors.ink, marginTop: 8 },
  meta: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft },
  desc: { fontFamily: font.regular, fontSize: 14, lineHeight: 22, color: colors.inkSoft, marginTop: 14 },
  label: { fontFamily: font.semiBold, fontSize: 15, color: colors.ink, marginBottom: 10 },
  size: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.border, marginBottom: 8 },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: colors.borderStrong, alignItems: 'center', justifyContent: 'center' },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.primary },
  sizeName: { flex: 1, fontFamily: font.medium, fontSize: 14.5, color: colors.ink },
  sizePrice: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  note: { fontFamily: font.regular, fontSize: 14, color: colors.ink, backgroundColor: colors.bg, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 12, outlineStyle: 'none' },
  footer: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingTop: 12, borderTopWidth: 1, borderTopColor: colors.border, backgroundColor: colors.surface },
  closed: { flex: 1, flexDirection: 'row', gap: 8, alignItems: 'center', justifyContent: 'center', padding: 12, borderRadius: radius.md, backgroundColor: colors.warningBg },
  closedText: { fontFamily: font.medium, fontSize: 13.5, color: colors.inkSoft },
});
