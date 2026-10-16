// The printed bill / kitchen ticket, drawn as real views so it looks like the
// website POS bill (main/assets/js/script.js buildBillPreviewHtml) and KOT
// (printKOT). Printing captures this view as a picture (react-native-view-shot)
// and the server turns it into an ESC/POS raster, so any script (Hindi etc.)
// and any styling prints as seen.
//
// The printer is 1-bit: api/convert_receipt_image.php prints every pixel with
// luminance under 200 as black and everything else as white. So:
//  - text colours (green, red, grey, amber) all come out solid black;
//  - the light tints behind the info box / KOT banner come out white;
//  - divider lines use #9ca3af, not the website's #d1d5db, which would vanish.
//
// ── data contract (what BillPreviewModal / <Receipt data> accept) ──
// Every field is optional unless noted. Restaurant fields fall back to the
// logged-in restaurant (useAuth().user) when not passed.
//   type             'bill' (default) | 'kot'
//   title            modal heading, e.g. 'Bill Preview' (not printed)
//   restaurantName, restaurantAddress, restaurantPhone
//   businessIdLabel, businessIdNo   printed only when businessIdNo is set
//                                   (callers pass null when show_business_id is off)
//   restaurantLogoUrl              override for the logo (default: restaurant logo)
//   paymentQrUrl                   override for "Scan to Pay" (default: the
//                                   restaurant's payment QR when it has one; null hides it)
//   billNo           counting bill number; header shows BILL #billNo || orderNumber || kotNumber
//   orderNumber, kotNumber
//   createdAt        ISO / 'YYYY-MM-DD HH:MM:SS' (default: now)
//   tableLabel       'T4 · Ground Floor' / 'Takeaway' (falls back to tableName, then orderType)
//   tableName, orderType            legacy table fields
//   status           'Paid' | 'Partially Paid' | 'Unpaid' (bill); derived when absent
//   kotStatus        KOT only, e.g. 'Pending'
//   customer         { name, phone } | null (falls back to customerName unless it
//                    is a placeholder like 'Walk-in' / 'Table Customer')
//   customerName     legacy
//   items            [{ name, quantity, price, variationName,
//                       addons: [{ name, price }]   charged once per unit,
//                       lineTotal                   incl. add-ons (default (price + add-ons) × qty),
//                       notes                       KOT: cooking note }]
//   subtotal, tax, total
//   discounts        [[label, amount], …]  e.g. ['Discount 10%', 25], ['Reward ABC', 50],
//                    ['120 points', 12]. When present, replaces discount/couponCode.
//   discount, couponCode            legacy single discount
//   taxName          'GST' (default: restaurant's tax name, else 'Tax')
//   taxPercent       5 → 'GST (5%)'
//   charges          [[label, amount], …] added after tax, e.g. ['Delivery charge', 40]
//   walletPaid       amount paid from the customer's wallet
//   payments         [[methodLabel, amount], …] split payments; when absent one
//                    row for paymentMethod with the paid amount
//   paymentMethod    legacy, e.g. 'Cash' or 'Cash+UPI'
//   returnAmount     change given back (green "Return")
//   dueAmount        still to pay (amber "Due")
//   loyaltyLine      'You earned 12 points · Balance 240 points'
//   currency         default '₹'
//   afterClose       callback run by BillPreviewModal's caller after closing (not printed)
import { useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { BASE_URL, restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { realName } from '../utils/format';

// Website bill accent (green) and KOT accent (red).
const BILL_ACCENT = '#059669';
const KOT_ACCENT = '#dc2626';
const INK = '#111827';
const MUTED = '#6b7280';
const AMBER = '#b45309';
// Dark enough to survive the printer's threshold (see top of file).
const RULE = '#9ca3af';
const TINT = '#f9fafb';

// Receipt width in dp. Close to the website's printable width (54mm / 76mm
// at 96dpi), so the same font sizes come out the same physical size once the
// server scales the capture to 384 / 576 printer dots.
export function receiptWidth(paperWidth) {
  return String(paperWidth) === '80' ? 340 : 232;
}

// Shows a slip narrower than `contentWidth` when the space is too tight
// (an 80mm slip is 340dp, wider than a small phone's sheet). Only the
// on-screen copy shrinks: the transform sits on this wrapper, never on the
// captured view inside it, so the printed picture keeps its proportions.
export function FitWidth({ available, contentWidth, children }) {
  const [height, setHeight] = useState(0);
  const scale = available > 0 && available < contentWidth ? available / contentWidth : 1;
  const inner = <View onLayout={(e) => setHeight(e.nativeEvent.layout.height)}>{children}</View>;
  if (scale === 1) return inner;
  return (
    <View style={{ width: contentWidth * scale, height: height * scale }}>
      <View
        style={{
          width: contentWidth,
          transform: [
            { translateX: (-contentWidth * (1 - scale)) / 2 },
            { translateY: (-height * (1 - scale)) / 2 },
            { scale },
          ],
        }}
      >
        {inner}
      </View>
    </View>
  );
}

const num = (v) => Number(v) || 0;

function fmtDateTime(value) {
  const d = value ? new Date(String(value).replace(' ', 'T')) : new Date();
  if (Number.isNaN(d.getTime())) return String(value || '');
  const p = (n) => String(n).padStart(2, '0');
  let h = d.getHours();
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} | ${p(h)}:${p(d.getMinutes())} ${ampm}`;
}

function addonsOf(it) {
  return (Array.isArray(it.addons) ? it.addons : [])
    .map((a) => ({ name: a.name || a.addon_name || '', price: num(a.price ?? a.addon_price) }))
    .filter((a) => a.name);
}

function lineTotalOf(it) {
  if (it.lineTotal != null && it.lineTotal !== '') return num(it.lineTotal);
  const addonSum = addonsOf(it).reduce((s, a) => s + a.price, 0);
  return (num(it.price) + addonSum) * num(it.quantity);
}

// Android ignores borderStyle 'dashed' on a single side (it draws solid or
// nothing), so dashed rules are drawn as a row of short segments instead.
function Dashes({ color = RULE, thickness = 1, style }) {
  return (
    <View style={[styles.dashes, { height: thickness }, style]}>
      {Array.from({ length: 70 }).map((_, i) => (
        <View key={i} style={{ width: 4, height: thickness, marginRight: 3, backgroundColor: color }} />
      ))}
    </View>
  );
}

// The website header's "3px double" bottom border.
function DoubleRule({ color }) {
  return (
    <View style={styles.doubleRule}>
      <View style={{ height: 1, backgroundColor: color }} />
      <View style={{ height: 1, backgroundColor: color, marginTop: 1.5 }} />
    </View>
  );
}

// Remote images (logo, payment QR) hide themselves if they fail to load,
// like the website's onerror="this.style.display='none'".
function RemoteImage({ uri, style }) {
  const [failed, setFailed] = useState(false);
  if (!uri || failed) return null;
  return <Image source={{ uri }} style={style} resizeMode="contain" onError={() => setFailed(true)} />;
}

function Header({ info, accent, showBusinessId = true }) {
  return (
    <View style={styles.header}>
      <RemoteImage uri={info.logoUri} style={styles.logo} />
      <Text style={[styles.restaurantName, { color: accent }]}>{String(info.name || 'Restaurant').toUpperCase()}</Text>
      {info.address ? <Text style={styles.details}>{info.address}</Text> : null}
      {info.phone ? <Text style={styles.details}>Ph: {info.phone}</Text> : null}
      {showBusinessId && info.businessIdNo ? <Text style={styles.details}>{info.businessIdLabel || 'PAN'}: {info.businessIdNo}</Text> : null}
    </View>
  );
}

function InfoBox({ accent, rows }) {
  return (
    <View style={[styles.infoBox, { borderLeftColor: accent }]}>
      {rows.filter(Boolean).map(([label, value]) => (
        <View key={label} style={styles.infoRow}>
          <Text style={styles.infoLabel}>{label}</Text>
          <Text style={styles.infoValue}>{value}</Text>
        </View>
      ))}
    </View>
  );
}

function Footer() {
  return (
    <View style={styles.footer}>
      <Dashes thickness={2} />
      <Text style={styles.footerText}>Thank you!</Text>
      <View style={styles.brandRow}>
        <Image source={require('../assets/restrogrow-logo.png')} style={styles.brandLogo} />
        <Text style={styles.brandText}>Powered by RestroGrow</Text>
      </View>
    </View>
  );
}

function Row({ label, value, style, textStyle }) {
  return (
    <View style={[styles.row, style]}>
      <Text style={[styles.rowLabel, textStyle]}>{label}</Text>
      <Text style={[styles.rowValue, textStyle]}>{value}</Text>
    </View>
  );
}

function Bill({ data, info }) {
  const cur = data.currency || '₹';
  const m = (v) => `${cur}${num(v).toFixed(2)}`;
  const items = data.items || [];
  const total = num(data.total);
  const walletPaid = num(data.walletPaid);
  const dueAmount = num(data.dueAmount);
  const returnAmount = num(data.returnAmount);

  const discounts = Array.isArray(data.discounts)
    ? data.discounts.filter((d) => d && num(d[1]) > 0)
    : num(data.discount) > 0
      ? [[data.couponCode ? `Coupon ${data.couponCode}` : 'Discount', num(data.discount)]]
      : [];

  const payments = Array.isArray(data.payments)
    ? data.payments.filter((p) => p && num(p[1]) > 0)
    : data.paymentMethod && data.paymentMethod !== 'Due' && data.status !== 'Unpaid'
      ? [[data.paymentMethod, Math.max(0, total - walletPaid - dueAmount + returnAmount)]].filter((p) => p[1] > 0)
      : [];

  const status = data.status || (dueAmount > 0 ? 'Partially Paid' : data.paymentMethod ? 'Paid' : null);
  const customer = data.customer
    || (realName(data.customerName) ? { name: realName(data.customerName), phone: data.customerPhone || '' } : null);
  const table = data.tableLabel || data.tableName || data.orderType || 'Takeaway';
  const billNo = data.billNo || data.orderNumber || data.kotNumber;
  const taxName = data.taxName || info.taxName || 'Tax';
  const taxPct = data.taxPercent != null && data.taxPercent !== '' ? ` (${Number(data.taxPercent)}%)` : '';

  return (
    <>
      <Header info={info} accent={BILL_ACCENT} />
      <View style={styles.center}>
        {billNo ? <Text style={[styles.billTag, { color: BILL_ACCENT }]}>BILL #{billNo}</Text> : null}
        <Text style={styles.dateText}>{fmtDateTime(data.createdAt)}</Text>
      </View>
      <DoubleRule color={BILL_ACCENT} />

      <InfoBox
        accent={BILL_ACCENT}
        rows={[
          ['TABLE', table],
          status ? ['STATUS', status] : null,
          customer ? ['CUSTOMER', [customer.name, customer.phone].filter(Boolean).join('\n')] : null,
        ]}
      />

      <View style={styles.tableHead}>
        <Text style={[styles.th, styles.colItem]}>Item</Text>
        <Text style={[styles.th, styles.colQty]}>Qty</Text>
        <Text style={[styles.th, styles.colAmt]}>Amt</Text>
      </View>
      {items.map((it, i) => {
        const addons = addonsOf(it);
        return (
          <View key={i}>
            <View style={styles.itemRow}>
              <View style={styles.colItem}>
                <Text style={styles.itemName}>
                  {it.name}
                  {it.variationName ? <Text style={styles.variation}> ({it.variationName})</Text> : null}
                </Text>
                {addons.length ? (
                  <Text style={styles.addonLine}>+ {addons.map((a) => `${a.name} ${m(a.price)}`).join(', ')}</Text>
                ) : null}
              </View>
              <Text style={[styles.cell, styles.colQty]}>{num(it.quantity)}</Text>
              <Text style={[styles.cell, styles.colAmt]}>{m(lineTotalOf(it))}</Text>
            </View>
            <Dashes />
          </View>
        );
      })}

      <View style={styles.totals}>
        <Row label="Subtotal" value={m(data.subtotal)} />
        {discounts.map(([label, amount], i) => (
          <Row key={i} label={label} value={`-${m(amount)}`} />
        ))}
        {num(data.tax) > 0 ? <Row label={`${taxName}${taxPct}`} value={m(data.tax)} /> : null}
        {(Array.isArray(data.charges) ? data.charges : []).filter((c) => c && num(c[1]) > 0).map(([label, amount], i) => (
          <Row key={`c${i}`} label={label} value={m(amount)} />
        ))}
        <Dashes thickness={2} style={styles.grandRule} />
        <Row label="Total" value={m(total)} textStyle={styles.grandText} />
        {walletPaid > 0 ? <Row label="Paid from wallet" value={`-${m(walletPaid)}`} /> : null}
      </View>

      {payments.length || returnAmount > 0 || dueAmount > 0 ? (
        <View style={styles.payBox}>
          <Dashes thickness={2} style={styles.payRule} />
          {payments.map(([label, amount], i) => (
            <Row key={i} label={label} value={m(amount)} textStyle={styles.payText} />
          ))}
          {returnAmount > 0 ? (
            <Row label="Return" value={m(returnAmount)} textStyle={[styles.payText, styles.strong, { color: BILL_ACCENT }]} />
          ) : null}
          {dueAmount > 0 ? (
            <Row label="Due" value={m(dueAmount)} textStyle={[styles.payText, styles.strong, { color: AMBER }]} />
          ) : null}
        </View>
      ) : null}

      {data.loyaltyLine ? (
        <View style={[styles.loyaltyBox, { borderColor: BILL_ACCENT }]}>
          <Text style={styles.loyaltyText}>{data.loyaltyLine}</Text>
        </View>
      ) : null}

      {info.qrUri ? (
        <View style={styles.qrBlock}>
          <Dashes />
          <Text style={styles.qrLabel}>SCAN TO PAY</Text>
          <RemoteImage uri={info.qrUri} style={styles.qr} />
        </View>
      ) : null}

      <Footer />
    </>
  );
}

function Kot({ data, info }) {
  const items = data.items || [];
  const table = data.tableLabel || data.tableName || data.orderType || 'Takeaway';
  const orderNo = data.orderNumber || data.billNo;
  return (
    <>
      {/* the website KOT leaves the tax ID off: it's for the kitchen */}
      <Header info={info} accent={KOT_ACCENT} showBusinessId={false} />
      <DoubleRule color={KOT_ACCENT} />

      <View style={[styles.kotBanner, { borderColor: KOT_ACCENT }]}>
        <Text style={[styles.kotTitle, { color: KOT_ACCENT }]}>KOT</Text>
        {data.kotNumber ? <Text style={styles.kotNumber}>#{data.kotNumber}</Text> : null}
        <Dashes style={styles.kotBannerRule} />
        <Text style={styles.dateText}>{fmtDateTime(data.createdAt)}</Text>
      </View>

      <InfoBox
        accent={KOT_ACCENT}
        rows={[
          ['TABLE', table],
          data.kotStatus ? ['STATUS', data.kotStatus] : null,
          orderNo ? ['ORDER #', String(orderNo)] : null,
        ]}
      />

      <View style={styles.tableHead}>
        <Text style={[styles.th, styles.kotQty]}>Qty</Text>
        <Text style={[styles.th, styles.colItem, styles.kotItemCol]}>Item</Text>
      </View>
      {items.map((it, i) => {
        const addons = addonsOf(it);
        return (
          <View key={i}>
            <View style={styles.itemRow}>
              <Text style={[styles.kotQty, styles.kotQtyText]}>{num(it.quantity)}</Text>
              <View style={[styles.colItem, styles.kotItemCol]}>
                <Text style={styles.kotItemName}>
                  {it.name}
                  {it.variationName ? <Text style={styles.variation}> ({it.variationName})</Text> : null}
                </Text>
                {addons.length ? <Text style={styles.kotAddons}>+ {addons.map((a) => a.name).join(', ')}</Text> : null}
                {it.notes ? <Text style={styles.kotNote}>* {it.notes}</Text> : null}
              </View>
            </View>
            <Dashes />
          </View>
        );
      })}

      <Text style={styles.totalItems}>Total Items: {items.length}</Text>
      <Footer />
    </>
  );
}

// The ref for capturing goes on the caller's wrapper View (see
// BillPreviewModal), so this component stays a plain renderer.
export default function Receipt({ data, paperWidth = '58' }) {
  let user = null;
  try {
    user = useAuth().user;
  } catch (e) {
    // rendered outside AuthProvider: only the passed data is used
  }
  if (!data) return null;
  const uid = user?.user_id || user?.id;
  const info = {
    name: data.restaurantName || user?.restaurant_name,
    address: data.restaurantAddress ?? user?.address,
    phone: data.restaurantPhone ?? user?.phone,
    businessIdLabel: data.businessIdLabel || user?.business_id_label,
    businessIdNo: data.businessIdNo !== undefined
      ? data.businessIdNo
      : (String(user?.show_business_id) === '1' || user?.show_business_id === true ? user?.business_id_no : null),
    taxName: user?.tax_name,
    logoUri: data.restaurantLogoUrl !== undefined ? data.restaurantLogoUrl : restaurantLogoUrl(user),
    qrUri: data.paymentQrUrl !== undefined
      ? data.paymentQrUrl
      : (user?.business_qr_code_path && uid ? `${BASE_URL}/api/image.php?type=business_qr&id=${encodeURIComponent(uid)}` : null),
  };
  return (
    <View style={[styles.paper, { width: receiptWidth(paperWidth) }]}>
      {data.type === 'kot' ? <Kot data={data} info={info} /> : <Bill data={data} info={info} />}
    </View>
  );
}

// System font throughout (no fontFamily): it carries Devanagari and other
// scripts on every phone, where the app's Poppins has Latin glyphs only.
const styles = StyleSheet.create({
  paper: { backgroundColor: '#fff', paddingHorizontal: 8, paddingVertical: 10 },
  center: { alignItems: 'center' },
  dashes: { flexDirection: 'row', overflow: 'hidden', alignSelf: 'stretch' },
  doubleRule: { marginTop: 8, marginBottom: 10 },

  header: { alignItems: 'center' },
  logo: { width: 60, height: 60, marginBottom: 6 },
  restaurantName: { fontSize: 17, fontWeight: '800', letterSpacing: 0.5, textAlign: 'center' },
  details: { fontSize: 10.5, lineHeight: 14, color: MUTED, textAlign: 'center', marginTop: 3 },
  billTag: { fontSize: 12.5, fontWeight: '700', marginTop: 6 },
  dateText: { fontSize: 11, color: MUTED, marginTop: 4, textAlign: 'center' },

  infoBox: { backgroundColor: TINT, borderLeftWidth: 4, paddingVertical: 7, paddingHorizontal: 9, marginBottom: 10, gap: 3 },
  infoRow: { flexDirection: 'row', justifyContent: 'space-between', gap: 10 },
  infoLabel: { fontSize: 11.5, fontWeight: '600', color: MUTED },
  infoValue: { flex: 1, fontSize: 11.5, fontWeight: '700', color: INK, textAlign: 'right' },

  tableHead: { flexDirection: 'row', alignItems: 'flex-end', borderBottomWidth: 2, borderBottomColor: INK, paddingBottom: 4 },
  th: { fontSize: 11, fontWeight: '700', color: INK, textTransform: 'uppercase', letterSpacing: 0.3 },
  colItem: { flex: 1 },
  colQty: { width: 30, textAlign: 'center' },
  colAmt: { width: 70, textAlign: 'right' },
  itemRow: { flexDirection: 'row', alignItems: 'flex-start', paddingVertical: 6 },
  itemName: { fontSize: 13, color: INK },
  variation: { color: MUTED },
  addonLine: { fontSize: 11, color: MUTED, marginTop: 2 },
  cell: { fontSize: 13, color: INK, fontVariant: ['tabular-nums'] },

  totals: { marginTop: 10, gap: 3 },
  row: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  rowLabel: { flexShrink: 1, fontSize: 13, color: INK },
  rowValue: { fontSize: 13, color: INK, fontVariant: ['tabular-nums'] },
  grandRule: { marginTop: 4, marginBottom: 4 },
  grandText: { fontSize: 15, fontWeight: '800' },
  strong: { fontWeight: '700' },

  payBox: { marginTop: 10, gap: 2 },
  payRule: { marginBottom: 8 },
  payText: { fontSize: 12 },

  // borderRadius keeps older Android drawing the dashed border at all.
  loyaltyBox: { marginTop: 10, padding: 8, borderWidth: 1, borderStyle: 'dashed', borderRadius: 1 },
  loyaltyText: { fontSize: 12, fontWeight: '700', color: '#065f46', textAlign: 'center' },

  qrBlock: { marginTop: 12, alignItems: 'center' },
  qrLabel: { fontSize: 9.5, fontWeight: '700', color: MUTED, letterSpacing: 1, marginTop: 10, marginBottom: 6 },
  qr: { width: 108, height: 108, borderWidth: 1, borderColor: '#e5e7eb', padding: 4, backgroundColor: '#fff' },

  footer: { marginTop: 14, alignItems: 'center' },
  footerText: { fontSize: 10.5, color: MUTED, marginTop: 10 },
  brandRow: { flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 4 },
  brandLogo: { width: 16, height: 16, resizeMode: 'contain' },
  brandText: { fontSize: 10.5, fontWeight: '700', color: INK },

  kotBanner: { alignItems: 'center', borderWidth: 2, backgroundColor: '#fef2f2', paddingVertical: 8, paddingHorizontal: 10, marginBottom: 10 },
  kotTitle: { fontSize: 26, fontWeight: '900', letterSpacing: 3 },
  kotNumber: { fontSize: 18, fontWeight: '700', color: INK, marginTop: 1 },
  kotBannerRule: { marginTop: 6 },
  kotQty: { width: 34, textAlign: 'center' },
  kotQtyText: { fontSize: 14, fontWeight: '700', color: INK },
  kotItemCol: { paddingLeft: 6 },
  kotItemName: { fontSize: 14, fontWeight: '600', color: INK },
  kotAddons: { fontSize: 12, fontWeight: '700', color: AMBER, marginTop: 2 },
  kotNote: { fontSize: 11, fontStyle: 'italic', color: AMBER, marginTop: 2 },
  totalItems: { fontSize: 12, fontWeight: '700', color: INK, textAlign: 'center', marginTop: 12 },
});
