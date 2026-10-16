import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef } from 'react';
import { ActivityIndicator, Animated, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BRAND } from '../config/brand';
import { colors, CONTENT_MAX, font, radius, shadow } from '../theme';
import { tap } from '../utils/haptics';

// ── Layout ──────────────────────────────────────────────────────────────────────────────────
// Every screen sits in a centred column so the app also looks right on tablets and in a browser.
export function Screen({ children, style, edges = ['top'], bg = colors.bg }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.screenOuter, { backgroundColor: bg }]}>
      <View
        style={[
          styles.screenInner,
          { paddingTop: edges.includes('top') ? insets.top : 0, paddingBottom: edges.includes('bottom') ? insets.bottom : 0 },
          style,
        ]}
      >
        {children}
      </View>
    </View>
  );
}

export function Header({ title, onBack, right, subtitle, transparent }) {
  return (
    <View style={[styles.header, transparent && { backgroundColor: 'transparent' }]}>
      {onBack ? (
        <Pressable onPress={() => { tap(); onBack(); }} style={styles.headerBtn} hitSlop={8} accessibilityLabel="Back">
          <Ionicons name="chevron-back" size={22} color={colors.ink} />
        </Pressable>
      ) : (
        <View style={{ width: 4 }} />
      )}
      <View style={{ flex: 1 }}>
        <Text style={styles.headerTitle} numberOfLines={1}>{title}</Text>
        {subtitle ? <Text style={styles.headerSub} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      {right}
    </View>
  );
}

// ── Brand ───────────────────────────────────────────────────────────────────────────────────
export function BrandMark({ size = 44, light }) {
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.3,
        backgroundColor: light ? 'rgba(255,255,255,0.2)' : colors.primary,
        alignItems: 'center',
        justifyContent: 'center',
        borderWidth: light ? 1.5 : 0,
        borderColor: 'rgba(255,255,255,0.5)',
      }}
    >
      <Text style={{ fontFamily: font.bold, color: light ? '#fff' : colors.onPrimary, fontSize: size * 0.4, letterSpacing: 0.5 }}>
        {BRAND.initials}
      </Text>
    </View>
  );
}

// ── Food ────────────────────────────────────────────────────────────────────────────────────
export function FoodImage({ source, style, radiusValue = radius.md, children }) {
  return (
    <View style={[{ overflow: 'hidden', borderRadius: radiusValue, backgroundColor: colors.skeleton }, style]}>
      <Image source={source} style={StyleSheet.absoluteFill} contentFit="cover" transition={180} />
      {children}
    </View>
  );
}

export function VegMark({ kind, size = 16 }) {
  if (kind === 'drink') return null;
  const color = kind === 'veg' ? colors.veg : colors.nonveg;
  return (
    <View style={{ width: size, height: size, borderWidth: 1.5, borderColor: color, borderRadius: 3, alignItems: 'center', justifyContent: 'center', backgroundColor: '#fff' }}>
      <View style={{ width: size * 0.5, height: size * 0.5, borderRadius: size, backgroundColor: color }} />
    </View>
  );
}

export function RatingPill({ value, count, small }) {
  return (
    <View style={[styles.rating, small && { paddingVertical: 1, paddingHorizontal: 6 }]}>
      <Ionicons name="star" size={small ? 10 : 12} color={colors.star} />
      <Text style={[styles.ratingText, small && { fontSize: 11 }]}>{value.toFixed(1)}</Text>
      {count ? <Text style={styles.ratingCount}>({count})</Text> : null}
    </View>
  );
}

// ── Buttons ─────────────────────────────────────────────────────────────────────────────────
export function Button({ title, onPress, variant = 'primary', icon, loading, disabled, style, small }) {
  const isPrimary = variant === 'primary';
  const isDark = variant === 'dark';
  const bg = isPrimary ? colors.primary : isDark ? colors.ink : variant === 'soft' ? colors.primarySoft : 'transparent';
  const fg = isPrimary ? colors.onPrimary : isDark ? '#fff' : colors.primary;
  return (
    <Pressable
      onPress={() => { if (disabled || loading) return; tap(); onPress && onPress(); }}
      style={({ pressed }) => [
        styles.btn,
        small && { paddingVertical: 10, paddingHorizontal: 16 },
        { backgroundColor: bg },
        variant === 'outline' && { borderWidth: 1.5, borderColor: colors.primary },
        isPrimary && !disabled && shadow.sm,
        disabled && { opacity: 0.45 },
        pressed && { opacity: 0.88, transform: [{ scale: 0.985 }] },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <>
          {icon ? <Ionicons name={icon} size={small ? 16 : 18} color={fg} /> : null}
          <Text style={[styles.btnText, small && { fontSize: 13 }, { color: fg }]}>{title}</Text>
        </>
      )}
    </Pressable>
  );
}

