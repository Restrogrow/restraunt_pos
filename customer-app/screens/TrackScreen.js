import { Ionicons } from '@expo/vector-icons';
import { useEffect, useRef } from 'react';
import { Animated, Easing, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { BillRows } from './CartScreen';
import { Button, Card, Header, Screen, SectionTitle, VegMark } from '../components/ui';
import { BRAND } from '../config/brand';
import { useApp, useNow } from '../store/AppContext';
import { FLOWS, FLOW_NOTES, isFinished, minutesLeft, progressOf, stageIndex } from '../store/orderFlow';
import { colors, font, radius } from '../theme';
import { clockTime, money } from '../utils/format';
import { tap } from '../utils/haptics';

export default function TrackScreen({ route, navigation }) {
  const { orders, actions } = useApp();
  const order = orders.find((o) => o.id === route.params.id);
  const now = useNow(1000);
  const pulse = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 900, easing: Easing.out(Easing.quad), useNativeDriver: Platform.OS !== 'web' }),
        Animated.timing(pulse, { toValue: 0, duration: 0, useNativeDriver: Platform.OS !== 'web' }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);

  if (!order) {
    return (
      <Screen>
        <Header title="Order" onBack={() => navigation.goBack()} />
        <Text style={{ textAlign: 'center', marginTop: 40, fontFamily: font.regular, color: colors.inkSoft }}>We could not find this order.</Text>
      </Screen>
    );
  }

  const flow = FLOWS[order.type] || FLOWS.Delivery;
  const idx = stageIndex(order, now);
  const done = isFinished(order, now);
  const mins = minutesLeft(order, now);
  const progress = progressOf(order, now);
  const pulseStyle = { opacity: pulse.interpolate({ inputRange: [0, 1], outputRange: [0.5, 0] }), transform: [{ scale: pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.6] }) }] };

  const headline = done
    ? order.type === 'Delivery' ? 'Delivered. Enjoy!' : order.type === 'Takeaway' ? 'Picked up. Enjoy!' : 'Served. Enjoy!'
    : flow[idx] === 'Order placed' ? 'Order placed' : flow[idx];

  return (
    <Screen>
      <Header title={order.number} subtitle={clockTime(order.createdAt) + ' - ' + order.type} onBack={() => (navigation.canGoBack() ? navigation.goBack() : navigation.navigate('Main'))} />
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 40 }}>
        {route.params.fresh ? (
          <View style={styles.banner}>
            <Ionicons name="checkmark-circle" size={22} color={colors.success} />
            <Text style={styles.bannerText}>Order placed. {BRAND.name} has your order.</Text>
          </View>
        ) : null}

        <Card style={{ marginHorizontal: 16, marginTop: 8 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
            <View style={styles.pulseWrap}>
              {!done ? <Animated.View style={[styles.pulseRing, pulseStyle]} /> : null}
              <View style={[styles.statusIcon, done && { backgroundColor: colors.success }]}>
                <Ionicons name={done ? 'checkmark' : idx >= 3 && order.type === 'Delivery' ? 'bicycle' : idx === 2 ? 'flame' : 'receipt'} size={24} color="#fff" />
              </View>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.headline}>{headline}</Text>
              <Text style={styles.sub}>{done ? 'Thank you for ordering with us' : `Arriving in about ${Math.max(mins, 1)} min`}</Text>
            </View>
          </View>
          <View style={styles.track}><View style={[styles.fill, { width: `${Math.round(progress * 100)}%` }]} /></View>
          {!done ? <Text style={styles.speedNote}>Preview runs faster than real life so you can see every step.</Text> : null}
        </Card>

        <SectionTitle title="Order status" />
        <Card style={{ marginHorizontal: 16 }}>
          {flow.map((label, i) => {
            const reached = i <= idx;
            const last = i === flow.length - 1;
            return (
              <View key={label} style={{ flexDirection: 'row', gap: 14 }}>
                <View style={{ alignItems: 'center' }}>
                  <View style={[styles.node, reached && { backgroundColor: colors.primary, borderColor: colors.primary }]}>
                    {reached ? <Ionicons name="checkmark" size={13} color={colors.onPrimary} /> : null}
                  </View>
                  {!last ? <View style={[styles.stem, i < idx && { backgroundColor: colors.primary }]} /> : null}
                </View>
                <View style={{ paddingBottom: last ? 0 : 18, flex: 1 }}>
                  <Text style={[styles.stepTitle, !reached && { color: colors.muted }]}>{label}</Text>
                  <Text style={styles.stepNote}>{FLOW_NOTES[label]}</Text>
                </View>
              </View>
            );
          })}
        </Card>

        {order.type === 'Delivery' && idx >= 3 ? (
          <Card style={{ marginHorizontal: 16, marginTop: 14, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <View style={styles.rider}><Ionicons name="person" size={22} color={colors.primary} /></View>
            <View style={{ flex: 1 }}>
              <Text style={styles.stepTitle}>Ravi K.</Text>
              <Text style={styles.stepNote}>Your delivery partner</Text>
            </View>
            <Pressable style={styles.call} onPress={() => Linking.openURL('tel:' + BRAND.phone.replace(/\s/g, ''))}>
              <Ionicons name="call" size={18} color={colors.onPrimary} />
            </Pressable>
          </Card>
        ) : null}

        {order.type === 'Delivery' && order.address ? (
          <Card style={{ marginHorizontal: 16, marginTop: 14, flexDirection: 'row', gap: 12 }}>
            <Ionicons name="location" size={20} color={colors.primary} />
            <View style={{ flex: 1 }}>
              <Text style={styles.stepTitle}>{order.address.label}</Text>
              <Text style={styles.stepNote}>{order.address.line}, {order.address.area}</Text>
            </View>
          </Card>
        ) : null}
        {order.type === 'Dine-in' ? (
          <Card style={{ marginHorizontal: 16, marginTop: 14, flexDirection: 'row', gap: 12 }}>
            <Ionicons name="restaurant" size={20} color={colors.primary} />
            <Text style={styles.stepTitle}>Table {order.table}</Text>
          </Card>
        ) : null}

        {done ? (
          <Card style={{ marginHorizontal: 16, marginTop: 14, alignItems: 'center' }}>
            <Text style={styles.stepTitle}>{order.rating ? 'Thanks for rating!' : 'How was your food?'}</Text>
            <View style={{ flexDirection: 'row', gap: 6, marginTop: 10 }}>
              {[1, 2, 3, 4, 5].map((s) => (
                <Pressable key={s} onPress={() => { tap(); actions.rateOrder(order.id, s); }} hitSlop={4}>
                  <Ionicons name={s <= order.rating ? 'star' : 'star-outline'} size={32} color={colors.star} />
                </Pressable>
              ))}
            </View>
          </Card>
        ) : null}

        <SectionTitle title="Your order" />
        <Card style={{ marginHorizontal: 16 }}>
          {order.items.map((l) => (
            <View key={l.key} style={styles.item}>
              <VegMark kind={l.kind} size={13} />
              <Text style={styles.itemName}>{l.qty} x {l.name}{l.size ? ` (${l.size})` : ''}</Text>
              <Text style={styles.itemPrice}>{money(l.price * l.qty)}</Text>
            </View>
          ))}
          <View style={{ height: 1, backgroundColor: colors.border, marginVertical: 10 }} />
          <BillRows bill={order.bill} orderType={order.type} />
          <Text style={styles.paid}>Payment: {order.payment}</Text>
        </Card>

        <View style={{ paddingHorizontal: 16, marginTop: 18, gap: 10 }}>
          <Button title="Reorder these items" variant="outline" icon="refresh" onPress={() => { actions.reorder(order); navigation.navigate('Main', { screen: 'Cart' }); }} />
          <Button title="Back to home" variant="soft" onPress={() => navigation.navigate('Main', { screen: 'Home' })} />
        </View>
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, marginHorizontal: 16, padding: 12, borderRadius: radius.md, backgroundColor: colors.successBg },
  bannerText: { flex: 1, fontFamily: font.medium, fontSize: 13, color: colors.ink },
  pulseWrap: { width: 56, height: 56, alignItems: 'center', justifyContent: 'center' },
  pulseRing: { position: 'absolute', width: 56, height: 56, borderRadius: 28, backgroundColor: colors.primary },
  statusIcon: { width: 52, height: 52, borderRadius: 26, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  headline: { fontFamily: font.bold, fontSize: 19, color: colors.ink },
  sub: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, marginTop: 1 },
  track: { height: 8, borderRadius: 4, backgroundColor: colors.border, marginTop: 18, overflow: 'hidden' },
  fill: { height: '100%', backgroundColor: colors.primary, borderRadius: 4 },
  speedNote: { fontFamily: font.regular, fontSize: 11, color: colors.muted, marginTop: 8 },
  node: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: colors.borderStrong, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.surface },
  stem: { width: 2, flex: 1, minHeight: 22, backgroundColor: colors.border, marginVertical: 2 },
  stepTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  stepNote: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 1 },
  rider: { width: 46, height: 46, borderRadius: 23, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center' },
  call: { width: 42, height: 42, borderRadius: 21, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  item: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 5 },
  itemName: { flex: 1, fontFamily: font.regular, fontSize: 13.5, color: colors.ink },
  itemPrice: { fontFamily: font.medium, fontSize: 13.5, color: colors.ink },
  paid: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 10 },
});
