import { Ionicons } from '@expo/vector-icons';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Image, Linking, Modal, Pressable, ScrollView, Share, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Badge from '../components/Badge';
import BillPreviewModal from '../components/BillPreviewModal';
import { apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { stopNewOrderSound } from '../utils/orderAlerts';

// Mirrors main/config/order_state_machine.php's ORDER_STATUS_TRANSITIONS —
// every legal next status gets its own button here, not just a single
// hardcoded "next" step, so Reject/Cancel and same-status shortcuts (Pending
// straight to Preparing) are all reachable from the app, same as the site.
const STATUS_ACTIONS = {
  Scheduled: {
    primary: [
      { to: 'Cancelled', label: 'Cancel', tone: 'danger' },
      { to: 'Pending', label: 'Activate Now', tone: 'success' },
    ],
  },
  Pending: {
    primary: [
      { to: 'Rejected', label: 'Reject', tone: 'danger' },
      { to: 'Accepted', label: 'Accept', tone: 'success' },
    ],
    secondary: [
      { to: 'Preparing', label: 'Skip to Preparing' },
      { to: 'Cancelled', label: 'Cancel Order' },
    ],
  },
  Accepted: {
    primary: [
      { to: 'Cancelled', label: 'Cancel', tone: 'danger' },
      { to: 'Preparing', label: 'Start Preparing', tone: 'success' },
    ],
  },
  Preparing: {
    primary: [
      { to: 'Cancelled', label: 'Cancel', tone: 'danger' },
      { to: 'Ready', label: 'Mark Ready', tone: 'success' },
    ],
  },
  Ready: {
    primary: [
      { to: 'Cancelled', label: 'Cancel', tone: 'danger' },
      { to: 'Served', label: 'Mark Served', tone: 'success' },
    ],
  },
  Served: {
    primary: [{ to: 'Completed', label: 'Mark Completed', tone: 'success', full: true }],
  },
  Completed: {},
  Cancelled: {},
  Rejected: {},
};

// Mirrors PAYMENT_STATUS_TRANSITIONS in the same state-machine file, minus
// 'Failed' — that value exists there for the payments-table/gateway-callback
// side of the state machine, but orders.payment_status itself is only
// enum('Pending','Paid','Partially Paid','Refunded'); offering 'Failed' here
// would throw a raw DB error the moment someone tapped it.
const PAYMENT_STATUS_TRANSITIONS = {
  Pending: ['Paid', 'Partially Paid', 'Refunded'],
  Paid: ['Refunded', 'Partially Paid'],
  'Partially Paid': ['Paid', 'Refunded'],
  Refunded: [],
};

const VEG_DOT_COLOR = { Veg: colors.success, 'Non Veg': colors.danger };

// Stepper bounds for the inline "Set Preparation Time" card shown on a
// still-Pending order — matches the common delivery-platform partner app
// pattern of a -/value/+ control rather than a separate confirm step.
const PREP_STEP = 5;
const PREP_MIN = 5;
const PREP_MAX = 180;
const DEFAULT_PREP_MINUTES = 15;

function formatOrderDateTime(value) {
  if (!value) return '';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  const datePart = d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  const timePart = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  return `${datePart} | ${timePart}`;
}

function formatReadyByTime(value) {
  if (!value) return '';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function VegDot({ itemType }) {
  const color = VEG_DOT_COLOR[itemType] || colors.muted;
  return (
    <View style={[styles.vegDot, { borderColor: color }]}>
      <View style={[styles.vegDotInner, { backgroundColor: color }]} />
    </View>
  );
}

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
  const [updatingTo, setUpdatingTo] = useState(null);
  const [paymentPickerOpen, setPaymentPickerOpen] = useState(false);
  const [prepMinutes, setPrepMinutes] = useState(DEFAULT_PREP_MINUTES);
  const [customTimeOpen, setCustomTimeOpen] = useState(false);
  const [customTimeInput, setCustomTimeInput] = useState('');
  const [updatingPayment, setUpdatingPayment] = useState(false);
  const [billPreview, setBillPreview] = useState(null);
  const [riderQr, setRiderQr] = useState(null); // { url, expiresAt }
  const [generatingQr, setGeneratingQr] = useState(false);
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const currency = user?.currency_symbol || '₹';

  const items = order.items || [];
  const isDelivery = order.order_type === 'Delivery';
  const actions = STATUS_ACTIONS[order.order_status] || {};
  const paymentOptions = PAYMENT_STATUS_TRANSITIONS[order.payment_status] || [];
  const isPending = order.order_status === 'Pending';

  // This screen's Accept/Reject/Cancel footer sits at the very bottom, and
  // the app's tab bar is a floating overlay (not a docked bar react-navigation
  // reserves space for) — guessing its height to avoid overlapping it was
  // fragile and, on device, ended up either covering the footer's buttons or
  // stealing the ScrollView's touches. Simplest fix: hide it here entirely,
  // same way POSScreen already hides it for its cart sheet.
  useEffect(() => {
    const parent = navigation.getParent();
    parent?.setOptions({ tabBarStyle: { display: 'none' } });
    return () => parent?.setOptions({ tabBarStyle: undefined });
  }, [navigation]);

  // Reaching this screen — whether auto-opened for an incoming order or
  // tapped into normally — means the alert already did its job; no need to
  // keep ringing while the person is looking straight at it.
  useEffect(() => {
    stopNewOrderSound();
  }, []);

  const openInMaps = () => {
    const address = order.customer_address;
    if (!address) return;
    const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address);
    Linking.openURL(url).catch(() => {
      Alert.alert('Could not open Maps', 'Please check the address manually.');
    });
  };

  const callCustomer = () => {
    if (!order.customer_phone) return;
    Linking.openURL(`tel:${order.customer_phone}`).catch(() => {});
  };

  // Mirrors the website's "Generate QR for Rider" button on a delivery
  // order — same session-authenticated endpoint, same rider-page.php link,
  // just rendered here instead of in the admin dashboard. The rider scans
  // this (or gets the link shared to them) to open a page that lets them
  // update their live location/delivery status without a login of their own.
  const generateRiderQr = async () => {
    setGeneratingQr(true);
    try {
      const res = await apiPostForm('/api/generate_delivery_qr.php', { order_id: order.id });
      if (!res.success) throw new Error(res.message || 'Could not generate QR');
      setRiderQr({ url: res.qr_url, expiresAt: res.expires_at });
    } catch (e) {
      Alert.alert('Could not generate QR', e.message);
    } finally {
      setGeneratingQr(false);
    }
  };

  const shareRiderLink = () => {
    if (!riderQr) return;
    Share.share({ message: riderQr.url }).catch(() => {});
  };

  const updateStatus = async (to, extraFields = {}) => {
    setUpdatingTo(to);
    try {
      const res = await apiPostForm('/api/update_order_status.php', {
        orderId: order.id,
        status: to,
        ...extraFields,
      });
      if (!res.success) throw new Error(res.message || 'Update failed');
      const next = { ...order, order_status: to };
      if (extraFields.prep_minutes) {
        next.prep_minutes = extraFields.prep_minutes;
        next.estimated_ready_at = new Date(Date.now() + extraFields.prep_minutes * 60000)
          .toISOString()
          .replace('T', ' ')
          .slice(0, 19);
      }
      setOrder(next);
      navigation.setParams({ order: next });
    } catch (e) {
      Alert.alert('Could not update order', e.message);
    } finally {
      setUpdatingTo(null);
    }
  };

  const decreasePrepMinutes = () => setPrepMinutes((m) => Math.max(PREP_MIN, m - PREP_STEP));
  const increasePrepMinutes = () => setPrepMinutes((m) => Math.min(PREP_MAX, m + PREP_STEP));

  const applyCustomPrepTime = () => {
    const n = parseInt(customTimeInput, 10);
    if (Number.isFinite(n) && n > 0) {
      setPrepMinutes(Math.min(PREP_MAX, Math.max(PREP_MIN, n)));
    }
    setCustomTimeOpen(false);
    setCustomTimeInput('');
  };

  const updatePayment = async (to) => {
    setUpdatingPayment(true);
    try {
      const res = await apiPostForm('/api/update_payment_status.php', {
        orderId: order.id,
        status: to,
      });
      if (!res.success) throw new Error(res.message || 'Update failed');
      const next = { ...order, payment_status: to };
      setOrder(next);
      navigation.setParams({ order: next });
      setPaymentPickerOpen(false);
    } catch (e) {
      Alert.alert('Could not update payment', e.message);
    } finally {
      setUpdatingPayment(false);
    }
  };

  const openKotPrint = () => {
    setBillPreview({
      type: 'kot',
      title: 'KOT Print',
      restaurantName: user?.restaurant_name || 'Receipt',
      restaurantAddress: user?.address,
      gstin: user?.show_gstin ? user?.gstin_no : null,
      kotNumber: order.order_number,
      orderType: order.order_type,
      tableName: order.table_name,
      items: items.map((it) => ({
        name: it.item_name,
        quantity: it.quantity,
        price: it.unit_price,
        variationName: it.variation_name,
      })),
      subtotal: order.subtotal,
      discount: order.discount_amount,
      couponCode: order.coupon_code,
      tax: order.tax,
      total: order.total,
      paymentMethod: order.payment_method,
      currency,
    });
  };

  const dateTime = useMemo(() => formatOrderDateTime(order.created_at), [order.created_at]);
  const paid = order.payment_status === 'Paid';

  return (
    <View style={styles.fill}>
      <View style={[styles.header, { paddingTop: insets.top + spacing.sm }]}>
        <Pressable style={styles.headerIconButton} onPress={() => navigation.goBack()} hitSlop={8}>
          <Ionicons name="arrow-back" size={20} color={colors.ink} />
        </Pressable>
        <Text style={styles.headerTitle} numberOfLines={1}>
          {isPending ? 'New Order' : (order.order_number || `#${order.id}`)}
        </Text>
        <Pressable style={styles.headerAction} onPress={openKotPrint} hitSlop={6}>
          <Ionicons name="print-outline" size={20} color={colors.inkSoft} />
          <Text style={styles.headerActionText}>KOT Print</Text>
        </Pressable>
        <Pressable style={styles.headerAction} onPress={stopNewOrderSound} hitSlop={6}>
          <Ionicons name="notifications-off-outline" size={20} color={colors.warning} />
          <Text style={styles.headerActionText}>Mute</Text>
        </Pressable>
      </View>

      <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={[styles.card, shadow.sm]}>
          <View style={styles.orderInfoTop}>
            <Text style={styles.orderNumber} numberOfLines={1}>Order No: {order.order_number || `#${order.id}`}</Text>
            <Badge label={order.order_status} />
          </View>
          {dateTime ? <Text style={styles.orderDate}>{dateTime}</Text> : null}
          {order.prep_minutes ? (
            <View style={styles.prepTimeRow}>
              <Ionicons name="time-outline" size={14} color={colors.primary} />
              <Text style={styles.prepTimeText}>
                {order.prep_minutes} min prep
                {order.estimated_ready_at ? ` · Ready by ${formatReadyByTime(order.estimated_ready_at)}` : ''}
              </Text>
            </View>
          ) : null}
        </View>

        <View style={[styles.card, shadow.sm]}>
          <View style={styles.customerRow}>
            <View style={styles.customerIconWrap}>
              <Ionicons name="person" size={16} color={colors.primary} />
            </View>
            <Text style={styles.customerName} numberOfLines={1}>{order.customer_name || 'Walk-in'}</Text>
            {order.customer_phone ? (
              <Pressable style={styles.callButton} onPress={callCustomer} hitSlop={8}>
                <Ionicons name="call" size={15} color={colors.primary} />
              </Pressable>
            ) : null}
          </View>
          {isDelivery && order.customer_address ? (
            <View style={[styles.customerRow, { alignItems: 'flex-start' }]}>
              <View style={styles.customerIconWrap}>
                <Ionicons name="location" size={16} color={colors.danger} />
              </View>
              <Text style={styles.addressText}>{order.customer_address}</Text>
            </View>
          ) : (
            <Row label="Order Type" value={order.table_name || order.order_type} />
          )}
          {isDelivery && order.customer_address ? (
            <Pressable style={styles.mapsButton} onPress={openInMaps}>
              <Ionicons name="navigate-outline" size={15} color={colors.primary} />
              <Text style={styles.mapsButtonText}>Open in Google Maps</Text>
            </Pressable>
          ) : null}
          {isDelivery ? (
            <>
              <Pressable style={styles.mapsButton} onPress={generateRiderQr} disabled={generatingQr}>
                {generatingQr ? (
                  <ActivityIndicator size="small" color={colors.primary} />
                ) : (
                  <Ionicons name="qr-code-outline" size={15} color={colors.primary} />
                )}
                <Text style={styles.mapsButtonText}>{riderQr ? 'Regenerate QR for Rider' : 'Generate QR for Rider'}</Text>
              </Pressable>
              {riderQr ? (
                <View style={styles.riderQrBox}>
                  <Image
                    source={{ uri: `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(riderQr.url)}` }}
                    style={styles.riderQrImage}
                  />
                  <Text style={styles.riderQrLink} numberOfLines={2}>{riderQr.url}</Text>
                  <Pressable style={styles.riderQrShareButton} onPress={shareRiderLink}>
                    <Ionicons name="share-outline" size={14} color={colors.primary} />
                    <Text style={styles.riderQrShareText}>Share Link</Text>
                  </Pressable>
                </View>
              ) : null}
            </>
          ) : null}
          {order.notes ? <Row label="Notes" value={order.notes} /> : null}
        </View>

        <View style={[styles.card, shadow.sm]}>
          <View style={styles.itemsHeader}>
            <Text style={styles.itemsTitle}>Item Details</Text>
            <View style={styles.orderTypeBadge}>
              <Text style={styles.orderTypeBadgeText}>{order.order_type}</Text>
            </View>
          </View>

          {items.length === 0 ? (
            <Text style={styles.emptyItems}>No item details available</Text>
          ) : (
            items.map((item, i) => (
              <View key={i} style={styles.itemRow}>
                <VegDot itemType={item.item_type} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.itemName}>{item.quantity} x {item.item_name}</Text>
                  {item.variation_name ? (
                    <View style={styles.variationTag}>
                      <Text style={styles.variationTagText}>{item.variation_name}</Text>
                    </View>
                  ) : null}
                  {item.notes ? <Text style={styles.itemNotes}>{item.notes}</Text> : null}
                </View>
                <Text style={styles.itemPrice}>{currency}{item.total_price ?? 0}</Text>
              </View>
            ))
          )}

          <View style={styles.divider} />
          <Row label="Subtotal" value={`${currency}${order.subtotal ?? 0}`} />
          {order.tax > 0 ? <Row label="Tax" value={`${currency}${order.tax}`} /> : null}

          <View style={styles.paymentFooter}>
            <Pressable
              style={[styles.paymentBadge, { backgroundColor: paid ? colors.successBg : colors.warningBg }]}
              onPress={() => paymentOptions.length > 0 && setPaymentPickerOpen(true)}
              disabled={paymentOptions.length === 0}
            >
              <Text style={[styles.paymentBadgeText, { color: paid ? colors.success : colors.warning }]}>
                {order.payment_status}
              </Text>
              {paymentOptions.length > 0 ? (
                <Ionicons name="chevron-down" size={12} color={paid ? colors.success : colors.warning} />
              ) : null}
            </Pressable>
            <Text style={styles.totalValue}>{currency}{order.total ?? 0}</Text>
          </View>
        </View>

        {isPending ? (
          <View style={[styles.card, shadow.sm]}>
            <Text style={styles.itemsTitle}>Set Preparation Time</Text>
            <View style={styles.prepStepperRow}>
              <Pressable style={[styles.prepStepButton, { backgroundColor: colors.successBg }]} onPress={decreasePrepMinutes} hitSlop={8}>
                <Ionicons name="remove" size={20} color={colors.success} />
              </Pressable>
              <View style={styles.prepValueBox}>
                <Text style={styles.prepValueText}>{prepMinutes}m</Text>
              </View>
              <Pressable style={[styles.prepStepButton, { backgroundColor: colors.dangerBg }]} onPress={increasePrepMinutes} hitSlop={8}>
                <Ionicons name="add" size={20} color={colors.danger} />
              </Pressable>
            </View>

            {customTimeOpen ? (
              <View style={styles.customTimeRow}>
                <TextInput
                  style={styles.customTimeInput}
                  value={customTimeInput}
                  onChangeText={setCustomTimeInput}
                  placeholder="Minutes"
                  placeholderTextColor={colors.muted}
                  keyboardType="number-pad"
                  autoFocus
                  onSubmitEditing={applyCustomPrepTime}
                />
                <Pressable style={styles.customTimeApply} onPress={applyCustomPrepTime}>
                  <Text style={styles.customTimeApplyText}>Set</Text>
                </Pressable>
              </View>
            ) : (
              <Pressable onPress={() => setCustomTimeOpen(true)} hitSlop={6}>
                <Text style={styles.additionalTimeLink}>+ Additional Time Settings</Text>
              </Pressable>
            )}
          </View>
        ) : null}
      </ScrollView>

      {(actions.primary?.length || actions.secondary?.length) ? (
        <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, spacing.md) }]}>
          {actions.secondary?.length ? (
            <View style={styles.secondaryRow}>
              {actions.secondary.map((a) => (
                <Pressable
                  key={a.to}
                  style={styles.secondaryButton}
                  onPress={() => updateStatus(a.to)}
                  disabled={!!updatingTo}
                >
                  <Text style={styles.secondaryButtonText}>{a.label}</Text>
                </Pressable>
              ))}
            </View>
          ) : null}
          <View style={styles.primaryRow}>
            {actions.primary?.map((a) => (
              <Pressable
                key={a.to}
                style={[
                  styles.primaryButton,
                  a.full && { flex: 1 },
                  { backgroundColor: a.tone === 'danger' ? colors.danger : colors.success },
                ]}
                onPress={() => updateStatus(a.to, a.to === 'Accepted' ? { prep_minutes: prepMinutes } : {})}
                disabled={!!updatingTo}
              >
                {updatingTo === a.to ? (
                  <ActivityIndicator color="#fff" size="small" />
                ) : (
                  <Text style={styles.primaryButtonText}>{a.label.toUpperCase()}</Text>
                )}
              </Pressable>
            ))}
          </View>
        </View>
      ) : null}

      <Modal visible={paymentPickerOpen} transparent animationType="fade" onRequestClose={() => setPaymentPickerOpen(false)}>
        <Pressable style={styles.pickerBackdrop} onPress={() => setPaymentPickerOpen(false)}>
          <Pressable style={[styles.pickerSheet, shadow.lg]} onPress={() => {}}>
            <Text style={styles.pickerTitle}>Update Payment Status</Text>
            {paymentOptions.map((status) => (
              <Pressable
                key={status}
                style={styles.pickerOption}
                onPress={() => updatePayment(status)}
                disabled={updatingPayment}
              >
                <Text style={styles.pickerOptionText}>{status}</Text>
                {updatingPayment ? <ActivityIndicator size="small" color={colors.primary} /> : (
                  <Ionicons name="chevron-forward" size={16} color={colors.muted} />
                )}
              </Pressable>
            ))}
          </Pressable>
        </Pressable>
      </Modal>

      <BillPreviewModal
        visible={!!billPreview}
        data={billPreview}
        onClose={() => setBillPreview(null)}
      />
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
  headerIconButton: {
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
  headerAction: {
    alignItems: 'center',
    gap: 2,
    paddingHorizontal: 4,
  },
  headerActionText: {
    fontFamily: font.medium,
    fontSize: 9.5,
    color: colors.muted,
  },
  content: {
    padding: spacing.xl,
    paddingBottom: spacing.xl,
    gap: spacing.md,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  orderInfoTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: spacing.sm,
  },
  orderNumber: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  orderDate: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 6,
  },
  prepTimeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    marginTop: 8,
  },
  prepTimeText: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.primary,
  },
  customerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: 6,
  },
  customerIconWrap: {
    width: 30,
    height: 30,
    borderRadius: 15,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  customerName: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  callButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  addressText: {
    flex: 1,
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.inkSoft,
    lineHeight: 19,
    marginTop: 2,
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
  riderQrBox: {
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.sm,
  },
  riderQrImage: {
    width: 160,
    height: 160,
    borderRadius: radius.sm,
  },
  riderQrLink: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    textAlign: 'center',
    marginTop: spacing.sm,
  },
  riderQrShareButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    marginTop: spacing.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: 6,
    borderRadius: radius.pill,
    backgroundColor: colors.primaryLight,
  },
  riderQrShareText: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.primary,
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
  itemsHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  itemsTitle: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  orderTypeBadge: {
    backgroundColor: colors.infoBg,
    borderRadius: radius.pill,
    paddingHorizontal: 12,
    paddingVertical: 5,
  },
  orderTypeBadgeText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.info,
  },
  emptyItems: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    paddingVertical: 8,
    gap: spacing.sm,
  },
  vegDot: {
    width: 15,
    height: 15,
    borderRadius: 3,
    borderWidth: 1.5,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 2,
  },
  vegDotInner: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  itemName: {
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  variationTag: {
    alignSelf: 'flex-start',
    backgroundColor: colors.successBg,
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 2,
    marginTop: 4,
  },
  variationTagText: {
    fontFamily: font.medium,
    fontSize: 10.5,
    color: colors.success,
  },
  itemNotes: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 2,
  },
  itemPrice: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginVertical: spacing.sm,
  },
  paymentFooter: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingTop: spacing.sm,
    marginTop: spacing.xs,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  paymentBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    borderRadius: radius.pill,
    paddingHorizontal: 12,
    paddingVertical: 7,
  },
  paymentBadgeText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
  },
  totalValue: {
    fontFamily: font.bold,
    fontSize: 17,
    color: colors.ink,
  },
  // Normal flex-flow sibling (not absolutely positioned) — the ScrollView
  // above is flex:1, so it automatically gets exactly (screen height minus
  // header minus this footer) to scroll within, with zero risk of the two
  // overlapping or of this footer's buttons ending up unreachable.
  footer: {
    backgroundColor: colors.surface,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.sm,
    gap: spacing.sm,
  },
  secondaryRow: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  secondaryButton: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 9,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
  },
  secondaryButtonText: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
  },
  primaryRow: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  primaryButton: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 15,
    borderRadius: radius.pill,
  },
  primaryButtonText: {
    color: '#fff',
    fontFamily: font.bold,
    fontSize: 14.5,
    letterSpacing: 0.3,
  },
  pickerBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  pickerSheet: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  pickerTitle: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  pickerOption: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 13,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  pickerOptionText: {
    fontFamily: font.medium,
    fontSize: 14.5,
    color: colors.ink,
  },
  prepStepperRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.md,
  },
  prepStepButton: {
    width: 48,
    height: 48,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  prepValueBox: {
    flex: 1,
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  prepValueText: {
    fontFamily: font.bold,
    fontSize: 17,
    color: colors.ink,
  },
  additionalTimeLink: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.primary,
    textAlign: 'center',
    marginTop: spacing.md,
  },
  customTimeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.md,
  },
  customTimeInput: {
    flex: 1,
    height: 44,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  customTimeApply: {
    height: 44,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  customTimeApplyText: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: '#fff',
  },
});
