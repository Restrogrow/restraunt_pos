import { Ionicons } from '@expo/vector-icons';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { pf } from '../config/api';
import { colors, font } from '../theme';
import { Button, Initials } from './kit';
import SearchField from './SearchField';
import PressableScale from './ui/PressableScale';
import Sheet from './ui/Sheet';

let cache = null; // restaurant list, shared by every picker while the app runs

export function useRestaurantOptions() {
  const [rows, setRows] = useState(cache);
  useEffect(() => {
    pf.get('restaurantOptions')
      .then((r) => {
        cache = r.rows;
        setRows(r.rows);
      })
      .catch(() => {});
  }, []);
  return rows;
}

// A field that opens a searchable list of restaurants. `multiple` picks several (branch admins).
// value: restaurant_id (string) or an array of them.
export default function RestaurantPicker({ label = 'Restaurant', value, onChange, multiple, placeholder = 'Choose a restaurant', allowAll }) {
  const rows = useRestaurantOptions();
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [draft, setDraft] = useState([]);

  const byId = useMemo(() => Object.fromEntries((rows || []).map((r) => [r.restaurant_id, r])), [rows]);
  const list = useMemo(() => {
    const t = q.trim().toLowerCase();
    return (rows || []).filter((r) => !t || `${r.restaurant_name} ${r.restaurant_id}`.toLowerCase().includes(t));
  }, [rows, q]);

  const chosen = multiple ? value || [] : value ? [value] : [];
  const summary = !chosen.length
    ? allowAll
      ? 'All restaurants'
      : placeholder
    : multiple && chosen.length > 1
      ? `${chosen.length} restaurants`
      : byId[chosen[0]]?.restaurant_name || chosen[0];

  const show = () => {
    setQ('');
    setDraft(chosen);
    setOpen(true);
  };
  const tap = (rid) => {
    if (!multiple) {
      onChange(rid);
      setOpen(false);
      return;
    }
    setDraft((d) => (d.includes(rid) ? d.filter((x) => x !== rid) : [...d, rid]));
  };

  return (
    <View style={{ marginBottom: 12 }}>
      {label ? <Text style={styles.label}>{label}</Text> : null}
      <PressableScale onPress={show} style={styles.field}>
        <Ionicons name="storefront-outline" size={17} color={colors.muted} />
        <Text style={[styles.value, !chosen.length && !allowAll && { color: colors.muted }]} numberOfLines={1}>
          {summary}
        </Text>
        <Ionicons name="chevron-down" size={16} color={colors.muted} />
      </PressableScale>
      <Sheet visible={open} onClose={() => setOpen(false)} title={multiple ? 'Choose restaurants' : 'Choose a restaurant'} maxHeight={0.9}>
        <SearchField value={q} onChangeText={setQ} placeholder="Search" style={{ backgroundColor: colors.bg, marginBottom: 8 }} />
        {!rows ? <ActivityIndicator color={colors.primary} style={{ marginVertical: 24 }} /> : null}
        <ScrollView style={{ maxHeight: 380 }} keyboardShouldPersistTaps="handled">
          {allowAll && !multiple ? (
            <Option title="All restaurants" on={!value} onPress={() => tap('')} />
          ) : null}
          {list.map((r) => (
            <Option key={r.id} title={r.restaurant_name || r.restaurant_id} sub={r.restaurant_id} on={(multiple ? draft : chosen).includes(r.restaurant_id)} onPress={() => tap(r.restaurant_id)} />
          ))}
          {rows && !list.length ? <Text style={styles.empty}>No match</Text> : null}
        </ScrollView>
        {multiple ? (
          <Button
            title={`Done · ${draft.length} selected`}
            icon="checkmark"
            style={{ marginTop: 12 }}
            onPress={() => {
              onChange(draft);
              setOpen(false);
            }}
          />
        ) : null}
      </Sheet>
    </View>
  );
}

function Option({ title, sub, on, onPress }) {
  return (
    <PressableScale onPress={onPress} style={styles.option}>
      <Initials name={title} size={34} tone={on ? 'primary' : 'muted'} />
      <View style={{ flex: 1 }}>
        <Text style={styles.optTitle} numberOfLines={1}>
          {title}
        </Text>
        {sub ? <Text style={styles.optSub}>{sub}</Text> : null}
      </View>
      <Ionicons name={on ? 'checkmark-circle' : 'ellipse-outline'} size={22} color={on ? colors.primary : colors.border} />
    </PressableScale>
  );
}

const styles = StyleSheet.create({
  label: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft, marginBottom: 6 },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    minHeight: 48,
    paddingHorizontal: 14,
    backgroundColor: colors.bg,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 13,
  },
  value: { flex: 1, fontFamily: font.medium, fontSize: 14, color: colors.ink },
  option: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.border },
  optTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  optSub: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted },
  empty: { fontFamily: font.regular, fontSize: 13, color: colors.muted, textAlign: 'center', paddingVertical: 20 },
});
