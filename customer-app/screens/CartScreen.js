import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { DishCard } from '../components/food';
import { Button, Card, Divider, EmptyState, Header, Screen, SectionTitle, Stepper, VegMark } from '../components/ui';
import { BRAND } from '../config/brand';
import { COUPONS, PAIRS_WITH } from '../data/restaurant';
import { couponStatus } from '../store/bill';
import { useApp } from '../store/AppContext';
import { colors, font, radius } from '../theme';
import { money } from '../utils/format';
import { tap } from '../utils/haptics';

const TYPES = ['Delivery', 'Takeaway', 'Dine-in'];

export function BillRows({ bill, orderType }) {
  const Row = ({ label, value, green, bold }) => (
    <View style={styles.billRow}>
      <Text style={[styles.billLabel, bold && { fontFamily: font.bold, color: colors.ink, fontSize: 15.5 }]}>{label}</Text>
      <Text style={[styles.billValue, green && { color: colors.success }, bold && { fontFamily: font.bold, fontSize: 15.5 }]}>{value}</Text>
    </View>
  );
  return (
    <View>
      <Row label="Item total" value={money(bill.subtotal)} />
      {bill.discount > 0 ? <Row label={`Coupon ${bill.coupon.code}`} value={'-' + money(bill.discount)} green /> : null}
      {orderType === 'Delivery' ? <Row label="Delivery fee" value={bill.delivery ? money(bill.delivery) : 'FREE'} green={!bill.delivery} /> : null}
      {orderType !== 'Dine-in' ? <Row label="Packaging" value={money(bill.packaging)} /> : null}
      <Row label={`${BRAND.taxName} (${BRAND.taxPercent}%)`} value={money(bill.tax)} />
      <Divider style={{ marginVertical: 10 }} />
      <Row label="To pay" value={money(bill.total)} bold />
    </View>
  );
}

