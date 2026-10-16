import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { MenuRow } from '../components/food';
import { BrandMark, Button, Card, Chip, EmptyState, Header, Row, Screen, Stepper } from '../components/ui';
import { BRAND, DEMO } from '../config/brand';
import { COUPONS, itemById } from '../data/restaurant';
import { useApp } from '../store/AppContext';
import { colors, font, radius } from '../theme';
import { shortDate } from '../utils/format';
import { success, tap } from '../utils/haptics';

// ── Offers ──────────────────────────────────────────────────────────────────────────────────
export function OffersScreen({ navigation }) {
  const { coupon, actions, showToast, cartCount } = useApp();
  return (
    <Screen>
      <Header title="Offers for you" onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 12 }}>
        {COUPONS.map((c) => {
          const on = coupon === c.code;
          return (
            <View key={c.code} style={styles.coupon}>
              <View style={styles.couponLeft}>
                <Ionicons name="pricetag" size={22} color={colors.onPrimary} />
                <Text style={styles.couponValue}>{c.type === 'flat' ? '₹' + c.value : c.value + '%'}</Text>
                <Text style={styles.couponOff}>OFF</Text>
              </View>
              <View style={{ flex: 1, padding: 14 }}>
                <Text style={styles.couponCode}>{c.code}</Text>
                <Text style={styles.couponText}>{c.text}</Text>
                <Text style={styles.couponHint}>{c.hint}</Text>
                <Pressable
                  onPress={() => {
                    tap();
                    actions.set({ coupon: on ? null : c.code });
                    showToast(on ? 'Coupon removed' : cartCount ? `${c.code} applied to your cart` : `${c.code} saved. Add dishes to use it`, on ? 'dark' : 'success');
                  }}
                  style={[styles.couponBtn, on && { backgroundColor: colors.primary }]}
                >
                  <Text style={[styles.couponBtnText, on && { color: colors.onPrimary }]}>{on ? 'Applied' : 'Use this offer'}</Text>
                </Pressable>
              </View>
            </View>
          );
        })}
        <Button title="Start ordering" onPress={() => navigation.navigate('Main', { screen: 'Menu' })} style={{ marginTop: 8 }} />
      </ScrollView>
    </Screen>
  );
}

// ── Favourites ──────────────────────────────────────────────────────────────────────────────
export function FavoritesScreen({ navigation }) {
  const { favorites } = useApp();
  const items = favorites.map(itemById).filter(Boolean);
  return (
    <Screen>
      <Header title="Favourites" onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={{ paddingBottom: 60 }}>
        {items.length === 0 ? (
          <EmptyState icon="heart-outline" title="No favourites yet" text="Tap the heart on any dish to keep it here for next time." action="Browse the menu" onAction={() => navigation.navigate('Main', { screen: 'Menu' })} />
        ) : (
          items.map((i) => <MenuRow key={i.id} item={i} />)
        )}
      </ScrollView>
    </Screen>
  );
}

// ── Addresses ───────────────────────────────────────────────────────────────────────────────
export function AddressesScreen({ navigation }) {
  const { addresses, addressId, actions, orderType } = useApp();
  const [adding, setAdding] = useState(addresses.length === 0);
  const [label, setLabel] = useState('Home');
  const [line, setLine] = useState('');
  const [area, setArea] = useState('Indiranagar, Bengaluru');
  const [error, setError] = useState('');

  const save = () => {
    if (line.trim().length < 5) return setError('Add your flat, building and street');
    actions.addAddress({ id: 'a' + Date.now(), label, line: line.trim(), area: area.trim() || BRAND.area });
    setLine('');
    setError('');
    setAdding(false);
  };

  return (
    <Screen>
      <Header title="Saved addresses" onBack={() => navigation.goBack()} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 10 }}>
        {orderType !== 'Delivery' ? (
          <View style={styles.notice}><Text style={styles.noticeText}>You are ordering for {orderType.toLowerCase()}, so no address is needed. Addresses are used for delivery.</Text></View>
        ) : null}
        {addresses.map((a) => (
          <Pressable key={a.id} onPress={() => { tap(); actions.set({ addressId: a.id }); }} style={[styles.addr, a.id === addressId && { borderColor: colors.primary, backgroundColor: colors.primarySoft }]}>
            <Ionicons name={a.label === 'Work' ? 'briefcase' : a.label === 'Other' ? 'location' : 'home'} size={20} color={colors.primary} />
            <View style={{ flex: 1 }}>
              <Text style={styles.addrLabel}>{a.label}</Text>
              <Text style={styles.addrLine}>{a.line}, {a.area}</Text>
            </View>
            <Pressable onPress={() => actions.deleteAddress(a.id)} hitSlop={8}><Ionicons name="trash-outline" size={19} color={colors.danger} /></Pressable>
          </Pressable>
        ))}

        {adding ? (
          <Card style={{ gap: 12 }}>
            <Text style={styles.addrLabel}>New address</Text>
            <View style={{ flexDirection: 'row', gap: 8 }}>
              {['Home', 'Work', 'Other'].map((l) => <Chip key={l} label={l} active={label === l} onPress={() => setLabel(l)} />)}
            </View>
            <TextInput value={line} onChangeText={setLine} placeholder="Flat, building, street" placeholderTextColor={colors.muted} style={styles.input} />
            <TextInput value={area} onChangeText={setArea} placeholder="Area and city" placeholderTextColor={colors.muted} style={styles.input} />
            {error ? <Text style={{ fontFamily: font.medium, fontSize: 12.5, color: colors.danger }}>{error}</Text> : null}
            <Button title="Save address" onPress={save} />
          </Card>
        ) : (
          <Button title="Add a new address" variant="outline" icon="add" onPress={() => setAdding(true)} />
        )}
        {addresses.length > 0 && !adding ? <Button title="Done" onPress={() => navigation.goBack()} /> : null}
      </ScrollView>
    </Screen>
  );
}

