import { BRAND } from '../config/brand';
import { COUPONS } from '../data/restaurant';

export function findCoupon(code) {
  const c = String(code || '').trim().toUpperCase();
  return COUPONS.find((x) => x.code === c) || null;
}

export function couponStatus(coupon, subtotal, now = new Date()) {
  if (!coupon) return { ok: false, reason: '' };
  if (coupon.weekend && now.getDay() !== 0 && now.getDay() !== 6) {
    return { ok: false, reason: `${coupon.code} works on Saturday and Sunday` };
  }
  if (subtotal < coupon.min) {
    return { ok: false, reason: `Add ${BRAND.currency}${Math.ceil(coupon.min - subtotal)} more to use ${coupon.code}` };
  }
  return { ok: true, reason: '' };
}

export function computeBill(cart, orderType, couponCode) {
  const subtotal = cart.reduce((s, l) => s + l.price * l.qty, 0);
  const coupon = findCoupon(couponCode);
  const status = couponStatus(coupon, subtotal);
  let discount = 0;
  if (coupon && status.ok) {
    discount = coupon.type === 'flat' ? coupon.value : Math.min(coupon.max || Infinity, (subtotal * coupon.value) / 100);
    discount = Math.min(discount, subtotal);
  }
  const afterDiscount = subtotal - discount;
  const delivery = orderType === 'Delivery' && subtotal > 0 && afterDiscount < BRAND.freeDeliveryAbove ? BRAND.deliveryFee : 0;
  const packaging = orderType !== 'Dine-in' && subtotal > 0 ? BRAND.packagingFee : 0;
  const tax = Math.round(afterDiscount * BRAND.taxPercent) / 100;
  const total = Math.round((afterDiscount + delivery + packaging + tax) * 100) / 100;
  return {
    subtotal,
    discount: Math.round(discount * 100) / 100,
    delivery,
    packaging,
    tax,
    total,
    coupon,
    couponOk: !!coupon && status.ok,
    couponReason: status.reason,
    freeDeliveryLeft: orderType === 'Delivery' && subtotal > 0 && afterDiscount < BRAND.freeDeliveryAbove ? BRAND.freeDeliveryAbove - afterDiscount : 0,
  };
}