export default function CartScreen({ navigation }) {
  const { cart, bill, orderType, coupon, actions, showToast } = useApp();
  const insets = useSafeAreaInsets();
  const [code, setCode] = useState('');

  if (!cart.length) {
    return (
      <Screen>
        <Header title="Your cart" />
        <EmptyState
          icon="bag-outline"
          title="Your cart is empty"
          text="Good food is waiting. Pick something from the menu."
          action="Browse the menu"
          onAction={() => navigation.navigate('Menu')}
        />
      </Screen>
    );
  }

  const tryCode = (value) => {
    const res = actions.applyCoupon(value);
    setCode('');
    showToast(res.message, res.ok && !res.soft ? 'success' : 'dark');
  };

  const pairs = PAIRS_WITH.filter((p) => !cart.some((l) => l.itemId === p.id)).slice(0, 5);
  const minLeft = BRAND.minOrder - bill.subtotal;

  return (
    <Screen>
      <Header
        title="Your cart"
        subtitle={BRAND.name}
        right={
          <Pressable onPress={() => { tap(); actions.clearCart(); }} hitSlop={8} style={{ paddingHorizontal: 8 }}>
            <Text style={{ fontFamily: font.semiBold, fontSize: 13, color: colors.danger }}>Clear</Text>
          </Pressable>
        }
      />
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 150 }} keyboardShouldPersistTaps="handled">
        {/* order type */}
        <View style={styles.types}>
          {TYPES.map((t) => (
            <Pressable key={t} onPress={() => { tap(); actions.set({ orderType: t }); }} style={[styles.type, orderType === t && styles.typeOn]}>
              <Text style={[styles.typeText, orderType === t && { color: colors.onPrimary }]}>{t}</Text>
            </Pressable>
          ))}
        </View>

        <Card style={{ marginHorizontal: 16, paddingVertical: 6 }}>
          {cart.map((l, i) => (
            <View key={l.key}>
              {i > 0 ? <Divider /> : null}
              <View style={styles.line}>
                <VegMark kind={l.kind} size={14} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.lineName}>{l.name}</Text>
                  {l.size ? <Text style={styles.lineMeta}>{l.size}</Text> : null}
                  {l.note ? <Text style={styles.lineMeta}>"{l.note}"</Text> : null}
                </View>
                <Stepper qty={l.qty} onMinus={() => actions.changeQty(l.key, -1)} onPlus={() => actions.changeQty(l.key, 1)} />
                <Text style={styles.linePrice}>{money(l.price * l.qty)}</Text>
              </View>
            </View>
          ))}
        </Card>

        {pairs.length ? (
          <>
            <SectionTitle title="Complete your meal" />
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 16, gap: 14 }}>
              {pairs.map((p) => <DishCard key={p.id} item={p} />)}
            </ScrollView>
          </>
        ) : null}

        {/* coupons */}
        <SectionTitle title="Offers" />
        <Card style={{ marginHorizontal: 16 }}>
          {coupon ? (
            <View style={styles.applied}>
              <Ionicons name={bill.couponOk ? 'checkmark-circle' : 'alert-circle'} size={22} color={bill.couponOk ? colors.success : colors.warning} />
              <View style={{ flex: 1 }}>
                <Text style={styles.appliedCode}>{coupon}</Text>
                <Text style={styles.appliedText}>{bill.couponOk ? `You save ${money(bill.discount)}` : bill.couponReason}</Text>
              </View>
              <Pressable onPress={() => actions.removeCoupon()} hitSlop={8}><Text style={styles.remove}>Remove</Text></Pressable>
            </View>
          ) : (
            <View style={styles.codeRow}>
              <Ionicons name="pricetag-outline" size={18} color={colors.primary} />
              <TextInput
                value={code}
                onChangeText={(t) => setCode(t.toUpperCase())}
                placeholder="Enter coupon code"
                placeholderTextColor={colors.muted}
                autoCapitalize="characters"
                style={styles.codeInput}
                onSubmitEditing={() => code && tryCode(code)}
              />
              <Pressable onPress={() => code && tryCode(code)}><Text style={styles.apply}>APPLY</Text></Pressable>
            </View>
          )}
          {!coupon ? (
            <View style={{ marginTop: 12, gap: 8 }}>
              {COUPONS.map((c) => {
                const st = couponStatus(c, bill.subtotal);
                return (
                  <Pressable key={c.code} onPress={() => tryCode(c.code)} style={[styles.offer, !st.ok && { opacity: 0.6 }]}>
                    <View style={styles.offerBadge}><Text style={styles.offerBadgeText}>{c.code}</Text></View>
                    <Text style={styles.offerText}>{st.ok ? c.text : st.reason}</Text>
                  </Pressable>
                );
              })}
            </View>
          ) : null}
        </Card>

        {bill.freeDeliveryLeft > 0 ? (
          <View style={styles.freeBar}>
            <Ionicons name="bicycle" size={18} color={colors.primary} />
            <Text style={styles.freeText}>Add {money(Math.ceil(bill.freeDeliveryLeft))} more for free delivery</Text>
          </View>
        ) : null}

        <SectionTitle title="Bill details" />
        <Card style={{ marginHorizontal: 16 }}>
          <BillRows bill={bill} orderType={orderType} />
        </Card>
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 12) + 62 }]}>
        <View>
          <Text style={styles.footTotal}>{money(bill.total)}</Text>
          <Text style={styles.footSub}>Total</Text>
        </View>
        <Button
          title={minLeft > 0 ? `Add ${money(minLeft)} more` : 'Checkout'}
          disabled={minLeft > 0}
          icon="arrow-forward"
          onPress={() => navigation.navigate('Checkout')}
          style={{ flex: 1 }}
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  types: { flexDirection: 'row', gap: 8, paddingHorizontal: 16, marginBottom: 14 },
  type: { flex: 1, alignItems: 'center', paddingVertical: 10, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  typeOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  typeText: { fontFamily: font.semiBold, fontSize: 13, color: colors.inkSoft },
  line: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 14 },
  lineName: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  lineMeta: { fontFamily: font.regular, fontSize: 11.5, color: colors.inkSoft, marginTop: 1 },
  linePrice: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink, minWidth: 58, textAlign: 'right' },
  codeRow: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: colors.bg, borderRadius: radius.md, paddingHorizontal: 14, borderWidth: 1, borderColor: colors.border },
  codeInput: { flex: 1, fontFamily: font.semiBold, fontSize: 14, color: colors.ink, paddingVertical: 12, letterSpacing: 0.5, outlineStyle: 'none' },
  apply: { fontFamily: font.bold, fontSize: 13, color: colors.primary },
  applied: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  appliedCode: { fontFamily: font.bold, fontSize: 14, color: colors.ink, letterSpacing: 0.5 },
  appliedText: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 1 },
  remove: { fontFamily: font.semiBold, fontSize: 13, color: colors.danger },
  offer: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  offerBadge: { backgroundColor: colors.primarySoft, borderRadius: 8, paddingVertical: 4, paddingHorizontal: 8, borderWidth: 1, borderColor: colors.primary, borderStyle: 'dashed' },
  offerBadgeText: { fontFamily: font.bold, fontSize: 11, color: colors.primary, letterSpacing: 0.4 },
  offerText: { flex: 1, fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft },
  freeBar: { flexDirection: 'row', alignItems: 'center', gap: 10, marginHorizontal: 16, marginTop: 14, padding: 12, borderRadius: radius.md, backgroundColor: colors.primarySoft },
  freeText: { flex: 1, fontFamily: font.medium, fontSize: 12.5, color: colors.ink },
  billRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5 },
  billLabel: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft },
  billValue: { fontFamily: font.medium, fontSize: 13.5, color: colors.ink },
  footer: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', alignItems: 'center', gap: 16, paddingHorizontal: 16, paddingTop: 12, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  footTotal: { fontFamily: font.bold, fontSize: 18, color: colors.ink },
  footSub: { fontFamily: font.regular, fontSize: 11.5, color: colors.inkSoft },
});