export function Chip({ label, active, onPress, icon, style }) {
  return (
    <Pressable
      onPress={() => { tap(); onPress && onPress(); }}
      style={[styles.chip, active && { backgroundColor: colors.primary, borderColor: colors.primary }, style]}
    >
      {icon ? <Ionicons name={icon} size={14} color={active ? colors.onPrimary : colors.inkSoft} /> : null}
      <Text style={[styles.chipText, active && { color: colors.onPrimary, fontFamily: font.semiBold }]}>{label}</Text>
    </Pressable>
  );
}

export function IconButton({ name, onPress, color = colors.ink, size = 20, badge, style, label }) {
  return (
    <Pressable onPress={() => { tap(); onPress && onPress(); }} style={[styles.iconBtn, style]} hitSlop={6} accessibilityLabel={label}>
      <Ionicons name={name} size={size} color={color} />
      {badge ? <View style={styles.dot} /> : null}
    </Pressable>
  );
}

// Add to cart. Becomes a - n + stepper once the dish is in the cart.
export function AddControl({ qty, onAdd, onMinus, onPlus, disabled, hasOptions }) {
  if (disabled) {
    return (
      <View style={[styles.addBtn, { borderColor: colors.borderStrong, backgroundColor: colors.bg }]}>
        <Text style={[styles.addText, { color: colors.muted }]}>UNAVAILABLE</Text>
      </View>
    );
  }
  if (!qty) {
    return (
      <Pressable onPress={() => { tap(); onAdd(); }} style={({ pressed }) => [styles.addBtn, pressed && { opacity: 0.85 }]}>
        <Text style={styles.addText}>ADD</Text>
        <View style={styles.addPlus}>
          <Ionicons name="add" size={14} color={colors.primary} />
        </View>
        {hasOptions ? <Text style={styles.customisable}>customisable</Text> : null}
      </Pressable>
    );
  }
  return <Stepper qty={qty} onMinus={onMinus} onPlus={onPlus} />;
}

export function Stepper({ qty, onMinus, onPlus, large }) {
  return (
    <View style={[styles.stepper, large && { height: 44 }]}>
      <Pressable onPress={() => { tap(); onMinus(); }} style={styles.stepBtn} hitSlop={6} accessibilityLabel="Remove one">
        <Ionicons name="remove" size={large ? 20 : 16} color={colors.onPrimary} />
      </Pressable>
      <Text style={[styles.stepQty, large && { fontSize: 16, minWidth: 28 }]}>{qty}</Text>
      <Pressable onPress={() => { tap(); onPlus(); }} style={styles.stepBtn} hitSlop={6} accessibilityLabel="Add one">
        <Ionicons name="add" size={large ? 20 : 16} color={colors.onPrimary} />
      </Pressable>
    </View>
  );
}

