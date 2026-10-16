import AsyncStorage from '@react-native-async-storage/async-storage';
import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, useRef, useState } from 'react';

import { BRAND } from '../config/brand';
import { itemById, SAMPLE_ADDRESS } from '../data/restaurant';
import { computeBill, couponStatus, findCoupon } from './bill';

const STORAGE_KEY = 'customer_app_state_v1';

const INITIAL = {
  onboarded: false,
  user: null, // { name, phone, guest }
  cart: [],
  orderType: 'Delivery',
  coupon: null,
  favorites: [],
  orders: [],
  addresses: [],
  addressId: null,
  tableNo: null,
  pickup: 'ASAP',
  points: 120,
  reservations: [],
  notifications: true,
};

const lineKey = (itemId, size) => `${itemId}|${size || ''}`;

function reducer(state, action) {
  switch (action.type) {
    case 'HYDRATE':
      return { ...INITIAL, ...action.value };
    case 'SET':
      return { ...state, ...action.value };
    case 'ADD': {
      const { item, size, qty, note } = action;
      const sizeObj = size ? item.sizes.find((s) => s.name === size) : null;
      const price = sizeObj ? sizeObj.price : item.price;
      const key = lineKey(item.id, size);
      const existing = state.cart.find((l) => l.key === key);
      if (existing) {
        return {
          ...state,
          cart: state.cart.map((l) => (l.key === key ? { ...l, qty: l.qty + qty, note: note || l.note } : l)),
        };
      }
      const line = { key, itemId: item.id, name: item.name, kind: item.kind, size: size || null, price, qty, note: note || '' };
      return { ...state, cart: [...state.cart, line] };
    }
    case 'QTY': {
      const cart = state.cart
        .map((l) => (l.key === action.key ? { ...l, qty: l.qty + action.delta } : l))
        .filter((l) => l.qty > 0);
      return { ...state, cart, coupon: cart.length ? state.coupon : null };
    }
    case 'CLEAR_CART':
      return { ...state, cart: [], coupon: null };
    case 'FAV':
      return {
        ...state,
        favorites: state.favorites.includes(action.id)
          ? state.favorites.filter((x) => x !== action.id)
          : [...state.favorites, action.id],
      };
    case 'PLACE_ORDER':
      return {
        ...state,
        orders: [action.order, ...state.orders],
        cart: [],
        coupon: null,
        points: state.points + action.earned,
      };
    case 'RATE_ORDER':
      return { ...state, orders: state.orders.map((o) => (o.id === action.id ? { ...o, rating: action.rating } : o)) };
    case 'ADD_ADDRESS':
      return { ...state, addresses: [...state.addresses, action.address], addressId: action.address.id };
    case 'DELETE_ADDRESS': {
      const addresses = state.addresses.filter((a) => a.id !== action.id);
      return { ...state, addresses, addressId: state.addressId === action.id ? addresses[0]?.id || null : state.addressId };
    }
    case 'ADD_RESERVATION':
      return { ...state, reservations: [action.reservation, ...state.reservations] };
    case 'RESET':
      return { ...INITIAL, onboarded: true };
    default:
      return state;
  }
}

const AppContext = createContext(null);

