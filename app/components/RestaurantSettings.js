import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { apiGet, apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import SelectField from './SelectField';
import { feedback } from './ui/Feedback';
import PressableScale from './ui/PressableScale';

// The restaurant settings the website's Settings page has, editable from the app. Each
// section loads the current values and saves only its own fields (api/app_settings.php),
// so nothing else on the restaurant's profile is touched.

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const TIMES = [];
for (let m = 0; m < 24 * 60; m += 30) {
  const h = Math.floor(m / 60);
  const h12 = h % 12 === 0 ? 12 : h % 12;
  TIMES.push(`${String(h12).padStart(2, '0')}:${String(m % 60).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`);
}
TIMES.push('11:59 PM');
const TIME_OPTIONS = TIMES.map((t) => ({ label: t, value: t }));

// a stored time like "9:00 AM" is matched to the list's "09:00 AM"
const normTime = (t, def) => {
  const m = String(t || '').match(/^(\d{1,2}):(\d{2}) (AM|PM)$/);
  return m ? `${m[1].padStart(2, '0')}:${m[2]} ${m[3]}` : def;
};

const SECTION_FIELDS = {
  details: ['restaurant_name', 'owner_name', 'description', 'phone', 'email', 'address', 'google_maps_link'],
  hours: ['opening_hours'],
  ordering: ['enable_delivery', 'enable_takeaway', 'enable_dinein', 'enable_reservations', 'cod_enabled', 'minimum_order_value', 'packaging_charge'],
  delivery: ['delivery_radius_km', 'enable_km_delivery', 'delivery_rate_per_km'],
  tax: ['enable_gst', 'tax_name', 'tax_percent'],
  social: ['instagram_link', 'facebook_link', 'twitter_link', 'youtube_link', 'linkedin_link'],
};

function Field({ label, value, onChangeText, placeholder, keyboardType, multiline, editable, prefix, suffix, autoCapitalize }) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={[styles.inputWrap, !editable && styles.inputOff]}>
        {prefix ? <Text style={styles.affix}>{prefix}</Text> : null}
        <TextInput
          style={[styles.input, multiline && styles.inputMulti]}
          value={value == null ? '' : String(value)}
          onChangeText={onChangeText}
          placeholder={placeholder}
          placeholderTextColor={colors.muted}
          keyboardType={keyboardType}
          multiline={multiline}
          editable={editable}
          autoCapitalize={autoCapitalize}
        />
        {suffix ? <Text style={styles.affix}>{suffix}</Text> : null}
      </View>
    </View>
  );
}

function Toggle({ icon, label, sub, value, onChange, editable, first }) {
  return (
    <View style={[styles.toggleRow, !first && styles.line]}>
      <View style={styles.toggleIcon}>
        <Ionicons name={icon} size={17} color={colors.inkSoft} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.toggleLabel}>{label}</Text>
        {sub ? <Text style={styles.toggleSub}>{sub}</Text> : null}
      </View>
      <Switch
        value={!!value}
        onValueChange={onChange}
        disabled={!editable}
        trackColor={{ false: colors.border, true: '#9BE3BF' }}
        thumbColor={value ? colors.success : '#fff'}
        accessibilityLabel={label}
      />
    </View>
  );
}

