import { Ionicons } from '@expo/vector-icons';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import Avatar from '../components/Avatar';
import ScreenHeader from '../components/ScreenHeader';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';

function Row({ icon, label, value }) {
  return (
    <View style={styles.row}>
      <View style={styles.rowIconWrap}>
        <Ionicons name={icon} size={16} color={colors.primary} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.label}>{label}</Text>
        <Text style={styles.value}>{value || '—'}</Text>
      </View>
    </View>
  );
}

export default function SettingsScreen() {
  const { user, logout } = useAuth();

  return (
    <View style={styles.fill}>
      <ScreenHeader
        eyebrow="Account"
        title="Settings"
        right={<Avatar name={user?.username || user?.restaurant_name} />}
      />

      <ScrollView
        style={styles.fill}
        contentContainerStyle={styles.content}
        showsVerticalScrollIndicator={false}
      >
        <View style={[styles.profileCard, shadow.md]}>
          <Avatar
            name={user?.username || user?.restaurant_name}
            size={54}
            bg={colors.primaryLight}
            color={colors.primary}
          />
          <View style={{ marginLeft: spacing.md, flex: 1 }}>
            <Text style={styles.profileName} numberOfLines={1}>{user?.restaurant_name || 'Your Restaurant'}</Text>
            <Text style={styles.profileRole}>{user?.role || 'Administrator'}</Text>
          </View>
        </View>

        <View style={[styles.card, shadow.sm]}>
          <Row icon="person-outline" label="Username" value={user?.username} />
          <View style={styles.divider} />
          <Row icon="mail-outline" label="Email" value={user?.email} />
          <View style={styles.divider} />
          <Row icon="shield-checkmark-outline" label="Role" value={user?.role} />
        </View>

        <Pressable
          style={({ pressed }) => [styles.logoutButton, pressed && { opacity: 0.9 }]}
          onPress={logout}
        >
          <Ionicons name="log-out-outline" size={18} color={colors.danger} />
          <Text style={styles.logoutText}>Log Out</Text>
        </Pressable>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  content: {
    padding: spacing.xl,
    paddingBottom: 120,
  },
  profileCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    marginTop: -spacing.xl,
    marginBottom: spacing.lg,
  },
  profileName: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  profileRole: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 2,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.lg,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
    gap: spacing.md,
  },
  rowIconWrap: {
    width: 34,
    height: 34,
    borderRadius: 11,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  divider: {
    height: 1,
    backgroundColor: colors.border,
  },
  label: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
  },
  value: {
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
    marginTop: 1,
  },
  logoutButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: colors.dangerBg,
    borderRadius: radius.md,
    paddingVertical: 15,
    marginTop: spacing.xl,
  },
  logoutText: {
    color: colors.danger,
    fontFamily: font.semiBold,
    fontSize: 15,
  },
});
