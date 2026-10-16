import { Ionicons } from '@expo/vector-icons';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, Animated, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, font, radius, spacing } from '../../theme';
import { haptic } from '../../utils/haptics';
import PressableScale from './PressableScale';
import Sheet from './Sheet';

/*
 * App-wide feedback, callable from anywhere (screens, helpers, async code):
 *   feedback.success(msg) / .error(msg) / .info(msg)    short toast at the top
 *   await feedback.confirm({ title, message, confirmText, danger })   -> true / false
 *   await feedback.choose({ title, message, options: [{ label, value, icon, tone }] })  -> value | null
 *   await feedback.reason({ mode: 'reject' | 'cancel', delivery })  -> { code, text, strike } | null
 * Replaces Alert.alert, which blocks the screen and silently does nothing on the web build.
 *
 * <FeedbackHost /> draws them. One sits at the app root; full-screen Modals (POS cart, bill
 * preview...) mount their own, and only the most recently mounted host draws, so toasts and
 * dialogs always appear in the window the person is looking at.
 */

let state = { toast: null, dialog: null };
const listeners = new Set();
const set = (patch) => {
  state = { ...state, ...patch };
  listeners.forEach((l) => l());
};
const subscribe = (l) => {
  listeners.add(l);
  return () => listeners.delete(l);
};

let toastTimer = null;
let toastSeq = 0;
function toast(message, type = 'info', ms) {
  if (!message) return;
  clearTimeout(toastTimer);
  haptic[type === 'error' ? 'error' : type === 'success' ? 'success' : 'tap']();
  set({ toast: { id: ++toastSeq, message: String(message), type } });
  toastTimer = setTimeout(() => set({ toast: null }), ms || (type === 'error' ? 4200 : 2600));
}

function openDialog(dialog) {
  return new Promise((resolve) => {
    set({ dialog: { ...dialog, resolve } });
  });
}
function closeDialog(value) {
  const d = state.dialog;
  if (!d) return;
  set({ dialog: null });
  d.resolve(value);
}

const REASONS = {
  reject: (delivery) => [
    { code: 'out_of_stock', icon: 'cube-outline', title: 'Items out of stock', sub: 'Some dishes in this order have run out' },
    { code: 'too_busy', icon: 'hourglass-outline', title: 'Kitchen is too busy', sub: "Too many orders to make this one in time" },
    { code: 'closed', icon: 'storefront-outline', title: 'Closed or closing', sub: 'The restaurant is closed or about to close' },
    delivery
      ? { code: 'too_far', icon: 'navigate-outline', title: 'Address too far', sub: 'Outside the area you deliver to' }
      : { code: 'kitchen_issue', icon: 'flame-outline', title: 'Kitchen problem', sub: 'Equipment, gas or staff problem' },
    { code: 'no_answer', icon: 'call-outline', title: "Couldn't reach the customer", sub: 'No answer, or looks fake. Counts as a strike', warn: true },
  ],
  cancel: (delivery) => [
    { code: 'customer_asked', icon: 'person-remove-outline', title: 'Customer asked to cancel', sub: 'They called or messaged to cancel' },
    { code: 'out_of_stock', icon: 'cube-outline', title: 'Items ran out', sub: "Couldn't finish the order" },
    { code: 'kitchen_issue', icon: 'flame-outline', title: 'Kitchen problem', sub: 'Equipment, gas or staff problem' },
    delivery
      ? { code: 'delivery_issue', icon: 'bicycle-outline', title: "Can't deliver", sub: "No rider, or the address can't be reached" }
      : { code: 'closed', icon: 'storefront-outline', title: 'Closing early', sub: 'The restaurant has to close' },
    { code: 'no_answer', icon: 'call-outline', title: "Couldn't reach the customer", sub: "Didn't pick up or never came. Counts as a strike", warn: true },
  ],
};

export const feedback = {
  success: (m, ms) => toast(m, 'success', ms),
  error: (m, ms) => toast(m, 'error', ms),
  info: (m, ms) => toast(m, 'info', ms),
  confirm: (opts) => openDialog({ kind: 'confirm', ...opts }),
  choose: (opts) => openDialog({ kind: 'choose', ...opts }),
  reason: (opts = {}) => openDialog({ kind: 'reason', mode: opts.mode || 'reject', delivery: !!opts.delivery, title: opts.title }),
  // Friendlier wording for network failures, server messages pass through.
  errorFrom: (e, fallback = 'Something went wrong') => {
    const m = e?.message || fallback;
    toast(/fetch|network|reach the server/i.test(m) ? "Couldn't reach the server. Check the connection and try again." : m, 'error');
  },
};

// ── host registry: only the newest mounted host renders ──
let hostSeq = 0;
let hosts = [];
const hostListeners = new Set();
function useIsTopHost() {
  const id = useRef(null);
  if (id.current === null) id.current = ++hostSeq;
  const top = useSyncExternalStore(
    (l) => {
      hostListeners.add(l);
      return () => hostListeners.delete(l);
    },
    () => hosts[hosts.length - 1]
  );
  useEffect(() => {
    hosts = [...hosts, id.current];
    hostListeners.forEach((l) => l());
    return () => {
      hosts = hosts.filter((h) => h !== id.current);
      hostListeners.forEach((l) => l());
    };
  }, []);
  return top === id.current;
}

