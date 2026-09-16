import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import DatePickerModal from './DatePickerModal';
import SelectField from './SelectField';
import { EmptyState, ErrorState, LoadingState } from './ScreenState';

const DISCOUNT_TYPES = [
  { value: 'percent', label: 'Percent off (%)' },
  { value: 'flat', label: 'Flat amount off' },
];

function toDateKey(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function formatDate(dateStr) {
  if (!dateStr) return null;
  const d = new Date(dateStr);
  return isNaN(d.getTime()) ? null : d;
}

// Manages coupons directly from the app — mirrors the website dashboard's
// Coupons page (main/views/dashboard.php + coupon_operations.php), which is
// otherwise the only place they could be created/edited.
export default function CouponsModal({ visible, onClose }) {
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const currency = user?.currency_symbol || '₹';

  const [coupons, setCoupons] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [code, setCode] = useState('');
  const [discountType, setDiscountType] = useState('percent');
  const [discountValue, setDiscountValue] = useState('');
  const [minOrder, setMinOrder] = useState('');
  const [maxUses, setMaxUses] = useState('');
  const [validFrom, setValidFrom] = useState(null);
  const [validUntil, setValidUntil] = useState(null);
  const [description, setDescription] = useState('');
  const [saving, setSaving] = useState(false);
  const [datePickerTarget, setDatePickerTarget] = useState(null); // 'from' | 'until' | null
  const [datePickerViewDate, setDatePickerViewDate] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    apiGet('/api/get_admin_coupons.php')
      .then((res) => {
        if (!res.success) throw new Error(res.message || 'Could not load coupons');
        setCoupons(res.coupons || []);
      })
      .catch((e) => setError(e.message || 'Could not load coupons'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (visible) load();
  }, [visible, load]);

  const resetForm = () => {
    setEditingId(null);
    setCode('');
    setDiscountType('percent');
    setDiscountValue('');
    setMinOrder('');
    setMaxUses('');
    setValidFrom(null);
    setValidUntil(null);
    setDescription('');
  };

  const openAddForm = () => {
    resetForm();
    setFormOpen(true);
  };

  const openEditForm = (c) => {
    setEditingId(c.id);
    setCode(c.coupon_code);
    setDiscountType(c.discount_type);
    setDiscountValue(String(c.discount_value));
    setMinOrder(Number(c.minimum_order_amount) > 0 ? String(c.minimum_order_amount) : '');
    setMaxUses(Number(c.max_uses) > 0 ? String(c.max_uses) : '');
    setValidFrom(formatDate(c.valid_from));
    setValidUntil(formatDate(c.valid_until));
    setDescription(c.description || '');
    setFormOpen(true);
  };

  const saveCoupon = async () => {
    if (!code.trim() || !discountValue.trim()) {
      Alert.alert('Missing info', 'Coupon code and discount value are required');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        action: editingId ? 'update' : 'add',
        coupon_code: code.trim(),
        discount_type: discountType,
        discount_value: discountValue.trim(),
        minimum_order_amount: minOrder.trim() || '0',
        max_uses: maxUses.trim() || '0',
        valid_from: validFrom ? toDateKey(validFrom) : '',
        valid_until: validUntil ? toDateKey(validUntil) : '',
        description: description.trim(),
      };
      if (editingId) payload.id = editingId;
      const res = await apiPostForm('/controllers/coupon_operations.php', payload);
      if (!res.success) throw new Error(res.message || 'Could not save coupon');
      setFormOpen(false);
      load();
    } catch (e) {
      Alert.alert('Could not save coupon', e.message);
    } finally {
      setSaving(false);
    }
  };

  const deleteCoupon = (c) => {
    Alert.alert('Delete coupon', `Delete ${c.coupon_code}? This can't be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          try {
            const res = await apiPostForm('/controllers/coupon_operations.php', { action: 'delete', id: c.id });
            if (!res.success) throw new Error(res.message || 'Could not delete coupon');
            load();
          } catch (e) {
            Alert.alert('Could not delete coupon', e.message);
          }
        },
      },
    ]);
  };

  const toggleActive = async (c) => {
    const nextActive = c.is_active == 1 ? 0 : 1;
    setCoupons((prev) => prev.map((x) => (x.id === c.id ? { ...x, is_active: nextActive } : x)));
    try {
      const res = await apiPostForm('/controllers/coupon_operations.php', { action: 'toggle', id: c.id, is_active: nextActive });
      if (!res.success) throw new Error(res.message || 'Could not update coupon');
    } catch (e) {
      setCoupons((prev) => prev.map((x) => (x.id === c.id ? { ...x, is_active: c.is_active } : x)));
      Alert.alert('Could not update coupon', e.message);
    }
  };

  return (
    <Modal visible={visible} animationType="slide" transparent={Platform.OS === 'web'} onRequestClose={onClose}>
      <View style={styles.backdrop}>
        <View style={[styles.panel, { paddingTop: insets.top }]}>
          <View style={styles.header}>
            <Pressable style={styles.iconButton} onPress={onClose} hitSlop={8}>
              <Ionicons name="close" size={20} color={colors.ink} />
            </Pressable>
            <Text style={styles.headerTitle}>Coupons</Text>
            <Pressable style={styles.iconButton} onPress={openAddForm} hitSlop={8}>
              <Ionicons name="add" size={22} color={colors.primary} />
            </Pressable>
          </View>

          {loading ? (
            <LoadingState />
          ) : error ? (
            <ErrorState message={error} />
          ) : coupons.length === 0 ? (
            <EmptyState icon="pricetag-outline" title="No coupons yet" subtitle="Tap + to create your first coupon" />
          ) : (
            <ScrollView contentContainerStyle={styles.list} showsVerticalScrollIndicator={false}>
              {coupons.map((c) => {
                const usesLabel = Number(c.max_uses) > 0 ? `${c.current_uses}/${c.max_uses} used` : `${c.current_uses} used`;
                const discountLabel = c.discount_type === 'percent' ? `${c.discount_value}% off` : `${currency}${c.discount_value} off`;
                return (
                  <View key={c.id} style={[styles.couponCard, shadow.sm]}>
                    <View style={styles.couponTop}>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.couponCode}>{c.coupon_code}</Text>
                        <Text style={styles.couponMeta}>{discountLabel} · {usesLabel}</Text>
                        {Number(c.minimum_order_amount) > 0 ? (
                          <Text style={styles.couponMeta}>Min order {currency}{c.minimum_order_amount}</Text>
                        ) : null}
                        {c.valid_until ? (
                          <Text style={styles.couponMeta}>Expires {new Date(c.valid_until).toLocaleDateString()}</Text>
                        ) : null}
                      </View>
                      <Pressable onPress={() => toggleActive(c)} style={[styles.activePill, { backgroundColor: c.is_active == 1 ? colors.successBg : colors.border }]}>
                        <Text style={[styles.activePillText, { color: c.is_active == 1 ? colors.success : colors.muted }]}>
                          {c.is_active == 1 ? 'Active' : 'Inactive'}
                        </Text>
                      </Pressable>
                    </View>
                    <View style={styles.couponActions}>
                      <Pressable style={styles.couponActionButton} onPress={() => openEditForm(c)}>
                        <Ionicons name="pencil" size={14} color={colors.inkSoft} />
                        <Text style={styles.couponActionText}>Edit</Text>
                      </Pressable>
                      <Pressable style={styles.couponActionButton} onPress={() => deleteCoupon(c)}>
                        <Ionicons name="trash-outline" size={14} color={colors.danger} />
                        <Text style={[styles.couponActionText, { color: colors.danger }]}>Delete</Text>
                      </Pressable>
                    </View>
                  </View>
                );
              })}
            </ScrollView>
          )}
        </View>
      </View>

      {/* Add/Edit form */}
      <Modal visible={formOpen} animationType="slide" transparent onRequestClose={() => setFormOpen(false)}>
        <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <Pressable style={styles.formBackdrop} onPress={() => setFormOpen(false)}>
          <Pressable style={[styles.formSheet, shadow.lg]} onPress={() => {}}>
            <ScrollView showsVerticalScrollIndicator={false}>
              <Text style={styles.formTitle}>{editingId ? 'Edit Coupon' : 'New Coupon'}</Text>

              <Text style={styles.fieldLabel}>Coupon Code</Text>
              <TextInput
                style={styles.input}
                placeholder="e.g. WELCOME10"
                placeholderTextColor={colors.muted}
                autoCapitalize="characters"
                value={code}
                onChangeText={setCode}
              />

              <Text style={styles.fieldLabel}>Discount Type</Text>
              <SelectField value={discountType} onChange={setDiscountType} options={DISCOUNT_TYPES} />

              <Text style={styles.fieldLabel}>{discountType === 'percent' ? 'Discount (%)' : `Discount (${currency})`}</Text>
              <TextInput
                style={styles.input}
                placeholder={discountType === 'percent' ? 'e.g. 10' : 'e.g. 50'}
                placeholderTextColor={colors.muted}
                keyboardType="decimal-pad"
                value={discountValue}
                onChangeText={setDiscountValue}
              />

              <View style={styles.rowFields}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.fieldLabel}>Min order (optional)</Text>
                  <TextInput
                    style={styles.input}
                    placeholder={`${currency}0`}
                    placeholderTextColor={colors.muted}
                    keyboardType="decimal-pad"
                    value={minOrder}
                    onChangeText={setMinOrder}
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.fieldLabel}>Max uses (optional)</Text>
                  <TextInput
                    style={styles.input}
                    placeholder="Unlimited"
                    placeholderTextColor={colors.muted}
                    keyboardType="number-pad"
                    value={maxUses}
                    onChangeText={setMaxUses}
                  />
                </View>
              </View>

              <View style={styles.rowFields}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.fieldLabel}>Valid from (optional)</Text>
                  <Pressable
                    style={styles.dateField}
                    onPress={() => { setDatePickerViewDate(validFrom || new Date()); setDatePickerTarget('from'); }}
                  >
                    <Text style={validFrom ? styles.dateFieldText : styles.dateFieldPlaceholder}>
                      {validFrom ? validFrom.toLocaleDateString() : 'Any time'}
                    </Text>
                    <Ionicons name="calendar-outline" size={16} color={colors.muted} />
                  </Pressable>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.fieldLabel}>Valid until (optional)</Text>
                  <Pressable
                    style={styles.dateField}
                    onPress={() => { setDatePickerViewDate(validUntil || new Date()); setDatePickerTarget('until'); }}
                  >
                    <Text style={validUntil ? styles.dateFieldText : styles.dateFieldPlaceholder}>
                      {validUntil ? validUntil.toLocaleDateString() : 'No expiry'}
                    </Text>
                    <Ionicons name="calendar-outline" size={16} color={colors.muted} />
                  </Pressable>
                </View>
              </View>

              <Text style={styles.fieldLabel}>Description (optional)</Text>
              <TextInput
                style={styles.input}
                placeholder="Shown to staff, e.g. Diwali offer"
                placeholderTextColor={colors.muted}
                value={description}
                onChangeText={setDescription}
              />

              <View style={styles.formActions}>
                <Pressable style={styles.cancelButton} onPress={() => setFormOpen(false)} disabled={saving}>
                  <Text style={styles.cancelButtonText}>Cancel</Text>
                </Pressable>
                <Pressable style={styles.confirmButton} onPress={saveCoupon} disabled={saving}>
                  {saving ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.confirmButtonText}>{editingId ? 'Save' : 'Create'}</Text>}
                </Pressable>
              </View>
            </ScrollView>
          </Pressable>
        </Pressable>
        </KeyboardAvoidingView>
      </Modal>

      <DatePickerModal
        visible={!!datePickerTarget}
        viewDate={datePickerViewDate}
        selectedDate={datePickerTarget === 'from' ? validFrom : validUntil}
        onChangeMonth={(delta) => setDatePickerViewDate((d) => {
          const next = new Date(d || new Date());
          next.setDate(1);
          next.setMonth(next.getMonth() + delta);
          return next;
        })}
        onSelectDay={(day) => {
          if (datePickerTarget === 'from') setValidFrom(day);
          else setValidUntil(day);
          setDatePickerTarget(null);
        }}
        onClose={() => setDatePickerTarget(null)}
      />
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: Platform.select({
    web: { flex: 1, alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.5)' },
    default: { flex: 1 },
  }),
  panel: Platform.select({
    web: { flex: 1, width: '100%', maxWidth: 480, backgroundColor: colors.bg },
    default: { flex: 1, backgroundColor: colors.bg },
  }),
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  iconButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
  },
  headerTitle: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  list: {
    padding: spacing.xl,
    paddingBottom: 60,
    gap: spacing.sm,
  },
  couponCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  couponTop: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
  },
  couponCode: {
    fontFamily: font.bold,
    fontSize: 15,
    color: colors.ink,
    letterSpacing: 0.3,
  },
  couponMeta: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 2,
  },
  activePill: {
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  activePillText: {
    fontFamily: font.semiBold,
    fontSize: 11,
  },
  couponActions: {
    flexDirection: 'row',
    gap: spacing.lg,
    marginTop: spacing.sm,
    paddingTop: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  couponActionButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
  },
  couponActionText: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  formBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  formSheet: {
    width: '100%',
    maxWidth: 420,
    maxHeight: '85%',
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  formTitle: {
    fontFamily: font.semiBold,
    fontSize: 17,
    color: colors.ink,
    marginBottom: spacing.sm,
  },
  fieldLabel: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
    marginBottom: spacing.xs,
    marginTop: spacing.md,
  },
  input: {
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  rowFields: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  dateField: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  dateFieldText: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  dateFieldPlaceholder: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.muted,
  },
  formActions: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.lg,
  },
  cancelButton: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
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
  confirmButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: '#fff',
  },
});
