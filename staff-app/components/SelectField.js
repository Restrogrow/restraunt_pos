import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { colors, font, radius, spacing } from '../theme';
import PressableScale from './ui/PressableScale';
import Sheet from './ui/Sheet';

// A tap-to-open dropdown: looks like a form field and opens its options in a bottom sheet —
// the app's version of the website's <select> fields.
export default function SelectField({ options, value, onChange, placeholder = 'Select', title, icon, compact, style }) {
  const [open, setOpen] = useState(false);
  const selected = options.find((o) => String(o.value) === String(value));

  return (
    <>
      <PressableScale style={[styles.field, compact && styles.compact, style]} onPress={() => setOpen(true)} scale={0.98} accessibilityLabel={title || placeholder}>
        {icon ? <Ionicons name={icon} size={15} color={colors.primary} style={{ marginRight: 6 }} /> : null}
        <Text style={[styles.fieldText, compact && styles.compactText, !selected && !compact && styles.placeholder]} numberOfLines={1}>
          {selected ? selected.label : placeholder}
        </Text>
        <Ionicons name="chevron-down" size={16} color={colors.muted} />
      </PressableScale>

      <Sheet visible={open} onClose={() => setOpen(false)} title={title || placeholder}>
        <ScrollView style={{ maxHeight: 440 }} showsVerticalScrollIndicator={false} contentContainerStyle={styles.list}>
          {options.map((o) => {
            const isSelected = String(o.value) === String(value);
            return (
              <PressableScale
                key={String(o.value)}
                feedback="select"
                style={[styles.option, isSelected && styles.optionOn]}
                onPress={() => {
                  onChange(o.value);
                  setOpen(false);
                }}
              >
                <Text style={[styles.optionText, isSelected && styles.optionTextSelected]} numberOfLines={1}>
                  {o.label}
                </Text>
                {isSelected ? <Ionicons name="checkmark-circle" size={20} color={colors.primary} /> : null}
              </PressableScale>
            );
          })}
          {options.length === 0 ? <Text style={styles.emptyText}>No options</Text> : null}
        </ScrollView>
      </Sheet>
    </>
  );
}

const styles = StyleSheet.create({
  compact: {
    height: 42,
    minHeight: 42,
    paddingVertical: 0,
    paddingHorizontal: 12,
    borderRadius: 12,
    borderWidth: 0,
  },
  compactText: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
  },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    minHeight: 46,
  },
  fieldText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
    marginRight: spacing.sm,
  },
  placeholder: {
    color: colors.muted,
  },
  list: {
    gap: 6,
    paddingBottom: 4,
  },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    minHeight: 50,
    borderRadius: radius.md,
    backgroundColor: colors.bg,
    gap: spacing.sm,
  },
  optionOn: {
    backgroundColor: colors.primaryLight,
  },
  optionText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 14.5,
    color: colors.inkSoft,
  },
  optionTextSelected: {
    color: colors.primary,
    fontFamily: font.semiBold,
  },
  emptyText: {
    textAlign: 'center',
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    paddingVertical: spacing.lg,
  },
});
