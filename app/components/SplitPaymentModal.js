import { Ionicons } from '@expo/vector-icons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../theme';

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

// Mirrors the website's POS "collect payment" modal: tap one method and it
// auto-fills the full total; tap a second and both become editable amount
// inputs so the total can be split across methods (e.g. part Cash, part
// Card). Confirms with a '+'-joined method string (e.g. "Cash+Card") plus a
// human-readable breakdown line — the same shape the website sends.
export default function SplitPaymentModal({ visible, onClose, onConfirm, total, currency = '₹', methods, submitting = false }) {
  const [selected, setSelected] = useState([]); // method names, in tap order
  const [amounts, setAmounts] = useState({}); // { [method]: "12.50" }

  useEffect(() => {
    if (visible) {
      setSelected([]);
      setAmounts({});
    }
  }, [visible]);

  const entered = round2(selected.reduce((s, m) => s + (Number(amounts[m]) || 0), 0));
  const due = round2(Math.max(total - entered, 0));
  const change = round2(Math.max(entered - total, 0));

  const toggleMethod = (name) => {
    setSelected((prev) => {
      if (prev.includes(name)) {
        setAmounts((a) => {
          const next = { ...a };
          delete next[name];
          return next;
        });
        return prev.filter((m) => m !== name);
      }
      const next = [...prev, name];
      const remaining = round2(Math.max(total - entered, 0));
      setAmounts((a) => ({ ...a, [name]: remaining > 0 ? String(remaining) : String(total) }));
      return next;
    });
  };

  const confirm = () => {
    if (selected.length === 0) return;
    const withAmounts = selected.filter((m) => Number(amounts[m]) > 0);
    const paymentMethod = (withAmounts.length > 0 ? withAmounts : selected).join('+');
    const status = entered <= 0 ? 'Pending' : Math.abs(entered - total) < 0.01 ? 'Paid' : entered < total ? 'Partially Paid' : 'Paid';
    const breakdown =
      withAmounts.length > 1
        ? withAmounts.map((m) => `${m} ${currency}${Number(amounts[m]).toFixed(2)}`).join(', ')
        : null;
    onConfirm({ paymentMethod, status, breakdown, entered, due, change });
  };

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <Pressable style={styles.backdrop} onPress={onClose}>
        <Pressable style={[styles.sheet, shadow.lg]} onPress={() => {}}>
          <Text style={styles.title}>Collect Payment</Text>
          <Text style={styles.totalDue}>{currency}{total.toFixed(2)}</Text>

          <ScrollView style={{ maxHeight: 320 }} showsVerticalScrollIndicator={false}>
            <View style={styles.methodGrid}>
              {methods.map((name) => {
                const isSelected = selected.includes(name);
                return (
                  <Pressable
                    key={name}
                    style={[styles.methodChip, isSelected && styles.methodChipSelected]}
                    onPress={() => toggleMethod(name)}
                  >
                    {isSelected ? <Ionicons name="checkmark-circle" size={15} color="#fff" style={{ marginRight: 4 }} /> : null}
                    <Text style={[styles.methodChipText, isSelected && styles.methodChipTextSelected]}>{name}</Text>
                  </Pressable>
                );
              })}
            </View>

            {selected.length >= 2 ? (
              <View style={styles.amountsBox}>
                <Text style={styles.amountsHint}>Split the total across methods</Text>
                {selected.map((m) => (
                  <View key={m} style={styles.amountRow}>
                    <Text style={styles.amountLabel}>{m}</Text>
                    <View style={styles.amountInputWrap}>
                      <Text style={styles.amountCurrency}>{currency}</Text>
                      <TextInput
                        style={styles.amountInput}
                        keyboardType="decimal-pad"
                        value={amounts[m] ?? ''}
                        onChangeText={(v) => setAmounts((a) => ({ ...a, [m]: v }))}
                        placeholder="0"
                        placeholderTextColor={colors.muted}
                      />
                    </View>
                  </View>
                ))}
              </View>
            ) : null}
          </ScrollView>

          {selected.length > 0 ? (
            <View style={styles.summaryRow}>
              {due > 0 ? (
                <Text style={styles.summaryDue}>Due: {currency}{due.toFixed(2)}</Text>
              ) : change > 0 ? (
                <Text style={styles.summaryChange}>Change: {currency}{change.toFixed(2)}</Text>
              ) : (
                <Text style={styles.summaryPaid}>Fully paid</Text>
              )}
            </View>
          ) : null}

          <View style={styles.actionsRow}>
            <Pressable style={styles.cancelButton} onPress={onClose} disabled={submitting}>
              <Text style={styles.cancelButtonText}>Cancel</Text>
            </Pressable>
            <Pressable
              style={[styles.confirmButton, (selected.length === 0 || submitting) && styles.confirmButtonDisabled]}
              onPress={confirm}
              disabled={selected.length === 0 || submitting}
            >
              {submitting ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <Text style={styles.confirmButtonText}>Confirm Payment</Text>
              )}
            </Pressable>
          </View>
        </Pressable>
      </Pressable>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  sheet: {
    width: '100%',
    maxWidth: 380,
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  title: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
    textAlign: 'center',
  },
  totalDue: {
    fontFamily: font.bold,
    fontSize: 28,
    color: colors.primary,
    textAlign: 'center',
    marginTop: 4,
    marginBottom: spacing.md,
  },
  methodGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    justifyContent: 'center',
  },
  methodChip: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.pill,
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  methodChipSelected: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  methodChipText: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.inkSoft,
  },
  methodChipTextSelected: {
    color: '#fff',
    fontFamily: font.semiBold,
  },
  amountsBox: {
    marginTop: spacing.lg,
    gap: spacing.sm,
  },
  amountsHint: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginBottom: 2,
  },
  amountRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  amountLabel: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  amountInputWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.sm,
  },
  amountCurrency: {
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.muted,
    marginRight: 2,
  },
  amountInput: {
    width: 80,
    paddingVertical: 8,
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
    textAlign: 'right',
  },
  summaryRow: {
    alignItems: 'center',
    marginTop: spacing.md,
  },
  summaryDue: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.warning,
  },
  summaryChange: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.info,
  },
  summaryPaid: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.success,
  },
  actionsRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.lg,
  },
  cancelButton: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.border,
    borderRadius: radius.md,
    paddingVertical: 13,
  },
  cancelButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.inkSoft,
  },
  confirmButton: {
    flex: 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 13,
  },
  confirmButtonDisabled: {
    opacity: 0.5,
  },
  confirmButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: '#fff',
  },
});
