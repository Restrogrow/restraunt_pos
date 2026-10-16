import { Ionicons } from '@expo/vector-icons';
import { ActivityIndicator, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../../theme';
import PressableScale from '../ui/PressableScale';

// Small building blocks shared by the Loyalty screen's tabs (components/loyalty/*Tab.js).

export const LOYALTY_API = '/api/loyalty_admin.php';

export function Card({ children, style }) {
  return <View style={[s.card, shadow.sm, style]}>{children}</View>;
}

export function SectionTitle({ children, sub, right }) {
  return (
    <View style={s.secRow}>
      <View style={{ flex: 1 }}>
        <Text style={s.secTitle}>{children}</Text>
        {sub ? <Text style={s.secSub}>{sub}</Text> : null}
      </View>
      {right}
    </View>
  );
}

// on/off switch in the app's colours; shows a spinner while the change saves
export function Toggle({ value, onChange, busy, disabled, label }) {
  if (busy) return <ActivityIndicator color={colors.primary} size="small" style={{ width: 44 }} />;
  return (
    <Switch
      value={!!value}
      onValueChange={onChange}
      disabled={disabled}
      accessibilityLabel={label}
      trackColor={{ false: colors.border, true: colors.primaryLight }}
      thumbColor={value ? colors.primary : '#fff'}
    />
  );
}

// a row of numbers: [[value, label], ...]
export function Nums({ items, dark }) {
  return (
    <View style={[s.nums, dark && s.numsDark]}>
      {items.map(([v, l]) => (
        <View key={l} style={{ flex: 1 }}>
          <Text style={[s.numValue, dark && { color: '#fff' }]} numberOfLines={1} adjustsFontSizeToFit>{v}</Text>
          <Text style={[s.numLabel, dark && { color: 'rgba(255,255,255,0.6)' }]} numberOfLines={2}>{l}</Text>
        </View>
      ))}
    </View>
  );
}

export function Note({ icon = 'information-circle', children, tone = 'info' }) {
  const t = { info: [colors.info, colors.infoBg], warn: [colors.warning, colors.warningBg], ok: [colors.success, colors.successBg], muted: [colors.inkSoft, colors.bg] }[tone];
  return (
    <View style={[s.note, { backgroundColor: t[1] }]}>
      <Ionicons name={icon} size={16} color={t[0]} style={{ marginTop: 1 }} />
      <Text style={s.noteText}>{children}</Text>
    </View>
  );
}

export function Field({ label, value, onChangeText, placeholder, suffix, keyboardType = 'numeric' }) {
  return (
    <View style={{ flex: 1, minWidth: 140 }}>
      <Text style={s.fieldLabel}>{label}</Text>
      <View style={s.inputWrap}>
        <TextInput
          style={s.input}
          value={value == null ? '' : String(value)}
          onChangeText={onChangeText}
          placeholder={placeholder}
          placeholderTextColor={colors.muted}
          keyboardType={keyboardType}
          accessibilityLabel={label}
        />
        {suffix ? <Text style={s.suffix}>{suffix}</Text> : null}
      </View>
    </View>
  );
}

export function FieldLabel({ children }) {
  return <Text style={s.fieldLabel}>{children}</Text>;
}

export function Button({ icon, label, onPress, busy, tone = 'primary', disabled, style }) {
  const solid = tone === 'primary';
  return (
    <PressableScale style={[s.btn, solid ? s.btnSolid : s.btnSoft, (disabled || busy) && { opacity: 0.6 }, style]} onPress={onPress} disabled={disabled || busy} accessibilityLabel={label}>
      {busy ? <ActivityIndicator size="small" color={solid ? '#fff' : colors.primary} /> : icon ? <Ionicons name={icon} size={17} color={solid ? '#fff' : colors.primary} /> : null}
      <Text style={[s.btnText, { color: solid ? '#fff' : colors.primary }]}>{label}</Text>
    </PressableScale>
  );
}

export function Pill({ label, tone = 'ok' }) {
  const t = { ok: [colors.success, colors.successBg], off: [colors.inkSoft, colors.border], warn: [colors.warning, colors.warningBg], info: [colors.info, colors.infoBg] }[tone];
  return (
    <View style={[s.pill, { backgroundColor: t[1] }]}>
      <Text style={[s.pillText, { color: t[0] }]}>{label}</Text>
    </View>
  );
}

export function IconBox({ name, tint = colors.primary, bg = colors.primaryLight, size = 38 }) {
  return (
    <View style={[s.iconBox, { width: size, height: size, backgroundColor: bg }]}>
      <Ionicons name={name} size={Math.round(size * 0.5)} color={tint} />
    </View>
  );
}

// segmented chips (day ranges, series switch)
export function Chips({ options, value, onChange, small }) {
  return (
    <View style={s.chips}>
      {options.map(([k, l]) => {
        const on = k === value;
        return (
          <PressableScale key={String(k)} feedback="select" style={[s.chip, small && s.chipSmall, on && s.chipOn]} onPress={() => onChange(k)}>
            <Text style={[s.chipText, on && { color: '#fff' }]}>{l}</Text>
          </PressableScale>
        );
      })}
    </View>
  );
}

export const pct = (a, b) => (b > 0 ? Math.round((a * 100) / b) : 0);
export const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const s = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  secRow: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: spacing.sm,
    marginBottom: spacing.sm,
  },
  secTitle: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  secSub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 1,
    lineHeight: 18,
  },
  nums: {
    flexDirection: 'row',
    gap: spacing.sm,
    paddingTop: spacing.md,
    marginTop: spacing.md,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  numsDark: {
    borderTopColor: 'rgba(255,255,255,0.12)',
  },
  numValue: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
  },
  numLabel: {
    fontFamily: font.regular,
    fontSize: 11,
    color: colors.muted,
    marginTop: 1,
  },
  note: {
    flexDirection: 'row',
    gap: 8,
    borderRadius: radius.md,
    padding: spacing.md,
  },
  noteText: {
    flex: 1,
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.inkSoft,
    lineHeight: 18,
  },
  fieldLabel: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
    marginBottom: 6,
  },
  inputWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
  },
  input: {
    flex: 1,
    minWidth: 0,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  suffix: {
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.muted,
    marginLeft: 6,
  },
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    height: 50,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  btnSolid: {
    backgroundColor: colors.primary,
  },
  btnSoft: {
    backgroundColor: colors.primaryLight,
  },
  btnText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
  },
  pill: {
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 3,
  },
  pillText: {
    fontFamily: font.semiBold,
    fontSize: 11,
  },
  iconBox: {
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  chip: {
    height: 36,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chipSmall: {
    height: 30,
    paddingHorizontal: 11,
  },
  chipOn: {
    backgroundColor: colors.ink,
    borderColor: colors.ink,
  },
  chipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
});
