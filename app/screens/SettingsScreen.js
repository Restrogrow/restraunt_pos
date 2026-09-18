import { Ionicons } from '@expo/vector-icons';
import Constants from 'expo-constants';
import * as ImagePicker from 'expo-image-picker';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import Avatar from '../components/Avatar';
import CouponsModal from '../components/CouponsModal';
import ScreenHeader from '../components/ScreenHeader';
import { getBiometricLockEnabled, setBiometricLockEnabled } from '../config/biometricSettings';
import { getOrderSoundEnabled, setOrderSoundEnabled } from '../config/notificationSettings';
import { apiPostForm, restaurantLogoUrl } from '../config/api';
import { getPrinterSettings, savePrinterSettings } from '../config/printerSettings';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { isBiometricSupported, authenticate } from '../utils/biometricAuth';
import { listPairedPrinters, printToBluetoothPrinter } from '../utils/bluetoothPrinter';
import { buildReceiptEscPos, printToNetworkPrinter } from '../utils/receiptPrinter';

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

function PrinterSettingsCard() {
  const [type, setType] = useState('network');
  const [ip, setIp] = useState('');
  const [port, setPort] = useState('9100');
  const [btAddress, setBtAddress] = useState('');
  const [btName, setBtName] = useState('');
  const [pairedDevices, setPairedDevices] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [message, setMessage] = useState(null); // { text, isError }

  useEffect(() => {
    getPrinterSettings().then((s) => {
      setType(s.type || 'network');
      setIp(s.ip || '');
      setPort(s.port || '9100');
      setBtAddress(s.btAddress || '');
      setBtName(s.btName || '');
    });
  }, []);

  const currentSettings = () => ({
    type,
    ip: ip.trim(),
    port: port.trim() || '9100',
    btAddress,
    btName,
  });

  const onSave = async () => {
    setSaving(true);
    setMessage(null);
    try {
      await savePrinterSettings(currentSettings());
      setMessage({ text: 'Printer settings saved', isError: false });
    } catch (e) {
      setMessage({ text: 'Could not save printer settings', isError: true });
    } finally {
      setSaving(false);
    }
  };

  const onScanPaired = async () => {
    setScanning(true);
    setMessage(null);
    try {
      const devices = await listPairedPrinters();
      setPairedDevices(devices);
      if (devices.length === 0) {
        setMessage({ text: 'No paired devices found — pair your printer in the phone\'s Bluetooth settings first.', isError: true });
      }
    } catch (e) {
      setMessage({ text: e.message, isError: true });
    } finally {
      setScanning(false);
    }
  };

  const onTestPrint = async () => {
    if (type === 'network' && !ip.trim()) {
      setMessage({ text: 'Enter the printer IP first', isError: true });
      return;
    }
    if (type === 'bluetooth' && !btAddress) {
      setMessage({ text: 'Pick a paired Bluetooth printer first', isError: true });
      return;
    }
    setTesting(true);
    setMessage(null);
    try {
      await savePrinterSettings(currentSettings());
      const bytes = buildReceiptEscPos({
        restaurantName: 'Test Print',
        orderType: 'Test',
        items: [{ name: 'Sample Item', quantity: 1, price: 0 }],
        subtotal: 0,
        tax: 0,
        total: 0,
      });
      if (type === 'bluetooth') {
        await printToBluetoothPrinter({ address: btAddress, bytes });
      } else {
        await printToNetworkPrinter({ ip: ip.trim(), port: port.trim(), bytes });
      }
      setMessage({ text: 'Test receipt sent to printer', isError: false });
    } catch (e) {
      setMessage({ text: e.message, isError: true });
    } finally {
      setTesting(false);
    }
  };

  return (
    <View style={[styles.card, shadow.sm, styles.section]}>
      <Text style={styles.cardTitle}>Receipt Printer</Text>

      <View style={styles.printerTypeRow}>
        <Pressable
          style={[styles.printerTypeChip, type === 'network' && styles.printerTypeChipActive]}
          onPress={() => setType('network')}
        >
          <Text style={[styles.printerTypeChipText, type === 'network' && styles.printerTypeChipTextActive]}>Network (LAN)</Text>
        </Pressable>
        <Pressable
          style={[styles.printerTypeChip, type === 'bluetooth' && styles.printerTypeChipActive]}
          onPress={() => setType('bluetooth')}
        >
          <Text style={[styles.printerTypeChipText, type === 'bluetooth' && styles.printerTypeChipTextActive]}>Bluetooth</Text>
        </Pressable>
      </View>

      {type === 'network' ? (
        <>
          <Text style={styles.printerHint}>
            For a LAN thermal printer (KOT/receipts from POS). Find its IP in the printer's network settings — usually port 9100.
          </Text>
          <TextInput
            style={styles.input}
            placeholder="Printer IP e.g. 192.168.1.50"
            placeholderTextColor={colors.muted}
            autoCapitalize="none"
            keyboardType="numbers-and-punctuation"
            value={ip}
            onChangeText={setIp}
          />
          <TextInput
            style={styles.input}
            placeholder="Port (default 9100)"
            placeholderTextColor={colors.muted}
            keyboardType="number-pad"
            value={port}
            onChangeText={setPort}
          />
        </>
      ) : (
        <>
          <Text style={styles.printerHint}>
            For a Bluetooth thermal printer — no WiFi/LAN needed. Pair it in your phone's Bluetooth settings first, then pick it below.
            Only available in the installed app, not this web preview.
          </Text>
          {btAddress ? (
            <View style={styles.couponAppliedRowLike}>
              <View style={{ flex: 1 }}>
                <Text style={styles.printerSelectedName}>{btName || btAddress}</Text>
                <Text style={styles.printerSelectedAddress}>{btAddress}</Text>
              </View>
              <Pressable onPress={() => { setBtAddress(''); setBtName(''); }} hitSlop={8}>
                <Ionicons name="close-circle" size={22} color={colors.danger} />
              </Pressable>
            </View>
          ) : null}
          <Pressable
            style={({ pressed }) => [styles.scanButton, pressed && { opacity: 0.9 }]}
            onPress={onScanPaired}
            disabled={scanning}
          >
            {scanning ? (
              <ActivityIndicator color={colors.primary} size="small" />
            ) : (
              <>
                <Ionicons name="bluetooth" size={15} color={colors.primary} />
                <Text style={styles.scanButtonText}>Show paired devices</Text>
              </>
            )}
          </Pressable>
          {pairedDevices.map((d) => (
            <Pressable
              key={d.address}
              style={styles.deviceRow}
              onPress={() => { setBtAddress(d.address); setBtName(d.name); }}
            >
              <Ionicons name="print-outline" size={16} color={colors.inkSoft} />
              <Text style={styles.deviceRowText} numberOfLines={1}>{d.name}</Text>
            </Pressable>
          ))}
        </>
      )}

      {message ? (
        <Text style={[styles.formMessage, { color: message.isError ? colors.danger : colors.success }]}>
          {message.text}
        </Text>
      ) : null}
      <View style={{ flexDirection: 'row', gap: spacing.sm }}>
        <Pressable
          style={({ pressed }) => [styles.saveButton, { flex: 1 }, pressed && { opacity: 0.9 }]}
          onPress={onSave}
          disabled={saving}
        >
          {saving ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.saveButtonText}>Save</Text>}
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.testPrintButton, { flex: 1 }, pressed && { opacity: 0.9 }]}
          onPress={onTestPrint}
          disabled={testing}
        >
          {testing ? (
            <ActivityIndicator color={colors.primary} size="small" />
          ) : (
            <Text style={styles.testPrintButtonText}>Test Print</Text>
          )}
        </Pressable>
      </View>
    </View>
  );
}

