import { Ionicons } from '@expo/vector-icons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, font, radius, spacing } from '../theme';
import { money } from '../utils/format';
import PressableScale from './ui/PressableScale';
import Sheet from './ui/Sheet';

// The POS's smaller pickers, same rules as the website POS:
//  - DiscountSheet: the cashier's own discount, % or flat (main/assets/js/pos-discount.js)
//  - AddonSheet:    add-ons on a dish, charged once per unit; "all of them" or "just one" (pos-addons.js)
//  - ComboSheet:    a combo's choices, each with its extra price (pos-combos.js)
//  - HeldSheet:     orders put on hold, to resume or discard (controllers/pos_operations.php list_held)

const PRESETS = [5, 10, 15, 20];

// The amount the cashier's discount actually takes off: from the subtotal, never into what the
// reward code + points already took, never below zero.
export function manualDiscountAmount(disc, sub, taken) {
  if (!disc || !(disc.value > 0) || !(sub > 0)) return 0;
  const amt = Math.round((disc.type === 'percent' ? (sub * disc.value) / 100 : disc.value) * 100) / 100;
  return Math.max(0, Math.min(amt, sub - (taken || 0)));
}
export function manualDiscountLabel(disc, currency) {
  if (!disc || !(disc.value > 0)) return 'Discount';
  return disc.type === 'percent' ? `Discount ${disc.value}%` : `Discount ${money(disc.value, currency)}`;
}

export function DiscountSheet({ visible, onClose, value, onApply, subtotal, currency, inline }) {
  const [type, setType] = useState('percent');
  const [val, setVal] = useState('');
  useEffect(() => {
    if (!visible) return;
    setType(value?.type || 'percent');
    setVal(value?.value ? String(value.value) : '');
  }, [visible]); // eslint-disable-line react-hooks/exhaustive-deps

  const n = parseFloat(val) || 0;
  const bad = type === 'percent' ? n > 100 : n > subtotal;
  const preview = manualDiscountAmount({ type, value: n }, subtotal, 0);

  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      inline={inline}
      title="Discount on this bill"
      subtitle="Comes off before tax"
      footer={
        <View style={styles.footRow}>
          {value ? (
            <PressableScale style={[styles.btn, styles.btnGhost]} onPress={() => onApply(null)}>
              <Text style={styles.btnGhostText}>Remove</Text>
            </PressableScale>
          ) : null}
          <PressableScale style={[styles.btn, { flex: 2 }, (!n || bad) && { opacity: 0.5 }]} disabled={!n || bad} onPress={() => onApply({ type, value: n })}>
            <Ionicons name="checkmark" size={17} color="#fff" />
            <Text style={styles.btnText}>{n && !bad ? `Take off ${money(preview, currency)}` : 'Apply'}</Text>
          </PressableScale>
        </View>
      }
    >
      <View style={styles.seg}>
        {[['percent', '% off'], ['amount', `${currency} off`]].map(([k, l]) => (
          <Pressable key={k} style={[styles.segBtn, type === k && styles.segOn]} onPress={() => { setType(k); setVal(''); }}>
            <Text style={[styles.segText, type === k && styles.segTextOn]}>{l}</Text>
          </Pressable>
        ))}
      </View>
      {type === 'percent' ? (
        <View style={styles.chips}>
          {PRESETS.map((p) => (
            <PressableScale key={p} feedback="select" style={[styles.chip, n === p && styles.chipOn]} onPress={() => setVal(String(p))}>
              <Text style={[styles.chipText, n === p && styles.chipTextOn]}>{p}%</Text>
            </PressableScale>
          ))}
        </View>
      ) : null}
      <TextInput
        style={styles.input}
        keyboardType="decimal-pad"
        value={val}
        onChangeText={setVal}
        placeholder={type === 'percent' ? 'Or type a %' : `Amount in ${currency}`}
        placeholderTextColor={colors.muted}
      />
      {bad ? <Text style={styles.err}>{type === 'percent' ? 'Up to 100%.' : `The bill is only ${money(subtotal, currency)}.`}</Text> : null}
    </Sheet>
  );
}

