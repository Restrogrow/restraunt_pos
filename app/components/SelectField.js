import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../theme';

// A tap-to-open dropdown: looks like a form field, opens a modal list of
// options — the RN equivalent of the website admin's <select> fields
// (Choose Menu, Subcategory, Item Type, Stock Status).
export default function SelectField({ options, value, onChange, placeholder = 'Select' }) {
  const [open, setOpen] = useState(false);
  const selected = options.find((o) => String(o.value) === String(value));

  return (
    <>
      <Pressable style={styles.field} onPress={() => setOpen(true)}>
        <Text style={[styles.fieldText, !selected && styles.placeholder]} numberOfLines={1}>
          {selected ? selected.label : placeholder}
        </Text>
        <Ionicons name="chevron-down" size={16} color={colors.muted} />
      </Pressable>

      <Modal visible={open} transparent animationType="fade" onRequestClose={() => setOpen(false)}>
        <Pressable style={styles.backdrop} onPress={() => setOpen(false)}>
          <Pressable style={[styles.sheet, shadow.lg]} onPress={() => {}}>
            <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.list}>
              {options.map((o) => {
                const isSelected = String(o.value) === String(value);
                return (
                  <Pressable
                    key={String(o.value)}
                    style={({ pressed }) => [styles.option, pressed && styles.optionPressed]}
                    onPress={() => {
                      onChange(o.value);
                      setOpen(false);
                    }}
                  >
                    <Text style={[styles.optionText, isSelected && styles.optionTextSelected]} numberOfLines={1}>
                      {o.label}
                    </Text>
                    {isSelected ? <Ionicons name="checkmark" size={18} color={colors.primary} /> : null}
                  </Pressable>
                );
              })}
              {options.length === 0 ? <Text style={styles.emptyText}>No options</Text> : null}
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
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
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  sheet: {
    width: '100%',
    maxWidth: 360,
    maxHeight: '70%',
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    overflow: 'hidden',
  },
  list: {
    padding: spacing.sm,
  },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingVertical: 13,
    borderRadius: radius.md,
    gap: spacing.sm,
  },
  optionPressed: {
    backgroundColor: colors.bg,
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
