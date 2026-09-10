import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Badge from '../components/Badge';
import { apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';

const NEXT_STATUS = {
  Pending: 'Accepted',
  Accepted: 'Preparing',
  Preparing: 'Ready',
  Ready: 'Served',
  Served: 'Completed',
};

function Row({ label, value }) {
  if (!value) return null;
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

export default function OrderDetailScreen({ route, navigation }) {
  const { order: initialOrder } = route.params;
  const [order, setOrder] = useState(initialOrder);
  const [updating, setUpdating] = useState(false);
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const currency = user?.currency_symbol || '₹';

  const items = order.items || [];
  const isDelivery = order.order_type === 'Delivery';
  const next = NEXT_STATUS[order.order_status];

  const openInMaps = () => {
    const address = order.customer_address;
    if (!address) return;
    const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address);
    Linking.openURL(url).catch(() => {
      Alert.alert('Could not open Maps', 'Please check the address manually.');
    });
  };

  const advanceOrder = async () => {
    if (!next) return;
    setUpdating(true);
    try {
      const res = await apiPostForm('/api/update_order_status.php', {
        orderId: order.id,
        status: next,
      });
      if (!res.success) throw new Error(res.message || 'Update failed');
      setOrder((o) => ({ ...o, order_status: next }));
      navigation.setParams({ order: { ...order, order_status: next } });
    } catch (e) {
      Alert.alert('Could not update order', e.message);
    } finally {
      setUpdating(false);
    }
  };

  return (
    <View style={styles.fill}>
      <View style={[styles.header, { paddingTop: insets.top + spacing.sm }]}>
        <Pressable style={styles.backButton} onPress={() => navigation.goBack()} hitSlop={8}>
          <Ionicons name="arrow-back" size={20} color={colors.ink} />
        </Pressable>
        <Text style={styles.headerTitle} numberOfLines={1}>{order.order_number || `#${order.id}`}</Text>
        <Badge label={order.order_status} />
      </View>

      <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={[styles.card, shadow.sm]}>
          <Text style={styles.sectionTitle}>Customer</Text>
          <Row label="Name" value={order.customer_name || 'Walk-in'} />
          <Row label="Phone" value={order.customer_phone} />
          <Row label="Email" value={order.customer_email} />
          <Row label="Order Type" value={order.order_type} />
          <Row label="Table" value={order.table_name} />
          {isDelivery && order.customer_address ? (
            <>
              <Row label="Address" value={order.customer_address} />
              <Pressable style={styles.mapsButton} onPress={openInMaps}>
                <Ionicons name="location" size={16} color={colors.primary} />
                <Text style={styles.mapsButtonText}>Open in Google Maps</Text>
              </Pressable>
            </>
          ) : null}
          {order.notes ? <Row label="Notes" value={order.notes} /> : null}
        </View>

        <View style={[styles.card, shadow.sm]}>
          <Text style={styles.sectionTitle}>Items</Text>
          {items.length === 0 ? (
            <Text style={styles.emptyItems}>No item details available</Text>
          ) : (
            items.map((item, i) => (
              <View key={i} style={styles.itemRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.itemName}>{item.item_name}</Text>
                  {item.notes ? <Text style={styles.itemNotes}>{item.notes}</Text> : null}
                </View>
                <Text style={styles.itemQty}>x{item.quantity}</Text>
                <Text style={styles.itemPrice}>{currency}{item.total_price ?? 0}</Text>
              </View>
            ))
          )}

          <View style={styles.divider} />
          <Row label="Subtotal" value={`${currency}${order.subtotal ?? 0}`} />
          {order.tax > 0 ? <Row label="Tax" value={`${currency}${order.tax}`} /> : null}
          <View style={styles.totalRow}>
            <Text style={styles.totalLabel}>Total</Text>
            <Text style={styles.totalValue}>{currency}{order.total ?? 0}</Text>
          </View>
        </View>

        <View style={[styles.card, shadow.sm]}>
          <Text style={styles.sectionTitle}>Payment</Text>
          <Row label="Method" value={order.payment_method} />
          <Row label="Status" value={order.payment_status} />
        </View>
      </ScrollView>

      {next ? (
        <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.md }]}>
          <Pressable style={styles.advanceButton} onPress={advanceOrder} disabled={updating}>
            {updating ? (
              <ActivityIndicator color="#fff" size="small" />
            ) : (
              <>
                <Text style={styles.advanceButtonText}>Mark {next}</Text>
                <Ionicons name="arrow-forward" size={16} color="#fff" />
              </>
            )}
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  backButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  headerTitle: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  content: {
    padding: spacing.xl,
    paddingBottom: 140,
    gap: spacing.md,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  sectionTitle: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.muted,
    marginBottom: spacing.sm,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 7,
    gap: spacing.md,
  },
  infoLabel: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  infoValue: {
    flex: 1,
    textAlign: 'right',
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  mapsButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.primaryLight,
    borderRadius: radius.md,
    paddingVertical: 10,
    marginTop: spacing.sm,
  },
  mapsButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  emptyItems: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    gap: spacing.sm,
  },
  itemName: {
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  itemNotes: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  itemQty: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    width: 32,
    textAlign: 'center',
  },
  itemPrice: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
    width: 70,
    textAlign: 'right',
  },
  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginVertical: spacing.sm,
  },
  totalRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingTop: spacing.sm,
    marginTop: spacing.xs,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  totalLabel: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  totalValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  footer: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    backgroundColor: colors.surface,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
  },
  advanceButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 14,
  },
  advanceButtonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 15,
  },
});