export function FeedbackHost() {
  const isTop = useIsTopHost();
  const snap = useSyncExternalStore(subscribe, () => state);
  if (!isTop) return null;
  return (
    <>
      <DialogLayer dialog={snap.dialog} />
      <ToastLayer toast={snap.toast} />
    </>
  );
}

const TOAST_ICON = { success: 'checkmark-circle', error: 'alert-circle', info: 'information-circle' };
const TOAST_TINT = { success: '#4ADE80', error: '#FF8A80', info: '#93C5FD' };

function ToastLayer({ toast: t }) {
  const insets = useSafeAreaInsets();
  const v = useRef(new Animated.Value(0)).current;
  const [shown, setShown] = useState(t);
  useEffect(() => {
    if (t) {
      setShown(t);
      v.setValue(0);
      Animated.spring(v, { toValue: 1, useNativeDriver: true, damping: 18, stiffness: 260 }).start();
    } else if (shown) {
      Animated.timing(v, { toValue: 0, duration: 180, useNativeDriver: true }).start(() => setShown(null));
    }
  }, [t?.id]); // eslint-disable-line react-hooks/exhaustive-deps
  if (!shown) return null;
  return (
    <View pointerEvents="box-none" style={[styles.toastWrap, { top: insets.top + 8 }]}>
      <Animated.View
        style={[
          styles.toast,
          { opacity: v, transform: [{ translateY: v.interpolate({ inputRange: [0, 1], outputRange: [-24, 0] }) }, { scale: v.interpolate({ inputRange: [0, 1], outputRange: [0.96, 1] }) }] },
        ]}
      >
        <Pressable style={styles.toastInner} onPress={() => set({ toast: null })} accessibilityRole="alert">
          <Ionicons name={TOAST_ICON[shown.type] || TOAST_ICON.info} size={20} color={TOAST_TINT[shown.type] || TOAST_TINT.info} />
          <Text style={styles.toastText} numberOfLines={3}>{shown.message}</Text>
        </Pressable>
      </Animated.View>
    </View>
  );
}

function DialogLayer({ dialog }) {
  // Keep the last dialog's content while the sheet animates closed.
  const last = useRef(null);
  if (dialog) last.current = dialog;
  const d = dialog || last.current;
  const visible = !!dialog;
  if (!d) return null;
  const cancel = () => closeDialog(d.kind === 'confirm' ? false : null);
  return (
    <Sheet
      inline
      visible={visible}
      onClose={cancel}
      title={d.kind === 'reason' ? (d.mode === 'cancel' ? 'Cancel this order' : 'Reject this order') : d.title}
      subtitle={d.kind !== 'reason' ? d.message : undefined}
    >
      {d.kind === 'confirm' ? <ConfirmBody d={d} /> : d.kind === 'choose' ? <ChooseBody d={d} /> : <ReasonBody key={d.mode + String(visible)} d={d} />}
    </Sheet>
  );
}

function ConfirmBody({ d }) {
  return (
    <View style={styles.btnRow}>
      <PressableScale style={[styles.btn, styles.btnGhost]} onPress={() => closeDialog(false)}>
        <Text style={styles.btnGhostText}>{d.cancelText || 'Cancel'}</Text>
      </PressableScale>
      <PressableScale
        style={[styles.btn, { backgroundColor: d.danger ? colors.danger : colors.primary }]}
        feedback={d.danger ? 'warning' : 'press'}
        onPress={() => closeDialog(true)}
      >
        <Text style={styles.btnText}>{d.confirmText || 'OK'}</Text>
      </PressableScale>
    </View>
  );
}

function ChooseBody({ d }) {
  return (
    <ScrollView style={{ maxHeight: 420 }} contentContainerStyle={{ gap: 8, paddingBottom: 4 }} showsVerticalScrollIndicator={false}>
      {(d.options || []).map((o) => (
        <PressableScale key={String(o.value)} style={styles.option} onPress={() => closeDialog(o.value)}>
          {o.icon ? (
            <View style={[styles.optionIcon, o.tone === 'danger' && { backgroundColor: colors.dangerBg }]}>
              <Ionicons name={o.icon} size={18} color={o.tone === 'danger' ? colors.danger : colors.primary} />
            </View>
          ) : null}
          <View style={{ flex: 1 }}>
            <Text style={[styles.optionTitle, o.tone === 'danger' && { color: colors.danger }]}>{o.label}</Text>
            {o.sub ? <Text style={styles.optionSub}>{o.sub}</Text> : null}
          </View>
          <Ionicons name="chevron-forward" size={16} color={colors.muted} />
        </PressableScale>
      ))}
      {d.cancelText !== null ? (
        <PressableScale style={[styles.btn, styles.btnGhost, { marginTop: 4 }]} onPress={() => closeDialog(null)}>
          <Text style={styles.btnGhostText}>{d.cancelText || 'Close'}</Text>
        </PressableScale>
      ) : null}
    </ScrollView>
  );
}

