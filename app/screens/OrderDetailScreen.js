import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Image, Linking, Pressable, ScrollView, Share, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Badge from '../components/Badge';
import BillPreviewModal from '../components/BillPreviewModal';
import { feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import Sheet from '../components/ui/Sheet';
import { SkeletonBlock } from '../components/ui/Skeleton';
import { useLayout } from '../hooks/useLayout';
import { haptic } from '../utils/haptics';
import { money, realName, splitNotes, timeAgo } from '../utils/format';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { isRingActive, playNewOrderSound, stopNewOrderSound, subscribeRingState } from '../utils/orderAlerts';
import { markOrderHandled } from 'order-watch';

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

// Raw DB enum values shown to staff as plain language — "Partially Paid"
// on a tiny badge was the #1 confusion; these read like sentences.
const PAYMENT_LABELS = {
  Pending: 'Not Paid',
  Paid: 'Paid',
  'Partially Paid': 'Partly Paid',
  Refunded: 'Refunded',
};
const paymentLabel = (s) => PAYMENT_LABELS[s] || s || 'Not Paid';

// Statuses where nothing is left to do — the footer must render NO action
// buttons at all (tapping one just got "already marked as completed" /
// "cannot change from Rejected to Accepted" errors from the API).
const TERMINAL_STATUSES = ['Completed', 'Cancelled', 'Rejected'];

// ── Reprinting a saved order (bill or KOT) in the website bill's layout ──
// The orders row has no split-payment or per-discount columns, so those are
// read back from the notes the POS writes when it saves a bill
// (controllers/pos_operations.php + the website's processPayment):
//   "Cash: ₹200.00, UPI: ₹61.45, Due: ₹10.00"   amounts per payment method
//   "[Coupon: CODE -50.00]" "[Points: 120 -12.00]" "[Discount 10%: -25.00]"
//   "[Wallet: 40.00]"
const BILL_STATUS = { Paid: 'Paid', 'Partially Paid': 'Partially Paid', Pending: 'Unpaid', Refunded: 'Refunded' };

function parseAddons(raw) {
  if (Array.isArray(raw)) return raw;
  if (!raw) return [];
  try {
    const list = JSON.parse(raw);
    return Array.isArray(list) ? list : [];
  } catch (e) {
    return [];
  }
}

function parseBillNotes(notes) {
  const raw = String(notes || '');
  const tags = [];
  const tagRe = /\[([^\]:]+):\s*([^\]]*)\]/g;
  let t;
  const seen = new Set();
  while ((t = tagRe.exec(raw))) {
    // An open bill paid in steps can carry the same tag twice; count it once.
    const key = `${t[1].trim()}|${t[2].trim()}`;
    if (!seen.has(key)) {
      seen.add(key);
      tags.push([t[1].trim(), t[2].trim()]);
    }
  }

  const discounts = [];
  let walletPaid = 0;
  tags.forEach(([key, val]) => {
    const amount = Math.abs(parseFloat((val.match(/-?\s*([\d,]+(?:\.\d+)?)\s*$/) || [])[1]?.replace(/,/g, '')) || 0);
    if (/^coupon$/i.test(key)) {
      const code = val.replace(/-?\s*[\d,.]+\s*$/, '').trim();
      if (amount > 0) discounts.push([code ? `Coupon ${code}` : 'Coupon', amount]);
    } else if (/^points$/i.test(key)) {
      const pts = (val.match(/^(\d+)/) || [])[1];
      if (amount > 0) discounts.push([pts ? `${pts} points` : 'Points', amount]);
    } else if (/^discount\b/i.test(key)) {
      if (amount > 0) discounts.push([key, amount]);
    } else if (/^wallet$/i.test(key)) {
      walletPaid = amount;
    }
  });

  // "Method: <symbol>123.45" pairs outside the [..] tags. The POS always
  // writes two decimals, which keeps a free-text note like "Table: 4" out.
  const plain = raw.replace(/\[[^\]]*\]/g, ' ');
  const payments = [];
  let dueAmount = 0;
  const payRe = /([A-Za-z][A-Za-z0-9 &._-]{0,30}?):\s*[^\d\s,]{0,4}\s*([\d,]+\.\d{2})\b/g;
  let mm;
  while ((mm = payRe.exec(plain))) {
    const label = mm[1].trim();
    const amount = parseFloat(mm[2].replace(/,/g, '')) || 0;
    if (/^due$/i.test(label)) dueAmount = amount;
    else if (/^wallet$/i.test(label)) walletPaid = walletPaid || amount;
    else if (amount > 0) payments.push([label, amount]);
  }
  return { discounts, payments, walletPaid, dueAmount };
}

