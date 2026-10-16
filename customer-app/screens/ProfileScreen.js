import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { Linking, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';

import { Button, Card, Row, Screen } from '../components/ui';
import { BRAND, DEMO } from '../config/brand';
import { useApp } from '../store/AppContext';
import { colors, font, radius, shadow } from '../theme';
import { initialsOf } from '../utils/format';

const TIERS = [
  { name: 'Member', at: 0 },
  { name: 'Silver', at: 300 },
  { name: 'Gold', at: 800 },
];

export default function ProfileScreen({ navigation }) {
  const { user, points, orders, favorites, addresses, notifications, actions } = useApp();
  const guest = !user || user.guest;
  const tierIdx = TIERS.reduce((acc, t, i) => (points >= t.at ? i : acc), 0);
  const tier = TIERS[tierIdx];
  const nextTier = TIERS[tierIdx + 1];
  const pct = nextTier ? Math.min(1, (points - tier.at) / (nextTier.at - tier.at)) : 1;

  return (
    <Screen>
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 150 }}>
        <View style={styles.head}>
          <View style={styles.avatar}><Text style={styles.avatarText}>{guest ? '?' : initialsOf(user.name)}</Text></View>
          <View style={{ flex: 1 }}>
            <Text style={styles.name}>{guest ? 'Guest' : user.name}</Text>
            <Text style={styles.phone}>{guest ? 'Log in to save your orders and points' : '+91 ' + user.phone}</Text>
          </View>
        </View>

        {/* Loyalty card */}
        <View style={{ paddingHorizontal: 16 }}>
          <LinearGradient colors={[colors.primary, colors.primaryDark]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.loyalty}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
              <Text style={styles.loyaltyName}>{BRAND.loyaltyName}</Text>
              <View style={styles.tier}><Ionicons name="ribbon" size={13} color={colors.onPrimary} /><Text style={styles.tierText}>{tier.name}</Text></View>
            </View>
            <Text style={styles.points}>{points}</Text>
            <View style={styles.bar}><View style={[styles.barFill, { width: `${pct * 100}%` }]} /></View>
            <Text style={styles.loyaltySub}>
              {nextTier ? `${nextTier.at - points} more points to reach ${nextTier.name}` : 'You are at the top tier. Thank you!'}
            </Text>
          </LinearGradient>
        </View>

        <View style={styles.stats}>
          <Stat n={orders.length} label="Orders" />
          <Stat n={favorites.length} label="Favourites" />
          <Stat n={addresses.length} label="Addresses" />
        </View>

        <Card style={styles.group}>
          <Row icon="receipt-outline" label="My orders" onPress={() => navigation.navigate('Orders')} />
          <Row icon="heart-outline" label="Favourites" value={favorites.length ? `${favorites.length} saved` : undefined} onPress={() => navigation.navigate('Favorites')} />
          <Row icon="location-outline" label="Saved addresses" onPress={() => navigation.navigate('Addresses')} />
          <Row icon="pricetags-outline" label="Offers and coupons" onPress={() => navigation.navigate('Offers')} />
          <Row icon="calendar-outline" label="Book a table" onPress={() => navigation.navigate('Reserve')} last />
        </Card>

        <Card style={styles.group}>
          <Row
            icon="notifications-outline"
            label="Order updates and offers"
            right={<Switch value={notifications} onValueChange={(v) => actions.set({ notifications: v })} trackColor={{ false: colors.borderStrong, true: colors.primary }} thumbColor="#fff" />}
          />
          <Row icon="storefront-outline" label={`About ${BRAND.name}`} onPress={() => navigation.navigate('About')} />
          <Row icon="call-outline" label="Help and support" value={BRAND.phone} onPress={() => Linking.openURL('tel:' + BRAND.phone.replace(/\s/g, ''))} last />
        </Card>

        {DEMO ? (
          <View style={styles.template}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Ionicons name="sparkles" size={18} color={colors.primary} />
              <Text style={styles.templateTitle}>This is a template</Text>
            </View>
            <Text style={styles.templateText}>
              Your customers would get an app exactly like this on the Play Store, with your restaurant's name, logo, colours and menu. It is built and published for you by RestroGrow.
            </Text>
            <Button title="Get an app like this" icon="arrow-forward" small onPress={() => Linking.openURL('https://restrogrow.com')} style={{ alignSelf: 'flex-start', marginTop: 12 }} />
          </View>
        ) : null}

        <View style={{ paddingHorizontal: 16, marginTop: 8 }}>
          <Button
            title={guest ? 'Log in' : 'Log out'}
            variant="outline"
            icon={guest ? 'log-in-outline' : 'log-out-outline'}
            onPress={() => actions.set({ user: null })}
          />
          <Text style={styles.version}>{BRAND.name} - version 1.0.0{DEMO ? ' - Powered by RestroGrow' : ''}</Text>
        </View>
      </ScrollView>
    </Screen>
  );
}

function Stat({ n, label }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statN}>{n}</Text>
      <Text style={styles.statL}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, paddingTop: 18 },
  avatar: { width: 60, height: 60, borderRadius: 30, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderColor: colors.primary },
  avatarText: { fontFamily: font.bold, fontSize: 21, color: colors.primary },
  name: { fontFamily: font.bold, fontSize: 20, color: colors.ink },
  phone: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 1 },
  loyalty: { borderRadius: radius.xl, padding: 20, ...shadow.md },
  loyaltyName: { fontFamily: font.semiBold, fontSize: 14, color: colors.onPrimary, opacity: 0.9 },
  tier: { flexDirection: 'row', alignItems: 'center', gap: 5, backgroundColor: 'rgba(255,255,255,0.22)', paddingVertical: 4, paddingHorizontal: 10, borderRadius: radius.pill },
  tierText: { fontFamily: font.semiBold, fontSize: 12, color: colors.onPrimary },
  points: { fontFamily: font.bold, fontSize: 40, color: colors.onPrimary, marginTop: 4 },
  bar: { height: 6, borderRadius: 3, backgroundColor: 'rgba(255,255,255,0.28)', marginTop: 8, overflow: 'hidden' },
  barFill: { height: '100%', backgroundColor: colors.onPrimary, borderRadius: 3 },
  loyaltySub: { fontFamily: font.regular, fontSize: 12, color: colors.onPrimary, opacity: 0.9, marginTop: 8 },
  stats: { flexDirection: 'row', gap: 10, paddingHorizontal: 16, marginTop: 14 },
  stat: { flex: 1, alignItems: 'center', paddingVertical: 14, borderRadius: radius.lg, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  statN: { fontFamily: font.bold, fontSize: 20, color: colors.ink },
  statL: { fontFamily: font.regular, fontSize: 11.5, color: colors.inkSoft },
  group: { marginHorizontal: 16, marginTop: 14, padding: 0, overflow: 'hidden' },
  template: { marginHorizontal: 16, marginTop: 16, padding: 16, borderRadius: radius.lg, backgroundColor: colors.primarySoft, borderWidth: 1.5, borderColor: colors.primary, borderStyle: 'dashed' },
  templateTitle: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  templateText: { fontFamily: font.regular, fontSize: 12.5, lineHeight: 19, color: colors.inkSoft, marginTop: 6 },
  version: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, textAlign: 'center', marginTop: 16 },
});
