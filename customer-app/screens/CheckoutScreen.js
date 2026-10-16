import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BillRows } from './CartScreen';
import { Button, Card, Header, Screen, SectionTitle } from '../components/ui';
import { BRAND, DEMO } from '../config/brand';
import { useApp } from '../store/AppContext';
import { colors, font, radius } from '../theme';
import { money } from '../utils/format';
import { success, tap } from '../utils/haptics';

const PAYMENTS = [
  { key: 'UPI', icon: 'phone-portrait-outline', title: 'UPI', sub: 'GPay, PhonePe, Paytm' },
  { key: 'Card', icon: 'card-outline', title: 'Credit / debit card', sub: 'Visa, Mastercard, RuPay' },
  { key: 'Cash', icon: 'cash-outline', title: 'Pay at the restaurant / on delivery', sub: 'Cash or card machine' },
];
const PICKUPS = ['ASAP', 'In 30 min', 'In 1 hour'];

export default function CheckoutScreen({ navigation }) {
  const { orderType, bill, user, addresses, addressId, tableNo, pickup, actions } = useApp();
  const insets = useSafeAreaInsets();
  const [name, setName] = useState(user?.guest ? '' : user?.name || '');
  const [phone, setPhone] = useState(user?.phone || '');
  const [payment, setPayment] = useState('UPI');
  const [instructions, setInstructions] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const place = () => {
    if (!name.trim()) return setError('Please add your name');
    if (phone.replace(/\D/g, '').length !== 10) return setError('Enter a 10-digit mobile number');
    if (orderType === 'Delivery' && !addressId) return setError('Add a delivery address');
    if (orderType === 'Dine-in' && !tableNo) return setError('Pick your table number');
    setError('');
    setBusy(true);
    // the template fakes the payment screen; a connected restaurant opens its payment gateway here
    setTimeout(() => {
      const order = actions.placeOrder({
        name: name.trim(),
        phone: phone.replace(/\D/g, ''),
        payment,
        instructions: instructions.trim(),
      });
      success();
      setBusy(false);
      navigation.reset({ index: 1, routes: [{ name: 'Main' }, { name: 'Track', params: { id: order.id, fresh: true } }] });
    }, 900);
  };

  return (
    <Screen>
      <Header title="Checkout" onBack={() => navigation.goBack()} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 140 }}>
          {/* Where */}
          {orderType === 'Delivery' ? (
            <>
              <SectionTitle title="Deliver to" style={{ marginTop: 6 }} action="Change" onAction={() => navigation.navigate('Addresses')} />
              {addresses.length ? (
                addresses.map((a) => (
                  <Pressable key={a.id} onPress={() => { tap(); actions.set({ addressId: a.id }); }} style={[styles.pick, a.id === addressId && styles.pickOn]}>
                    <Ionicons name={a.label === 'Work' ? 'briefcase-outline' : 'home-outline'} size={20} color={colors.primary} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.pickTitle}>{a.label}</Text>
                      <Text style={styles.pickSub} numberOfLines={2}>{a.line}, {a.area}</Text>
                    </View>
                    <Radio on={a.id === addressId} />
                  </Pressable>
                ))
              ) : (
                <Pressable onPress={() => navigation.navigate('Addresses')} style={[styles.pick, { borderStyle: 'dashed' }]}>
                  <Ionicons name="add-circle-outline" size={22} color={colors.primary} />
                  <Text style={[styles.pickTitle, { color: colors.primary }]}>Add a delivery address</Text>
                </Pressable>
              )}
            </>
          ) : null}

          {orderType === 'Takeaway' ? (
            <>
              <SectionTitle title="Pick up from" style={{ marginTop: 6 }} />
              <View style={[styles.pick, { marginBottom: 12 }]}>
                <Ionicons name="storefront-outline" size={20} color={colors.primary} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.pickTitle}>{BRAND.name}</Text>
                  <Text style={styles.pickSub} numberOfLines={2}>{BRAND.address}</Text>
                </View>
              </View>
              <View style={styles.pills}>
                {PICKUPS.map((p) => (
                  <Pressable key={p} onPress={() => { tap(); actions.set({ pickup: p }); }} style={[styles.pill, pickup === p && styles.pillOn]}>
                    <Text style={[styles.pillText, pickup === p && { color: colors.onPrimary }]}>{p}</Text>
                  </Pressable>
                ))}
              </View>
            </>
          ) : null}

          {orderType === 'Dine-in' ? (
            <>
              <SectionTitle title="Your table" style={{ marginTop: 6 }} />
              <View style={styles.tables}>
                {Array.from({ length: BRAND.tables }, (_, i) => i + 1).map((n) => (
                  <Pressable key={n} onPress={() => { tap(); actions.set({ tableNo: n }); }} style={[styles.table, tableNo === n && styles.pillOn]}>
                    <Text style={[styles.pillText, tableNo === n && { color: colors.onPrimary }]}>{n}</Text>
                  </Pressable>
                ))}
              </View>
              <Text style={styles.hint}>In the real app, scanning the QR code on your table fills this in for you.</Text>
            </>
          ) : null}

          {/* Who */}
          <SectionTitle title="Your details" />
          <Card style={{ marginHorizontal: 16, gap: 10 }}>
            <TextInput value={name} onChangeText={setName} placeholder="Name" placeholderTextColor={colors.muted} style={styles.input} />
            <TextInput value={phone} onChangeText={(t) => setPhone(t.replace(/\D/g, '').slice(0, 10))} placeholder="Mobile number" placeholderTextColor={colors.muted} keyboardType="number-pad" style={styles.input} />
            <TextInput value={instructions} onChangeText={setInstructions} placeholder="Note for the restaurant (optional)" placeholderTextColor={colors.muted} style={styles.input} maxLength={140} />
          </Card>

          {/* Pay */}
          <SectionTitle title="Payment" />
          {PAYMENTS.filter((p) => orderType === 'Delivery' || p.key !== 'Cash' || true).map((p) => (
            <Pressable key={p.key} onPress={() => { tap(); setPayment(p.key); }} style={[styles.pick, payment === p.key && styles.pickOn]}>
              <Ionicons name={p.icon} size={21} color={colors.primary} />
              <View style={{ flex: 1 }}>
                <Text style={styles.pickTitle}>{p.key === 'Cash' ? (orderType === 'Delivery' ? 'Cash on delivery' : 'Pay at the restaurant') : p.title}</Text>
                <Text style={styles.pickSub}>{p.sub}</Text>
              </View>
              <Radio on={payment === p.key} />
            </Pressable>
          ))}
          {DEMO ? (
            <View style={styles.demoNote}>
              <Ionicons name="information-circle" size={16} color={colors.info} />
              <Text style={styles.demoNoteText}>Template preview: no money is taken and no order reaches a kitchen. A connected restaurant opens its own payment gateway here.</Text>
            </View>
          ) : null}

          <SectionTitle title="Order summary" />
          <Card style={{ marginHorizontal: 16 }}>
            <BillRows bill={bill} orderType={orderType} />
          </Card>
          {error ? <Text style={styles.error}>{error}</Text> : null}
        </ScrollView>
      </KeyboardAvoidingView>

      <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 14) }]}>
        <Button title={busy ? 'Placing order' : `Place order  ${money(bill.total)}`} loading={busy} onPress={place} style={{ flex: 1 }} />
      </View>
    </Screen>
  );
}

