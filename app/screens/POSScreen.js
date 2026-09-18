import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useNavigation } from '@react-navigation/native';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import BillPreviewModal from '../components/BillPreviewModal';
import ScreenHeader from '../components/ScreenHeader';
import { ErrorState, LoadingState } from '../components/ScreenState';
import SelectField from '../components/SelectField';
import SplitPaymentModal from '../components/SplitPaymentModal';
import { apiGet, apiPostForm, imageUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { playClickSound } from '../utils/orderAlerts';

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function cartKeyFor(id, variationName) {
  return `${id}_${variationName || ''}`;
}

export default function POSScreen() {
  const { user } = useAuth();
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const tabBarHeight = useBottomTabBarHeight();
  const currency = user?.currency_symbol || '₹';

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [menuItems, setMenuItems] = useState([]);
  const [tables, setTables] = useState([]);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [coupons, setCoupons] = useState([]);

  const [search, setSearch] = useState('');
  const [menuFilter, setMenuFilter] = useState('All');
  const [vegOnly, setVegOnly] = useState(false);
  const listRef = useRef(null);
  const searchRef = useRef(null);
  const [cart, setCart] = useState([]); // [{key, id, name, price, quantity, variationName}]
  const [variationItem, setVariationItem] = useState(null); // item awaiting variation choice
  const [cartOpen, setCartOpen] = useState(false);

  const [tableId, setTableId] = useState('');
  const [couponInput, setCouponInput] = useState('');
  const [appliedCoupon, setAppliedCoupon] = useState(null); // matched row from `coupons`
  const [couponError, setCouponError] = useState('');
  const [holding, setHolding] = useState(false);
  const [sendingKot, setSendingKot] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payModalOpen, setPayModalOpen] = useState(false);
  const [billPreview, setBillPreview] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    Promise.all([
      apiGet('/api/get_menu_items.php?limit=200'),
      apiGet('/api/get_tables.php'),
      apiGet('/api/get_payment_methods.php'),
      apiGet('/api/get_coupons.php'),
    ])
      .then(([miRes, tRes, pmRes, cpRes]) => {
        setMenuItems(miRes?.success ? miRes.data || [] : []);
        setTables(tRes?.success ? tRes.data || [] : []);
        setPaymentMethods(pmRes?.success ? (pmRes.data || []).filter((m) => m.is_active) : []);
        setCoupons(cpRes?.success ? cpRes.coupons || [] : []);
      })
      .catch((e) => setError(e.message || 'Could not load POS'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  // The floating pill tab bar is an absolutely-positioned overlay that sits
  // on top of whatever's at the bottom of the tab's content — including the
  // cart sheet's Pay/Hold/KOT buttons, which are otherwise identical to it.
  // Hide it for the duration of the cart sheet so it can't cover them.
  useEffect(() => {
    navigation.setOptions({ tabBarStyle: cartOpen ? { display: 'none' } : undefined });
    return () => navigation.setOptions({ tabBarStyle: undefined });
  }, [navigation, cartOpen]);

  const menuOptions = useMemo(() => {
    const set = new Set(menuItems.map((it) => it.menu_name).filter(Boolean));
    return [{ label: 'All Menus', value: 'All' }, ...Array.from(set).map((m) => ({ label: m, value: m }))];
  }, [menuItems]);

  const visibleItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    return menuItems.filter((it) => {
      if (it.is_available != 1) return false;
      if (menuFilter !== 'All' && it.menu_name !== menuFilter) return false;
      if (vegOnly && it.item_type !== 'Veg') return false;
      if (q) {
        const name = (it.item_name_en || '').toLowerCase();
        const cat = (it.item_category || '').toLowerCase();
        if (!name.includes(q) && !cat.includes(q)) return false;
      }
      return true;
    });
  }, [menuItems, menuFilter, vegOnly, search]);

  // Item card price: a min–max range across variations (matches the
  // website's POS card), or the plain base price for simple items.
  const priceLabelFor = (item) => {
    if (item.has_variations == 1 && (item.variations || []).length > 0) {
      const prices = item.variations.map((v) => Number(v.price)).filter((n) => !Number.isNaN(n));
      if (prices.length > 0) {
        const min = Math.min(...prices);
        const max = Math.max(...prices);
        return min === max ? `${currency}${min}` : `${currency}${min} - ${currency}${max}`;
      }
    }
    return `${currency}${item.base_price ?? 0}`;
  };

  const addToCart = (item, variation) => {
    playClickSound();
    const variationName = variation?.variation_name || '';
    const price = variation ? Number(variation.price) : Number(item.base_price);
    const key = cartKeyFor(item.id, variationName);
    setCart((prev) => {
      const existing = prev.find((c) => c.key === key);
      if (existing) {
        return prev.map((c) => (c.key === key ? { ...c, quantity: c.quantity + 1 } : c));
      }
      return [...prev, { key, id: item.id, name: item.item_name_en, price, quantity: 1, variationName }];
    });
  };

  const onPressItem = (item) => {
    if (item.has_variations == 1 && (item.variations || []).length > 0) {
      setVariationItem(item);
    } else {
      addToCart(item, null);
    }
  };

  const changeQty = (key, delta) => {
    setCart((prev) =>
      prev
        .map((c) => (c.key === key ? { ...c, quantity: c.quantity + delta } : c))
        .filter((c) => c.quantity > 0)
    );
  };

  const removeLine = (key) => setCart((prev) => prev.filter((c) => c.key !== key));

  const subtotal = useMemo(() => round2(cart.reduce((s, c) => s + c.price * c.quantity, 0)), [cart]);
  // Discount comes off the raw item subtotal before tax — mirrors the
  // website's checkout and the server-side recompute in pos_operations.php,
  // which is the actual source of truth (this is just for display/preview).
  const discount = useMemo(() => {
    if (!appliedCoupon) return 0;
    const raw =
      appliedCoupon.discount_type === 'percent'
        ? subtotal * (Number(appliedCoupon.discount_value) || 0) / 100
        : Number(appliedCoupon.discount_value) || 0;
    return round2(Math.min(Math.max(raw, 0), subtotal));
  }, [appliedCoupon, subtotal]);
  const taxable = useMemo(() => round2(Math.max(0, subtotal - discount)), [subtotal, discount]);
  const gstEnabled = user?.enable_gst == 1;
  const taxPercent = Number(user?.tax_percent ?? 5);
  const tax = useMemo(() => (gstEnabled ? round2(taxable * (taxPercent / 100)) : 0), [taxable, gstEnabled, taxPercent]);
  const total = useMemo(() => round2(taxable + tax), [taxable, tax]);
  const itemCount = cart.reduce((s, c) => s + c.quantity, 0);

  const applyCoupon = () => {
    const code = couponInput.trim().toUpperCase();
    setCouponError('');
    if (!code) return;
    const match = coupons.find((c) => c.coupon_code.toUpperCase() === code);
    if (!match) {
      setCouponError('Invalid or expired coupon code');
      return;
    }
    const minOrder = Number(match.minimum_order_amount) || 0;
    if (minOrder > 0 && subtotal < minOrder) {
      setCouponError(`Minimum order of ${currency}${minOrder.toFixed(2)} required for this coupon`);
      return;
    }
    setAppliedCoupon(match);
    setCouponInput('');
  };

  const removeCoupon = () => {
    setAppliedCoupon(null);
    setCouponError('');
  };

  const resetCart = () => {
    setCart([]);
    setTableId('');
    setCouponInput('');
    setAppliedCoupon(null);
    setCouponError('');
    setCartOpen(false);
  };

  const clearCart = () => {
    if (cart.length === 0) return;
    Alert.alert('Clear Cart', 'Are you sure you want to clear the cart?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Clear', style: 'destructive', onPress: resetCart },
    ]);
  };

  // Shows what's about to be printed before it hits the printer — mirrors
  // the website's KOT/bill preview, instead of printing blind off a plain
  // "created" alert. The whole bill is snapshotted here (not read live from
  // state) since resetCart() runs right after this and would otherwise zero
  // out subtotal/discount/tax/total before the preview ever renders.
  const offerPrint = (title, kotNumber, paymentMethod, cartSnapshot, tableIdSnapshot) => {
    const tableName = tables.find((t) => String(t.id) === String(tableIdSnapshot))?.table_number;
    setBillPreview({
      title,
      restaurantName: user?.restaurant_name || 'Receipt',
      kotNumber,
      orderType: tableIdSnapshot ? 'Dine-in' : 'Takeaway',
      tableName,
      items: cartSnapshot.map((c) => ({ name: c.name, quantity: c.quantity, price: c.price, variationName: c.variationName })),
      subtotal: round2(cartSnapshot.reduce((s, c) => s + c.price * c.quantity, 0)),
      discount,
      couponCode: appliedCoupon?.coupon_code,
      tax,
      taxPercent: gstEnabled ? taxPercent : null,
      total,
      paymentMethod,
      currency,
    });
  };

  // Mirrors the website POS, which has no customer-details form at all — a
  // generic placeholder name is sent along with the table/order type instead.
  const buildCartPayload = (extraNotes) => ({
    tableId: tableId || '',
    orderType: tableId ? 'Dine-in' : 'Takeaway',
    customerName: tableId ? 'Table Customer' : 'Takeaway',
    cartItems: JSON.stringify(
      cart.map((c) => ({ id: c.id, name: c.name, price: c.price, quantity: c.quantity, variation_name: c.variationName || '' }))
    ),
    subtotal,
    tax,
    total,
    couponCode: appliedCoupon?.coupon_code || '',
    notes: extraNotes || '',
  });

  const holdOrder = async () => {
    if (cart.length === 0) return;
    setHolding(true);
    try {
      const res = await apiPostForm('/controllers/pos_operations.php', {
        action: 'hold_order',
        ...buildCartPayload(),
      });
      if (!res.success) throw new Error(res.message || 'Could not hold order');
      Alert.alert('Order held', `Held as ${res.order_number}. Resume it from the Orders tab.`);
      resetCart();
    } catch (e) {
      Alert.alert('Could not hold order', e.message);
    } finally {
      setHolding(false);
    }
  };

  // "Send KOT" — ticket goes to the kitchen with no payment collected yet
  // (mirrors the website's separate KOT button; payment is settled later,
  // either now via "Pay" or after the food is served).
  const sendKOT = async () => {
    if (cart.length === 0) return;
    setSendingKot(true);
    try {
      const cartSnapshot = cart;
      const tableIdSnapshot = tableId;
      const res = await apiPostForm('/controllers/pos_operations.php', {
        action: 'create_kot',
        ...buildCartPayload(),
        paymentMethod: 'Cash',
      });
      if (!res.success) throw new Error(res.message || 'Could not send to kitchen');
      resetCart();
      offerPrint('Sent to kitchen', res.kot_number, null, cartSnapshot, tableIdSnapshot);
    } catch (e) {
      Alert.alert('Could not send to kitchen', e.message);
    } finally {
      setSendingKot(false);
    }
  };

  // "Pay" — same create_kot ticket, but payment is collected up front via
  // the split-payment modal (mirrors the website's separate Pay button,
  // which supports splitting the total across multiple methods).
  const payNow = () => {
    if (cart.length === 0) return;
    setPayModalOpen(true);
  };

  const confirmPayment = async ({ paymentMethod, breakdown }) => {
    setPaying(true);
    try {
      const cartSnapshot = cart;
      const tableIdSnapshot = tableId;
      const res = await apiPostForm('/controllers/pos_operations.php', {
        action: 'create_kot',
        ...buildCartPayload(breakdown ? `Payment split: ${breakdown}` : null),
        paymentMethod,
      });
      if (!res.success) throw new Error(res.message || 'Could not process payment');
      setPayModalOpen(false);
      resetCart();
      offerPrint('Payment collected', res.kot_number, paymentMethod, cartSnapshot, tableIdSnapshot);
    } catch (e) {
      Alert.alert('Could not process payment', e.message);
    } finally {
      setPaying(false);
    }
  };

  const tableOptions = [
    { label: 'Walk-in', value: '' },
    ...tables.map((t) => ({ label: `${t.table_number} · seats ${t.capacity}`, value: String(t.id) })),
  ];

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Counter orders" title="POS" />

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={error} />
      ) : (
        <>
          {/* Filter grid — mirrors the website's mobile POS: search + Menu
              on one row, Category + Type on the next, all as dropdowns
              instead of scrolling chip rows. */}
          <View style={[styles.filterRow, styles.filterRowFirst]}>
            <View style={[styles.searchWrap, styles.filterHalf]}>
              <Ionicons name="search" size={16} color={colors.muted} />
              <TextInput
                ref={searchRef}
                style={styles.searchInput}
                placeholder="Search items..."
                placeholderTextColor={colors.muted}
                value={search}
                onChangeText={setSearch}
              />
            </View>
          </View>
          <View style={[styles.filterRow, styles.filterRowSecond]}>
            <View style={styles.filterHalf}>
              <SelectField placeholder="All Menus" value={menuFilter} onChange={setMenuFilter} options={menuOptions} />
            </View>
          </View>
          <View style={[styles.filterRow, styles.filterRowSecond, styles.vegRow]}>
            <View style={styles.vegDotOutline}>
              <View style={styles.vegDotInner} />
            </View>
            <Text style={styles.vegLabel}>Veg Only</Text>
            <Switch
              value={vegOnly}
              onValueChange={setVegOnly}
              trackColor={{ false: colors.border, true: colors.primaryLight }}
              thumbColor={vegOnly ? colors.primary : '#fff'}
            />
          </View>

          <FlatList
            ref={listRef}
            style={styles.fill}
            data={visibleItems}
            keyExtractor={(item, i) => String(item.id ?? i)}
            numColumns={2}
            columnWrapperStyle={{ gap: spacing.md }}
            contentContainerStyle={[styles.grid, { paddingBottom: 160 + tabBarHeight }]}
            showsVerticalScrollIndicator={false}
            renderItem={({ item }) => {
              const qtyInCart = cart.filter((c) => c.id === item.id).reduce((s, c) => s + c.quantity, 0);
              const thumb = imageUrl(item.item_image);
              return (
                <Pressable
                  style={({ pressed }) => [styles.itemCard, shadow.sm, pressed && { opacity: 0.85 }]}
                  onPress={() => onPressItem(item)}
                >
                  <View style={styles.itemThumb}>
                    {thumb ? <ItemImage uri={thumb} /> : <Ionicons name="restaurant-outline" size={26} color={colors.muted} />}
                    {qtyInCart > 0 ? (
                      <View style={styles.qtyBadge}>
                        <Text style={styles.qtyBadgeText}>{qtyInCart}</Text>
                      </View>
                    ) : null}
                  </View>
                  <View style={styles.itemCardBody}>
                    <Text style={styles.itemName} numberOfLines={2}>{item.item_name_en}</Text>
                    {item.item_category ? (
                      <Text style={styles.itemCategory} numberOfLines={1}>{item.item_category}</Text>
                    ) : null}
                    <View style={styles.itemPriceBadge}>
                      <Text style={styles.itemPriceText} numberOfLines={1}>{priceLabelFor(item)}</Text>
                    </View>
                    {item.has_variations == 1 ? <Text style={styles.itemVariationHint}>(Variations)</Text> : null}
                  </View>
                </Pressable>
              );
            }}
          />

          {/* A single "View Cart" trigger instead of a persistent bar of
              Pay/Hold/KOT/Add Item — those all still live inside the cart
              sheet below, one tap away, instead of cluttering the main
              screen at all times. */}
          {itemCount > 0 ? (
            <Pressable
              style={({ pressed }) => [styles.viewCartBar, shadow.md, { bottom: tabBarHeight + spacing.sm }, pressed && { opacity: 0.92 }]}
              onPress={() => setCartOpen(true)}
            >
              <View style={styles.viewCartBadge}>
                <Text style={styles.viewCartBadgeText}>{itemCount}</Text>
              </View>
              <Text style={styles.viewCartText}>View Cart</Text>
              <Text style={styles.viewCartTotal}>{currency}{total.toFixed(2)}</Text>
              <Ionicons name="chevron-forward" size={18} color="#fff" />
            </Pressable>
          ) : null}
        </>
      )}

      {/* Variation picker */}
      <Modal visible={!!variationItem} transparent animationType="fade" onRequestClose={() => setVariationItem(null)}>
        <Pressable style={styles.backdrop} onPress={() => setVariationItem(null)}>
          <Pressable style={[styles.variationSheet, shadow.lg]} onPress={() => {}}>
            <Text style={styles.variationTitle}>{variationItem?.item_name_en}</Text>
            <Text style={styles.variationSubtitle}>Choose a size</Text>
            {(variationItem?.variations || []).map((v) => (
              <Pressable
                key={v.id}
                style={styles.variationOption}
                onPress={() => {
                  addToCart(variationItem, v);
                  setVariationItem(null);
                }}
              >
                <Text style={styles.variationOptionText}>{v.variation_name}</Text>
                <Text style={styles.variationOptionPrice}>{currency}{v.price}</Text>
              </Pressable>
            ))}
          </Pressable>
        </Pressable>
      </Modal>

      {/* Cart / checkout sheet */}
      <Modal
        visible={cartOpen}
        animationType="slide"
        transparent={Platform.OS === 'web'}
        onRequestClose={() => setCartOpen(false)}
      >
        <View style={styles.cartModalBackdrop}>
        <KeyboardAvoidingView
          style={[styles.fill, styles.cartModalPanel, { paddingTop: insets.top }]}
          behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        >
          <View style={styles.cartHeader}>
            <Pressable style={styles.iconButton} onPress={() => setCartOpen(false)} hitSlop={8}>
              <Ionicons name="close" size={20} color={colors.ink} />
            </Pressable>
            <Text style={styles.cartHeaderTitle}>Current Order</Text>
            <Pressable style={styles.iconButton} onPress={clearCart} hitSlop={8}>
              <Ionicons name="trash-outline" size={18} color={colors.danger} />
            </Pressable>
          </View>

          <ScrollView style={styles.fill} contentContainerStyle={styles.cartContent} showsVerticalScrollIndicator={false}>
            {cart.map((c) => (
              <View key={c.key} style={styles.cartLine}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.cartLineName} numberOfLines={1}>
                    {c.name}{c.variationName ? ` (${c.variationName})` : ''}
                  </Text>
                  <Text style={styles.cartLinePrice}>{currency}{c.price} each</Text>
                </View>
                <View style={styles.qtyStepper}>
                  <Pressable style={styles.qtyButton} onPress={() => changeQty(c.key, -1)} hitSlop={6}>
                    <Ionicons name="remove" size={16} color={colors.ink} />
                  </Pressable>
                  <Text style={styles.qtyValue}>{c.quantity}</Text>
                  <Pressable style={styles.qtyButton} onPress={() => changeQty(c.key, 1)} hitSlop={6}>
                    <Ionicons name="add" size={16} color={colors.ink} />
                  </Pressable>
                </View>
                <Pressable style={styles.removeLineButton} onPress={() => removeLine(c.key)} hitSlop={6}>
                  <Ionicons name="trash-outline" size={16} color={colors.danger} />
                </Pressable>
              </View>
            ))}
            {cart.length === 0 ? <Text style={styles.emptyCartText}>Your cart is empty</Text> : null}

            <Text style={styles.fieldLabel}>Select Table</Text>
            <SelectField placeholder="Walk-in" value={tableId} onChange={setTableId} options={tableOptions} />

            <Text style={styles.fieldLabel}>Coupon</Text>
            {appliedCoupon ? (
              <View style={styles.couponAppliedRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.couponAppliedCode}>{appliedCoupon.coupon_code}</Text>
                  <Text style={styles.couponAppliedHint}>
                    {appliedCoupon.discount_type === 'percent'
                      ? `${appliedCoupon.discount_value}% off`
                      : `${currency}${Number(appliedCoupon.discount_value).toFixed(2)} off`}
                  </Text>
                </View>
                <Pressable onPress={removeCoupon} hitSlop={8}>
                  <Ionicons name="close-circle" size={22} color={colors.danger} />
                </Pressable>
              </View>
            ) : (
              <View style={styles.couponRow}>
                <TextInput
                  style={[styles.input, { flex: 1 }]}
                  placeholder="Enter coupon code"
                  placeholderTextColor={colors.muted}
                  autoCapitalize="characters"
                  value={couponInput}
                  onChangeText={(v) => { setCouponInput(v); setCouponError(''); }}
                  onSubmitEditing={applyCoupon}
                />
                <Pressable style={styles.couponApplyButton} onPress={applyCoupon}>
                  <Text style={styles.couponApplyButtonText}>Apply</Text>
                </Pressable>
              </View>
            )}
            {couponError ? <Text style={styles.couponErrorText}>{couponError}</Text> : null}
            {!appliedCoupon && coupons.length > 0 ? (
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginTop: spacing.sm }}>
                {coupons.map((c) => (
                  <Pressable
                    key={c.id}
                    style={styles.couponChip}
                    onPress={() => { setCouponInput(c.coupon_code); setCouponError(''); }}
                  >
                    <Ionicons name="pricetag" size={12} color={colors.primary} />
                    <Text style={styles.couponChipText}>{c.coupon_code}</Text>
                  </Pressable>
                ))}
              </ScrollView>
            ) : null}

            <View style={styles.summaryBox}>
              <View style={styles.summaryRow}>
                <Text style={styles.summaryLabel}>Subtotal</Text>
                <Text style={styles.summaryValue}>{currency}{subtotal.toFixed(2)}</Text>
              </View>
              {discount > 0 ? (
                <View style={styles.summaryRow}>
                  <Text style={[styles.summaryLabel, { color: colors.success }]}>Coupon ({appliedCoupon.coupon_code})</Text>
                  <Text style={[styles.summaryValue, { color: colors.success }]}>-{currency}{discount.toFixed(2)}</Text>
                </View>
              ) : null}
              {tax > 0 ? (
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Tax ({taxPercent}%)</Text>
                  <Text style={styles.summaryValue}>{currency}{tax.toFixed(2)}</Text>
                </View>
              ) : null}
              <View style={[styles.summaryRow, styles.summaryTotalRow]}>
                <Text style={styles.summaryTotalLabel}>Total</Text>
                <Text style={styles.summaryTotalValue}>{currency}{total.toFixed(2)}</Text>
              </View>
            </View>
          </ScrollView>

          <View style={[styles.cartFooter, styles.cartFooterPinned, { paddingBottom: Math.max(insets.bottom, spacing.md) }]}>
            <Pressable
              style={({ pressed }) => [styles.payButton, pressed && { opacity: 0.9 }]}
              onPress={payNow}
              disabled={holding || sendingKot || paying || cart.length === 0}
            >
              <Ionicons name="card" size={15} color="#fff" />
              <Text style={styles.payButtonText}>Pay</Text>
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.holdButton, pressed && { opacity: 0.9 }]}
              onPress={holdOrder}
              disabled={holding || sendingKot || paying || cart.length === 0}
            >
              {holding ? (
                <ActivityIndicator color={colors.info} size="small" />
              ) : (
                <>
                  <Ionicons name="pause" size={15} color={colors.info} />
                  <Text style={styles.holdButtonText}>Hold</Text>
                </>
              )}
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.kotButton, pressed && { opacity: 0.9 }]}
              onPress={sendKOT}
              disabled={holding || sendingKot || paying || cart.length === 0}
            >
              {sendingKot ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <>
                  <Ionicons name="restaurant" size={15} color="#fff" />
                  <Text style={styles.kotButtonText}>KOT</Text>
                </>
              )}
            </Pressable>
          </View>
        </KeyboardAvoidingView>
        </View>
      </Modal>

      <SplitPaymentModal
        visible={payModalOpen}
        onClose={() => setPayModalOpen(false)}
        onConfirm={confirmPayment}
        total={total}
        currency={currency}
        methods={paymentMethods.length > 0 ? paymentMethods.map((m) => m.method_name) : ['Cash', 'Card', 'UPI']}
        submitting={paying}
      />

      <BillPreviewModal
        visible={!!billPreview}
        data={billPreview}
        onClose={() => setBillPreview(null)}
      />
    </View>
  );
}

