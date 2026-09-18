import { useState } from 'react';
import { ActivityIndicator, StyleSheet, Switch, Text, View } from 'react-native';
import { apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font } from '../theme';

const TYPES = [
  { key: 'dinein', field: 'enable_dinein', label: 'Dine-in' },
  { key: 'delivery', field: 'enable_delivery', label: 'Delivery' },
];

// Quick on/off switches for the two order types the owner most often needs
// to flip mid-shift (kitchen swamped, no rider available, ...). Only the
// owner account can see or use these — staff sessions never get
// user_type 'admin'/'branch_admin' — mirroring who's allowed to touch this
// setting on the website (PERMISSION_MANAGE_SETTINGS is admin-only there too).
//
// A real Switch control (same one used elsewhere in Settings), not a
// text-labelled button.
//
// Toggling is a single direct tap with no confirmation step — React
// Native's Alert.alert() is a no-op on the web build (react-native-web
// ships `static alert() {}`), so a confirm-before-disable dialog would
// silently swallow every attempt to turn a type off there.
export default function OrderTypeToggles() {
  const { user, refresh } = useAuth();
  const [busyKey, setBusyKey] = useState(null);
  const [errorKey, setErrorKey] = useState(null);

  if (!user || (user.user_type !== 'admin' && user.user_type !== 'branch_admin')) {
    return null;
  }

  const onToggle = async (type) => {
    const isOn = user[type.field] == 1;
    const nextValue = isOn ? 0 : 1;
    setBusyKey(type.key);
    setErrorKey(null);
    try {
      const res = await apiPostForm('/admin/auth.php', {
        action: 'toggleOrderType',
        type: type.key,
        enabled: nextValue,
      });
      if (!res.success) setErrorKey(type.key);
    } catch (e) {
      setErrorKey(type.key);
    } finally {
      await refresh(true);
      setBusyKey(null);
    }
  };

  return (
    <View style={styles.wrap}>
      {TYPES.map((type) => {
        const isOn = user[type.field] == 1;
        const busy = busyKey === type.key;
        return (
          <View key={type.key} style={styles.row}>
            <Text style={styles.rowLabel}>{type.label}</Text>
            {busy ? (
              <ActivityIndicator size="small" color="#fff" style={styles.spinner} />
            ) : (
              <Switch
                value={isOn}
                onValueChange={() => onToggle(type)}
                trackColor={{ false: 'rgba(255,255,255,0.3)', true: colors.success }}
                thumbColor="#fff"
              />
            )}
            {errorKey === type.key ? <Text style={styles.errorText}>Couldn't update</Text> : null}
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    gap: 6,
    alignItems: 'flex-end',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  rowLabel: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: '#fff',
  },
  spinner: {
    width: 51,
  },
  errorText: {
    fontFamily: font.medium,
    fontSize: 10,
    color: '#fff',
    marginTop: 2,
  },
});
