// The printed bill / kitchen ticket, drawn as real views so it looks like the
// website's printed bill (main/assets/js/script.js window.printOrder) and KOT
// (window.printKOT). Printing captures this view as a picture (react-native-view-shot)
// and the server turns it into an ESC/POS raster, so any script (Hindi etc.)
// and any styling prints as seen.
//
// The printer is 1-bit: api/convert_receipt_image.php prints every pixel with
// luminance under 200 as black and everything else as white, so text is black or dark
// grey and divider lines use #9ca3af (the website's #d1d5db would vanish).
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
//   billNo           counting bill number (Bill #); falls back to orderNumber
//   orderId          the order's id (Order No #)
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
import { Image, Platform, StyleSheet, Text, View } from 'react-native';
import { BASE_URL, restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';

const INK = '#111827';
// Dark enough to survive the printer's threshold (see top of file).
const RULE = '#9ca3af';

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

// ── Same layout as the website's printed bill (script.js window.printOrder) and KOT
// (window.printKOT): Courier body text, the restaurant header in a sans font, black on
// white like the website's print styles. Change both together.

const MONO = Platform.OS === 'ios' ? 'Courier' : 'monospace';
const p2 = (n) => String(n).padStart(2, '0');

// "16/10/2026 | 09:38 pm" like the website (en-IN date, lowercase am/pm)
function webDateTime(value) {
  const d = value ? new Date(String(value).replace(' ', 'T')) : new Date();
  if (Number.isNaN(d.getTime())) return String(value || '');
  let h = d.getHours();
  const ap = h >= 12 ? 'pm' : 'am';
  h = h % 12 || 12;
  return `${p2(d.getDate())}/${p2(d.getMonth() + 1)}/${d.getFullYear()} | ${p2(h)}:${p2(d.getMinutes())} ${ap}`;
}
function ymd(value) {
  const d = value ? new Date(String(value).replace(' ', 'T')) : new Date();
  return Number.isNaN(d.getTime()) ? '' : `${d.getFullYear()}${p2(d.getMonth() + 1)}${p2(d.getDate())}`;
}

// The restaurant header: logo, NAME, address, phone (website .header)
function Header({ info, phoneLabel, rule }) {
  return (
    <View style={[styles.header, rule === 'double' ? null : styles.headerSolid]}>
      <RemoteImage uri={info.logoUri} style={styles.logo} />
      <Text style={styles.restaurantName}>{String(info.name || 'Restaurant').toUpperCase()}</Text>
      {info.address ? <Text style={styles.details}>{info.address}</Text> : null}
      {info.phone ? <Text style={styles.details}>{phoneLabel} {info.phone}</Text> : null}
      {rule === 'double' ? <DoubleRule color={INK} /> : null}
    </View>
  );
}

function Line({ label, value, labelStyle, valueStyle }) {
  return (
    <View style={styles.infoLine}>
      <Text style={[styles.infoLabel, labelStyle]}>{label}</Text>
      <Text style={[styles.infoValue, valueStyle]}>{value}</Text>
    </View>
  );
}

function tableOf(data, fallback) {
  const label = data.tableLabel && data.tableLabel !== 'Takeaway' ? data.tableLabel : '';
  const tableName = data.tableName || (label ? label.split(' · ')[0] : '');
  const area = label.includes(' · ') ? label.split(' · ')[1] : '';
  return { tableName, text: tableName ? `Table ${tableName}${area ? ` - ${area}` : ''}` : (data.orderType || fallback) };
}

function Bill({ data, info }) {
  const cur = data.currency || '₹';
  const m = (v) => `${cur}${num(v).toFixed(2)}`;
  const items = data.items || [];
  const total = num(data.total);
  const discount = Array.isArray(data.discounts)
    ? data.discounts.reduce((s, d) => s + (d ? num(d[1]) : 0), 0)
    : num(data.discount);
  const charges = (Array.isArray(data.charges) ? data.charges : []).filter((c) => c && num(c[1]) > 0);
  const gross = num(data.subtotal) + num(data.tax) + charges.reduce((s, c) => s + num(c[1]), 0);
  const t = tableOf(data, 'Walk-in');
  const orderNumber = data.orderNumber || data.kotNumber || '';
  const billNo = data.billNo || orderNumber;

  return (
    <>
      <Header info={info} phoneLabel="Mob:" />

      <View style={styles.meta}>
        <Line label={t.tableName ? 'DineIn' : 'Order'} value={t.text} />
        <Line label="Date" value={webDateTime(data.createdAt)} />
        {billNo ? <Line label="Bill" value={`#${billNo}`} /> : null}
        {orderNumber ? <Line label="Invoice" value={`#INV-${ymd(data.createdAt)}-${orderNumber}`} /> : null}
        {data.orderId || orderNumber ? <Line label="Order No" value={`#${data.orderId || orderNumber}`} /> : null}
      </View>

      <View style={styles.payTo}>
        <Text style={styles.payToLabel}>Pay To</Text>
        <Text style={styles.payToName}>{info.name}</Text>
      </View>

      <Dashes style={styles.sep} />

      <View style={styles.tableHead}>
        <Text style={[styles.th, styles.cIdx]}>#</Text>
        <Text style={[styles.th, styles.cItem]}>ITEMS</Text>
        <Text style={[styles.th, styles.cQty]}>QTY</Text>
        <Text style={[styles.th, styles.cRate]}>RATE</Text>
        <Text style={[styles.th, styles.cAmt]}>AMOUNT</Text>
      </View>
      {items.map((it, i) => {
        const addons = addonsOf(it);
        const qty = num(it.quantity) || 1;
        const amount = lineTotalOf(it);
        return (
          <View key={i}>
            <View style={styles.itemRow}>
              <Text style={[styles.td, styles.cIdx, styles.idx]}>{i + 1}</Text>
              <View style={styles.cItem}>
                <Text style={styles.itemName}>{it.name}{it.variationName ? ` (${it.variationName})` : ''}</Text>
                {addons.length ? <Text style={styles.itemNote}>+ {addons.map((a) => `${a.name} ${m(a.price)}`).join(', ')}</Text> : null}
                {it.notes ? <Text style={styles.itemNote}>{it.notes}</Text> : null}
              </View>
              <Text style={[styles.td, styles.cQty]}>{qty}</Text>
              <Text style={[styles.td, styles.cRate]}>{m(amount / qty)}</Text>
              <Text style={[styles.td, styles.cAmt, styles.amt]}>{m(amount)}</Text>
            </View>
            <Dashes />
          </View>
        );
      })}

      <Dashes style={styles.sep} />

      <View style={styles.grossRow}>
        <Line label="GROSS AMOUNT" value={m(gross)} labelStyle={styles.grossText} valueStyle={styles.grossText} />
      </View>
      {discount > 0 ? <Line label="Discount" value={`-${m(discount)}`} /> : null}
      <View style={styles.grandRow}>
        <Line label="TOTAL AMOUNT" value={m(total)} labelStyle={styles.grandText} valueStyle={styles.grandText} />
      </View>

      {data.loyaltyLine ? (
        <View style={styles.helpBox}>
          <Text style={[styles.helpText, styles.strong]}>{data.loyaltyLine}</Text>
        </View>
      ) : null}

      {info.phone ? (
        <>
          <Text style={styles.asterisk}>* * * * * * * * * * * * *</Text>
          <View style={styles.helpBox}>
            <Text style={[styles.helpText, styles.strong]}>Need help? Contact us:</Text>
            <Text style={styles.helpText}>{info.phone}</Text>
          </View>
        </>
      ) : null}

      {info.qrUri ? (
        <View style={styles.qrBlock}>
          <Dashes />
          <Text style={styles.qrLabel}>SCAN TO PAY</Text>
          <RemoteImage uri={info.qrUri} style={styles.qr} />
        </View>
      ) : null}

      <View style={styles.footer}>
        <Dashes />
        <Text style={styles.footerText}>Thank you for visiting</Text>
        <Text style={styles.powered}>Powered by Restro Grow</Text>
      </View>
    </>
  );
}

function Kot({ data, info }) {
  const items = data.items || [];
  const t = tableOf(data, 'Takeaway');
  const orderNo = data.orderNumber || data.billNo;
  return (
    <>
      <Header info={info} phoneLabel="Ph:" rule="double" />

      <Text style={styles.kotTitle}>KOT</Text>
      {data.kotNumber ? <Text style={styles.kotNumber}>#{data.kotNumber}</Text> : null}
      <Dashes style={styles.sep} />
      <Text style={styles.kotDate}>{webDateTime(data.createdAt)}</Text>

      <View style={styles.kotInfo}>
        <View style={styles.kotInfoRow}>
          <View style={styles.kotInfoCell}><Text style={styles.kotInfoLabel}>TABLE</Text><Text style={styles.kotInfoValue}>{t.text}</Text></View>
          <View style={styles.kotInfoCell}><Text style={styles.kotInfoLabel}>STATUS</Text><Text style={styles.kotInfoValue}>{data.kotStatus || 'Pending'}</Text></View>
        </View>
        {orderNo ? <View style={styles.kotInfoCell}><Text style={styles.kotInfoLabel}>ORDER #</Text><Text style={styles.kotInfoValue}>{String(orderNo)}</Text></View> : null}
      </View>

      <View style={styles.tableHead}>
        <Text style={[styles.th, styles.kQty]}>QTY</Text>
        <Text style={[styles.th, styles.kItem]}>ITEM</Text>
      </View>
      {items.map((it, i) => {
        const addons = addonsOf(it);
        return (
          <View key={i}>
            <View style={styles.itemRow}>
              <Text style={[styles.kQty, styles.kQtyText]}>{num(it.quantity)}</Text>
              <View style={styles.kItem}>
                <Text style={styles.kName}>{it.name}{it.variationName ? ` (${it.variationName})` : ''}</Text>
                {addons.length ? <Text style={styles.kAddons}>+ {addons.map((a) => a.name).join(', ')}</Text> : null}
                {it.notes ? <Text style={styles.kNote}>* {it.notes}</Text> : null}
              </View>
            </View>
            <Dashes />
          </View>
        );
      })}

      <Dashes thickness={2} style={styles.sep} />
      <View style={styles.footer}>
        <Text style={styles.totalItems}>Total Items: {items.length}</Text>
        <Text style={styles.footerText}>Thank you!</Text>
        <Text style={styles.powered}>Powered by Restro Grow</Text>
      </View>
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

// Body text in Courier like the website print; the restaurant header in the system sans font.
// Devanagari and other scripts fall back to the phone's system font automatically.
const styles = StyleSheet.create({
  paper: { backgroundColor: '#fff', paddingHorizontal: 8, paddingVertical: 10 },
  dashes: { flexDirection: 'row', overflow: 'hidden', alignSelf: 'stretch' },
  doubleRule: { alignSelf: 'stretch', marginTop: 10 },
  strong: { fontWeight: '700' },

  header: { alignItems: 'center', paddingBottom: 8, marginBottom: 8 },
  headerSolid: { borderBottomWidth: 2, borderBottomColor: INK },
  logo: { width: 66, height: 66, marginBottom: 6 },
  restaurantName: { fontSize: 16, fontWeight: '800', letterSpacing: 0.5, textAlign: 'center', color: '#000' },
  details: { fontSize: 10, lineHeight: 13, color: '#333', textAlign: 'center', marginTop: 1 },

  meta: { marginVertical: 6, gap: 2 },
  infoLine: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  infoLabel: { fontFamily: MONO, fontSize: 10.5, color: '#444' },
  infoValue: { flex: 1, fontFamily: MONO, fontSize: 10.5, fontWeight: '700', color: '#000', textAlign: 'right' },

  payTo: { alignItems: 'center', marginVertical: 6, paddingVertical: 4, paddingHorizontal: 8, borderWidth: 1, borderStyle: 'dashed', borderColor: RULE, borderRadius: 1 },
  payToLabel: { fontFamily: MONO, fontSize: 9.5, color: '#444' },
  payToName: { fontFamily: MONO, fontSize: 12, fontWeight: '700', color: '#000', textAlign: 'center' },
  sep: { marginVertical: 5 },

  tableHead: { flexDirection: 'row', alignItems: 'flex-end', borderBottomWidth: 2, borderBottomColor: '#000', paddingBottom: 4, marginTop: 4 },
  th: { fontFamily: MONO, fontSize: 9.5, fontWeight: '700', color: '#000', letterSpacing: 0.3 },
  td: { fontFamily: MONO, fontSize: 11, color: '#000' },
  cIdx: { width: 16, textAlign: 'center' },
  cItem: { flex: 1, paddingHorizontal: 2 },
  cQty: { width: 26, textAlign: 'center' },
  cRate: { width: 52, textAlign: 'right' },
  cAmt: { width: 60, textAlign: 'right' },
  idx: { color: '#555' },
  amt: { fontWeight: '700', fontSize: 11.5 },
  itemRow: { flexDirection: 'row', alignItems: 'flex-start', paddingVertical: 5 },
  itemName: { fontFamily: MONO, fontSize: 12, fontWeight: '700', color: '#000' },
  itemNote: { fontFamily: MONO, fontSize: 10, fontStyle: 'italic', color: '#333', marginTop: 1 },

  grossRow: { borderTopWidth: 1, borderTopColor: RULE, paddingTop: 3, marginTop: 4 },
  grossText: { fontSize: 11.5, fontWeight: '700', color: '#000' },
  grandRow: { borderTopWidth: 2, borderTopColor: '#000', paddingTop: 4, marginTop: 3 },
  grandText: { fontSize: 15, fontWeight: '800', color: '#000' },

  asterisk: { fontFamily: MONO, textAlign: 'center', color: '#555', fontSize: 10.5, marginVertical: 6 },
  helpBox: { alignItems: 'center', marginVertical: 6, padding: 4, borderWidth: 1, borderStyle: 'dashed', borderColor: RULE, borderRadius: 1 },
  helpText: { fontFamily: MONO, fontSize: 9.5, color: '#000', textAlign: 'center' },

  qrBlock: { marginTop: 8, alignItems: 'center' },
  qrLabel: { fontSize: 9.5, fontWeight: '700', color: '#444', letterSpacing: 1, marginTop: 10, marginBottom: 6 },
  qr: { width: 104, height: 104, borderWidth: 1, borderColor: '#e5e7eb', padding: 4, backgroundColor: '#fff' },

  footer: { marginTop: 8, alignItems: 'center' },
  footerText: { fontFamily: MONO, fontSize: 9.5, color: '#333', marginTop: 6 },
  powered: { fontFamily: MONO, fontSize: 9, color: '#555', marginTop: 2 },

  kotTitle: { fontFamily: MONO, fontSize: 20, fontWeight: '800', color: '#000', marginTop: 4 },
  kotNumber: { fontFamily: MONO, fontSize: 13, color: '#000', marginTop: 2 },
  kotDate: { fontFamily: MONO, fontSize: 11, color: '#333', marginTop: 2 },
  kotInfo: { borderLeftWidth: 4, borderLeftColor: '#000', paddingVertical: 6, paddingHorizontal: 8, marginVertical: 8, gap: 4 },
  kotInfoRow: { flexDirection: 'row', gap: 10 },
  kotInfoCell: { flex: 1, flexDirection: 'row', justifyContent: 'space-between', gap: 6 },
  kotInfoLabel: { fontFamily: MONO, fontSize: 11.5, fontWeight: '700', color: '#000' },
  kotInfoValue: { fontFamily: MONO, fontSize: 11.5, fontWeight: '700', color: '#000', textAlign: 'right', flexShrink: 1 },
  kQty: { width: 34, textAlign: 'center' },
  kQtyText: { fontFamily: MONO, fontSize: 14, fontWeight: '700', color: '#000' },
  kItem: { flex: 1, paddingLeft: 6 },
  kName: { fontFamily: MONO, fontSize: 14, fontWeight: '700', color: '#000' },
  kAddons: { fontFamily: MONO, fontSize: 12, fontWeight: '700', color: '#000', marginTop: 2 },
  kNote: { fontFamily: MONO, fontSize: 11, fontStyle: 'italic', color: '#000', marginTop: 2 },
  totalItems: { fontFamily: MONO, fontSize: 12, fontWeight: '700', color: '#000' },
});