function ItemImage({ uri }) {
  const [failed, setFailed] = useState(false);
  if (failed) return <Ionicons name="restaurant-outline" size={26} color={colors.muted} />;
  return <Image source={{ uri }} style={styles.itemThumbImage} onError={() => setFailed(true)} />;
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  // On web (a desktop browser window) the full-screen cart sheet would
  // otherwise stretch edge-to-edge; center it as a phone-width panel over a
  // dimmed backdrop instead, matching the rest of the app's web frame.
  // Native is untouched — Platform.select's default branch is a no-op.
  cartModalBackdrop: Platform.select({
    web: { flex: 1, alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.5)' },
    default: { flex: 1 },
  }),
  cartModalPanel: Platform.select({
    web: { width: '100%', maxWidth: 480 },
    default: {},
  }),
  // Filter grid — search + Menu / Category + Type, two dropdown-style
  // fields per row, mirroring the website's mobile POS filter layout.
  filterRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginHorizontal: spacing.xl,
  },
  filterRowFirst: {
    marginTop: -spacing.lg,
    marginBottom: spacing.sm,
  },
  filterRowSecond: {
    marginBottom: spacing.md,
  },
  filterHalf: {
    flex: 1,
  },
  vegRow: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  vegDotOutline: {
    width: 16,
    height: 16,
    borderRadius: 3,
    borderWidth: 1.5,
    borderColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
  },
  vegDotInner: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.success,
  },
  vegLabel: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    gap: spacing.sm,
  },
  searchInput: {
    flex: 1,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  grid: {
    paddingHorizontal: spacing.xl,
    paddingBottom: 160,
    gap: spacing.md,
  },
  // Full-bleed image on top (rounded top corners only) with the name/price
  // in a padded body below — mirrors the website's mobile POS item cards.
  itemCard: {
    flex: 1,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    marginBottom: spacing.md,
    overflow: 'hidden',
  },
  itemThumb: {
    width: '100%',
    height: 110,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  itemThumbImage: {
    width: '100%',
    height: '100%',
  },
  qtyBadge: {
    position: 'absolute',
    top: 8,
    right: 8,
    backgroundColor: colors.primary,
    borderRadius: 10,
    minWidth: 20,
    height: 20,
    paddingHorizontal: 4,
    alignItems: 'center',
    justifyContent: 'center',
    ...shadow.sm,
  },
  qtyBadgeText: {
    color: '#fff',
    fontFamily: font.bold,
    fontSize: 11,
  },
  itemCardBody: {
    padding: spacing.md,
  },
  itemName: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
    marginBottom: 2,
  },
  itemCategory: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    marginBottom: 6,
  },
  itemPriceBadge: {
    alignSelf: 'flex-start',
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  itemPriceText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.ink,
  },
  itemVariationHint: {
    fontFamily: font.regular,
    fontSize: 10.5,
    color: colors.muted,
    marginTop: 3,
  },
  viewCartBar: {
    position: 'absolute',
    left: spacing.lg,
    right: spacing.lg,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.primary,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    zIndex: 20,
    elevation: 20,
  },
  viewCartBadge: {
    minWidth: 22,
    height: 22,
    borderRadius: 11,
    paddingHorizontal: 5,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.25)',
  },
  viewCartBadgeText: {
    fontFamily: font.bold,
    fontSize: 12,
    color: '#fff',
  },
  viewCartText: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: '#fff',
  },
  viewCartTotal: {
    fontFamily: font.bold,
    fontSize: 14.5,
    color: '#fff',
  },
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  variationSheet: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  variationTitle: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  variationSubtitle: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginBottom: spacing.sm,
  },
  variationOption: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 13,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  variationOptionText: {
    fontFamily: font.medium,
    fontSize: 14.5,
    color: colors.ink,
  },
  variationOptionPrice: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.primary,
  },
  iconButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  cartHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  cartHeaderTitle: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  cartContent: {
    padding: spacing.xl,
    paddingBottom: 130,
    gap: spacing.sm,
  },
  cartLine: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  cartLineName: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  cartLinePrice: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 2,
  },
  qtyStepper: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.bg,
    borderRadius: radius.pill,
    paddingHorizontal: 6,
    paddingVertical: 4,
  },
  qtyButton: {
    width: 24,
    height: 24,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
  },
  qtyValue: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
    minWidth: 16,
    textAlign: 'center',
  },
  removeLineButton: {
    width: 30,
    height: 30,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.dangerBg,
  },
  emptyCartText: {
    textAlign: 'center',
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    paddingVertical: spacing.lg,
  },
  fieldLabel: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
    marginBottom: spacing.xs,
    marginTop: spacing.md,
  },
  input: {
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  couponRow: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  couponApplyButton: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primaryLight,
    borderRadius: radius.md,
    paddingHorizontal: spacing.lg,
  },
  couponApplyButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  couponAppliedRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.successBg,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: spacing.sm,
  },
  couponAppliedCode: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.success,
  },
  couponAppliedHint: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.inkSoft,
    marginTop: 1,
  },
  couponErrorText: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.danger,
    marginTop: spacing.xs,
  },
  couponChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    backgroundColor: colors.primaryLight,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 6,
    marginRight: spacing.sm,
  },
  couponChipText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.primary,
  },
  summaryBox: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginTop: spacing.lg,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 5,
  },
  summaryLabel: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  summaryValue: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  summaryTotalRow: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    marginTop: spacing.xs,
    paddingTop: spacing.sm,
  },
  summaryTotalLabel: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  summaryTotalValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  cartFooter: {
    flexDirection: 'row',
    gap: spacing.md,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  // Pinned to the bottom of the cart sheet regardless of how tall the
  // scrollable content above it gets, instead of trusting flex to leave
  // exactly enough room — belt-and-braces against it ending up squeezed
  // off-screen.
  cartFooterPinned: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    zIndex: 20,
  },
  // Pay/Hold/KOT colors mirror the website's mobile POS action bar (blue
  // Pay, blue-outline Hold, amber KOT) rather than the app's own orange
  // brand color, since matching that site is the point here.
  holdButton: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.surface,
    borderWidth: 2,
    borderColor: colors.info,
    borderRadius: radius.md,
    paddingVertical: 12,
  },
  holdButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.info,
  },
  kotButton: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.warning,
    borderRadius: radius.md,
    paddingVertical: 14,
  },
  kotButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: '#fff',
  },
  payButton: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.info,
    borderRadius: radius.md,
    paddingVertical: 14,
  },
  payButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: '#fff',
  },
});