function Radio({ on }) {
  return (
    <View style={[styles.radio, on && { borderColor: colors.primary }]}>
      {on ? <View style={styles.radioDot} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  pick: { flexDirection: 'row', alignItems: 'center', gap: 12, marginHorizontal: 16, marginBottom: 10, padding: 14, borderRadius: radius.lg, backgroundColor: colors.surface, borderWidth: 1.5, borderColor: colors.border },
  pickOn: { borderColor: colors.primary, backgroundColor: colors.primarySoft },
  pickTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  pickSub: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 1 },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: colors.borderStrong, alignItems: 'center', justifyContent: 'center' },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.primary },
  pills: { flexDirection: 'row', gap: 8, paddingHorizontal: 16 },
  pill: { paddingVertical: 10, paddingHorizontal: 16, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  pillOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  pillText: { fontFamily: font.semiBold, fontSize: 13, color: colors.inkSoft },
  tables: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16 },
  table: { width: 52, height: 46, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  hint: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, paddingHorizontal: 16, marginTop: 10 },
  input: { fontFamily: font.regular, fontSize: 14.5, color: colors.ink, backgroundColor: colors.bg, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 12, outlineStyle: 'none' },
  demoNote: { flexDirection: 'row', gap: 8, marginHorizontal: 16, padding: 12, borderRadius: radius.md, backgroundColor: colors.infoBg },
  demoNoteText: { flex: 1, fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, lineHeight: 17 },
  error: { fontFamily: font.semiBold, fontSize: 13, color: colors.danger, textAlign: 'center', marginTop: 16, paddingHorizontal: 16 },
  footer: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', paddingHorizontal: 16, paddingTop: 12, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
});
