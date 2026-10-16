import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { apiGet, apiPostJson } from '../config/api';
import { colors, font, radius, spacing } from '../theme';
import { feedback } from './ui/Feedback';

// "Rider" on a delivery order's detail (data: api/delivery_dispatch.php). Shows who has the delivery and
// where it is, and lets the owner or a manager give it to a rider, change the rider or take it back.
// Hidden when the restaurant has no riders yet (the QR link for outside riders still works below it).
const STEP = { Assigned: 'Assigned', Picked_Up: 'Picked up', In_Transit: 'On the way', Delivered: 'Delivered' };

export default function RiderAssign({ orderId }) {
  const [d, setD] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setD(await apiGet(`/api/delivery_dispatch.php?order_id=${orderId}`));
    } catch (e) {
      setD({ riders: [], error: true });
    }
  }, [orderId]);
  useEffect(() => { load(); }, [load]);

  if (!d) return <ActivityIndicator size="small" color={colors.primary} style={{ marginTop: spacing.sm }} />;
  if (d.error || !(d.riders || []).length) return null;

  const del = d.delivery || {};
  const moving = ['Picked_Up', 'In_Transit'].includes(del.delivery_status);
  const done = del.delivery_status === 'Delivered';

  const pick = async () => {
    const options = d.riders.map((r) => ({
      label: `${r.name}${r.id === del.rider_staff_id ? ' (now)' : ''}`,
      value: r.id,
      icon: 'bicycle',
      sub: r.active ? `Carrying ${r.active}` : 'Free',
    }));
    if (del.rider_staff_id && !moving) options.push({ label: 'Take it back', value: 0, icon: 'close-circle', tone: 'danger' });
    const choice = await feedback.choose({ title: del.rider_staff_id ? 'Change rider' : 'Give this delivery to', options });
    if (choice === null || choice === undefined) return;
    setBusy(true);
    try {
      const r = await apiPostJson('/api/delivery_dispatch.php', { action: 'assign', order_id: orderId, rider_id: choice });
      feedback.success(r.message || 'Done');
      await load();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={styles.box}>
      <View style={styles.row}>
        <Ionicons name="bicycle" size={18} color={colors.primary} />
        <Text style={styles.text} numberOfLines={1}>
          {del.rider_name ? `${del.rider_name} · ${STEP[del.delivery_status] || 'Assigned'}` : 'No rider yet'}
        </Text>
        {!done ? (
          <Pressable style={styles.btn} onPress={pick} disabled={busy} accessibilityLabel={del.rider_name ? 'Change rider' : 'Assign rider'}>
            {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.btnText}>{del.rider_name ? 'Change' : 'Assign rider'}</Text>}
          </Pressable>
        ) : null}
      </View>
      {del.cod_due > 0 && !done ? <Text style={styles.sub}>Rider collects {del.cod_due} in cash</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  box: { marginTop: spacing.sm, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.primaryLight, gap: 4 },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  text: { flex: 1, fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  sub: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginLeft: 26 },
  btn: { backgroundColor: colors.primary, borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 8, minWidth: 70, alignItems: 'center' },
  btnText: { fontFamily: font.semiBold, fontSize: 13, color: '#fff' },
});
