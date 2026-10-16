import { Ionicons } from '@expo/vector-icons';
import Constants from 'expo-constants';
import * as ImagePicker from 'expo-image-picker';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Image, KeyboardAvoidingView, Linking, Platform, Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { captureRef } from 'react-native-view-shot';
import Avatar from '../components/Avatar';
import CouponsModal from '../components/CouponsModal';
import { feedback } from '../components/ui/Feedback';
import RestaurantSettings from '../components/RestaurantSettings';
import ScreenHeader from '../components/ScreenHeader';
import { getBiometricLockEnabled, setBiometricLockEnabled } from '../config/biometricSettings';
import { getOrderSoundEnabled, setOrderSoundEnabled } from '../config/notificationSettings';
import { apiPostForm, restaurantLogoUrl } from '../config/api';
import { getPrinterSettings, savePrinterSettings } from '../config/printerSettings';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { isBiometricSupported, authenticate } from '../utils/biometricAuth';
import { connectToPrinter, isPrinterConnected, listPairedPrinters, printToBluetoothPrinter } from '../utils/bluetoothPrinter';
import { buildReceiptLines, convertReceiptImageToEscPos, printToNetworkPrinter } from '../utils/receiptPrinter';

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
  const [paperWidth, setPaperWidth] = useState('58');
  const [pairedDevices, setPairedDevices] = useState([]);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [message, setMessage] = useState(null); // { text, isError }
  const testReceiptRef = useRef(null);
  const [linked, setLinked] = useState(null); // Bluetooth link: null = checking
  const [linking, setLinking] = useState(false);

  // live connection state for the chosen Bluetooth printer
  useEffect(() => {
    if (type !== 'bluetooth' || !btAddress) {
      setLinked(null);
      return undefined;
    }
    let alive = true;
    const check = () => isPrinterConnected(btAddress).then((on) => alive && setLinked(on));
    check();
    const t = setInterval(check, 4000);
    return () => {
      alive = false;
      clearInterval(t);
    };
  }, [type, btAddress]);

  const connectNow = async (address = btAddress, name = btName) => {
    if (!address) return;
    setLinking(true);
    setMessage(null);
    try {
      await savePrinterSettings({ ...currentSettings(), type: 'bluetooth', btAddress: address, btName: name });
      await connectToPrinter(address);
      setLinked(true);
      feedback.success('Printer connected');
    } catch (e) {
      setLinked(false);
      setMessage({ text: e.message, isError: true });
    } finally {
      setLinking(false);
    }
  };

  useEffect(() => {
    getPrinterSettings().then((s) => {
      setType(s.type || 'network');
      setIp(s.ip || '');
      setPort(s.port || '9100');
      setBtAddress(s.btAddress || '');
      setBtName(s.btName || '');
      setPaperWidth(s.paperWidth || '58');
    });
  }, []);

  const currentSettings = () => ({
    type,
    ip: ip.trim(),
    port: port.trim() || '9100',
    btAddress,
    btName,
    paperWidth,
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
      // Same image-based pipeline as the real bill/KOT print — captures the
      // hidden test-receipt view below rather than encoding text into
      // printer bytes, so this also proves the actual print path works.
      const base64Png = await captureRef(testReceiptRef, { format: 'png', quality: 1, result: 'base64' });
      const bytes = await convertReceiptImageToEscPos({ base64Png });
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

      {/* Off-screen but still laid out (not display:none) so
          react-native-view-shot has a real rendered view to capture —
          same image-based print pipeline "Test Print" is meant to verify. */}
      <View ref={testReceiptRef} collapsable={false} style={styles.hiddenTestReceipt}>
        {buildReceiptLines({
          restaurantName: 'Test Print',
          orderType: 'Test',
          items: [{ name: 'Sample Item', quantity: 1, price: 0 }],
          subtotal: 0,
          tax: 0,
          total: 0,
        }).map((l, i) => (
          <Text
            key={i}
            style={[
              styles.hiddenTestReceiptText,
              l.size === 'title' && styles.hiddenTestReceiptTitle,
              l.size === 'small' && styles.hiddenTestReceiptSmall,
              l.bold && { fontWeight: 'bold' },
            ]}
          >
            {l.text}
          </Text>
        ))}
        <View style={styles.hiddenTestReceiptBrandFooter}>
          <Image source={require('../assets/restrogrow-logo.png')} style={styles.hiddenTestReceiptBrandLogo} />
          <Text style={styles.hiddenTestReceiptBrandText}>Powered by RestroGrow</Text>
        </View>
      </View>

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

      {/* Paper width — controls how wide the receipt image is rendered for
          this printer: 58mm rolls take a 384-dot raster, 80mm rolls 576.
          Wrong setting = small centered print with wide margins, or edges
          cropped off. */}
      <View style={styles.printerTypeRow}>
        <Text style={styles.paperLabel}>Paper width:</Text>
        <Pressable
          style={[styles.printerTypeChip, paperWidth === '58' && styles.printerTypeChipActive]}
          onPress={() => setPaperWidth('58')}
        >
          <Text style={[styles.printerTypeChipText, paperWidth === '58' && styles.printerTypeChipTextActive]}>58mm</Text>
        </Pressable>
        <Pressable
          style={[styles.printerTypeChip, paperWidth === '80' && styles.printerTypeChipActive]}
          onPress={() => setPaperWidth('80')}
        >
          <Text style={[styles.printerTypeChipText, paperWidth === '80' && styles.printerTypeChipTextActive]}>80mm</Text>
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
                <View style={styles.linkRow}>
                  <View style={[styles.linkDot, { backgroundColor: linked ? colors.success : linked === false ? colors.danger : colors.muted }]} />
                  <Text style={[styles.printerSelectedAddress, { marginTop: 0 }]}>
                    {linked ? 'Connected · stays connected' : linked === false ? 'Not connected' : 'Checking…'}
                  </Text>
                </View>
              </View>
              {linked === false ? (
                <Pressable style={styles.linkButton} onPress={() => connectNow()} disabled={linking} hitSlop={6}>
                  {linking ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.linkButtonText}>Connect</Text>}
                </Pressable>
              ) : null}
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
              onPress={() => { setBtAddress(d.address); setBtName(d.name); setPairedDevices([]); connectNow(d.address, d.name); }}
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

// Lets the owner turn on/edit the business registration number printed on
// the bill (GSTIN in India, PAN No in Nepal, ... — label is always freely
// editable) right from the app, instead of only from the website admin
// dashboard. Saves through its own small endpoint (update_business_id.php)
// rather than the website's whole-form updateRestaurantSettings action,
// which would blank out every other restaurant setting not sent with it.
function BusinessIdCard() {
  const { user, refresh } = useAuth();
  const [enabled, setEnabled] = useState(false);
  const [label, setLabel] = useState('PAN');
  const [idNo, setIdNo] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null); // { text, isError }

  useEffect(() => {
    setEnabled(user?.show_business_id == 1 || user?.show_business_id === true);
    setLabel(user?.business_id_label || 'PAN');
    setIdNo(user?.business_id_no || '');
  }, [user?.show_business_id, user?.business_id_label, user?.business_id_no]);

  const onSave = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const res = await apiPostForm('/api/update_business_id.php', {
        show_business_id: enabled ? '1' : '0',
        business_id_label: label,
        business_id_no: idNo,
      });
      if (!res.success) throw new Error(res.message || 'Could not save');
      await refresh();
      setMessage({ text: 'Saved', isError: false });
    } catch (e) {
      setMessage({ text: e.message, isError: true });
    } finally {
      setSaving(false);
    }
  };

  return (
    <View style={[styles.card, shadow.sm, styles.section]}>
      <View style={styles.bioRow}>
        <View style={styles.rowIconWrap}>
          <Ionicons name="id-card-outline" size={18} color={colors.primary} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.bioTitle}>Business ID on Bill</Text>
          <Text style={styles.bioHint}>e.g. GSTIN in India, PAN No in Nepal — rename the label to whatever your country calls it.</Text>
        </View>
        <Switch
          value={enabled}
          onValueChange={setEnabled}
          trackColor={{ false: colors.border, true: colors.primaryLight }}
          thumbColor={enabled ? colors.primary : '#fff'}
        />
      </View>
      <TextInput
        style={[styles.input, { marginTop: spacing.md }]}
        placeholder="Label, e.g. GSTIN"
        placeholderTextColor={colors.muted}
        value={label}
        onChangeText={setLabel}
      />
      <TextInput
        style={styles.input}
        placeholder="Number"
        placeholderTextColor={colors.muted}
        autoCapitalize="characters"
        value={idNo}
        onChangeText={setIdNo}
      />
      {message ? (
        <Text style={[styles.formMessage, { color: message.isError ? colors.danger : colors.success }]}>
          {message.text}
        </Text>
      ) : null}
      <Pressable
        style={({ pressed }) => [styles.saveButton, pressed && { opacity: 0.9 }]}
        onPress={onSave}
        disabled={saving}
      >
        {saving ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.saveButtonText}>Save</Text>}
      </Pressable>
    </View>
  );
}

