import { Ionicons } from '@expo/vector-icons';
import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { feedback } from '../components/ui/Feedback';
import { apiGet, apiPostJson } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';

// More › Riders (data: api/delivery_dispatch.php), the same board as the website's Delivery › Riders:
// each rider with what they carry, deliveries today and cash in hand (Settle cash), the delivery orders
// under way with Assign, and "riders can pick open deliveries themselves".
const STEP = { Pending: ['No rider yet', colors.muted], Preparing: ['No rider yet', colors.muted], Assigned: ['Assigned', colors.info], Picked_Up: ['Picked up', colors.warning], In_Transit: ['On the way', colors.primary] };

function money(n) {
  return `₹${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
}
function ago(ts) {
  if (!ts) return 'no location yet';
  const m = Math.round((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000);
  return m < 1 ? 'location now' : m < 60 ? `location ${m} min ago` : `location ${Math.round(m / 60)} h ago`;
}

export default function RidersScreen({ navigation }) {
  const [d, setD] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState(null);
  const { refresh } = useAuth();

  const load = useCallback(async () => {
    try {
      setD(await apiGet('/api/delivery_dispatch.php'));
    } catch (e) {
      feedback.errorFrom(e, 'Could not load riders');
    }
  }, []);
  useFocusEffect(useCallback(() => {
    load();
    const t = setInterval(load, 20000);
    return () => clearInterval(t);
  }, [load]));

  const post = async (key, body) => {
    setBusy(key);
    try {
      const r = await apiPostJson('/api/delivery_dispatch.php', body);
      feedback.success(r.message || 'Done');
      if (body.action === 'settings' && refresh) refresh(true); // the More tile follows the switch
      await load();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(null);
    }
  };

  const assign = async (del) => {
    const options = d.riders.map((r) => ({ label: r.name, value: r.id, icon: 'bicycle', sub: r.active ? `Carrying ${r.active}` : 'Free' }));
    if (del.rider_staff_id) options.push({ label: 'Take it back', value: 0, icon: 'close-circle', tone: 'danger' });
    const choice = await feedback.choose({ title: `${del.order_number}: give to`, options });
    if (choice === null || choice === undefined) return;
    post(`a${del.id}`, { action: 'assign', order_id: del.id, rider_id: choice });
  };
  const settle = async (r) => {
    const ok = await feedback.confirm({ title: 'Settle cash?', message: `${r.name} hands over ${money(r.cash_in_hand)} in cash.`, confirmText: 'Cash received' });
    if (ok) post(`s${r.id}`, { action: 'settle', rider_id: r.id });
  };

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScreenHeader eyebrow="Delivery" title="Riders" onBack={navigation?.canGoBack() ? () => navigation.goBack() : undefined} />
      {!d ? (
        <ActivityIndicator size="large" color={colors.primary} style={{ marginTop: 40 }} />
      ) : d.enabled === false ? (
        <View style={{ padding: spacing.lg }}>
          <View style={[styles.card, shadow.sm, { alignItems: 'center', gap: spacing.md }]}>
            <Ionicons name="bicycle" size={34} color={colors.muted} />
            <Text style={[styles.name, { textAlign: 'center' }]}>Own riders are switched off</Text>
            <Text style={[styles.sub, { textAlign: 'center' }]}>Turn on "We deliver with our own riders" to add riders, assign deliveries and settle their cash.</Text>
            {d.can_change ? (
              <Pressable style={[styles.btn, { alignSelf: 'stretch' }]} onPress={() => post('own', { action: 'settings', own_riders: 1 })} disabled={busy === 'own'}>
                {busy === 'own' ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.btnText}>Turn on own riders</Text>}
              </Pressable>
            ) : null}
          </View>
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={{ padding: spacing.lg, gap: spacing.md, paddingBottom: 60 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} tintColor={colors.primary} />}
        >
          {d.can_change ? (
            <View style={[styles.card, styles.rowCard, shadow.sm]}>
              <Text style={[styles.text, { flex: 1 }]}>We deliver with our own riders</Text>
              <Switch value onValueChange={(v) => !v && post('own', { action: 'settings', own_riders: 0 })} trackColor={{ true: colors.primary }} />
            </View>
          ) : null}
          <View style={[styles.card, styles.rowCard, shadow.sm]}>
            <Text style={[styles.text, { flex: 1 }]}>Riders can pick open deliveries themselves</Text>
            <Switch value={!!d.settings?.self_pick} onValueChange={(v) => post('self', { action: 'settings', self_pick: v ? 1 : 0 })} trackColor={{ true: colors.primary }} />
          </View>

          <Text style={styles.h2}>Riders <Text style={styles.h2n}>{d.riders.length}</Text></Text>
          {d.riders.length ? d.riders.map((r) => (
            <View key={r.id} style={[styles.card, shadow.sm]}>
              <View style={styles.rowCard}>
                <View style={styles.av}><Text style={styles.avText}>{(r.name || 'R').charAt(0)}</Text></View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.name}>{r.name}</Text>
                  <Text style={styles.sub}>{r.phone} · {ago(r.last_seen)}</Text>
                </View>
              </View>
              <View style={styles.stats}>
                {[['carrying', r.active], ['today', r.delivered_today], ['cash', money(r.cash_in_hand)]].map(([l, v]) => (
                  <View key={l} style={styles.stat}><Text style={styles.statV}>{v}</Text><Text style={styles.statL}>{l}</Text></View>
                ))}
              </View>
              {r.cash_in_hand > 0 ? (
                <Pressable style={styles.soft} onPress={() => settle(r)} disabled={busy === `s${r.id}`}>
                  <Ionicons name="cash-outline" size={17} color={colors.ink} />
                  <Text style={styles.softText}>Settle {money(r.cash_in_hand)}</Text>
                </Pressable>
              ) : null}
            </View>
          )) : (
            <View style={styles.card}><Text style={styles.sub}>No riders yet. Add one on the website in Staff with the role "Delivery rider".</Text></View>
          )}

          <Text style={styles.h2}>Deliveries under way <Text style={styles.h2n}>{d.deliveries.length}</Text></Text>
          {d.deliveries.length ? d.deliveries.map((del) => {
            const st = STEP[del.delivery_status] || STEP.Pending;
            const moving = ['Picked_Up', 'In_Transit'].includes(del.delivery_status);
            return (
              <View key={del.id} style={[styles.card, shadow.sm]}>
                <View style={styles.rowCard}>
                  <Text style={[styles.name, { flex: 1 }]}>{del.order_number}</Text>
                  <View style={[styles.pill, { backgroundColor: st[1] }]}><Text style={styles.pillText}>{st[0]}</Text></View>
                </View>
                <Text style={styles.sub} numberOfLines={2}>{del.customer_name} · {del.address || 'No address'}</Text>
                <Text style={styles.sub}>
                  {del.rider_name || (del.via_qr ? 'Outside rider (QR link)' : 'No rider yet')} · {del.cod_due > 0 ? `collect ${money(del.cod_due)} cash` : del.payment_status === 'Paid' ? 'paid' : 'online, payment pending'}
                </Text>
                {d.riders.length && !moving ? (
                  <Pressable style={styles.btn} onPress={() => assign(del)} disabled={busy === `a${del.id}`}>
                    {busy === `a${del.id}` ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.btnText}>{del.rider_name ? 'Change rider' : 'Assign rider'}</Text>}
                  </Pressable>
                ) : null}
              </View>
            );
          }) : (
            <View style={styles.card}><Text style={styles.sub}>No delivery orders under way.</Text></View>
          )}
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  h2: { fontFamily: font.bold, fontSize: 16, color: colors.ink, marginTop: spacing.sm },
  h2n: { fontFamily: font.medium, color: colors.muted },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: spacing.lg, gap: spacing.sm },
  rowCard: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  text: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  av: { width: 42, height: 42, borderRadius: 21, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
  avText: { fontFamily: font.bold, fontSize: 17, color: '#fff' },
  name: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  sub: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft },
  stats: { flexDirection: 'row', gap: spacing.sm },
  stat: { flex: 1, backgroundColor: colors.bg, borderRadius: radius.sm, padding: spacing.sm, alignItems: 'center' },
  statV: { fontFamily: font.bold, fontSize: 16, color: colors.ink },
  statL: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted },
  soft: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: '#F3EFE8', borderRadius: radius.md, paddingVertical: 11 },
  softText: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  pill: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 999 },
  pillText: { fontFamily: font.semiBold, fontSize: 11.5, color: '#fff' },
  btn: { backgroundColor: colors.primary, borderRadius: radius.md, paddingVertical: 11, alignItems: 'center' },
  btnText: { fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
});
