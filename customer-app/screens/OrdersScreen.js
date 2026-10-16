import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { Card, Chip, EmptyState, Screen } from '../components/ui';
import { useApp, useNow } from '../store/AppContext';
import { FLOWS, isFinished, stageIndex } from '../store/orderFlow';
import { colors, font, radius } from '../theme';
import { dateTime, money } from '../utils/format';
import { tap } from '../utils/haptics';

export default function OrdersScreen({ navigation }) {
  const { orders, actions } = useApp();
  const now = useNow(1000);
  const [tab, setTab] = useState('active');
  const list = orders.filter((o) => (tab === 'active' ? !isFinished(o, now) : isFinished(o, now)));

  return (
    <Screen>
      <View style={{ paddingHorizontal: 16, paddingTop: 12 }}>
        <Text style={styles.title}>Your orders</Text>
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
          <Chip label="Active" active={tab === 'active'} onPress={() => setTab('active')} />
          <Chip label="Past orders" active={tab === 'past'} onPress={() => setTab('past')} />
        </View>
      </View>
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ padding: 16, paddingBottom: 150, gap: 12 }}>
        {list.length === 0 ? (
          <EmptyState
            icon="receipt-outline"
            title={tab === 'active' ? 'No active orders' : 'No past orders yet'}
            text={tab === 'active' ? 'Orders you place show up here so you can track them live.' : 'Your finished orders will be listed here for easy reordering.'}
            action="Order now"
            onAction={() => navigation.navigate('Menu')}
          />
        ) : (
          list.map((o) => {
            const flow = FLOWS[o.type] || FLOWS.Delivery;
            const idx = stageIndex(o, now);
            const fin = isFinished(o, now);
            return (
              <Pressable key={o.id} onPress={() => { tap(); navigation.navigate('Track', { id: o.id }); }}>
                <Card>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Text style={styles.num}>{o.number}</Text>
                    <View style={[styles.status, { backgroundColor: fin ? colors.successBg : colors.primarySoft }]}>
                      <Text style={[styles.statusText, { color: fin ? colors.success : colors.primary }]}>{flow[idx]}</Text>
                    </View>
                  </View>
                  <Text style={styles.when}>{dateTime(o.createdAt)} - {o.type}</Text>
                  <Text style={styles.items} numberOfLines={2}>{o.items.map((l) => `${l.qty} x ${l.name}`).join(', ')}</Text>
                  <View style={styles.foot}>
                    <Text style={styles.total}>{money(o.bill.total)}</Text>
                    <View style={{ flexDirection: 'row', gap: 8 }}>
                      {fin ? (
                        <Pressable onPress={() => { tap(); actions.reorder(o); navigation.navigate('Cart'); }} style={styles.btnOutline}>
                          <Ionicons name="refresh" size={14} color={colors.primary} />
                          <Text style={styles.btnOutlineText}>Reorder</Text>
                        </Pressable>
                      ) : (
                        <View style={styles.btnSolid}>
                          <Ionicons name="navigate" size={14} color={colors.onPrimary} />
                          <Text style={styles.btnSolidText}>Track</Text>
                        </View>
                      )}
                    </View>
                  </View>
                </Card>
              </Pressable>
            );
          })
        )}
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontFamily: font.bold, fontSize: 24, color: colors.ink },
  num: { fontFamily: font.bold, fontSize: 15, color: colors.ink },
  status: { paddingVertical: 4, paddingHorizontal: 12, borderRadius: radius.pill },
  statusText: { fontFamily: font.semiBold, fontSize: 12 },
  when: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 4 },
  items: { fontFamily: font.regular, fontSize: 13.5, color: colors.ink, marginTop: 10, lineHeight: 20 },
  foot: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 14, paddingTop: 12, borderTopWidth: 1, borderTopColor: colors.border },
  total: { fontFamily: font.bold, fontSize: 16, color: colors.ink },
  btnOutline: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingVertical: 8, paddingHorizontal: 14, borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.primary },
  btnOutlineText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.primary },
  btnSolid: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingVertical: 8, paddingHorizontal: 14, borderRadius: radius.pill, backgroundColor: colors.primary },
  btnSolidText: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.onPrimary },
});
