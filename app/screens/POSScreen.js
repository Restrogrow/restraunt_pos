import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  RefreshControl,
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
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import SearchField from '../components/SearchField';
import SelectField from '../components/SelectField';
import SplitPaymentModal from '../components/SplitPaymentModal';
import AppImage from '../components/ui/AppImage';
import { FeedbackHost, feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import Sheet from '../components/ui/Sheet';
import { apiGet, apiPostForm, imageUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { money } from '../utils/format';
import { haptic } from '../utils/haptics';
import { playClickSound } from '../utils/orderAlerts';

const VEG = { Veg: colors.success, 'Non Veg': colors.danger, Egg: colors.warning };

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function cartKeyFor(id, variationName) {
  return `${id}_${variationName || ''}`;
}

export default function POSScreen({ route }) {
  const { user } = useAuth();
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, columns, contentWidth } = useLayout();
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

  // Arriving from Tables ("start an order for T4") preselects that table.
  useEffect(() => {
    const t = route?.params?.tableId;
    if (t) {
      setTableId(String(t));
      feedback.info(`New order for table ${route.params.tableLabel || ''}`.trim());
      navigation.setParams({ tableId: undefined, tableLabel: undefined });
    }
  }, [route?.params?.tableId]); // eslint-disable-line react-hooks/exhaustive-deps
  const [couponInput, setCouponInput] = useState('');
  const [appliedCoupon, setAppliedCoupon] = useState(null); // matched row from `coupons`
  const [couponError, setCouponError] = useState('');
  const [holding, setHolding] = useState(false);
  const [sendingKot, setSendingKot] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payModalOpen, setPayModalOpen] = useState(false);
  const [billPreview, setBillPreview] = useState(null);
  const [refreshing, setRefreshing] = useState(false);

  // silent=true skips the full-screen spinner — used when refreshing on tab
  // focus (see useFocusEffect below), where the screen is already showing
  // good data and a flash-to-spinner on every tab switch would be jarring.
  const load = useCallback((silent = false) => {
    if (!silent) setLoading(true);
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
      .catch((e) => {
        if (!silent) setError(e.message || 'Could not load POS');
      })
      .finally(() => {
        setLoading(false);
        setRefreshing(false);
      });
  }, []);

  // Pull-to-refresh on the item grid — silent reload (no spinner flash) so
  // the menu/tables/coupons shown stay on screen while they refresh.
  const refresh = useCallback(() => {
    setRefreshing(true);
    load(true);
  }, [load]);

  // The menu item list (add/edit/delete, availability toggle) is managed
  // from the Menu tab — reload here every time POS regains focus so a new
  // item shows up without the staff member having to restart the app,
  // which was the only way it used to show up before. The very first focus
  // coincides with mount, where we want the real (non-silent) load with its
  // spinner — skip that one here so it isn't fetched twice back to back.
  const mountedRef = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (!mountedRef.current) {
        mountedRef.current = true;
        load();
      } else {
        load(true);
      }
    }, [load])
  );

  // The floating pill tab bar is an absolutely-positioned overlay that sits
  // on top of whatever's at the bottom of the tab's content — including the
  // cart sheet's Pay/Hold/KOT buttons, which are otherwise identical to it.
  // Hide it for the duration of the cart sheet so it can't cover them.
  useEffect(() => {
    navigation.setOptions({ tabBarStyle: cartOpen ? { display: 'none' } : undefined });
    return () => navigation.setOptions({ tabBarStyle: undefined });
  }, [navigation, cartOpen]);

  // Filter by a normalized (trimmed, lower-cased) key rather than the raw
  // menu_name — stray whitespace or inconsistent casing from data entry
  // ("Delivery " vs "Delivery") would otherwise make that one menu's chip
  // render fine but never actually match any item, looking "broken" only
  // for that specific menu while every other one works.
  const menuOptions = useMemo(() => {
    const map = new Map(); // normalized key -> display label (first-seen casing)
    menuItems.forEach((it) => {
      const label = (it.menu_name || '').trim();
      if (!label) return;
      const key = label.toLowerCase();
      if (!map.has(key)) map.set(key, label);
    });
    return [{ label: 'All', value: 'All' }, ...Array.from(map, ([value, label]) => ({ label, value }))];
  }, [menuItems]);

  const visibleItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    return menuItems.filter((it) => {
      if (it.is_available != 1) return false;
      if (menuFilter !== 'All' && (it.menu_name || '').trim().toLowerCase() !== menuFilter) return false;
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
        return min === max ? money(min, currency) : `${money(min, currency)}–${money(max, currency).replace(currency, '')}`;
      }
    }
    return money(item.base_price, currency);
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

  // − on a tile takes one off that dish's most recent cart line.
  const decItem = (item) => {
    haptic.tap();
    setCart((prev) => {
      const lines = prev.filter((c) => c.id === item.id);
      if (!lines.length) return prev;
      const key = lines[lines.length - 1].key;
      return prev.map((c) => (c.key === key ? { ...c, quantity: c.quantity - 1 } : c)).filter((c) => c.quantity > 0);
    });
  };

  const changeQty = (key, delta) => {
    haptic.tap();
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

  const clearCart = async () => {
    if (cart.length === 0) return;
    const ok = await feedback.confirm({ title: 'Clear the cart?', message: `${itemCount} item${itemCount > 1 ? 's' : ''} will be removed.`, confirmText: 'Clear', danger: true });
    if (ok) resetCart();
  };

  // Shows what's about to be printed before it hits the printer — mirrors
  // the website's KOT/bill preview, instead of printing blind off a plain
  // "created" alert. Everything is snapshotted at call time (cart, table,
  // coupon, tax, totals) so a resetCart() right after — or an item added
  // while the preview is open — can never change what's on the receipt.
  // opts.afterClose chains a second preview (Pay flow: KOT first, then the
  // bill) once this one is dismissed or printed.
  const offerPrint = (...args) => setBillPreview(buildPreview(...args));
  function buildPreview(title, kotNumber, paymentMethod, type, opts = {}) {
    const cartSnapshot = [...cart];
    const tableIdSnapshot = tableId;
    const subtotalSnapshot = subtotal;
    const discountSnapshot = discount;
    const taxSnapshot = tax;
    const totalSnapshot = total;
    const couponSnapshot = appliedCoupon?.coupon_code;
    const tableName = tables.find((t) => String(t.id) === String(tableIdSnapshot))?.table_number;
    return {
      type,
      title,
      restaurantName: user?.restaurant_name || 'Receipt',
      restaurantAddress: user?.address,
      restaurantPhone: user?.phone,
      restaurantEmail: user?.email,
      restaurantWebsite: user?.username ? `www.restrogrow.com/${user.username}` : null,
      businessIdLabel: user?.business_id_label,
      businessIdNo: user?.show_business_id ? user?.business_id_no : null,
      orderNumber: opts.orderNumber || null,
      kotNumber,
      orderType: tableIdSnapshot ? 'Dine-in' : 'Takeaway',
      tableName,
      customerName: null,
      createdAt: new Date().toISOString(),
      items: cartSnapshot.map((c) => ({ name: c.name, quantity: c.quantity, price: c.price, variationName: c.variationName })),
      subtotal: round2(subtotalSnapshot),
      discount: discountSnapshot,
      couponCode: couponSnapshot,
      tax: taxSnapshot,
      taxPercent: gstEnabled ? taxPercent : null,
      total: totalSnapshot,
      paymentMethod,
      currency,
      afterClose: opts.afterClose,
    };
  }

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
      feedback.success(`Held as ${res.order_number}. Find it in Orders.`);
      resetCart();
    } catch (e) {
      feedback.errorFrom(e, 'Could not hold the order');
    } finally {
      setHolding(false);
    }
  };  // "Send KOT" — ticket goes to the kitchen with no payment collected yet.
  // The cart is deliberately KEPT (not cleared): the order isn't confirmed
  // until payment — "Pay" — so the counter staff can still collect it, or
  // add items and fire another KOT for the same table. Only Pay (or Clear
  // Cart) empties the cart.
  const sendKOT = async () => {
    if (cart.length === 0) return;
    setSendingKot(true);
    try {
      const res = await apiPostForm('/controllers/pos_operations.php', {
        action: 'create_kot',
        ...buildCartPayload(),
        paymentMethod: 'Cash',
        kot_only: 1, // kitchen ticket, not paid yet (the server ignores it; the app demo uses it)
      });
      if (!res.success) throw new Error(res.message || 'Could not send to kitchen');
      haptic.success();
      const pick = await feedback.choose({
        title: 'Sent to the kitchen',
        message: `Ticket ${res.kot_number}. The cart is kept, tap Pay when the guest pays.`,
        options: [{ label: 'Print KOT', value: 'kot', icon: 'print-outline' }],
        cancelText: 'Done',
      });
      if (pick === 'kot') offerPrint('KOT sent to kitchen', res.kot_number, null, 'kot');
    } catch (e) {
      feedback.errorFrom(e, 'Could not send to the kitchen');
    } finally {
      setSendingKot(false);
    }
  };

  // "Pay" — confirms the order: first a KOT ticket is fired to the kitchen,
  // then the paid bill (with the payment method on it) is offered for
  // printing. Only now does the server create the order (Ready + Paid,
  // counted in sales immediately) and the cart is cleared.
  const payNow = () => {
    if (cart.length === 0) return;
    setPayModalOpen(true);
  };

  const confirmPayment = async ({ paymentMethod, breakdown }) => {
    setPaying(true);
    try {
      const res = await apiPostForm('/controllers/pos_operations.php', {
        action: 'create_kot',
        ...buildCartPayload(breakdown ? `Payment split: ${breakdown}` : null),
        paymentMethod,
      });
      if (!res.success) throw new Error(res.message || 'Could not process payment');
      setPayModalOpen(false);
      // Snapshot BEFORE resetCart — offerPrint reads live discount/tax/total
      // state, which resetCart would zero out.
      const billSnapshot = { kotNumber: res.kot_number, paymentMethod, orderNumber: res.order_number || null };
      // Both previews snapshot the cart and totals, so build them before the cart is cleared.
      const totalPaid = total;
      const kotPreview = buildPreview('KOT', res.kot_number, null, 'kot', { orderNumber: billSnapshot.orderNumber });
      const billPreviewData = buildPreview('Payment collected', billSnapshot.kotNumber, billSnapshot.paymentMethod, 'bill', { orderNumber: billSnapshot.orderNumber });
      resetCart();
      haptic.success();
      const pick = await feedback.choose({
        title: `Paid ${money(totalPaid, currency)}`,
        message: res.order_number ? `${res.order_number} is in Orders and counted in today's sales.` : 'Sent to the kitchen.',
        options: [
          { label: 'Print KOT and bill', value: 'both', icon: 'print-outline' },
          { label: 'Print bill only', value: 'bill', icon: 'receipt-outline' },
        ],
        cancelText: 'Done',
      });
      if (pick === 'both') setBillPreview({ ...kotPreview, afterClose: () => setBillPreview(billPreviewData) });
      else if (pick === 'bill') setBillPreview(billPreviewData);
    } catch (e) {
      feedback.errorFrom(e, 'Could not take the payment');
    } finally {
      setPaying(false);
    }
  };

  const tableOptions = [
    { label: 'Walk-in', value: '' },
    ...tables.map((t) => ({ label: `${t.table_number} · seats ${t.capacity}`, value: String(t.id) })),
  ];

  // dish photos on the tiles only when the menu has some — a menu with none gets compact tiles
  const withPhotos = useMemo(() => menuItems.some((it) => !!it.item_image), [menuItems]);

  // 2 columns on phones (even small ones), more on tablets.
  const gridCols = columns(140, 6);
  const tileW = Math.floor((contentWidth - gutter * 2 - spacing.sm * (gridCols - 1)) / gridCols);

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Counter orders" title="POS">
        <View style={styles.headRow}>
          <SearchField ref={searchRef} style={{ flex: 1 }} value={search} onChangeText={setSearch} placeholder="Search dishes" />
          <View style={styles.tablePick}>
            <SelectField compact icon={tableId ? 'restaurant' : 'walk'} placeholder="Walk-in" title="Order for" value={tableId} onChange={setTableId} options={tableOptions} />
          </View>
        </View>
      </ScreenHeader>

      {loading ? (
        <LoadingState variant="grid" columns={gridCols} gutter={gutter} rows={4} />
      ) : error ? (
        <ErrorState message={error} onRetry={() => load()} />
      ) : (
        <>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.menuChipScroll} contentContainerStyle={[styles.menuChipRow, { paddingHorizontal: gutter }]}>
            <PressableScale feedback="select" style={[styles.vegChip, vegOnly && styles.vegChipOn]} onPress={() => setVegOnly((v) => !v)} accessibilityLabel="Veg only">
              <View style={[styles.vegDotOutline, vegOnly && { borderColor: '#fff' }]}>
                <View style={[styles.vegDotInner, vegOnly && { backgroundColor: '#fff' }]} />
              </View>
              <Text style={[styles.menuChipText, vegOnly && styles.menuChipTextActive]}>Veg</Text>
            </PressableScale>
            {menuOptions.map((opt) => {
              const active = menuFilter === opt.value;
              return (
                <PressableScale key={opt.value} feedback="select" style={[styles.menuChip, active && styles.menuChipActive]} onPress={() => setMenuFilter(opt.value)}>
                  <Text style={[styles.menuChipText, active && styles.menuChipTextActive]} numberOfLines={1}>
                    {opt.label}
                  </Text>
                </PressableScale>
              );
            })}
          </ScrollView>

          <FlatList
            key={gridCols}
            ref={listRef}
            style={styles.fill}
            data={visibleItems}
            keyExtractor={(item, i) => String(item.id ?? i)}
            numColumns={gridCols}
            columnWrapperStyle={gridCols > 1 ? { gap: spacing.sm } : undefined}
            contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.xs, paddingBottom: tabBarHeight + (itemCount > 0 ? 96 : 24), gap: spacing.sm, flexGrow: 1 }}
            showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            initialNumToRender={16}
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
            ListEmptyComponent={
              <EmptyState
                icon="search-outline"
                title={menuItems.length ? 'No dishes match' : 'No dishes yet'}
                subtitle={menuItems.length ? 'Try another word, menu or switch off Veg.' : 'Add dishes in the Menu tab and they appear here.'}
              />
            }
            renderItem={({ item }) => {
              const qtyInCart = cart.filter((c) => c.id === item.id).reduce((s, c) => s + c.quantity, 0);
              const thumb = imageUrl(item.item_image);
              const veg = VEG[item.item_type];
              return (
                <PressableScale
                  style={[styles.tile, shadow.sm, { width: tileW }, qtyInCart > 0 && styles.tileOn]}
                  onPress={() => onPressItem(item)}
                  scale={0.96}
                  feedback="none"
                  accessibilityLabel={`${item.item_name_en}, ${priceLabelFor(item)}${qtyInCart ? `, ${qtyInCart} in cart` : ''}`}
                >
                  {withPhotos ? (
                    <View style={[styles.tilePhoto, { height: Math.round(tileW * 0.5) }]}>
                      {thumb ? (
                        <AppImage uri={thumb} style={StyleSheet.absoluteFill} />
                      ) : (
                        <Text style={styles.tileInitial}>{String(item.item_name_en || '?').trim().charAt(0).toUpperCase()}</Text>
                      )}
                      {veg ? (
                        <View style={[styles.vegMark, styles.vegMarkOnPhoto, { borderColor: veg }]}>
                          <View style={[styles.vegMarkDot, { backgroundColor: veg }]} />
                        </View>
                      ) : null}
                    </View>
                  ) : null}
                  <View style={styles.tileBody}>
                    <View style={styles.tileNameRow}>
                      {veg && !withPhotos ? (
                        <View style={[styles.vegMark, { borderColor: veg }]}>
                          <View style={[styles.vegMarkDot, { backgroundColor: veg }]} />
                        </View>
                      ) : null}
                      <Text style={styles.itemName} numberOfLines={2}>{item.item_name_en}</Text>
                    </View>
                    <View style={styles.tileBottom}>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.itemPriceText} numberOfLines={1}>{priceLabelFor(item)}</Text>
                        {item.has_variations == 1 ? <Text style={styles.itemVariationHint}>Choose size</Text> : null}
                      </View>
                      {qtyInCart > 0 ? (
                        <View style={styles.tileStepper}>
                          <PressableScale style={styles.tileStepBtn} onPress={() => decItem(item)} hitSlop={6} feedback="none" accessibilityLabel={`One less ${item.item_name_en}`}>
                            <Ionicons name="remove" size={15} color="#fff" />
                          </PressableScale>
                          <Text style={styles.tileQty}>{qtyInCart}</Text>
                          <PressableScale style={styles.tileStepBtn} onPress={() => onPressItem(item)} hitSlop={6} feedback="none" accessibilityLabel={`One more ${item.item_name_en}`}>
                            <Ionicons name="add" size={15} color="#fff" />
                          </PressableScale>
                        </View>
                      ) : (
                        <View style={styles.tileAdd}>
                          <Text style={styles.tileAddText}>ADD</Text>
                        </View>
                      )}
                    </View>
                  </View>
                </PressableScale>
              );
            }}
          />

          {itemCount > 0 ? (
            <View style={[styles.viewCartWrap, { bottom: tabBarHeight + spacing.sm, paddingHorizontal: gutter }]} pointerEvents="box-none">
              <PressableScale style={[styles.viewCartBar, shadow.md]} onPress={() => setCartOpen(true)} feedback="press" scale={0.98}>
                <View style={styles.viewCartBadge}>
                  <Text style={styles.viewCartBadgeText}>{itemCount}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.viewCartText}>View cart</Text>
                  <Text style={styles.viewCartSub} numberOfLines={1}>
                    {tableId ? `Table ${tables.find((t) => String(t.id) === String(tableId))?.table_number || ''}` : 'Walk-in / takeaway'}
                  </Text>
                </View>
                <Text style={styles.viewCartTotal}>{money(total, currency)}</Text>
                <Ionicons name="chevron-forward" size={18} color="#fff" />
              </PressableScale>
            </View>
          ) : null}
        </>
      )}

      {/* size picker */}
      <Sheet visible={!!variationItem} onClose={() => setVariationItem(null)} title={variationItem?.item_name_en} subtitle="Choose a size">
        <View style={{ gap: 8 }}>
          {(variationItem?.variations || []).map((v) => (
            <PressableScale
              key={v.id}
              style={styles.variationOption}
              onPress={() => {
                addToCart(variationItem, v);
                setVariationItem(null);
              }}
            >
              <Text style={styles.variationOptionText}>{v.variation_name}</Text>
              <Text style={styles.variationOptionPrice}>{money(v.price, currency)}</Text>
            </PressableScale>
          ))}
        </View>
      </Sheet>

      {/* Cart / checkout sheet */}
      <Modal
        visible={cartOpen}
        animationType="slide"
        transparent={Platform.OS === 'web'}
        statusBarTranslucent
        navigationBarTranslucent
        onRequestClose={() => setCartOpen(false)}
      >
        <View style={styles.cartModalBackdrop}>
        <KeyboardAvoidingView
          style={[styles.fill, styles.cartModalPanel, { paddingTop: insets.top + spacing.sm }]}
          behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        >
          <View style={styles.cartHeader}>
            <PressableScale style={styles.iconButton} onPress={() => setCartOpen(false)} hitSlop={8} accessibilityLabel="Close cart">
              <Ionicons name="chevron-down" size={22} color={colors.ink} />
            </PressableScale>
            <View style={{ alignItems: 'center' }}>
              <Text style={styles.cartHeaderTitle}>Current order</Text>
              <Text style={styles.cartHeaderSub}>{itemCount} item{itemCount === 1 ? '' : 's'} · {money(total, currency)}</Text>
            </View>
            <PressableScale style={styles.iconButton} onPress={clearCart} hitSlop={8} accessibilityLabel="Clear cart" feedback="warning">
              <Ionicons name="trash-outline" size={18} color={colors.danger} />
            </PressableScale>
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

          <View style={[styles.cartFooter, styles.cartFooterPinned, { paddingBottom: Math.max(insets.bottom, spacing.md) + spacing.xs }]}>
            <PressableScale style={styles.holdButton} onPress={holdOrder} disabled={holding || sendingKot || paying || cart.length === 0} accessibilityLabel="Hold order">
              {holding ? (
                <ActivityIndicator color={colors.inkSoft} size="small" />
              ) : (
                <>
                  <Ionicons name="pause" size={16} color={colors.inkSoft} />
                  <Text style={styles.holdButtonText}>Hold</Text>
                </>
              )}
            </PressableScale>
            <PressableScale style={styles.kotButton} onPress={sendKOT} disabled={holding || sendingKot || paying || cart.length === 0} feedback="press" accessibilityLabel="Send to kitchen">
              {sendingKot ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <>
                  <Ionicons name="flame" size={16} color="#fff" />
                  <Text style={styles.kotButtonText}>KOT</Text>
                </>
              )}
            </PressableScale>
            <PressableScale style={styles.payButton} onPress={payNow} disabled={holding || sendingKot || paying || cart.length === 0} feedback="press" accessibilityLabel={`Pay ${money(total, currency)}`}>
              <Ionicons name="card" size={17} color="#fff" />
              <Text style={styles.payButtonText} numberOfLines={1}>Pay {money(total, currency)}</Text>
            </PressableScale>
          </View>
        </KeyboardAvoidingView>
        <FeedbackHost />
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
        onClose={() => {
          const chain = billPreview?.afterClose;
          setBillPreview(null);
          if (typeof chain === 'function') chain();
        }}
      />
    </View>
  );
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
  // Same look as the Reports screen's Today/7 Days/30 Days period chips,
  // but sized to their own content in a horizontally scrolling row instead
  // of flex:1 — menu names aren't a fixed short set like "Today"/"7 Days",
  // so equal-width chips either stretched huge for a long name or squeezed
  // everything else. A max width + single-line ellipsis keeps even a very
  // long name from blowing out the row.
  menuChipScroll: {
    flexGrow: 0,
    height: 38,
    marginBottom: spacing.md,
  },
  menuChipRow: {
    alignItems: 'center',
    paddingHorizontal: spacing.xl,
    gap: spacing.sm,
  },
  menuChip: {
    alignItems: 'center',
    justifyContent: 'center',
    height: 38,
    maxWidth: 160,
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.lg,
  },
  menuChipActive: {
    backgroundColor: colors.primary,
  },
  menuChipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  menuChipTextActive: {
    color: '#fff',
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
  headRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.md,
  },
  tablePick: {
    width: 124,
  },
  vegChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 36,
    paddingHorizontal: 12,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  vegChipOn: {
    backgroundColor: colors.success,
    borderColor: colors.success,
  },
  tile: {
    backgroundColor: colors.surface,
    borderRadius: 14,
    overflow: 'hidden',
    borderWidth: 1.5,
    borderColor: 'transparent',
  },
  tileOn: {
    borderColor: colors.primary,
    backgroundColor: '#FFF8F5',
  },
  tilePhoto: {
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileInitial: {
    fontFamily: font.bold,
    fontSize: 26,
    color: colors.primary,
    opacity: 0.55,
  },
  vegMarkOnPhoto: {
    position: 'absolute',
    top: 7,
    left: 7,
    marginTop: 0,
    backgroundColor: '#fff',
  },
  tileBody: {
    flex: 1,
    padding: 10,
    justifyContent: 'space-between',
    minHeight: 84,
  },
  tileNameRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 5,
  },
  vegMark: {
    width: 12,
    height: 12,
    borderWidth: 1.5,
    borderRadius: 3,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 3,
  },
  vegMarkDot: {
    width: 5,
    height: 5,
    borderRadius: 3,
  },
  tileBottom: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    marginTop: 6,
    gap: 6,
  },
  tileAdd: {
    height: 30,
    paddingHorizontal: 12,
    borderRadius: 9,
    borderWidth: 1.2,
    borderColor: colors.primary,
    backgroundColor: '#FFF6F3',
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileAddText: {
    fontFamily: font.bold,
    fontSize: 11.5,
    letterSpacing: 0.6,
    color: colors.primary,
  },
  tileStepper: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.primary,
    borderRadius: 10,
  },
  tileStepBtn: {
    width: 28,
    height: 30,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileQty: {
    minWidth: 16,
    textAlign: 'center',
    fontFamily: font.bold,
    fontSize: 13.5,
    color: '#fff',
  },
  viewCartWrap: {
    position: 'absolute',
    left: 0,
    right: 0,
    zIndex: 20,
    elevation: 20,
  },
  viewCartSub: {
    fontFamily: font.medium,
    fontSize: 11,
    color: 'rgba(255,255,255,0.85)',
  },
  cartHeaderSub: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
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
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 13,
    lineHeight: 17,
    minHeight: 34,
    color: colors.ink,
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
    fontFamily: font.bold,
    fontSize: 13,
    color: colors.ink,
  },
  itemVariationHint: {
    fontFamily: font.regular,
    fontSize: 10.5,
    color: colors.muted,
    marginTop: 3,
  },
  viewCartBar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.primary,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.lg,
    paddingVertical: 10,
    minHeight: 58,
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
    minHeight: 54,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    backgroundColor: colors.bg,
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
  // Pay is the big main action; KOT and Hold sit beside it, smaller.
  holdButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    height: 52,
    paddingHorizontal: 14,
    backgroundColor: colors.bg,
    borderRadius: radius.md,
  },
  holdButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.inkSoft,
  },
  kotButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    height: 52,
    paddingHorizontal: 16,
    backgroundColor: colors.ink,
    borderRadius: radius.md,
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
    height: 52,
    paddingHorizontal: 10,
    backgroundColor: colors.success,
    borderRadius: radius.md,
  },
  payButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: '#fff',
  },
});