// ── Reserve a table ─────────────────────────────────────────────────────────────────────────
const SLOTS = ['12:00 PM', '1:00 PM', '2:00 PM', '7:00 PM', '8:00 PM', '9:00 PM', '10:00 PM'];

export function ReserveScreen({ navigation }) {
  const { user, reservations, actions, showToast } = useApp();
  const days = Array.from({ length: 7 }, (_, i) => {
    const d = new Date();
    d.setDate(d.getDate() + i);
    return d;
  });
  const [day, setDay] = useState(0);
  const [slot, setSlot] = useState('8:00 PM');
  const [guests, setGuests] = useState(2);
  const [name, setName] = useState(user && !user.guest ? user.name : '');
  const [phone, setPhone] = useState(user?.phone || '');
  const [error, setError] = useState('');
  const [done, setDone] = useState(null);

  const book = () => {
    if (!name.trim()) return setError('Please add your name');
    if (phone.replace(/\D/g, '').length !== 10) return setError('Enter a 10-digit mobile number');
    const r = { id: String(Date.now()), when: days[day].getTime(), slot, guests, name: name.trim() };
    actions.addReservation(r);
    success();
    setDone(r);
    showToast('Table requested', 'success');
  };

  if (done) {
    return (
      <Screen>
        <Header title="Book a table" onBack={() => navigation.goBack()} />
        <View style={{ padding: 24, alignItems: 'center' }}>
          <View style={styles.bigCheck}><Ionicons name="checkmark" size={46} color="#fff" /></View>
          <Text style={styles.doneTitle}>You are booked in</Text>
          <Text style={styles.doneText}>
            {done.guests} {done.guests === 1 ? 'guest' : 'guests'}, {shortDate(done.when)} at {done.slot}.{'\n'}{BRAND.name} will confirm by SMS.
          </Text>
          <Button title="Back to home" onPress={() => navigation.navigate('Main', { screen: 'Home' })} style={{ marginTop: 24, alignSelf: 'stretch' }} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen>
      <Header title="Book a table" subtitle={BRAND.name} onBack={() => navigation.goBack()} />
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, paddingBottom: 60 }}>
        <Text style={styles.label}>Date</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
          {days.map((d, i) => (
            <Pressable key={i} onPress={() => { tap(); setDay(i); }} style={[styles.day, day === i && styles.dayOn]}>
              <Text style={[styles.dayName, day === i && { color: colors.onPrimary }]}>{i === 0 ? 'Today' : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getDay()]}</Text>
              <Text style={[styles.dayNum, day === i && { color: colors.onPrimary }]}>{d.getDate()}</Text>
            </Pressable>
          ))}
        </ScrollView>

        <Text style={[styles.label, { marginTop: 20 }]}>Time</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {SLOTS.map((s) => <Chip key={s} label={s} active={slot === s} onPress={() => setSlot(s)} />)}
        </View>

        <Text style={[styles.label, { marginTop: 20 }]}>Guests</Text>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Stepper qty={guests} large onMinus={() => setGuests(Math.max(1, guests - 1))} onPlus={() => setGuests(Math.min(20, guests + 1))} />
          <Text style={{ fontFamily: font.regular, fontSize: 13, color: colors.inkSoft }}>{guests === 1 ? 'guest' : 'guests'}</Text>
        </View>

        <Text style={[styles.label, { marginTop: 20 }]}>Your details</Text>
        <View style={{ gap: 10 }}>
          <TextInput value={name} onChangeText={setName} placeholder="Name" placeholderTextColor={colors.muted} style={styles.input} />
          <TextInput value={phone} onChangeText={(t) => setPhone(t.replace(/\D/g, '').slice(0, 10))} placeholder="Mobile number" placeholderTextColor={colors.muted} keyboardType="number-pad" style={styles.input} />
        </View>
        {error ? <Text style={{ fontFamily: font.medium, fontSize: 12.5, color: colors.danger, marginTop: 10 }}>{error}</Text> : null}
        <Button title="Request this table" onPress={book} style={{ marginTop: 20 }} />

        {reservations.length ? (
          <>
            <Text style={[styles.label, { marginTop: 28 }]}>Your bookings</Text>
            {reservations.map((r) => (
              <Card key={r.id} style={{ marginBottom: 8, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                <Ionicons name="calendar" size={20} color={colors.primary} />
                <Text style={{ fontFamily: font.medium, fontSize: 13.5, color: colors.ink }}>{shortDate(r.when)}, {r.slot} - {r.guests} guests</Text>
              </Card>
            ))}
          </>
        ) : null}
      </ScrollView>
    </Screen>
  );
}