export function AppProvider({ children }) {
  const [state, dispatch] = useReducer(reducer, INITIAL);
  const [ready, setReady] = useState(false);
  const [toast, setToast] = useState(null);
  const toastTimer = useRef(null);

  useEffect(() => {
    let alive = true;
    AsyncStorage.getItem(STORAGE_KEY)
      .then((raw) => {
        if (alive && raw) dispatch({ type: 'HYDRATE', value: JSON.parse(raw) });
      })
      .catch(() => {})
      .finally(() => alive && setReady(true));
    return () => {
      alive = false;
    };
  }, []);

  useEffect(() => {
    if (!ready) return;
    AsyncStorage.setItem(STORAGE_KEY, JSON.stringify(state)).catch(() => {});
  }, [state, ready]);

  const showToast = useCallback((message, tone = 'dark') => {
    if (toastTimer.current) clearTimeout(toastTimer.current);
    setToast({ message, tone, id: Date.now() });
    toastTimer.current = setTimeout(() => setToast(null), 2200);
  }, []);

  const bill = useMemo(() => computeBill(state.cart, state.orderType, state.coupon), [state.cart, state.orderType, state.coupon]);
  const cartCount = useMemo(() => state.cart.reduce((n, l) => n + l.qty, 0), [state.cart]);

  const actions = useMemo(
    () => ({
      set: (value) => dispatch({ type: 'SET', value }),
      addToCart: (item, { size = null, qty = 1, note = '' } = {}) => dispatch({ type: 'ADD', item, size, qty, note }),
      changeQty: (key, delta) => dispatch({ type: 'QTY', key, delta }),
      clearCart: () => dispatch({ type: 'CLEAR_CART' }),
      toggleFavorite: (id) => dispatch({ type: 'FAV', id }),
      rateOrder: (id, rating) => dispatch({ type: 'RATE_ORDER', id, rating }),
      addAddress: (address) => dispatch({ type: 'ADD_ADDRESS', address }),
      deleteAddress: (id) => dispatch({ type: 'DELETE_ADDRESS', id }),
      addReservation: (reservation) => dispatch({ type: 'ADD_RESERVATION', reservation }),
      reset: () => dispatch({ type: 'RESET' }),
      login: (user) => {
        const addresses = state.addresses.length ? state.addresses : user.guest ? [] : [SAMPLE_ADDRESS];
        dispatch({
          type: 'SET',
          value: { user, onboarded: true, addresses, addressId: state.addressId || addresses[0]?.id || null },
        });
      },
      applyCoupon: (code) => {
        const coupon = findCoupon(code);
        if (!coupon) return { ok: false, message: 'That code is not valid' };
        const subtotal = state.cart.reduce((s, l) => s + l.price * l.qty, 0);
        const status = couponStatus(coupon, subtotal);
        dispatch({ type: 'SET', value: { coupon: coupon.code } });
        return status.ok ? { ok: true, message: `${coupon.code} applied` } : { ok: true, soft: true, message: status.reason };
      },
      removeCoupon: () => dispatch({ type: 'SET', value: { coupon: null } }),
      placeOrder: (details) => {
        const now = Date.now();
        const number = 'ORD-' + String(now).slice(-6);
        const order = {
          id: String(now),
          number,
          createdAt: now,
          type: state.orderType,
          items: state.cart,
          bill,
          couponCode: bill.couponOk ? bill.coupon.code : null,
          address: state.orderType === 'Delivery' ? state.addresses.find((a) => a.id === state.addressId) || null : null,
          table: state.orderType === 'Dine-in' ? state.tableNo : null,
          pickup: state.orderType === 'Takeaway' ? state.pickup : null,
          rating: 0,
          ...details,
        };
        const earned = Math.round(bill.total * BRAND.pointsPerRupee);
        dispatch({ type: 'PLACE_ORDER', order, earned });
        return order;
      },
      reorder: (order) => {
        dispatch({ type: 'CLEAR_CART' });
        let added = 0;
        order.items.forEach((l) => {
          const item = itemById(l.itemId);
          if (!item) return;
          dispatch({ type: 'ADD', item, size: l.size, qty: l.qty, note: l.note });
          added += l.qty;
        });
        return added;
      },
    }),
    [state.addresses, state.addressId, state.cart, state.orderType, state.tableNo, state.pickup, bill]
  );

  const value = useMemo(
    () => ({ ...state, ready, bill, cartCount, actions, toast, showToast }),
    [state, ready, bill, cartCount, actions, toast, showToast]
  );

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp() {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error('useApp must be used inside AppProvider');
  return ctx;
}

// re-renders every `ms` (live order tracking)
export function useNow(ms = 1000) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), ms);
    return () => clearInterval(t);
  }, [ms]);
  return now;
}