export default function RestaurantSettings({ section, editable }) {
  const { user, refresh } = useAuth();
  const cur = user?.currency_symbol || '₹';
  const [form, setForm] = useState(null);
  const [saved, setSaved] = useState(null);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setError('');
    try {
      const res = await apiGet('/api/app_settings.php');
      if (!res?.success) throw new Error(res?.message || 'Could not load settings');
      const s = res.settings || {};
      const hours = {};
      DAYS.forEach((d) => {
        const day = s.opening_hours?.[d] || {};
        hours[d] = { open: s.opening_hours ? !!day.open : true, opening: normTime(day.opening, '09:00 AM'), closing: normTime(day.closing, '11:00 PM') };
      });
      const next = { ...s, opening_hours: hours };
      setForm(next);
      setSaved(next);
    } catch (e) {
      setError(e.message || 'Could not load settings');
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);

  if (error && !form) {
    return (
      <View style={[styles.card, shadow.sm, styles.center]}>
        <Text style={styles.errorText}>{error}</Text>
        <PressableScale style={styles.retry} onPress={load}>
          <Text style={styles.retryText}>Try again</Text>
        </PressableScale>
      </View>
    );
  }
  if (!form) {
    return (
      <View style={[styles.card, shadow.sm, styles.center]}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  const fields = SECTION_FIELDS[section] || [];
  const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));
  const setDay = (d, patch) => setForm((f) => ({ ...f, opening_hours: { ...f.opening_hours, [d]: { ...f.opening_hours[d], ...patch } } }));
  const dirty = fields.some((k) => JSON.stringify(form[k]) !== JSON.stringify(saved[k]));

  const save = async () => {
    setSaving(true);
    try {
      // Only what was changed here: re-sending a whole section from a form loaded earlier would
      // undo anything changed meanwhile elsewhere (e.g. the Dine-in / Delivery switches on Orders).
      const body = {};
      fields.forEach((k) => {
        if (JSON.stringify(form[k]) === JSON.stringify(saved[k])) return;
        const v = form[k];
        if (k === 'opening_hours') body[k] = JSON.stringify(v);
        else if (typeof v === 'boolean') body[k] = v ? 1 : 0;
        else body[k] = v == null ? '' : String(v);
      });
      const res = await apiPostForm('/api/app_settings.php', body);
      if (!res?.success) throw new Error(res?.message || 'Could not save');
      setSaved(form);
      feedback.success('Saved');
      refresh?.();
    } catch (e) {
      feedback.errorFrom(e, 'Could not save');
    } finally {
      setSaving(false);
    }
  };

  const money = { keyboardType: 'decimal-pad', editable };
  let body = null;

  if (section === 'details') {
    body = (
      <View style={[styles.card, shadow.sm]}>
        <Field label="Restaurant name" value={form.restaurant_name} onChangeText={set('restaurant_name')} editable={editable} />
        <Field label="Owner name" value={form.owner_name} onChangeText={set('owner_name')} editable={editable} />
        <Field label="About the restaurant" value={form.description} onChangeText={set('description')} multiline placeholder="A line or two shown on your website" editable={editable} />
        <Field label="Phone" value={form.phone} onChangeText={set('phone')} keyboardType="phone-pad" editable={editable} />
        <Field label="Email" value={form.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" editable={editable} />
        <Field label="Address" value={form.address} onChangeText={set('address')} multiline editable={editable} />
        <Field label="Google Maps link" value={form.google_maps_link} onChangeText={set('google_maps_link')} placeholder="https://maps.google.com/..." autoCapitalize="none" editable={editable} />
      </View>
    );
  } else if (section === 'hours') {
    body = (
      <View style={[styles.card, shadow.sm, { paddingVertical: spacing.xs }]}>
        {DAYS.map((d, i) => {
          const day = form.opening_hours[d];
          return (
            <View key={d} style={[styles.dayRow, i > 0 && styles.line]}>
              <View style={styles.dayHead}>
                <Text style={styles.dayName}>{d.charAt(0).toUpperCase() + d.slice(1)}</Text>
                <Text style={[styles.dayState, { color: day.open ? colors.success : colors.muted }]}>{day.open ? 'Open' : 'Closed'}</Text>
                <Switch
                  value={day.open}
                  onValueChange={(v) => setDay(d, { open: v })}
                  disabled={!editable}
                  trackColor={{ false: colors.border, true: '#9BE3BF' }}
                  thumbColor={day.open ? colors.success : '#fff'}
                  accessibilityLabel={`${d} open`}
                />
              </View>
              {day.open ? (
                <View style={styles.timeRow}>
                  <View style={{ flex: 1 }}>
                    <SelectField compact style={styles.timeField} icon="sunny-outline" title={`${d} opens at`} value={day.opening} onChange={(v) => editable && setDay(d, { opening: v })} options={TIME_OPTIONS} />
                  </View>
                  <Text style={styles.to}>to</Text>
                  <View style={{ flex: 1 }}>
                    <SelectField compact style={styles.timeField} icon="moon-outline" title={`${d} closes at`} value={day.closing} onChange={(v) => editable && setDay(d, { closing: v })} options={TIME_OPTIONS} />
                  </View>
                </View>
              ) : null}
            </View>
          );
        })}
        {editable ? (
          <PressableScale
            style={styles.copyBtn}
            onPress={() => {
              const mon = form.opening_hours.monday;
              setForm((f) => ({ ...f, opening_hours: Object.fromEntries(DAYS.map((d) => [d, { ...mon }])) }));
            }}
          >
            <Ionicons name="copy-outline" size={15} color={colors.primary} />
            <Text style={styles.copyText}>Use Monday's hours for every day</Text>
          </PressableScale>
        ) : null}
      </View>
    );
  } else if (section === 'ordering') {
    const b = (k) => form[k] == 1 || form[k] === true;
    body = (
      <>
        <View style={[styles.card, shadow.sm, { paddingVertical: spacing.xs }]}>
          <Toggle first icon="bicycle-outline" label="Delivery" sub="Customers order for home delivery" value={b('enable_delivery')} onChange={set('enable_delivery')} editable={editable} />
          <Toggle icon="bag-handle-outline" label="Takeaway" sub="Customers order and pick up" value={b('enable_takeaway')} onChange={set('enable_takeaway')} editable={editable} />
          <Toggle icon="restaurant-outline" label="Dine-in" sub="Table QR ordering" value={b('enable_dinein')} onChange={set('enable_dinein')} editable={editable} />
          <Toggle icon="calendar-outline" label="Table bookings" sub="Guests can book a table online" value={b('enable_reservations')} onChange={set('enable_reservations')} editable={editable} />
          <Toggle icon="cash-outline" label="Cash on delivery" sub="Pay when the order arrives" value={b('cod_enabled')} onChange={set('cod_enabled')} editable={editable} />
        </View>
        <View style={[styles.card, shadow.sm, { marginTop: spacing.md }]}>
          <Field label="Minimum order value" prefix={cur} value={form.minimum_order_value} onChangeText={set('minimum_order_value')} placeholder="0 for no minimum" {...money} />
          <Field label="Packaging charge per order" prefix={cur} value={form.packaging_charge} onChangeText={set('packaging_charge')} placeholder="0" {...money} />
        </View>
      </>
    );
  } else if (section === 'delivery') {
    const km = form.enable_km_delivery == 1 || form.enable_km_delivery === true;
    body = (
      <>
        <View style={[styles.card, shadow.sm]}>
          <Field label="Delivery distance" suffix="km" value={form.delivery_radius_km} onChangeText={set('delivery_radius_km')} placeholder="0 for no limit" {...money} />
        </View>
        <View style={[styles.card, shadow.sm, { marginTop: spacing.md, paddingVertical: spacing.xs }]}>
          <Toggle first icon="speedometer-outline" label="Charge by distance" sub="Delivery fee worked out per km" value={km} onChange={set('enable_km_delivery')} editable={editable} />
          {km ? (
            <View style={{ paddingBottom: spacing.sm }}>
              <Field label="Charge per km" prefix={cur} value={form.delivery_rate_per_km} onChangeText={set('delivery_rate_per_km')} placeholder="0" {...money} />
            </View>
          ) : null}
        </View>
      </>
    );
  } else if (section === 'tax') {
    const gst = form.enable_gst == 1 || form.enable_gst === true;
    body = (
      <View style={[styles.card, shadow.sm, { paddingVertical: spacing.xs }]}>
        <Toggle first icon="receipt-outline" label="Add tax to bills" sub="Shown as its own line on the bill" value={gst} onChange={set('enable_gst')} editable={editable} />
        {gst ? (
          <View style={styles.taxRow}>
            <View style={{ flex: 1.3 }}>
              <Field label="Tax name" value={form.tax_name} onChangeText={set('tax_name')} placeholder="GST" editable={editable} />
            </View>
            <View style={{ flex: 1 }}>
              <Field label="Rate" suffix="%" value={form.tax_percent} onChangeText={set('tax_percent')} {...money} />
            </View>
          </View>
        ) : null}
      </View>
    );
  } else if (section === 'social') {
    body = (
      <View style={[styles.card, shadow.sm]}>
        {[
          ['instagram_link', 'Instagram'],
          ['facebook_link', 'Facebook'],
          ['youtube_link', 'YouTube'],
          ['twitter_link', 'X (Twitter)'],
          ['linkedin_link', 'LinkedIn'],
        ].map(([k, label]) => (
          <Field key={k} label={label} value={form[k]} onChangeText={set(k)} placeholder="https://" autoCapitalize="none" keyboardType="url" editable={editable} />
        ))}
      </View>
    );
  }

  return (
    <>
      {body}
      {editable && (dirty || saving) ? (
        <PressableScale style={[styles.saveBtn, saving && styles.saveBtnOff]} onPress={save} disabled={saving}>
          {saving ? <ActivityIndicator color="#fff" /> : <Text style={styles.saveText}>Save changes</Text>}
        </PressableScale>
      ) : null}
    </>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
  },
  center: {
    alignItems: 'center',
    paddingVertical: spacing.xxl,
    gap: spacing.md,
  },
  errorText: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.danger,
    textAlign: 'center',
  },
  retry: {
    backgroundColor: colors.primaryLight,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.lg,
    paddingVertical: 8,
  },
  retryText: {
    fontFamily: font.semiBold,
    color: colors.primary,
  },
  field: {
    paddingVertical: 6,
  },
  fieldLabel: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.inkSoft,
    marginBottom: 6,
  },
  inputWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: 12,
  },
  inputOff: {
    opacity: 0.7,
  },
  input: {
    flex: 1,
    minHeight: 44,
    paddingVertical: 10,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  inputMulti: {
    minHeight: 72,
    textAlignVertical: 'top',
  },
  affix: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.muted,
    marginHorizontal: 2,
  },
  toggleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: 12,
  },
  line: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  toggleIcon: {
    width: 34,
    height: 34,
    borderRadius: 10,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  toggleLabel: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  toggleSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
  },
  dayRow: {
    paddingVertical: 10,
  },
  dayHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  dayName: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.ink,
  },
  dayState: {
    fontFamily: font.semiBold,
    fontSize: 12,
  },
  timeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.sm,
  },
  timeField: {
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
  },
  to: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.muted,
  },
  copyBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 12,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  copyText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  taxRow: {
    flexDirection: 'row',
    gap: spacing.md,
    paddingBottom: spacing.sm,
  },
  saveBtn: {
    marginTop: spacing.lg,
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    height: 50,
    alignItems: 'center',
    justifyContent: 'center',
  },
  saveBtnOff: {
    backgroundColor: '#F2B4A6',
  },
  saveText: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: '#fff',
  },
});
