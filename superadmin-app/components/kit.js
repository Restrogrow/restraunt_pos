import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../theme';
import { initials } from '../utils/format';
import PressableScale from './ui/PressableScale';
import { haptic } from '../utils/haptics';

// Small building blocks every Super Admin screen shares, so the screens read the same.

export const TONES = {
  success: { fg: colors.success, bg: colors.successBg },
  warning: { fg: '#B7791F', bg: colors.warningBg },
  danger: { fg: colors.danger, bg: colors.dangerBg },
  info: { fg: colors.info, bg: colors.infoBg },
  purple: { fg: colors.purple, bg: colors.purpleBg },
  teal: { fg: colors.teal, bg: colors.tealBg },
  primary: { fg: colors.primary, bg: colors.primaryLight },
  muted: { fg: colors.inkSoft, bg: colors.border },
};

export function Pill({ label, tone = 'muted', icon, small }) {
  const t = TONES[tone] || TONES.muted;
  return (
    <View style={[s.pill, { backgroundColor: t.bg }, small && s.pillSmall]}>
      {icon ? <Ionicons name={icon} size={small ? 10 : 12} color={t.fg} /> : <View style={[s.dot, { backgroundColor: t.fg }]} />}
      <Text style={[s.pillText, { color: t.fg }, small && { fontSize: 10.5 }]} numberOfLines={1}>
        {label}
      </Text>
    </View>
  );
}

export function Card({ children, style, onPress, padded = true }) {
  const body = [s.card, shadow.sm, padded && { padding: spacing.lg }, style];
  if (onPress) {
    return (
      <PressableScale onPress={onPress} style={body}>
        {children}
      </PressableScale>
    );
  }
  return <View style={body}>{children}</View>;
}

export function SectionTitle({ title, action, onAction, style }) {
  return (
    <View style={[s.sectionRow, style]}>
      <Text style={s.sectionTitle}>{title}</Text>
      {action ? (
        <PressableScale onPress={onAction} hitSlop={8}>
          <Text style={s.sectionAction}>{action}</Text>
        </PressableScale>
      ) : null}
    </View>
  );
}

export function Initials({ name, size = 42, tone = 'primary' }) {
  const t = TONES[tone] || TONES.primary;
  return (
    <View style={{ width: size, height: size, borderRadius: size * 0.32, backgroundColor: t.bg, alignItems: 'center', justifyContent: 'center' }}>
      <Text style={{ fontFamily: font.bold, fontSize: size * 0.36, color: t.fg }}>{initials(name)}</Text>
    </View>
  );
}

export function Button({ title, onPress, icon, tone = 'primary', variant = 'solid', loading, disabled, style, small }) {
  const t = TONES[tone] || TONES.primary;
  const solid = variant === 'solid';
  const bg = solid ? (tone === 'dark' ? colors.navy : t.fg) : variant === 'soft' ? t.bg : 'transparent';
  const fg = solid ? '#fff' : tone === 'dark' ? colors.navy : t.fg;
  return (
    <PressableScale
      onPress={onPress}
      disabled={disabled || loading}
      style={[
        s.btn,
        small && s.btnSmall,
        { backgroundColor: bg },
        variant === 'outline' && { borderWidth: 1.5, borderColor: t.fg },
        (disabled || loading) && { opacity: 0.55 },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator size="small" color={fg} />
      ) : (
        <>
          {icon ? <Ionicons name={icon} size={small ? 14 : 16} color={fg} /> : null}
          <Text style={[s.btnText, small && { fontSize: 12.5 }, { color: fg }]} numberOfLines={1}>
            {title}
          </Text>
        </>
      )}
    </PressableScale>
  );
}

export function Field({ label, hint, secure, style, inputStyle, multiline, value, ...props }) {
  const [hidden, setHidden] = useState(true);
  return (
    <View style={[{ marginBottom: spacing.md }, style]}>
      {label ? <Text style={s.fieldLabel}>{label}</Text> : null}
      <View style={[s.inputWrap, multiline && { alignItems: 'flex-start' }]}>
        <TextInput
          placeholderTextColor={colors.muted}
          style={[s.input, multiline && { minHeight: 88, paddingTop: 12, textAlignVertical: 'top' }, inputStyle]}
          secureTextEntry={secure && hidden}
          autoCapitalize="none"
          autoCorrect={false}
          multiline={multiline}
          value={value == null ? '' : String(value)}
          {...props}
        />
        {secure ? (
          <PressableScale onPress={() => setHidden((h) => !h)} hitSlop={10} style={{ paddingHorizontal: 12 }}>
            <Ionicons name={hidden ? 'eye-outline' : 'eye-off-outline'} size={18} color={colors.muted} />
          </PressableScale>
        ) : null}
      </View>
      {hint ? <Text style={s.hint}>{hint}</Text> : null}
    </View>
  );
}

// A row with an icon, title / subtitle and something on the right (value, chevron, switch).
export function Row({ icon, iconTone = 'muted', title, subtitle, value, onPress, right, chevron, last, danger }) {
  const t = TONES[danger ? 'danger' : iconTone] || TONES.muted;
  const inner = (
    <View style={[s.row, !last && s.rowBorder]}>
      {icon ? (
        <View style={[s.rowIcon, { backgroundColor: t.bg }]}>
          <Ionicons name={icon} size={17} color={t.fg} />
        </View>
      ) : null}
      <View style={{ flex: 1 }}>
        <Text style={[s.rowTitle, danger && { color: colors.danger }]} numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text style={s.rowSub} numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {value !== undefined && value !== null ? (
        <Text style={s.rowValue} numberOfLines={1}>
          {value}
        </Text>
      ) : null}
      {right}
      {chevron ? <Ionicons name="chevron-forward" size={16} color={colors.muted} /> : null}
    </View>
  );
  return onPress ? <PressableScale onPress={onPress}>{inner}</PressableScale> : inner;
}

