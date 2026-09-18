import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { apiPostForm } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius } from '../theme';

const TYPES = [
  { key: 'dinein', field: 'enable_dinein', label: 'Dine-in', icon: 'restaurant-outline' },
  { key: 'delivery', field: 'enable_delivery', label: 'Delivery', icon: 'bicycle-outline' },
];

// Quick on/off switches for the two order types the owner most often needs
// to flip mid-shift (kitchen swamped, no rider available, ...). Only the
// owner account can see or use these — staff sessions never get
// user_type 'admin'/'branch_admin' — mirroring who's allowed to touch this
// setting on the website (PERMISSION_MANAGE_SETTINGS is admin-only there too).
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
          <View key={type.key} style={styles.pillCol}>
            <Pressable
              style={({ pressed }) => [styles.pill, isOn ? styles.pillOn : styles.pillOff, pressed && { opacity: 0.85 }]}
              onPress={() => onToggle(type)}
              disabled={busy}
            >
              {busy ? (
                <ActivityIndicator size="small" color={isOn ? colors.primary : '#fff'} />
              ) : (
                <Ionicons name={type.icon} size={13} color={isOn ? colors.primary : 'rgba(255,255,255,0.8)'} />
              )}
              <Text style={[styles.label, isOn ? styles.labelOn : styles.labelOff]}>{type.label}</Text>
              <View style={[styles.dot, isOn ? styles.dotOn : styles.dotOff]} />
            </Pressable>
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
  },
  pillCol: {
    alignItems: 'flex-end',
  },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    paddingVertical: 6,
    paddingHorizontal: 10,
    borderRadius: radius.pill,
    minWidth: 100,
  },
  pillOn: {
    backgroundColor: '#fff',
  },
  pillOff: {
    backgroundColor: 'rgba(255,255,255,0.14)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.3)',
  },
  label: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    flex: 1,
  },
  labelOn: {
    color: colors.ink,
  },
  labelOff: {
    color: 'rgba(255,255,255,0.85)',
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  dotOn: {
    backgroundColor: colors.success,
  },
  dotOff: {
    backgroundColor: 'rgba(255,255,255,0.5)',
  },
  errorText: {
    fontFamily: font.medium,
    fontSize: 10,
    color: '#fff',
    marginTop: 2,
  },
});