function ReasonBody({ d }) {
  const list = REASONS[d.mode === 'cancel' ? 'cancel' : 'reject'](d.delivery);
  const [code, setCode] = useState(null);
  const [other, setOther] = useState('');
  const [fake, setFake] = useState(false);
  const picked = list.find((r) => r.code === code);
  const ready = code === 'other' ? other.trim().length >= 3 : !!picked;
  const submit = () => {
    if (!ready) return;
    closeDialog(code === 'other' ? { code: '', text: other.trim(), strike: fake } : { code, text: picked.title, strike: code === 'no_answer' });
  };
  const verb = d.mode === 'cancel' ? 'Cancel order' : 'Reject order';
  return (
    <View>
      <Text style={styles.reasonLead}>
        {d.mode === 'cancel' ? 'Why are you cancelling? ' : 'Why are you rejecting? '}
        Common reasons are shown to the customer on their tracking page.
      </Text>
      <ScrollView style={{ maxHeight: Platform.OS === 'web' ? 360 : 400 }} contentContainerStyle={{ gap: 8 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {[...list, { code: 'other', icon: 'create-outline', title: 'Other reason', sub: 'Type it yourself' }].map((r) => {
          const on = code === r.code;
          return (
            <PressableScale key={r.code} feedback="select" style={[styles.reason, on && styles.reasonOn]} onPress={() => setCode(r.code)}>
              <View style={[styles.optionIcon, on && { backgroundColor: colors.primary }]}>
                <Ionicons name={r.icon} size={18} color={on ? '#fff' : colors.primary} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.optionTitle}>{r.title}</Text>
                <Text style={[styles.optionSub, r.warn && { color: colors.danger }]}>{r.sub}</Text>
              </View>
              <Ionicons name={on ? 'radio-button-on' : 'radio-button-off'} size={20} color={on ? colors.primary : colors.border} />
            </PressableScale>
          );
        })}
        {code === 'other' ? (
          <View style={{ gap: 8 }}>
            <TextInput
              style={styles.input}
              value={other}
              onChangeText={setOther}
              placeholder="Write the reason"
              placeholderTextColor={colors.muted}
              autoFocus
              maxLength={200}
            />
            <Pressable style={styles.checkRow} onPress={() => setFake((f) => !f)}>
              <Ionicons name={fake ? 'checkbox' : 'square-outline'} size={20} color={fake ? colors.danger : colors.muted} />
              <Text style={styles.optionSub}>This order looks fake (counts as a strike)</Text>
            </Pressable>
          </View>
        ) : null}
      </ScrollView>
      <View style={[styles.btnRow, { marginTop: spacing.md }]}>
        <PressableScale style={[styles.btn, styles.btnGhost]} onPress={() => closeDialog(null)}>
          <Text style={styles.btnGhostText}>Go back</Text>
        </PressableScale>
        <PressableScale style={[styles.btn, { backgroundColor: colors.danger }]} disabled={!ready} feedback="warning" onPress={submit}>
          <Text style={styles.btnText}>{verb}</Text>
        </PressableScale>
      </View>
    </View>
  );
}

// Small inline busy indicator for buttons (kept here so screens import one module).
export function ButtonSpinner({ color = '#fff' }) {
  return <ActivityIndicator size="small" color={color} />;
}

const styles = StyleSheet.create({
  toastWrap: {
    position: 'absolute',
    left: 0,
    right: 0,
    alignItems: 'center',
    paddingHorizontal: spacing.lg,
    zIndex: 2000,
    elevation: 2000,
  },
  toast: {
    width: '100%',
    maxWidth: 520,
    borderRadius: radius.lg,
    backgroundColor: '#1D1B26',
    ...Platform.select({
      web: { boxShadow: '0 12px 28px rgba(0,0,0,0.28)' },
      default: { shadowColor: '#000', shadowOpacity: 0.25, shadowRadius: 14, shadowOffset: { width: 0, height: 8 }, elevation: 12 },
    }),
  },
  toastInner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: spacing.lg,
    paddingVertical: 13,
  },
  toastText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: '#fff',
    lineHeight: 19,
  },
  btnRow: {
    flexDirection: 'row',
    gap: spacing.md,
    marginTop: spacing.sm,
  },
  btn: {
    flex: 1,
    height: 50,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primary,
  },
  btnText: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: '#fff',
  },
  btnGhost: {
    backgroundColor: colors.bg,
  },
  btnGhostText: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.inkSoft,
  },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.bg,
  },
  optionIcon: {
    width: 36,
    height: 36,
    borderRadius: 12,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  optionTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  optionSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 1,
  },
  reasonLead: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.inkSoft,
    marginBottom: spacing.md,
  },
  reason: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  reasonOn: {
    borderColor: colors.primary,
    backgroundColor: colors.primaryLight,
  },
  input: {
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
    backgroundColor: colors.surface,
  },
  checkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
});
