import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import Avatar from '../components/Avatar';
import ScreenHeader from '../components/ScreenHeader';
import { apiPostForm } from '../config/api';
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

function StatusPill({ label, on }) {
  return (
    <View style={[styles.statusPill, { backgroundColor: on ? colors.successBg : colors.dangerBg }]}>
      <Ionicons
        name={on ? 'checkmark-circle' : 'close-circle'}
        size={13}
        color={on ? colors.success : colors.danger}
      />
      <Text style={[styles.statusPillText, { color: on ? colors.success : colors.danger }]}>{label}</Text>
    </View>
  );
}

function ChangePasswordCard() {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState(null); // { text, isError }

  const onSubmit = async () => {
    setMessage(null);
    if (!currentPassword || !newPassword || !confirmPassword) {
      setMessage({ text: 'Fill in all three fields', isError: true });
      return;
    }
    if (newPassword.length < 6) {
      setMessage({ text: 'New password must be at least 6 characters', isError: true });
      return;
    }
    if (newPassword !== confirmPassword) {
      setMessage({ text: 'New passwords do not match', isError: true });
      return;
    }
    setSubmitting(true);
    try {
      const res = await apiPostForm('/admin/auth.php', {
        action: 'changePassword',
        currentPassword,
        newPassword,
      });
      if (!res.success) throw new Error(res.message || 'Could not change password');
      setMessage({ text: 'Password changed successfully', isError: false });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (e) {
      setMessage({ text: e.message, isError: true });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <View style={[styles.card, shadow.sm, styles.section]}>
      <Text style={styles.cardTitle}>Change Password</Text>
      <TextInput
        style={styles.input}
        placeholder="Current password"
        placeholderTextColor={colors.muted}
        secureTextEntry
        value={currentPassword}
        onChangeText={setCurrentPassword}
      />
      <TextInput
        style={styles.input}
        placeholder="New password"
        placeholderTextColor={colors.muted}
        secureTextEntry
        value={newPassword}
        onChangeText={setNewPassword}
      />
      <TextInput
        style={styles.input}
        placeholder="Confirm new password"
        placeholderTextColor={colors.muted}
        secureTextEntry
        value={confirmPassword}
        onChangeText={setConfirmPassword}
      />
      {message ? (
        <Text style={[styles.formMessage, { color: message.isError ? colors.danger : colors.success }]}>
          {message.text}
        </Text>
      ) : null}
      <Pressable
        style={({ pressed }) => [styles.saveButton, pressed && { opacity: 0.9 }]}
        onPress={onSubmit}
        disabled={submitting}
      >
        {submitting ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.saveButtonText}>Update Password</Text>}
      </Pressable>
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

        <Text style={styles.sectionTitle}>Restaurant</Text>
        <View style={[styles.card, shadow.sm]}>
          <Row icon="person-circle-outline" label="Owner" value={user?.owner_name} />
          <View style={styles.divider} />
          <Row icon="call-outline" label="Phone" value={user?.phone} />
          <View style={styles.divider} />
          <Row icon="location-outline" label="Address" value={user?.address} />
        </View>

        <Text style={styles.sectionTitle}>Order Types</Text>
        <View style={[styles.card, shadow.sm, styles.pillsWrap]}>
          <StatusPill label="Delivery" on={user?.enable_delivery == 1} />
          <StatusPill label="Takeaway" on={user?.enable_takeaway == 1} />
          <StatusPill label="Dine-in" on={user?.enable_dinein == 1} />
          <StatusPill label="Cash on Delivery" on={user?.cod_enabled == 1} />
        </View>

        <Text style={styles.sectionTitle}>Tax</Text>
        <View style={[styles.card, shadow.sm]}>
          <Row icon="pricetag-outline" label={user?.tax_name || 'GST'} value={user?.enable_gst == 1 ? `${user?.tax_percent ?? 0}%` : 'Disabled'} />
        </View>

        <Text style={styles.sectionTitle}>Security</Text>
        <ChangePasswordCard />

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
  sectionTitle: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.muted,
    marginTop: spacing.lg,
    marginBottom: spacing.sm,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.lg,
  },
  section: {
    padding: spacing.lg,
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
  pillsWrap: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    paddingVertical: spacing.lg,
  },
  statusPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 6,
  },
  statusPillText: {
    fontFamily: font.semiBold,
    fontSize: 12,
  },
  cardTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
    marginBottom: spacing.md,
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
    marginBottom: spacing.sm,
  },
  formMessage: {
    fontFamily: font.medium,
    fontSize: 12.5,
    marginBottom: spacing.sm,
  },
  saveButton: {
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 13,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.xs,
  },
  saveButtonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 14,
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