export function ToggleRow({ title, subtitle, icon, iconTone, value, onChange, busy, last }) {
  return (
    <Row
      icon={icon}
      iconTone={iconTone}
      title={title}
      subtitle={subtitle}
      last={last}
      right={
        busy ? (
          <ActivityIndicator size="small" color={colors.primary} />
        ) : (
          <Switch
            value={!!value}
            onValueChange={(v) => {
              haptic.select();
              onChange(v);
            }}
            trackColor={{ false: '#DDD8D4', true: '#FFB4A3' }}
            thumbColor={value ? colors.primary : '#fff'}
            {...{ activeThumbColor: colors.primary }}
          />
        )
      }
    />
  );
}

// Horizontal filter chips (status tabs, ranges).
export function Chips({ options, value, onChange, style }) {
  return (
    <View style={[s.chips, style]}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <PressableScale key={String(o.value)} onPress={() => onChange(o.value)} style={[s.chip, on && s.chipOn]}>
            <Text style={[s.chipText, on && s.chipTextOn]} numberOfLines={1}>
              {o.label}
              {o.count !== undefined && o.count !== null ? `  ${o.count}` : ''}
            </Text>
          </PressableScale>
        );
      })}
    </View>
  );
}

// A two-up / three-up metric tile for dense dashboards.
export function Metric({ label, value, sub, icon, tone = 'primary', style, onPress }) {
  const t = TONES[tone] || TONES.primary;
  const Box = onPress ? PressableScale : View;
  return (
    <Box onPress={onPress} style={[s.metric, shadow.sm, style]}>
      <View style={s.metricTop}>
        <View style={[s.metricIcon, { backgroundColor: t.bg }]}>
          <Ionicons name={icon} size={15} color={t.fg} />
        </View>
        <Text style={s.metricLabel} numberOfLines={1}>
          {label}
        </Text>
      </View>
      <Text style={s.metricValue} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.7}>
        {value}
      </Text>
      {sub ? (
        <Text style={s.metricSub} numberOfLines={1}>
          {sub}
        </Text>
      ) : null}
      {onPress ? <Ionicons name="chevron-forward" size={14} color={colors.muted} style={s.metricGo} /> : null}
    </Box>
  );
}

export function HeaderIcon({ icon, onPress, badge, label }) {
  return (
    <PressableScale onPress={onPress} style={s.headerIcon} hitSlop={6} accessibilityLabel={label}>
      <Ionicons name={icon} size={19} color="#fff" />
      {badge ? (
        <View style={s.headerBadge}>
          <Text style={s.headerBadgeText}>{badge > 99 ? '99+' : badge}</Text>
        </View>
      ) : null}
    </PressableScale>
  );
}

const s = StyleSheet.create({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 5,
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 4,
  },
  pillSmall: { paddingHorizontal: 7, paddingVertical: 2.5 },
  dot: { width: 6, height: 6, borderRadius: 3 },
  pillText: { fontFamily: font.semiBold, fontSize: 11.5 },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg },
  sectionRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: spacing.xl, marginBottom: spacing.md },
  sectionTitle: { fontFamily: font.bold, fontSize: 15.5, color: colors.ink },
  sectionAction: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.primary },
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 7,
    height: 48,
    borderRadius: 14,
    paddingHorizontal: 18,
  },
  btnSmall: { height: 36, borderRadius: 11, paddingHorizontal: 12, gap: 5 },
  btnText: { fontFamily: font.semiBold, fontSize: 14 },
  fieldLabel: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft, marginBottom: 6 },
  inputWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 13,
  },
  input: {
    flex: 1,
    minHeight: 48,
    paddingHorizontal: 14,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
    outlineStyle: 'none',
  },
  hint: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, marginTop: 5 },
  row: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, paddingVertical: 13 },
  rowBorder: { borderBottomWidth: 1, borderBottomColor: colors.border },
  rowIcon: { width: 34, height: 34, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  rowTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  rowSub: { fontFamily: font.regular, fontSize: 12, color: colors.muted, marginTop: 1, lineHeight: 17 },
  rowValue: { fontFamily: font.semiBold, fontSize: 13, color: colors.inkSoft, maxWidth: '45%' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { paddingHorizontal: 13, paddingVertical: 8, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  chipOn: { backgroundColor: colors.navy, borderColor: colors.navy },
  chipText: { fontFamily: font.semiBold, fontSize: 12, color: colors.inkSoft },
  chipTextOn: { color: '#fff' },
  metric: { flexGrow: 1, flexBasis: '46%', backgroundColor: colors.surface, borderRadius: radius.lg, padding: 14 },
  metricTop: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 10 },
  metricIcon: { width: 28, height: 28, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  metricLabel: { flex: 1, fontFamily: font.medium, fontSize: 11.5, color: colors.muted },
  metricValue: { fontFamily: font.bold, fontSize: 21, color: colors.ink },
  metricGo: { position: 'absolute', top: 16, right: 12 },
  metricSub: { fontFamily: font.medium, fontSize: 11, color: colors.muted, marginTop: 2 },
  headerIcon: {
    width: 40,
    height: 40,
    borderRadius: 13,
    backgroundColor: 'rgba(255,255,255,0.12)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  headerBadge: {
    position: 'absolute',
    top: -4,
    right: -4,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.navy,
  },
  headerBadgeText: { fontFamily: font.bold, fontSize: 9.5, color: '#fff' },
});