// ── About ───────────────────────────────────────────────────────────────────────────────────
export function AboutScreen({ navigation }) {
  const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  const today = (new Date().getDay() + 6) % 7;
  return (
    <Screen>
      <Header title="About" onBack={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 60, gap: 14 }}>
        <View style={{ alignItems: 'center', paddingVertical: 8 }}>
          <BrandMark size={72} />
          <Text style={styles.aboutName}>{BRAND.name}</Text>
          <Text style={styles.aboutTag}>{BRAND.tagline}</Text>
          <Text style={styles.aboutCuisine}>{BRAND.cuisine}</Text>
        </View>
        <Card style={{ padding: 0, overflow: 'hidden' }}>
          <Row icon="location-outline" label="Address" value={BRAND.address} onPress={() => Linking.openURL('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(BRAND.name + ' ' + BRAND.address))} />
          <Row icon="call-outline" label="Phone" value={BRAND.phone} onPress={() => Linking.openURL('tel:' + BRAND.phone.replace(/\s/g, ''))} />
          <Row icon="mail-outline" label="Email" value={BRAND.email} onPress={() => Linking.openURL('mailto:' + BRAND.email)} />
          <Row icon="logo-instagram" label="Instagram" value={BRAND.instagram} last />
        </Card>
        <Card>
          <Text style={styles.label}>Opening hours</Text>
          {days.map((d, i) => (
            <View key={d} style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 5 }}>
              <Text style={[styles.hrDay, i === today && { color: colors.primary, fontFamily: font.semiBold }]}>{d}{i === today ? ' (today)' : ''}</Text>
              <Text style={[styles.hrTime, i === today && { color: colors.primary, fontFamily: font.semiBold }]}>{BRAND.hours.label}</Text>
            </View>
          ))}
        </Card>
        {DEMO ? <Text style={styles.powered}>This app is a template by RestroGrow. Your restaurant's details appear here.</Text> : null}
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  coupon: { flexDirection: 'row', backgroundColor: colors.surface, borderRadius: radius.lg, overflow: 'hidden', borderWidth: 1, borderColor: colors.border },
  couponLeft: { width: 86, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', gap: 2, paddingVertical: 16 },
  couponValue: { fontFamily: font.bold, fontSize: 22, color: colors.onPrimary },
  couponOff: { fontFamily: font.bold, fontSize: 11, color: colors.onPrimary, letterSpacing: 1 },
  couponCode: { fontFamily: font.bold, fontSize: 15, color: colors.ink, letterSpacing: 0.6 },
  couponText: { fontFamily: font.medium, fontSize: 13, color: colors.ink, marginTop: 3 },
  couponHint: { fontFamily: font.regular, fontSize: 11.5, color: colors.inkSoft, marginTop: 2 },
  couponBtn: { alignSelf: 'flex-start', marginTop: 10, paddingVertical: 7, paddingHorizontal: 14, borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.primary },
  couponBtnText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.primary },

  notice: { padding: 12, borderRadius: radius.md, backgroundColor: colors.infoBg },
  noticeText: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, lineHeight: 18 },
  addr: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: radius.lg, backgroundColor: colors.surface, borderWidth: 1.5, borderColor: colors.border },
  addrLabel: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  addrLine: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 1 },
  input: { fontFamily: font.regular, fontSize: 14.5, color: colors.ink, backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 12, outlineStyle: 'none' },

  label: { fontFamily: font.semiBold, fontSize: 15, color: colors.ink, marginBottom: 10 },
  day: { width: 62, alignItems: 'center', paddingVertical: 10, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  dayOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  dayName: { fontFamily: font.medium, fontSize: 11.5, color: colors.inkSoft },
  dayNum: { fontFamily: font.bold, fontSize: 19, color: colors.ink },
  bigCheck: { width: 88, height: 88, borderRadius: 44, backgroundColor: colors.success, alignItems: 'center', justifyContent: 'center', marginTop: 24 },
  doneTitle: { fontFamily: font.bold, fontSize: 22, color: colors.ink, marginTop: 18 },
  doneText: { fontFamily: font.regular, fontSize: 14, color: colors.inkSoft, textAlign: 'center', lineHeight: 22, marginTop: 6 },

  aboutName: { fontFamily: font.bold, fontSize: 22, color: colors.ink, marginTop: 12 },
  aboutTag: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft, marginTop: 2 },
  aboutCuisine: { fontFamily: font.medium, fontSize: 12, color: colors.primary, marginTop: 6 },
  hrDay: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft },
  hrTime: { fontFamily: font.medium, fontSize: 13.5, color: colors.ink },
  powered: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, textAlign: 'center' },
});