// Settings is a short menu of clear sections; each opens its own page (SettingsSection) with
// only that section's controls, instead of one very long page mixing everything.
const SECTIONS = [
  {
    title: 'Restaurant',
    items: [
      { key: 'details', icon: 'storefront', tint: '#FF5A3C', label: 'Restaurant details', sub: 'Name, phone, email, address' },
      { key: 'hours', icon: 'time', tint: '#0EA5A5', label: 'Business hours', sub: 'When you take orders each day' },
      { key: 'social', icon: 'share-social', tint: '#D946EF', label: 'Social links', sub: 'Instagram, Facebook and more' },
    ],
  },
  {
    title: 'Orders and billing',
    items: [
      { key: 'ordering', icon: 'bag-handle', tint: '#22A06B', label: 'Ordering', sub: 'Order types, bookings, minimum order' },
      { key: 'delivery', icon: 'bicycle', tint: '#F97316', label: 'Delivery', sub: 'Distance and delivery charge' },
      { key: 'tax', icon: 'receipt', tint: '#3D7BF0', label: 'Tax and bill', sub: 'GST and business ID on the bill' },
      { key: 'coupons', icon: 'pricetag', tint: '#8B5CF6', label: 'Coupons', sub: 'Discount codes for customers' },
    ],
  },
  {
    title: 'This device',
    items: [
      { key: 'printer', icon: 'print', tint: '#0EA5A5', label: 'Printer', sub: 'Receipts and kitchen tickets' },
      { key: 'alerts', icon: 'notifications', tint: '#F5A623', label: 'New-order alerts', sub: 'Ring and notification' },
    ],
  },
  {
    title: 'Security',
    items: [
      { key: 'lock', icon: 'finger-print', tint: '#1D1B26', label: 'Fingerprint lock', sub: 'Lock the app when you leave it' },
      { key: 'password', icon: 'key', tint: '#E5484D', label: 'Change password', sub: 'Your login password' },
    ],
  },
  {
    title: 'About',
    items: [
      { key: 'privacy', icon: 'shield-checkmark', tint: '#585667', label: 'Privacy policy', link: 'https://restrogrow.com/privacy-policy/' },
      { key: 'terms', icon: 'document-text', tint: '#585667', label: 'Terms of service', link: 'https://restrogrow.com/terms-of-service/' },
    ],
  },
];
const SECTION_TITLE = Object.fromEntries(SECTIONS.flatMap((s) => s.items.map((i) => [i.key, i.label])));