export function AddonSheet({ visible, onClose, line, addons, onSave, currency, inline }) {
  const [on, setOn] = useState({});
  const [scope, setScope] = useState('all');
  useEffect(() => {
    if (!visible || !line) return;
    const m = {};
    (line.addons || []).forEach((a) => { m[a.id] = true; });
    setOn(m);
    setScope('all');
  }, [visible, line]);
  if (!line) return null;
  const picked = addons.filter((a) => on[a.id]);
  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      inline={inline}
      title={`Add-ons for ${line.name}`}
      subtitle={`Charged for each ${line.name} they go with`}
      footer={
        <PressableScale style={styles.btn} onPress={() => onSave(picked, scope)}>
          <Ionicons name="checkmark" size={17} color="#fff" />
          <Text style={styles.btnText}>Save</Text>
        </PressableScale>
      }
    >
      <View style={{ gap: 6 }}>
        {addons.map((a) => {
          const sel = !!on[a.id];
          return (
            <PressableScale key={a.id} feedback="select" style={[styles.opt, sel && styles.optOn]} onPress={() => setOn((m) => ({ ...m, [a.id]: !m[a.id] }))}>
              <View style={[styles.ck, sel && styles.ckOn]}>{sel ? <Ionicons name="checkmark" size={14} color="#fff" /> : null}</View>
              <Text style={styles.optName} numberOfLines={1}>{a.name}</Text>
              <Text style={styles.optPrice}>+{money(a.price, currency)}</Text>
            </PressableScale>
          );
        })}
      </View>
      {line.quantity > 1 ? (
        <View style={[styles.seg, { marginTop: spacing.md }]}>
          {[['all', `All ${line.quantity}`], ['one', 'Just one']].map(([k, l]) => (
            <Pressable key={k} style={[styles.segBtn, scope === k && styles.segOn]} onPress={() => setScope(k)}>
              <Text style={[styles.segText, scope === k && styles.segTextOn]}>{l}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </Sheet>
  );
}

export function ComboSheet({ visible, onClose, combo, onAdd, currency }) {
  const [picks, setPicks] = useState({});
  const [qty, setQty] = useState(1);
  useEffect(() => {
    if (!visible || !combo) return;
    const p = {};
    combo.slots.forEach((s) => { p[s.id] = s.choices[0]?.id; });
    setPicks(p);
    setQty(1);
  }, [visible, combo]);
  if (!combo) return null;
  const chosen = (s) => s.choices.find((o) => o.id === picks[s.id]) || s.choices[0];
  const unit = combo.slots.reduce((t, s) => t + (Number(chosen(s)?.extra) || 0), Number(combo.price) || 0);
  const add = () => {
    const ids = combo.slots.map((s) => chosen(s).id);
    const summary = combo.slots.map((s) => { const o = chosen(s); return `${s.qty > 1 ? `${s.qty} × ` : ''}${o.name}${o.variation ? ` (${o.variation})` : ''}`; }).join(', ');
    onAdd({ comboId: combo.id, key: `combo_${combo.id}_${ids.join('-')}`, name: combo.name, price: Math.round(unit * 100) / 100, quantity: qty, picks: { ...picks }, summary });
  };
  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      title={combo.name}
      subtitle={`${money(combo.price, currency)}${combo.save > 0 ? ` · saves ${money(combo.save, currency)}` : ''}`}
      footer={
        <View style={styles.footRow}>
          <View style={styles.qty}>
            <Pressable style={styles.qtyBtn} onPress={() => setQty((q) => Math.max(1, q - 1))}><Ionicons name="remove" size={16} color={colors.ink} /></Pressable>
            <Text style={styles.qtyText}>{qty}</Text>
            <Pressable style={styles.qtyBtn} onPress={() => setQty((q) => Math.min(50, q + 1))}><Ionicons name="add" size={16} color={colors.ink} /></Pressable>
          </View>
          <PressableScale style={[styles.btn, { flex: 1 }]} onPress={add}>
            <Text style={styles.btnText}>Add · {money(unit * qty, currency)}</Text>
          </PressableScale>
        </View>
      }
    >
      {combo.slots.map((s) => (
        <View key={s.id} style={{ marginBottom: spacing.md }}>
          <Text style={styles.slotTitle}>{s.qty > 1 ? `${s.qty} × ` : ''}{s.title}</Text>
          <View style={{ gap: 6 }}>
            {s.choices.map((o) => {
              const sel = picks[s.id] === o.id;
              return (
                <PressableScale key={o.id} feedback="select" style={[styles.opt, sel && styles.optOn]} onPress={() => setPicks((p) => ({ ...p, [s.id]: o.id }))}>
                  <Ionicons name={sel ? 'radio-button-on' : 'radio-button-off'} size={18} color={sel ? colors.primary : colors.muted} />
                  <View style={{ flex: 1 }}>
                    <Text style={styles.optName} numberOfLines={1}>{o.name}</Text>
                    {o.variation ? <Text style={styles.optSub}>{o.variation}</Text> : null}
                  </View>
                  <Text style={styles.optPrice}>{o.extra > 0 ? `+${money(o.extra, currency)}` : 'Included'}</Text>
                </PressableScale>
              );
            })}
          </View>
        </View>
      ))}
    </Sheet>
  );
}

export function HeldSheet({ visible, onClose, orders, loading, busyId, onResume, onDiscard, currency }) {
  return (
    <Sheet visible={visible} onClose={onClose} title="Held orders" subtitle="Bring one back into the cart, or discard it">
      {loading ? (
        <ActivityIndicator color={colors.primary} style={{ marginVertical: spacing.xl }} />
      ) : !orders.length ? (
        <View style={styles.empty}>
          <Ionicons name="pause-circle-outline" size={34} color={colors.muted} />
          <Text style={styles.emptyText}>No orders on hold</Text>
        </View>
      ) : (
        <View style={{ gap: 8 }}>
          {orders.map((o) => (
            <View key={o.id} style={styles.held}>
              <View style={{ flex: 1 }}>
                <Text style={styles.optName}>{o.order_number}{o.table_number ? ` · Table ${o.table_number}` : ''}</Text>
                <Text style={styles.optSub}>
                  {Number(o.item_count) || 0} item{Number(o.item_count) === 1 ? '' : 's'} · {money(o.total, currency)} · {new Date(String(o.created_at).replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                </Text>
              </View>
              {busyId === o.id ? (
                <ActivityIndicator color={colors.primary} />
              ) : (
                <>
                  <Pressable style={styles.heldX} onPress={() => onDiscard(o)} hitSlop={6} accessibilityLabel={`Discard ${o.order_number}`}>
                    <Ionicons name="trash-outline" size={17} color={colors.danger} />
                  </Pressable>
                  <PressableScale style={styles.heldGo} onPress={() => onResume(o)}>
                    <Text style={styles.heldGoText}>Resume</Text>
                  </PressableScale>
                </>
              )}
            </View>
          ))}
        </View>
      )}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  footRow: { flexDirection: 'row', gap: spacing.sm },
  btn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, height: 50, borderRadius: radius.md, backgroundColor: colors.primary },
  btnText: { fontFamily: font.semiBold, fontSize: 14.5, color: '#fff' },
  btnGhost: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border },
  btnGhostText: { fontFamily: font.semiBold, fontSize: 14, color: colors.danger },
  seg: { flexDirection: 'row', padding: 4, borderRadius: radius.md, backgroundColor: colors.bg, gap: 4 },
  segBtn: { flex: 1, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  segOn: { backgroundColor: colors.surface },
  segText: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.inkSoft },
  segTextOn: { color: colors.ink },
  chips: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.md },
  chip: { flex: 1, height: 44, borderRadius: radius.pill, borderWidth: 1.5, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  chipOn: { backgroundColor: colors.ink, borderColor: colors.ink },
  chipText: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  chipTextOn: { color: '#fff' },
  input: { marginTop: spacing.md, height: 50, paddingHorizontal: spacing.md, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, fontFamily: font.semiBold, fontSize: 17, color: colors.ink },
  err: { marginTop: 6, fontFamily: font.medium, fontSize: 12.5, color: colors.danger },
  opt: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, minHeight: 50, paddingHorizontal: spacing.md, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.border },
  optOn: { borderColor: colors.primary, backgroundColor: '#FFF8F5' },
  ck: { width: 22, height: 22, borderRadius: 7, borderWidth: 2, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  ckOn: { backgroundColor: colors.primary, borderColor: colors.primary },
  optName: { flex: 1, fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  optSub: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft },
  optPrice: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.ink },
  slotTitle: { fontFamily: font.semiBold, fontSize: 13, color: colors.inkSoft, marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.4 },
  qty: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 6, borderRadius: radius.md, backgroundColor: colors.bg },
  qtyBtn: { width: 36, height: 36, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  qtyText: { minWidth: 20, textAlign: 'center', fontFamily: font.bold, fontSize: 15, color: colors.ink },
  empty: { alignItems: 'center', gap: 6, paddingVertical: spacing.xl },
  emptyText: { fontFamily: font.medium, fontSize: 14, color: colors.inkSoft },
  held: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.bg },
  heldX: { width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.dangerBg },
  heldGo: { paddingHorizontal: 16, height: 38, borderRadius: radius.pill, backgroundColor: colors.primary, justifyContent: 'center' },
  heldGoText: { fontFamily: font.semiBold, fontSize: 13, color: '#fff' },
});