function BiometricLockCard() {
  const [supported, setSupported] = useState(null); // null = still checking
  const [enabled, setEnabled] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    isBiometricSupported().then(setSupported);
    getBiometricLockEnabled().then(setEnabled);
  }, []);

  const onToggle = async (next) => {
    if (!next) {
      setEnabled(false);
      await setBiometricLockEnabled(false);
      return;
    }
    // Require a successful scan before turning the lock on, so it can't be
    // enabled and then immediately lock the owner out of their own app.
    setBusy(true);
    try {
      const ok = await authenticate('Confirm your fingerprint to enable app lock');
      if (ok) {
        setEnabled(true);
        await setBiometricLockEnabled(true);
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={[styles.card, shadow.sm, styles.section]}>
      <View style={styles.bioRow}>
        <View style={styles.rowIconWrap}>
          <Ionicons name="finger-print-outline" size={18} color={colors.primary} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.bioTitle}>Fingerprint Lock</Text>
          <Text style={styles.bioHint}>
            {supported === false
              ? "Not available — this device has no fingerprint/Face ID set up."
              : 'Ask for your fingerprint or Face ID whenever the app is reopened.'}
          </Text>
        </View>
        {busy ? (
          <ActivityIndicator color={colors.primary} size="small" />
        ) : (
          <Switch
            value={enabled}
            onValueChange={onToggle}
            disabled={supported === false || supported === null}
            trackColor={{ false: colors.border, true: colors.primaryLight }}
            thumbColor={enabled ? colors.primary : '#fff'}
          />
        )}
      </View>
    </View>
  );
}