export default function SettingsScreen({ navigation }) {
  const { user, logout, refresh } = useAuth();
  const [couponsOpen, setCouponsOpen] = useState(false);
  const [pendingAsset, setPendingAsset] = useState(null); // picked photo awaiting explicit Save
  const [uploadingPhoto, setUploadingPhoto] = useState(false);
  // The logo URL never changes after a re-upload, so bump this to bypass the cached image.
  const [photoVersion, setPhotoVersion] = useState(0);

  const logoUrl = restaurantLogoUrl(user);
  const displayPhotoUri = pendingAsset?.uri || (logoUrl ? `${logoUrl}&v=${photoVersion}` : null);

  const pickPhoto = async () => {
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (perm.status !== 'granted' && perm.granted !== true) {
        feedback.error('Allow photo access to change your logo.');
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], base64: true, allowsEditing: true, aspect: [1, 1], quality: 0.6 });
      if (result.canceled) return;
      const asset = result.assets?.[0];
      if (!asset?.base64) {
        feedback.error('Could not read that photo. Please try a different image.');
        return;
      }
      setPendingAsset(asset);
    } catch (e) {
      feedback.errorFrom(e, 'Could not open the photo library');
    }
  };

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
      feedback.success('Logo saved');
    } catch (e) {
      feedback.errorFrom(e, 'Could not save the logo');
    } finally {
      setUploadingPhoto(false);
    }
  };

  const open = (item) => {
    if (item.link) Linking.openURL(item.link).catch(() => {});
    else if (item.key === 'coupons') setCouponsOpen(true);
    else navigation.navigate('SettingsSection', { section: item.key });
  };

  const doLogout = async () => {
    const ok = await feedback.confirm({ title: 'Log out?', message: 'You will need your username and password to log in again.', confirmText: 'Log out', danger: true });
    if (ok) logout();
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Account" title="Settings" onBack={navigation?.canGoBack() ? () => navigation.goBack() : undefined} />
      <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={[styles.profileCard, shadow.md]}>
          <Pressable onPress={pickPhoto} disabled={uploadingPhoto} style={styles.avatarPressable} accessibilityLabel="Change logo">
            <Avatar name={user?.restaurant_name || user?.username} size={58} bg={colors.primaryLight} color={colors.primary} uri={displayPhotoUri} />
            <View style={styles.avatarEditBadge}>
              <Ionicons name="camera" size={12} color="#fff" />
            </View>
          </Pressable>
          <View style={{ marginLeft: spacing.md, flex: 1 }}>
            <Text style={styles.profileName} numberOfLines={1}>{user?.restaurant_name || 'Your restaurant'}</Text>
            <Text style={styles.profileRole} numberOfLines={1}>{user?.staff_name || user?.username} · {user?.role || 'Administrator'}</Text>
            <Text style={styles.profileHint}>Tap the photo to change your logo</Text>
          </View>
        </View>

        {pendingAsset ? (
          <View style={[styles.photoConfirmRow, shadow.sm]}>
            <Ionicons name="image-outline" size={16} color={colors.primary} />
            <Text style={styles.photoConfirmText}>New logo selected</Text>
            <Pressable style={styles.photoCancelButton} onPress={() => setPendingAsset(null)} disabled={uploadingPhoto}>
              <Text style={styles.photoCancelText}>Cancel</Text>
            </Pressable>
            <Pressable style={styles.photoSaveButton} onPress={savePhoto} disabled={uploadingPhoto}>
              {uploadingPhoto ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.photoSaveText}>Save</Text>}
            </Pressable>
          </View>
        ) : null}

        {SECTIONS.map((sec) => (
          <View key={sec.title}>
            <Text style={styles.sectionTitle}>{sec.title}</Text>
            <View style={[styles.card, shadow.sm, styles.menuCard]}>
              {sec.items.map((it, i) => (
                <Pressable
                  key={it.key}
                  onPress={() => open(it)}
                  style={({ pressed }) => [styles.menuRow, i > 0 && styles.menuRowLine, pressed && { backgroundColor: colors.bg }]}
                  accessibilityRole="button"
                  accessibilityLabel={it.label}
                >
                  <View style={[styles.menuIcon, { backgroundColor: it.tint }]}>
                    <Ionicons name={it.icon} size={17} color="#fff" />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.menuLabel}>{it.label}</Text>
                    {it.sub ? <Text style={styles.menuSub} numberOfLines={1}>{it.sub}</Text> : null}
                  </View>
                  <Ionicons name={it.link ? 'open-outline' : 'chevron-forward'} size={17} color={colors.muted} />
                </Pressable>
              ))}
            </View>
          </View>
        ))}

        <Pressable style={({ pressed }) => [styles.logoutButton, pressed && { opacity: 0.9 }]} onPress={doLogout}>
          <Ionicons name="log-out-outline" size={18} color={colors.danger} />
          <Text style={styles.logoutText}>Log out</Text>
        </Pressable>

        <Text style={styles.versionText}>
          {Constants.expoConfig?.name || 'Restrogrow Partner'} · version {Constants.expoConfig?.version || '1.0.0'}
        </Text>
      </ScrollView>

      <CouponsModal visible={couponsOpen} onClose={() => setCouponsOpen(false)} />
    </View>
  );
}