// Builds BillPreviewModal's data (contract: components/Receipt.js) from a
// full order row (api/get_order_details_by_id.php).
function orderReceiptData(order, user, currency, type) {
  const parsed = parseBillNotes(order.notes);
  const total = Number(order.total) || 0;

  // Coupon + manual discount land together in discount_amount (points are
  // stored apart), so the tags are only trusted when they add up to it.
  let discounts = parsed.discounts;
  const tagged = discounts.filter(([l]) => !/points$/i.test(l)).reduce((sum, d) => sum + d[1], 0);
  if (Math.abs(tagged - (Number(order.discount_amount) || 0)) > 0.01) discounts = [];
  if (discounts.length === 0) {
    if (Number(order.discount_amount) > 0) {
      discounts.push([order.coupon_code ? `Coupon ${order.coupon_code}` : 'Discount', Number(order.discount_amount)]);
    }
    if (Number(order.loyalty_discount_amount) > 0) {
      const pts = Number(order.loyalty_points_redeemed) || 0;
      discounts.push([pts ? `${pts} points` : 'Points', Number(order.loyalty_discount_amount)]);
    }
  }
  if (Number(order.vip_discount) > 0) discounts.push(['VIP discount', Number(order.vip_discount)]);

  const walletPaid = Number(order.wallet_paid) || parsed.walletPaid;
  const status = BILL_STATUS[order.payment_status] || order.payment_status || null;
  const dueAmount = parsed.dueAmount || (status === 'Unpaid' ? Math.max(0, total - walletPaid) : 0);
  const tableLabel = order.table_number
    ? `${order.table_number}${order.area_name ? ` · ${order.area_name}` : ''}`
    : (order.table_name || order.order_type || 'Takeaway');
  const name = realName(order.customer_name);
  const kotTag = splitNotes(order.notes).kot;

  return {
    type,
    title: type === 'kot' ? 'KOT Print' : 'Bill Print',
    restaurantName: user?.restaurant_name || 'Receipt',
    restaurantAddress: user?.address,
    restaurantPhone: user?.phone,
    businessIdLabel: user?.business_id_label,
    businessIdNo: String(user?.show_business_id) === '1' || user?.show_business_id === true ? user?.business_id_no : null,
    billNo: order.bill_no || null,
    orderId: order.id,
    orderNumber: order.order_number,
    kotNumber: kotTag || order.id,
    orderType: order.order_type,
    tableName: order.table_name,
    tableLabel,
    customer: name ? { name, phone: order.customer_phone || '' } : null,
    customerName: order.customer_name,
    status,
    kotStatus: type === 'kot' ? order.order_status : undefined,
    createdAt: order.created_at,
    items: (order.items || []).map((it) => {
      const addons = parseAddons(it.addons).map((a) => ({ name: a.name || a.addon_name, price: Number(a.price ?? a.addon_price) || 0 }));
      const unit = Number(it.unit_price) || 0;
      const qty = Number(it.quantity) || 0;
      return {
        name: it.item_name,
        quantity: qty,
        price: unit,
        variationName: it.variation_name,
        addons,
        // unit_price never includes the add-ons; they're charged once per unit
        lineTotal: (unit + addons.reduce((sum, a) => sum + a.price, 0)) * qty,
        notes: it.notes,
      };
    }),
    subtotal: order.subtotal,
    discounts,
    taxName: user?.tax_name || 'GST',
    taxPercent: Number(order.tax) > 0 && user?.tax_percent != null ? user.tax_percent : null,
    tax: order.tax,
    charges: Number(order.delivery_charge) > 0 ? [['Delivery charge', Number(order.delivery_charge)]] : [],
    total,
    walletPaid,
    payments: parsed.payments.length ? parsed.payments : undefined,
    paymentMethod: order.payment_method,
    dueAmount,
    currency,
  };
}

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
  // Opened from a short summary (Home's live list, a notification): show placeholders for the
  // dishes and payment until the full order arrives, never "no items / ₹0 / not paid".
  const [hydrated, setHydrated] = useState(Array.isArray(initialOrder.items) && initialOrder.items.length > 0 && !!initialOrder.payment_status);
  const [updatingTo, setUpdatingTo] = useState(null);
  const [paymentPickerOpen, setPaymentPickerOpen] = useState(false);
  const [prepMinutes, setPrepMinutes] = useState(DEFAULT_PREP_MINUTES);
  const [customTimeOpen, setCustomTimeOpen] = useState(false);
  const [customTimeInput, setCustomTimeInput] = useState('');
  const [updatingPayment, setUpdatingPayment] = useState(false);
  const [billPreview, setBillPreview] = useState(null);
  const [riderQr, setRiderQr] = useState(null); // { url, expiresAt }
  const [generatingQr, setGeneratingQr] = useState(false);
  // True when the new-order ring is NOT sounding — the header button reads
  // this to flip between Mute (ring on) and Unmute (ring off) with matching
  // icons, instead of the old always-useless one-way Mute.
  const [muted, setMuted] = useState(true);
  // Set while a background re-fetch of this order is in flight (focus
  // refresh + 15s poll) so the header can show a subtle "updating" state.
  const [refreshing, setRefreshing] = useState(false);
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const currency = user?.currency_symbol || '₹';

  const items = order.items || [];
  const isDelivery = order.order_type === 'Delivery';
  const isTerminal = TERMINAL_STATUSES.includes(order.order_status);
  const actions = isTerminal ? {} : STATUS_ACTIONS[order.order_status] || {};
  const paymentOptions = isTerminal ? [] : PAYMENT_STATUS_TRANSITIONS[order.payment_status] || [];
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

  // Reaching this screen while the new-order ring is sounding no longer
  // kills the ring — someone walking past a ringing tablet shouldn't have
  // to open an order to silence it, the Mute/Unmute button is the explicit
  // control. The ring keeps looping while the screen is open; it stops via
  // the Mute button, accept/reject/cancel, leaving the screen, or the
  // 10-minute safety net.
  useEffect(() => {
    setMuted(!isRingActive());
    const unsub = subscribeRingState((active) => setMuted(!active));
    return () => {
      unsub();
    };
  }, []);

  // Leaving the screen (back button, tab switch away from the Orders
  // stack, or the auto-open flow navigating elsewhere) always silences the
  // alert — same as the website closing its overlay. Accept/reject handle
  // their own stop inside updateStatus().
  useEffect(() => {
    const unsub = navigation.addListener('beforeRemove', () => {
      stopNewOrderSound();
    });
    return unsub;
  }, [navigation]);

  // --- Live refresh -------------------------------------------------------
  // The order row on screen used to go stale: another staff member (or the
  // customer's own payment callback) could complete the order while this
  // screen sat open — the footer then offered actions the backend had
  // already rejected ("already marked as completed"). Re-fetch the order
  // when the screen regains focus and on a slow interval while open.
  const refreshOrder = useCallback(async () => {
    try {
      setRefreshing(true);
      const res = await apiGet(`/api/get_order_details_by_id.php?id=${order.id}`);
      if (res?.success && res.order) {
        setOrder((prev) => ({ ...prev, ...res.order }));
        setHydrated(true);
      }
    } catch (e) {
      // silent — refresh is best-effort; the current snapshot stays usable
    } finally {
      setRefreshing(false);
    }
  }, [order.id]);

  useEffect(() => {
    const unsub = navigation.addListener('focus', refreshOrder);
    return unsub;
  }, [navigation, refreshOrder]);

  useEffect(() => {
    const id = setInterval(refreshOrder, 15000);
    return () => clearInterval(id);
  }, [refreshOrder]);

  // Hardware/system back (gesture, button) — react-navigation's native-stack
  // normally handles this, but this screen sits in a custom-tab-bar setup
  // where the parent's tabBarStyle juggling raced the removal listener and
  // on some devices the back press did nothing at all. Handling it
  // explicitly guarantees one tap = leave the screen (which also silences
  // the ring via beforeRemove).
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      navigation.goBack();
      return true;
    });
    return () => sub.remove();
  }, [navigation]);

  const toggleMute = useCallback(() => {
    // Unmute restarts the ring from the top so a short clip doesn't resume
    // mid-tail — it replays like a fresh incoming-call alert. Mute keeps
    // the ring silenced until the order is acted on or the screen closes.
    if (muted) {
      playNewOrderSound();
    } else {
      stopNewOrderSound();
    }
  }, [muted]);

  const openInMaps = () => {
    const address = order.customer_address;
    if (!address) return;
    const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address);
    Linking.openURL(url).catch(() => {
      feedback.error('Could not open Maps. Please check the address manually.');
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
      feedback.errorFrom(e, 'Could not make the rider QR');
    } finally {
      setGeneratingQr(false);
    }
  };

  const shareRiderLink = () => {
    if (!riderQr) return;
    Share.share({ message: riderQr.url }).catch(() => {});
  };

  const DONE_MESSAGE = {
    Accepted: 'Order accepted',
    Preparing: 'Kitchen is preparing it',
    Ready: 'Marked ready',
    Served: 'Marked served',
    Completed: 'Order completed',
    Rejected: 'Order rejected',
    Cancelled: 'Order cancelled',
    Pending: 'Order is live now',
  };

  const updateStatus = async (to, extraFields = {}) => {
    // Rejecting or cancelling always asks why (like the website) and is never one stray tap.
    if (to === 'Rejected' || to === 'Cancelled') {
      const picked = await feedback.reason({ mode: to === 'Cancelled' ? 'cancel' : 'reject', delivery: isDelivery });
      if (!picked) return;
      extraFields = { ...extraFields, reason: picked.text, reason_code: picked.code };
      if (picked.strike) extraFields.strike = 1;
    }
    setUpdatingTo(to);
    try {
      const res = await apiPostForm('/api/update_order_status.php', {
        orderId: order.id,
        status: to,
        ...extraFields,
      });
      if (!res.success) {
        // "Already marked as completed" / "cannot change from Rejected" mean
        // this screen's snapshot went stale (someone else acted first).
        // Pull the real current state instead of just showing an error.
        if (/already|cannot change|invalid transition/i.test(res.message || '')) {
          await refreshOrder();
        }
        throw new Error(res.message || 'Update failed');
      }
      // The order has been acted on — Accept/Reject/Cancel/etc. — so kill the
      // new-order ring right here rather than waiting for the unmount effect.
      // Otherwise the telephone ring keeps looping while the user stays on
      // this screen checking payment status or printing the KOT. Also tells
      // the native background watcher to stop ringing/notifying for it.
      stopNewOrderSound();
      markOrderHandled(order.id);
      const next = { ...order, order_status: to };
      if (extraFields.prep_minutes) {
        next.prep_minutes = extraFields.prep_minutes;
        // local time, like the server's timestamps (toISOString() is UTC and showed the
        // wrong "ready by" time, e.g. 5½ hours early in India)
        const d = new Date(Date.now() + extraFields.prep_minutes * 60000);
        const p = (n) => String(n).padStart(2, '0');
        next.estimated_ready_at = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
      }
      setOrder(next);
      navigation.setParams({ order: next });
      if (to === 'Rejected' || to === 'Cancelled') feedback.info(DONE_MESSAGE[to]);
      else feedback.success(DONE_MESSAGE[to] || `Marked ${to.toLowerCase()}`);
    } catch (e) {
      // Network failures get a message a busy counter worker can act on; server messages
      // (e.g. "Order is already Completed") pass through as-is.
      feedback.errorFrom(e, 'Could not update the order');
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
      feedback.success(`Payment: ${paymentLabel(to)}`);
    } catch (e) {
      feedback.errorFrom(e, 'Could not update the payment');
    } finally {
      setUpdatingPayment(false);
    }
  };

  const openKotPrint = () => setBillPreview(orderReceiptData(order, user, currency, 'kot'));
  const openBillPrint = () => setBillPreview(orderReceiptData(order, user, currency, 'bill'));

  const dateTime = useMemo(() => formatOrderDateTime(order.created_at), [order.created_at]);
  const notes = useMemo(() => splitNotes(order.notes), [order.notes]);
  const { gutter } = useLayout();
  const paid = order.payment_status === 'Paid';
  return (
    <View style={styles.fill}>
      <View style={[styles.header, { paddingTop: insets.top + spacing.sm }]}>
        <Pressable style={styles.headerIconButton} onPress={() => navigation.goBack()} hitSlop={8} accessibilityRole="button" accessibilityLabel="Back">
          <Ionicons name="arrow-back" size={20} color={colors.ink} />
        </Pressable>
        <Text style={styles.headerTitle} numberOfLines={1}>
          {isPending ? 'New Order' : (order.order_number || `#${order.id}`)}
        </Text>
        <Pressable style={styles.headerAction} onPress={openBillPrint} hitSlop={6} disabled={!hydrated}>
          <Ionicons name="receipt-outline" size={20} color={colors.inkSoft} />
          <Text style={styles.headerActionText}>Bill Print</Text>
        </Pressable>
        <Pressable style={styles.headerAction} onPress={openKotPrint} hitSlop={6} disabled={!hydrated}>
          <Ionicons name="print-outline" size={20} color={colors.inkSoft} />
          <Text style={styles.headerActionText}>KOT Print</Text>
        </Pressable>
        <Pressable style={styles.headerAction} onPress={toggleMute} hitSlop={6}>
          {muted ? (
            <>
              <Ionicons name="notifications-off-outline" size={20} color={colors.warning} />
              <Text style={styles.headerActionText}>Unmute</Text>
            </>
          ) : (
            <>
              <Ionicons name="notifications-outline" size={20} color={colors.warning} />
              <Text style={styles.headerActionText}>Mute</Text>
            </>
          )}
        </Pressable>
      </View>

      <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={[styles.card, shadow.sm]}>
          <View style={styles.orderInfoTop}>
            <Text style={styles.orderNumber} numberOfLines={1}>Order No: {order.order_number || `#${order.id}`}</Text>
            <Badge label={order.order_status} />
          </View>
          {dateTime ? <Text style={styles.orderDate}>{dateTime} · {timeAgo(order.created_at)}</Text> : null}
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
            <Text style={styles.customerName} numberOfLines={1}>
              {realName(order.customer_name) || (order.order_type === 'Dine-in' ? 'Dine-in guest' : 'Walk-in customer')}
            </Text>
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
          {notes.text ? (
            <View style={styles.noteBox}>
              <Ionicons name="document-text-outline" size={15} color="#92400E" />
              <Text style={styles.noteText}>{notes.text}</Text>
            </View>
          ) : null}
          {notes.stopReason ? (
            <View style={[styles.noteBox, { backgroundColor: colors.dangerBg }]}>
              <Ionicons name="close-circle-outline" size={15} color={colors.danger} />
              <Text style={[styles.noteText, { color: colors.danger }]}>Reason: {notes.stopReason}</Text>
            </View>
          ) : null}
          {notes.kot ? (
            <View style={styles.kotChip}>
              <Ionicons name="flame-outline" size={13} color={colors.primary} />
              <Text style={styles.kotChipText}>Kitchen ticket {notes.kot}</Text>
            </View>
          ) : null}
        </View>

        <View style={[styles.card, shadow.sm]}>
          <View style={styles.itemsHeader}>
            <Text style={styles.itemsTitle}>Item Details</Text>
            <View style={styles.orderTypeBadge}>
              <Text style={styles.orderTypeBadgeText}>{order.order_type}</Text>
            </View>
          </View>

          {!hydrated ? (
            <View style={{ gap: 12, paddingVertical: 6 }}>
              <SkeletonBlock width="70%" height={14} />
              <SkeletonBlock width="55%" height={14} />
              <SkeletonBlock width="40%" height={14} />
            </View>
          ) : items.length === 0 ? (
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
                <Text style={styles.itemPrice}>{money(item.total_price, currency)}</Text>
              </View>
            ))
          )}

          <View style={styles.divider} />
          {hydrated ? <Row label="Subtotal" value={money(order.subtotal, currency)} /> : null}
          {Number(order.discount_amount) > 0 ? <Row label={`Discount${order.coupon_code ? ` (${order.coupon_code})` : ''}`} value={`-${money(order.discount_amount, currency)}`} /> : null}
          {order.tax > 0 ? <Row label="Tax" value={money(order.tax, currency)} /> : null}

          <View style={styles.paymentFooter}>
            {!hydrated ? <SkeletonBlock width={80} height={28} r={14} /> : (
            <Pressable
              style={[styles.paymentBadge, { backgroundColor: paid ? colors.successBg : colors.warningBg }]}
              onPress={() => paymentOptions.length > 0 && setPaymentPickerOpen(true)}
              disabled={paymentOptions.length === 0}
            >
              <Text style={[styles.paymentBadgeText, { color: paid ? colors.success : colors.warning }]}>
                {paymentLabel(order.payment_status)}
              </Text>
              {paymentOptions.length > 0 ? (
                <Ionicons name="chevron-down" size={12} color={paid ? colors.success : colors.warning} />
              ) : null}
            </Pressable>
            )}
            <Text style={styles.totalValue}>{money(order.total, currency)}</Text>
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
        <View style={[styles.footer, { paddingHorizontal: gutter, paddingBottom: Math.max(insets.bottom, spacing.md) + spacing.sm }]}>
          {actions.secondary?.length ? (
            <View style={styles.secondaryRow}>
              {actions.secondary.map((a) => (
                <PressableScale
                  key={a.to}
                  style={styles.secondaryButton}
                  onPress={() => updateStatus(a.to)}
                  disabled={!!updatingTo}
                >
                  <Text style={styles.secondaryButtonText}>{a.label}</Text>
                </PressableScale>
              ))}
            </View>
          ) : null}
          <View style={styles.primaryRow}>
            {actions.primary?.map((a) => {
              const danger = a.tone === 'danger';
              return (
                <PressableScale
                  key={a.to}
                  style={[styles.primaryButton, danger ? styles.dangerButton : styles.goButton, a.full && { flex: 1 }]}
                  feedback={danger ? 'warning' : 'press'}
                  onPress={() => updateStatus(a.to, a.to === 'Accepted' ? { prep_minutes: prepMinutes } : {})}
                  disabled={!!updatingTo}
                  accessibilityLabel={a.label}
                >
                  {updatingTo === a.to ? (
                    <ActivityIndicator color={danger ? colors.danger : '#fff'} size="small" />
                  ) : (
                    <Text style={[styles.primaryButtonText, danger && { color: colors.danger }]}>{a.label}</Text>
                  )}
                </PressableScale>
              );
            })}
          </View>
        </View>
      ) : null}

      <Sheet visible={paymentPickerOpen} onClose={() => setPaymentPickerOpen(false)} title="Payment" subtitle={`Now: ${paymentLabel(order.payment_status)} · ${money(order.total, currency)}`}>
        <View style={{ gap: 8 }}>
          {paymentOptions.map((status) => (
            <PressableScale key={status} style={styles.pickerOption} onPress={() => updatePayment(status)} disabled={updatingPayment}>
              <Ionicons
                name={status === 'Paid' ? 'checkmark-circle' : status === 'Refunded' ? 'arrow-undo' : 'time'}
                size={20}
                color={status === 'Paid' ? colors.success : status === 'Refunded' ? colors.danger : colors.warning}
              />
              <Text style={styles.pickerOptionText}>Mark as {paymentLabel(status).toLowerCase()}</Text>
              {updatingPayment ? <ActivityIndicator size="small" color={colors.primary} /> : <Ionicons name="chevron-forward" size={16} color={colors.muted} />}
            </PressableScale>
          ))}
        </View>
      </Sheet>

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
    // Extra bottom padding so Accept/Reject sit clearly above Android's
    // gesture nav bar — with insets alone the buttons hugged the bar and
    // taps near the bottom edge hit the system navigation instead.
    elevation: 8,
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: -2 },
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
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 52,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  goButton: {
    flex: 1,
    backgroundColor: colors.success,
  },
  // Reject / Cancel: outlined and narrower than the main action, so the safe choice is the
  // big obvious one and the destructive one is never hit by accident.
  dangerButton: {
    borderWidth: 1.5,
    borderColor: colors.danger,
    backgroundColor: colors.surface,
    minWidth: 110,
  },
  primaryButtonText: {
    color: '#fff',
    fontFamily: font.bold,
    fontSize: 15,
  },
  noteBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 8,
    backgroundColor: '#FEF7E6',
    borderRadius: radius.md,
    padding: spacing.md,
    marginTop: spacing.sm,
  },
  noteText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13,
    color: '#92400E',
    lineHeight: 19,
  },
  kotChip: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 5,
    backgroundColor: colors.primaryLight,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 5,
    marginTop: spacing.sm,
  },
  kotChipText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.primary,
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
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.bg,
  },
  pickerOptionText: {
    flex: 1,
    fontFamily: font.semiBold,
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