function OrderAlertsCard() {
  const [enabled, setEnabled] = useState(true);

  useEffect(() => {
    getOrderSoundEnabled().then(setEnabled);
  }, []);

  const onToggle = async (next) => {
    setEnabled(next);
    await setOrderSoundEnabled(next);
  };

  return (
    <View style={[styles.card, shadow.sm, styles.section]}>
      <View style={styles.bioRow}>
        <View style={styles.rowIconWrap}>
          <Ionicons name="notifications-outline" size={18} color={colors.primary} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.bioTitle}>New Order Alerts</Text>
          <Text style={styles.bioHint}>Beep and notify this device when a new order comes in.</Text>
        </View>
        <Switch
          value={enabled}
          onValueChange={onToggle}
          trackColor={{ false: colors.border, true: colors.primaryLight }}
          thumbColor={enabled ? colors.primary : '#fff'}
        />
      </View>
    </View>
  );
}

export default function SettingsScreen() {
  const { user, logout, refresh } = useAuth();
  const [couponsOpen, setCouponsOpen] = useState(false);
  const [pendingAsset, setPendingAsset] = useState(null); // picked photo awaiting explicit Save
  const [uploadingPhoto, setUploadingPhoto] = useState(false);
  // The logo URL is always the same `?type=logo&id=X` regardless of what the
  // photo actually is, so after a re-upload the Image component would just
  // keep showing its cached copy of the old one — bump this to force a
  // fresh fetch once a new photo is actually saved.
  const [photoVersion, setPhotoVersion] = useState(0);

  const logoUrl = restaurantLogoUrl(user);
  const displayPhotoUri = pendingAsset?.uri || (logoUrl ? `${logoUrl}&v=${photoVersion}` : null);

  const pickPhoto = async () => {
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (perm.status !== 'granted' && perm.granted !== true) {
        Alert.alert('Permission needed', 'Allow photo access to change your profile picture.');
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        base64: true,
        allowsEditing: true,
        aspect: [1, 1],
        quality: 0.6,
      });
      if (result.canceled) return;
      const asset = result.assets?.[0];
      if (!asset?.base64) {
        Alert.alert('Could not read that photo', 'Please try a different image.');
        return;
      }
      setPendingAsset(asset);
    } catch (e) {
      Alert.alert('Could not open photo library', e.message);
    }
  };

  const cancelPhoto = () => setPendingAsset(null);

  const savePhoto = async () => {
    if (!pendingAsset) return;
    setUploadingPhoto(true);
    try {
      const res = await apiPostForm('/admin/auth.php', {
        action: 'uploadRestaurantLogo',
        logoBase64: `data:${pendingAsset.mimeType || 'image/jpeg'};base64,${pendingAsset.base64}`,
      });
      if (!res.success) throw new Error(res.message || 'Could not upload photo');
      await refresh();
      setPhotoVersion((v) => v + 1);
      setPendingAsset(null);
    } catch (e) {
      Alert.alert('Could not save photo', e.message);
    } finally {
      setUploadingPhoto(false);
    }
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader
        eyebrow="Account"
        title="Settings"
        right={<Avatar name={user?.username || user?.restaurant_name} uri={displayPhotoUri} />}
      />

      <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <ScrollView
        style={styles.fill}
        contentContainerStyle={styles.content}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={[styles.profileCard, shadow.md]}>
          <Pressable onPress={pickPhoto} disabled={uploadingPhoto} style={styles.avatarPressable}>
            <Avatar
              name={user?.username || user?.restaurant_name}
              size={54}
              bg={colors.primaryLight}
              color={colors.primary}
              uri={displayPhotoUri}
            />
            <View style={styles.avatarEditBadge}>
              <Ionicons name="camera" size={12} color="#fff" />
            </View>
          </Pressable>
          <View style={{ marginLeft: spacing.md, flex: 1 }}>
            <Text style={styles.profileName} numberOfLines={1}>{user?.restaurant_name || 'Your Restaurant'}</Text>
            <Text style={styles.profileRole}>{user?.role || 'Administrator'}</Text>
          </View>
        </View>

        {pendingAsset ? (
          <View style={[styles.photoConfirmRow, shadow.sm]}>
            <Ionicons name="image-outline" size={16} color={colors.primary} />
            <Text style={styles.photoConfirmText}>New photo selected</Text>
            <Pressable style={styles.photoCancelButton} onPress={cancelPhoto} disabled={uploadingPhoto}>
              <Text style={styles.photoCancelText}>Cancel</Text>
            </Pressable>
            <Pressable style={styles.photoSaveButton} onPress={savePhoto} disabled={uploadingPhoto}>
              {uploadingPhoto ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <Text style={styles.photoSaveText}>Save Photo</Text>
              )}
            </Pressable>
          </View>
        ) : null}

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

        <Text style={styles.sectionTitle}>Marketing</Text>
        <Pressable style={({ pressed }) => [styles.card, shadow.sm, styles.row, pressed && { opacity: 0.9 }]} onPress={() => setCouponsOpen(true)}>
          <View style={styles.rowIconWrap}>
            <Ionicons name="pricetag-outline" size={16} color={colors.primary} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.label}>Coupons</Text>
            <Text style={styles.value}>Create and manage discount codes</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={colors.muted} />
        </Pressable>

        <Text style={styles.sectionTitle}>Printing</Text>
        <PrinterSettingsCard />

        <Text style={styles.sectionTitle}>Notifications</Text>
        <OrderAlertsCard />

        <Text style={styles.sectionTitle}>Security</Text>
        <BiometricLockCard />
        <View style={{ height: spacing.md }} />
        <ChangePasswordCard />

        <Text style={styles.sectionTitle}>Legal</Text>
        <Pressable
          style={({ pressed }) => [styles.card, shadow.sm, styles.row, pressed && { opacity: 0.9 }]}
          onPress={() => Linking.openURL('https://restrogrow.com/privacy-policy/')}
        >
          <View style={styles.rowIconWrap}>
            <Ionicons name="shield-checkmark-outline" size={16} color={colors.primary} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.label}>Privacy Policy</Text>
          </View>
          <Ionicons name="open-outline" size={16} color={colors.muted} />
        </Pressable>
        <View style={{ height: spacing.sm }} />
        <Pressable
          style={({ pressed }) => [styles.card, shadow.sm, styles.row, pressed && { opacity: 0.9 }]}
          onPress={() => Linking.openURL('https://restrogrow.com/terms-of-service/')}
        >
          <View style={styles.rowIconWrap}>
            <Ionicons name="document-text-outline" size={16} color={colors.primary} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.label}>Terms of Service</Text>
          </View>
          <Ionicons name="open-outline" size={16} color={colors.muted} />
        </Pressable>

        <Pressable
          style={({ pressed }) => [styles.logoutButton, pressed && { opacity: 0.9 }]}
          onPress={logout}
        >
          <Ionicons name="log-out-outline" size={18} color={colors.danger} />
          <Text style={styles.logoutText}>Log Out</Text>
        </Pressable>

        <Text style={styles.versionText}>
          {Constants.expoConfig?.name || 'Restrogrow Partner'} v{Constants.expoConfig?.version || '1.0.0'}
        </Text>
      </ScrollView>
      </KeyboardAvoidingView>

      <CouponsModal visible={couponsOpen} onClose={() => setCouponsOpen(false)} />
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
  avatarPressable: {
    width: 54,
    height: 54,
  },
  avatarEditBadge: {
    position: 'absolute',
    right: -2,
    bottom: -2,
    width: 22,
    height: 22,
    borderRadius: 11,
    backgroundColor: colors.primary,
    borderWidth: 2,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  photoConfirmRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    marginTop: -spacing.sm,
    marginBottom: spacing.lg,
  },
  photoConfirmText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  photoCancelButton: {
    paddingHorizontal: spacing.sm,
    paddingVertical: 8,
  },
  photoCancelText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.muted,
  },
  photoSaveButton: {
    backgroundColor: colors.primary,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: 8,
    minWidth: 84,
    alignItems: 'center',
  },
  photoSaveText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: '#fff',
  },
  bioRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  bioTitle: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
    marginBottom: 2,
  },
  bioHint: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    lineHeight: 15.5,
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
  printerHint: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginBottom: spacing.md,
    lineHeight: 17,
  },
  printerTypeRow: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  printerTypeChip: {
    flex: 1,
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.pill,
    paddingVertical: 10,
  },
  printerTypeChipActive: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  printerTypeChipText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  printerTypeChipTextActive: {
    color: '#fff',
  },
  couponAppliedRowLike: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.successBg,
    borderRadius: radius.md,
    padding: spacing.md,
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  printerSelectedName: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.success,
  },
  printerSelectedAddress: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.inkSoft,
    marginTop: 1,
  },
  scanButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingVertical: 12,
    marginBottom: spacing.md,
  },
  scanButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  deviceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: 10,
    paddingHorizontal: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  deviceRowText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  testPrintButton: {
    backgroundColor: colors.primaryLight,
    borderRadius: radius.md,
    paddingVertical: 13,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.xs,
  },
  testPrintButtonText: {
    color: colors.primary,
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
  versionText: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    textAlign: 'center',
    marginTop: spacing.lg,
  },
});