// One settings section on its own page.
export function SettingsSectionScreen({ route, navigation }) {
  const { user } = useAuth();
  const section = route.params?.section;
  const ownerOnly = user?.user_type === 'admin' || user?.user_type === 'branch_admin';
  let body = null;
  let hint = null;
  const lockedHint = 'Only the restaurant owner or branch admin can change these.';
  if (section === 'details') {
    hint = ownerOnly ? 'Shown on your website, bills and the order page your customers see.' : lockedHint;
    body = <RestaurantSettings section="details" editable={ownerOnly} />;
  } else if (section === 'hours') {
    hint = ownerOnly ? 'Customers can only order online during these hours.' : lockedHint;
    body = <RestaurantSettings section="hours" editable={ownerOnly} />;
  } else if (section === 'ordering') {
    hint = ownerOnly ? 'What your customers can order on your website. Dine-in and delivery also switch from the top of the Orders tab.' : lockedHint;
    body = <RestaurantSettings section="ordering" editable={ownerOnly} />;
  } else if (section === 'delivery') {
    hint = ownerOnly ? 'How far you deliver and what delivery costs the customer.' : lockedHint;
    body = <RestaurantSettings section="delivery" editable={ownerOnly} />;
  } else if (section === 'social') {
    hint = ownerOnly ? 'Shown as icons on your website.' : lockedHint;
    body = <RestaurantSettings section="social" editable={ownerOnly} />;
  } else if (section === 'tax') {
    hint = 'Tax is added to every bill. The business ID (for example your GSTIN) can be printed at the top of the bill.';
    body = (
      <>
        <RestaurantSettings section="tax" editable={ownerOnly} />
        <Text style={styles.sectionTitle}>Business ID on the bill</Text>
        <BusinessIdCard />
      </>
    );
  } else if (section === 'printer') {
    hint = 'Choose the printer this phone or tablet prints to. Each device keeps its own printer.';
    body = <PrinterSettingsCard />;
  } else if (section === 'alerts') {
    hint = 'How this device tells you about a new order from your website.';
    body = <OrderAlertsCard />;
  } else if (section === 'lock') {
    hint = 'Ask for your fingerprint or face whenever the app is opened again.';
    body = <BiometricLockCard />;
  } else if (section === 'password') {
    hint = 'Use at least 6 characters. You stay logged in on this device.';
    body = <ChangePasswordCard />;
  }

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Settings" title={SECTION_TITLE[section] || 'Settings'} onBack={() => navigation.goBack()} />
      <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <ScrollView style={styles.fill} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          {hint ? (
            <View style={styles.hintBox}>
              <Ionicons name="information-circle" size={18} color={colors.info} />
              <Text style={styles.hintText}>{hint}</Text>
            </View>
          ) : null}
          {body}
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  linkRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 3 },
  linkDot: { width: 8, height: 8, borderRadius: 4 },
  linkButton: { backgroundColor: colors.primary, borderRadius: radius.pill, paddingHorizontal: 14, height: 32, minWidth: 82, alignItems: 'center', justifyContent: 'center', marginRight: spacing.sm },
  linkButtonText: { color: '#fff', fontFamily: font.semiBold, fontSize: 12.5 },
  fill: { flex: 1, backgroundColor: colors.bg },
  hiddenTestReceipt: {
    position: 'absolute',
    top: -9999,
    left: -9999,
    backgroundColor: '#fff',
    padding: spacing.sm,
  },
  hiddenTestReceiptText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
    fontSize: 12,
    lineHeight: 16,
    color: '#000',
  },
  hiddenTestReceiptTitle: {
    fontSize: 20,
    lineHeight: 24,
  },
  hiddenTestReceiptSmall: {
    fontSize: 10.5,
    lineHeight: 14,
    color: '#333',
  },
  hiddenTestReceiptBrandFooter: {
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.sm,
    gap: 4,
  },
  hiddenTestReceiptBrandLogo: {
    width: 32,
    height: 32,
    resizeMode: 'contain',
  },
  hiddenTestReceiptBrandText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
    fontWeight: 'bold',
    fontSize: 14,
    color: '#000',
  },
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
    alignItems: 'center',
  },
  paperLabel: {
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.inkSoft,
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
  profileHint: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 3,
  },
  menuCard: {
    padding: 0,
    overflow: 'hidden',
  },
  menuRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: 13,
    paddingHorizontal: spacing.lg,
    minHeight: 60,
  },
  menuRowLine: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  menuIcon: {
    width: 34,
    height: 34,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  menuLabel: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
  },
  menuSub: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    marginTop: 1,
  },
  hintBox: {
    flexDirection: 'row',
    gap: 8,
    backgroundColor: colors.infoBg,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  hintText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13,
    color: '#1E4FAF',
    lineHeight: 19,
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