// ── Misc ────────────────────────────────────────────────────────────────────────────────────
export function SectionTitle({ title, action, onAction, style }) {
  return (
    <View style={[styles.sectionRow, style]}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {action ? (
        <Pressable onPress={() => { tap(); onAction && onAction(); }} hitSlop={8}>
          <Text style={styles.sectionAction}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function Card({ children, style }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Divider({ style }) {
  return <View style={[{ height: 1, backgroundColor: colors.border }, style]} />;
}

export function EmptyState({ icon = 'fast-food-outline', title, text, action, onAction }) {
  return (
    <View style={styles.empty}>
      <View style={styles.emptyIcon}>
        <Ionicons name={icon} size={40} color={colors.primary} />
      </View>
      <Text style={styles.emptyTitle}>{title}</Text>
      {text ? <Text style={styles.emptyText}>{text}</Text> : null}
      {action ? <Button title={action} onPress={onAction} style={{ marginTop: 18, alignSelf: 'center' }} /> : null}
    </View>
  );
}

export function Row({ icon, label, value, onPress, danger, last, right }) {
  return (
    <Pressable
      onPress={onPress ? () => { tap(); onPress(); } : undefined}
      style={({ pressed }) => [styles.listRow, last && { borderBottomWidth: 0 }, pressed && onPress && { backgroundColor: colors.bg }]}
    >
      <View style={[styles.listIcon, danger && { backgroundColor: colors.dangerBg }]}>
        <Ionicons name={icon} size={18} color={danger ? colors.danger : colors.primary} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={[styles.listLabel, danger && { color: colors.danger }]}>{label}</Text>
        {value ? <Text style={styles.listValue} numberOfLines={1}>{value}</Text> : null}
      </View>
      {right || (onPress ? <Ionicons name="chevron-forward" size={18} color={colors.muted} /> : null)}
    </Pressable>
  );
}

export function GradientOverlay({ style, from = 'rgba(0,0,0,0)', to = 'rgba(0,0,0,0.72)' }) {
  return <LinearGradient colors={[from, to]} style={[StyleSheet.absoluteFill, style]} />;
}

export function Toast({ toast }) {
  const opacity = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.timing(opacity, { toValue: toast ? 1 : 0, duration: 180, useNativeDriver: Platform.OS !== 'web' }).start();
  }, [toast, opacity]);
  if (!toast) return null;
  return (
    <Animated.View pointerEvents="none" style={[styles.toastWrap, { opacity }]}>
      <View style={[styles.toast, toast.tone === 'success' && { backgroundColor: colors.success }]}>
        <Text style={styles.toastText}>{toast.message}</Text>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  screenOuter: { flex: 1, alignItems: 'center' },
  screenInner: { flex: 1, width: '100%', maxWidth: CONTENT_MAX, backgroundColor: colors.bg },

  header: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingVertical: 10, gap: 8 },
  headerBtn: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', ...shadow.sm },
  headerTitle: { fontFamily: font.semiBold, fontSize: 18, color: colors.ink },
  headerSub: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: -2 },

  rating: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: colors.warningBg, paddingVertical: 2, paddingHorizontal: 8, borderRadius: radius.pill, alignSelf: 'flex-start' },
  ratingText: { fontFamily: font.semiBold, fontSize: 12, color: colors.ink },
  ratingCount: { fontFamily: font.regular, fontSize: 11, color: colors.inkSoft },

  btn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 15, paddingHorizontal: 22, borderRadius: radius.pill },
  btnText: { fontFamily: font.semiBold, fontSize: 15 },

  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 8, paddingHorizontal: 14, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  chipText: { fontFamily: font.medium, fontSize: 13, color: colors.inkSoft },

  iconBtn: { width: 42, height: 42, borderRadius: 21, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', ...shadow.sm },
  dot: { position: 'absolute', top: 9, right: 10, width: 8, height: 8, borderRadius: 4, backgroundColor: colors.primary, borderWidth: 1.5, borderColor: '#fff' },

  addBtn: { minWidth: 92, height: 38, paddingHorizontal: 14, borderRadius: radius.sm, borderWidth: 1.5, borderColor: colors.primary, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', flexDirection: 'row', gap: 4, ...shadow.sm },
  addText: { fontFamily: font.bold, fontSize: 13, color: colors.primary, letterSpacing: 0.4 },
  addPlus: { marginRight: -4 },
  customisable: { position: 'absolute', bottom: -15, alignSelf: 'center', fontFamily: font.regular, fontSize: 9, color: colors.muted },
  stepper: { flexDirection: 'row', alignItems: 'center', height: 38, borderRadius: radius.sm, backgroundColor: colors.primary, overflow: 'hidden', ...shadow.sm },
  stepBtn: { width: 34, height: '100%', alignItems: 'center', justifyContent: 'center' },
  stepQty: { fontFamily: font.bold, fontSize: 14, color: colors.onPrimary, minWidth: 22, textAlign: 'center' },

  sectionRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, marginTop: 22, marginBottom: 12 },
  sectionTitle: { fontFamily: font.bold, fontSize: 18, color: colors.ink },
  sectionAction: { fontFamily: font.semiBold, fontSize: 13, color: colors.primary },

  card: { backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, padding: 16, ...shadow.sm },

  empty: { alignItems: 'center', paddingHorizontal: 36, paddingVertical: 60 },
  emptyIcon: { width: 92, height: 92, borderRadius: 46, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  emptyTitle: { fontFamily: font.semiBold, fontSize: 18, color: colors.ink, textAlign: 'center' },
  emptyText: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft, textAlign: 'center', marginTop: 6, lineHeight: 20 },

  listRow: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 14, paddingHorizontal: 16, borderBottomWidth: 1, borderBottomColor: colors.border, backgroundColor: colors.surface },
  listIcon: { width: 36, height: 36, borderRadius: 11, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center' },
  listLabel: { fontFamily: font.medium, fontSize: 14.5, color: colors.ink },
  listValue: { fontFamily: font.regular, fontSize: 12, color: colors.inkSoft, marginTop: 1 },

  toastWrap: { position: 'absolute', left: 0, right: 0, bottom: 110, alignItems: 'center', paddingHorizontal: 24 },
  toast: { backgroundColor: colors.ink, paddingVertical: 11, paddingHorizontal: 18, borderRadius: radius.pill, ...shadow.lg },
  toastText: { fontFamily: font.medium, fontSize: 13.5, color: '#fff' },
});
