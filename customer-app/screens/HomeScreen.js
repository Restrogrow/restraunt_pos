import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { useRef, useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';

import { DishCard } from '../components/food';
import { BrandMark, Card, IconButton, RatingPill, Screen, SectionTitle } from '../components/ui';
import { BRAND } from '../config/brand';
import { BANNERS, BESTSELLERS, CATEGORIES, ITEMS, SPECIALS, itemById, restaurantOpenNow } from '../data/restaurant';
import { useApp } from '../store/AppContext';
import { isFinished } from '../store/orderFlow';
import { colors, CONTENT_MAX, font, radius, shadow } from '../theme';
import { greeting, money } from '../utils/format';
import { tap } from '../utils/haptics';

const TYPES = [
  { key: 'Delivery', icon: 'bicycle', sub: '30-40 min' },
  { key: 'Takeaway', icon: 'bag-handle', sub: '15-20 min' },
  { key: 'Dine-in', icon: 'restaurant', sub: 'Scan & order' },
];

export default function HomeScreen({ navigation }) {
  const { user, orderType, actions, addresses, addressId, orders, cart, favorites } = useApp();
  const { width } = useWindowDimensions();
  const w = Math.min(width, CONTENT_MAX);
  const [banner, setBanner] = useState(0);
  const bannerRef = useRef(null);
  const open = restaurantOpenNow(BRAND);
  const address = addresses.find((a) => a.id === addressId);
  const firstName = (user?.name || '').split(' ')[0];
  const last = orders.find((o) => isFinished(o));
  const active = orders.find((o) => !isFinished(o));
  const favItems = favorites.map(itemById).filter(Boolean);

  const goMenu = (params) => navigation.navigate('Menu', params);
  const bw = w - 32;

  return (
    <Screen>
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 150 }}>
        {/* Top bar */}
        <View style={styles.top}>
          <Pressable
            style={{ flex: 1 }}
            onPress={() => { tap(); navigation.navigate('Addresses'); }}
          >
            <Text style={styles.deliverTo}>{orderType === 'Delivery' ? 'Deliver to' : orderType === 'Takeaway' ? 'Pick up from' : 'Dining at'}</Text>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
              <Ionicons name="location" size={16} color={colors.primary} />
              <Text style={styles.deliverAddr} numberOfLines={1}>
                {orderType === 'Delivery' ? (address ? `${address.label}, ${address.line}` : 'Add an address') : BRAND.name + ', ' + BRAND.area.split(',')[0]}
              </Text>
              <Ionicons name="chevron-down" size={14} color={colors.inkSoft} />
            </View>
          </Pressable>
          <IconButton name="heart-outline" onPress={() => navigation.navigate('Favorites')} label="Favourites" badge={favorites.length > 0} />
        </View>

        <View style={{ paddingHorizontal: 16 }}>
          <Text style={styles.hello}>{greeting()}{firstName && firstName !== 'Guest' ? `, ${firstName}` : ''}</Text>
          <Text style={styles.helloSub}>What are you craving today?</Text>

          <Pressable onPress={() => { tap(); goMenu({ focusSearch: Date.now() }); }} style={styles.search}>
            <Ionicons name="search" size={19} color={colors.muted} />
            <Text style={styles.searchText}>Search dishes, e.g. biryani</Text>
          </Pressable>
        </View>

        {/* Live order */}
        {active ? (
          <Pressable onPress={() => navigation.navigate('Track', { id: active.id })} style={styles.activeOrder}>
            <View style={styles.pulse}><Ionicons name="time" size={18} color={colors.onPrimary} /></View>
            <View style={{ flex: 1 }}>
              <Text style={styles.activeTitle}>Your order {active.number} is on its way</Text>
              <Text style={styles.activeSub}>Tap to track it live</Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.primary} />
          </Pressable>
        ) : null}

        {/* Banners */}
        <View style={{ marginTop: 18 }}>
          <ScrollView
            ref={bannerRef}
            horizontal
            pagingEnabled
            snapToInterval={bw + 12}
            decelerationRate="fast"
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={{ paddingHorizontal: 16, gap: 12 }}
            onMomentumScrollEnd={(e) => setBanner(Math.round(e.nativeEvent.contentOffset.x / (bw + 12)))}
          >
            {BANNERS.map((b) => (
              <Pressable
                key={b.id}
                onPress={() => { tap(); b.code ? actions.set({ coupon: b.code }) : null; navigation.navigate('Offers'); }}
                style={[styles.banner, { width: bw }]}
              >
                <Image source={b.image} style={StyleSheet.absoluteFill} contentFit="cover" />
                <LinearGradient colors={['rgba(10,8,14,0.82)', 'rgba(10,8,14,0.15)']} start={{ x: 0, y: 0.5 }} end={{ x: 1, y: 0.5 }} style={StyleSheet.absoluteFill} />
                <View style={{ padding: 20, flex: 1, justifyContent: 'center' }}>
                  <Text style={styles.bannerTitle}>{b.title}</Text>
                  <Text style={styles.bannerSub}>{b.sub}</Text>
                  {b.code ? (
                    <View style={styles.bannerCode}><Text style={styles.bannerCodeText}>{b.code}</Text></View>
                  ) : (
                    <View style={[styles.bannerCode, { backgroundColor: '#fff' }]}><Text style={[styles.bannerCodeText, { color: colors.primary }]}>Order now</Text></View>
                  )}
                </View>
              </Pressable>
            ))}
          </ScrollView>
          <View style={styles.dots}>
            {BANNERS.map((b, i) => (
              <View key={b.id} style={[styles.dot, i === banner && { width: 18, backgroundColor: colors.primary }]} />
            ))}
          </View>
        </View>

        {/* Order type */}
        <View style={styles.types}>
          {TYPES.map((t) => {
            const on = orderType === t.key;
            return (
              <Pressable key={t.key} onPress={() => { tap(); actions.set({ orderType: t.key }); }} style={[styles.type, on && styles.typeOn]}>
                <Ionicons name={t.icon} size={22} color={on ? colors.onPrimary : colors.primary} />
                <Text style={[styles.typeName, on && { color: colors.onPrimary }]}>{t.key}</Text>
                <Text style={[styles.typeSub, on && { color: colors.onPrimary, opacity: 0.85 }]}>{t.sub}</Text>
              </Pressable>
            );
          })}
        </View>

        {/* Categories */}
        <SectionTitle title="Explore the menu" action="See all" onAction={() => goMenu({})} />
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 14 }}>
          {CATEGORIES.map((c) => (
            <Pressable key={c.key} onPress={() => { tap(); goMenu({ category: c.key, t: Date.now() }); }} style={styles.cat}>
              <View style={styles.catImg}>
                <Image source={c.image} style={StyleSheet.absoluteFill} contentFit="cover" />
              </View>
              <Text style={styles.catName} numberOfLines={1}>{c.name}</Text>
            </Pressable>
          ))}
        </ScrollView>

        {/* Specials */}
        <SectionTitle title="Today's specials" />
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 14 }}>
          {SPECIALS.map((s) => (
            <DishCard key={s.id} item={s} note={s.note} />
          ))}
        </ScrollView>

        {/* Favourites */}
        <SectionTitle title="Customer favourites" action="See menu" onAction={() => goMenu({})} />
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 14 }}>
          {BESTSELLERS.map((b) => (
            <DishCard key={b.id} item={b} />
          ))}
        </ScrollView>

        {/* Reorder */}
        {last ? (
          <>
            <SectionTitle title="Order it again" />
            <Card style={{ marginHorizontal: 16 }}>
              <Text style={styles.reorderItems} numberOfLines={2}>{last.items.map((l) => `${l.qty} x ${l.name}`).join(', ')}</Text>
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 12 }}>
                <Text style={styles.reorderTotal}>{money(last.bill.total)}</Text>
                <Pressable
                  onPress={() => { actions.reorder(last); navigation.navigate('Cart'); }}
                  style={styles.reorderBtn}
                >
                  <Ionicons name="refresh" size={15} color={colors.primary} />
                  <Text style={styles.reorderBtnText}>Reorder</Text>
                </Pressable>
              </View>
            </Card>
          </>
        ) : null}

        {/* About */}
        <SectionTitle title="About the restaurant" />
        <Card style={{ marginHorizontal: 16 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <BrandMark size={50} />
            <View style={{ flex: 1 }}>
              <Text style={styles.aboutName}>{BRAND.name}</Text>
              <Text style={styles.aboutCuisine} numberOfLines={1}>{BRAND.cuisine}</Text>
            </View>
          </View>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 14 }}>
            <RatingPill value={BRAND.rating} count={BRAND.ratingCount} />
            <View style={[styles.openPill, { backgroundColor: open ? colors.successBg : colors.dangerBg }]}>
              <View style={[styles.openDot, { backgroundColor: open ? colors.success : colors.danger }]} />
              <Text style={[styles.openText, { color: open ? colors.success : colors.danger }]}>{open ? 'Open now' : 'Closed'}</Text>
            </View>
          </View>
          <Text style={styles.aboutLine}><Ionicons name="time-outline" size={13} /> {BRAND.hours.label}</Text>
          <Text style={styles.aboutLine}><Ionicons name="location-outline" size={13} /> {BRAND.address}</Text>
          <View style={{ flexDirection: 'row', gap: 10, marginTop: 14 }}>
            <Pressable style={styles.aboutBtn} onPress={() => Linking.openURL('tel:' + BRAND.phone.replace(/\s/g, ''))}>
              <Ionicons name="call-outline" size={16} color={colors.primary} />
              <Text style={styles.aboutBtnText}>Call</Text>
            </Pressable>
            <Pressable style={styles.aboutBtn} onPress={() => Linking.openURL('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(BRAND.name + ' ' + BRAND.address))}>
              <Ionicons name="navigate-outline" size={16} color={colors.primary} />
              <Text style={styles.aboutBtnText}>Directions</Text>
            </Pressable>
            <Pressable style={styles.aboutBtn} onPress={() => navigation.navigate('Reserve')}>
              <Ionicons name="calendar-outline" size={16} color={colors.primary} />
              <Text style={styles.aboutBtnText}>Book a table</Text>
            </Pressable>
          </View>
        </Card>
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, paddingTop: 10, gap: 12 },
  deliverTo: { fontFamily: font.regular, fontSize: 11.5, color: colors.inkSoft },
  deliverAddr: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink, flexShrink: 1 },
  hello: { fontFamily: font.bold, fontSize: 24, color: colors.ink, marginTop: 18 },
  helloSub: { fontFamily: font.regular, fontSize: 14, color: colors.inkSoft, marginTop: 1 },
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: colors.surface, borderRadius: radius.pill, paddingVertical: 14, paddingHorizontal: 18, marginTop: 16, borderWidth: 1, borderColor: colors.border, ...shadow.sm },
  searchText: { fontFamily: font.regular, fontSize: 14, color: colors.muted },

  activeOrder: { flexDirection: 'row', alignItems: 'center', gap: 12, marginHorizontal: 16, marginTop: 16, padding: 14, borderRadius: radius.lg, backgroundColor: colors.primarySoft, borderWidth: 1, borderColor: colors.primary },
  pulse: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  activeTitle: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink },
  activeSub: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft },

  banner: { height: 148, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: colors.ink },
  bannerTitle: { fontFamily: font.bold, fontSize: 24, color: '#fff' },
  bannerSub: { fontFamily: font.regular, fontSize: 12.5, color: 'rgba(255,255,255,0.88)', marginTop: 2, maxWidth: '65%' },
  bannerCode: { alignSelf: 'flex-start', marginTop: 12, borderRadius: radius.pill, backgroundColor: colors.primary, paddingVertical: 6, paddingHorizontal: 14 },
  bannerCodeText: { fontFamily: font.bold, fontSize: 12, color: colors.onPrimary, letterSpacing: 0.6 },
  dots: { flexDirection: 'row', justifyContent: 'center', gap: 5, marginTop: 10 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: colors.borderStrong },

  types: { flexDirection: 'row', gap: 10, paddingHorizontal: 16, marginTop: 20 },
  type: { flex: 1, alignItems: 'center', gap: 3, paddingVertical: 14, borderRadius: radius.lg, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, ...shadow.sm },
  typeOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  typeName: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink, marginTop: 4 },
  typeSub: { fontFamily: font.regular, fontSize: 10.5, color: colors.inkSoft },

  cat: { width: 74, alignItems: 'center' },
  catImg: { width: 70, height: 70, borderRadius: 35, overflow: 'hidden', backgroundColor: colors.skeleton, borderWidth: 2, borderColor: colors.surface, ...shadow.sm },
  catName: { fontFamily: font.medium, fontSize: 12, color: colors.ink, marginTop: 7 },

  reorderItems: { fontFamily: font.medium, fontSize: 13.5, color: colors.ink, lineHeight: 20 },
  reorderTotal: { fontFamily: font.bold, fontSize: 16, color: colors.ink },
  reorderBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 8, paddingHorizontal: 16, borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.primary },
  reorderBtnText: { fontFamily: font.semiBold, fontSize: 13, color: colors.primary },

  aboutName: { fontFamily: font.bold, fontSize: 17, color: colors.ink },
  aboutCuisine: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft },
  openPill: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 3, paddingHorizontal: 10, borderRadius: radius.pill },
  openDot: { width: 7, height: 7, borderRadius: 4 },
  openText: { fontFamily: font.semiBold, fontSize: 12 },
  aboutLine: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 8, lineHeight: 19 },
  aboutBtn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 10, borderRadius: radius.md, backgroundColor: colors.primarySoft },
  aboutBtnText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.primary },
});
