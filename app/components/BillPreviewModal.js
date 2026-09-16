import { Ionicons } from '@expo/vector-icons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../theme';
import { getPrinterSettings, savePrinterSettings } from '../config/printerSettings';
import {
  connectToPrinter,
  disconnectPrinter,
  isPrinterConnected,
  listPairedPrinters,
  writeToPrinter,
} from '../utils/bluetoothPrinter';
import { buildReceiptEscPos, printToNetworkPrinter, testNetworkPrinter } from '../utils/receiptPrinter';

const STATUS_META = {
  idle: { label: 'Not connected', color: colors.muted },
  unconfigured: { label: 'No printer set up', color: colors.muted },
  connecting: { label: 'Connecting…', color: colors.warning },
  connected: { label: 'Connected', color: colors.success },
  printing: { label: 'Printing…', color: colors.info },
  printed: { label: 'Printed', color: colors.success },
  error: { label: 'Connection failed', color: colors.danger },
};

// Shows what's about to be printed before it's sent to the printer, and lets
// the user actually pick/connect a real printer right here — instead of
// bouncing them to Settings and hoping one was configured beforehand.
export default function BillPreviewModal({ visible, onClose, data }) {
  const [settings, setSettings] = useState(null);
  const [status, setStatus] = useState('idle');
  const [statusMessage, setStatusMessage] = useState('');
  const [setupOpen, setSetupOpen] = useState(false);

  const [type, setType] = useState('network');
  const [ip, setIp] = useState('');
  const [port, setPort] = useState('9100');
  const [btAddress, setBtAddress] = useState('');
  const [btName, setBtName] = useState('');
  const [pairedDevices, setPairedDevices] = useState([]);
  const [scanning, setScanning] = useState(false);

  useEffect(() => {
    if (!visible) return;
    setStatusMessage('');
    getPrinterSettings().then(async (s) => {
      setSettings(s);
      setType(s.type || 'network');
      setIp(s.ip || '');
      setPort(s.port || '9100');
      setBtAddress(s.btAddress || '');
      setBtName(s.btName || '');
      const configured = s.type === 'bluetooth' ? !!s.btAddress : !!s.ip;
      if (!configured) {
        setSetupOpen(true);
        setStatus('unconfigured');
        return;
      }
      setSetupOpen(false);
      if (s.type === 'bluetooth') {
        const already = await isPrinterConnected(s.btAddress);
        setStatus(already ? 'connected' : 'idle');
      } else {
        setStatus('idle');
      }
    });
  }, [visible]);

  if (!data) return null;
  const {
    title,
    restaurantName,
    kotNumber,
    orderType,
    tableName,
    items = [],
    subtotal = 0,
    discount = 0,
    couponCode,
    tax = 0,
    taxPercent,
    total = 0,
    paymentMethod,
    currency = '₹',
  } = data;

  const currentPrinter = () => ({
    type,
    ip: ip.trim(),
    port: port.trim() || '9100',
    btAddress,
    btName,
  });

  // Real connect step — saves the config and actually opens a connection
  // (Bluetooth SPP) or proves one is reachable (TCP test-connect) before any
  // printing happens.
  const ensureConnected = async () => {
    const cfg = currentPrinter();
    if (cfg.type === 'bluetooth' && !cfg.btAddress) {
      throw new Error('Pick a paired Bluetooth printer first');
    }
    if (cfg.type === 'network' && !cfg.ip) {
      throw new Error('Enter the printer IP first');
    }
    setStatus('connecting');
    setStatusMessage('');
    await savePrinterSettings(cfg);
    setSettings(cfg);
    if (cfg.type === 'bluetooth') {
      await connectToPrinter(cfg.btAddress);
    } else {
      await testNetworkPrinter({ ip: cfg.ip, port: cfg.port });
    }
    setStatus('connected');
    return cfg;
  };

  const handleConnect = async () => {
    try {
      await ensureConnected();
      setSetupOpen(false);
    } catch (e) {
      setStatus('error');
      setStatusMessage(e.message);
    }
  };

  const handleScan = async () => {
    setScanning(true);
    setStatusMessage('');
    try {
      const devices = await listPairedPrinters();
      setPairedDevices(devices);
      if (devices.length === 0) {
        setStatusMessage("No paired devices — pair your printer in the phone's Bluetooth settings first.");
      }
    } catch (e) {
      setStatusMessage(e.message);
    } finally {
      setScanning(false);
    }
  };

  const handlePrint = async () => {
    try {
      const cfg = await ensureConnected();
      setStatus('printing');
      const bytes = buildReceiptEscPos(data);
      if (cfg.type === 'bluetooth') {
        await writeToPrinter(cfg.btAddress, bytes);
      } else {
        await printToNetworkPrinter({ ip: cfg.ip, port: cfg.port, bytes });
      }
      setStatus('printed');
      setTimeout(() => {
        if (cfg.type === 'bluetooth') disconnectPrinter(cfg.btAddress);
        onClose();
      }, 900);
    } catch (e) {
      setStatus('error');
      setStatusMessage(e.message);
    }
  };

  const handleClose = () => {
    if (settings?.type === 'bluetooth' && settings?.btAddress && status === 'connected') {
      disconnectPrinter(settings.btAddress);
    }
    onClose();
  };

  const busy = status === 'connecting' || status === 'printing';
  const meta = STATUS_META[status] || STATUS_META.idle;
  const printerLabel = type === 'bluetooth' ? (btName || btAddress) : ip;

  let printButtonText = 'Print';
  if (status === 'connecting') printButtonText = 'Connecting…';
  else if (status === 'printing') printButtonText = 'Printing…';
  else if (status === 'printed') printButtonText = 'Printed';
  else if (status === 'error') printButtonText = 'Retry Print';
  else if (status !== 'connected') printButtonText = 'Connect & Print';

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={handleClose}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <Pressable style={styles.backdrop} onPress={handleClose}>
        <Pressable style={[styles.sheet, shadow.lg]} onPress={() => {}}>
          <View style={styles.header}>
            <Text style={styles.eyebrow}>{title}</Text>
            <Pressable onPress={handleClose} hitSlop={8}>
              <Ionicons name="close" size={20} color={colors.ink} />
            </Pressable>
          </View>

          <View style={styles.printerBar}>
            {!setupOpen ? (
              <>
                <View style={styles.printerInfo}>
                  <View style={[styles.printerIconWrap, { backgroundColor: meta.color + '1A' }]}>
                    <Ionicons
                      name={type === 'bluetooth' ? 'bluetooth' : 'wifi'}
                      size={14}
                      color={meta.color}
                    />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.printerName} numberOfLines={1}>
                      {printerLabel || 'No printer set up'}
                    </Text>
                    <Text style={[styles.printerStatusText, { color: meta.color }]}>
                      {status === 'error' ? (statusMessage || meta.label) : meta.label}
                    </Text>
                  </View>
                </View>
                <Pressable onPress={() => setSetupOpen(true)} hitSlop={8} disabled={busy}>
                  <Text style={styles.changeLink}>{printerLabel ? 'Change' : 'Connect'}</Text>
                </Pressable>
              </>
            ) : (
              <View style={{ width: '100%' }}>
                <View style={styles.typeRow}>
                  <Pressable
                    style={[styles.typeChip, type === 'network' && styles.typeChipActive]}
                    onPress={() => { setType('network'); setStatus('idle'); setStatusMessage(''); }}
                  >
                    <Text style={[styles.typeChipText, type === 'network' && styles.typeChipTextActive]}>Network</Text>
                  </Pressable>
                  <Pressable
                    style={[styles.typeChip, type === 'bluetooth' && styles.typeChipActive]}
                    onPress={() => { setType('bluetooth'); setStatus('idle'); setStatusMessage(''); }}
                  >
                    <Text style={[styles.typeChipText, type === 'bluetooth' && styles.typeChipTextActive]}>Bluetooth</Text>
                  </Pressable>
                  {printerLabel ? (
                    <Pressable style={styles.cancelSetup} onPress={() => setSetupOpen(false)} hitSlop={8}>
                      <Ionicons name="close" size={16} color={colors.muted} />
                    </Pressable>
                  ) : null}
                </View>

                {type === 'network' ? (
                  <View style={styles.fieldRow}>
                    <TextInput
                      style={[styles.input, { flex: 2 }]}
                      placeholder="Printer IP e.g. 192.168.1.50"
                      placeholderTextColor={colors.muted}
                      autoCapitalize="none"
                      keyboardType="numbers-and-punctuation"
                      value={ip}
                      onChangeText={(v) => { setIp(v); setStatus('idle'); }}
                    />
                    <TextInput
                      style={[styles.input, { flex: 1 }]}
                      placeholder="Port"
                      placeholderTextColor={colors.muted}
                      keyboardType="number-pad"
                      value={port}
                      onChangeText={setPort}
                    />
                  </View>
                ) : Platform.OS === 'web' ? (
                  <Text style={styles.printerHint}>
                    Bluetooth printing needs the installed app — not available in this web preview.
                  </Text>
                ) : (
                  <>
                    {btAddress ? (
                      <View style={styles.deviceSelected}>
                        <Ionicons name="print" size={14} color={colors.primary} />
                        <Text style={styles.deviceSelectedText} numberOfLines={1}>{btName || btAddress}</Text>
                        <Pressable onPress={() => { setBtAddress(''); setBtName(''); setStatus('idle'); }} hitSlop={8}>
                          <Ionicons name="close-circle" size={16} color={colors.muted} />
                        </Pressable>
                      </View>
                    ) : null}
                    <Pressable style={styles.scanButton} onPress={handleScan} disabled={scanning}>
                      {scanning ? (
                        <ActivityIndicator color={colors.primary} size="small" />
                      ) : (
                        <>
                          <Ionicons name="bluetooth" size={14} color={colors.primary} />
                          <Text style={styles.scanButtonText}>Show paired devices</Text>
                        </>
                      )}
                    </Pressable>
                    {pairedDevices.map((d) => (
                      <Pressable
                        key={d.address}
                        style={styles.deviceRow}
                        onPress={() => { setBtAddress(d.address); setBtName(d.name); setStatus('idle'); }}
                      >
                        <Ionicons name="print-outline" size={14} color={colors.inkSoft} />
                        <Text style={styles.deviceRowText} numberOfLines={1}>{d.name}</Text>
                      </Pressable>
                    ))}
                  </>
                )}

                {statusMessage ? (
                  <Text style={[styles.setupMessage, { color: status === 'error' ? colors.danger : colors.inkSoft }]}>
                    {statusMessage}
                  </Text>
                ) : null}

                <Pressable style={styles.connectButton} onPress={handleConnect} disabled={status === 'connecting'}>
                  {status === 'connecting' ? (
                    <ActivityIndicator color="#fff" size="small" />
                  ) : (
                    <>
                      <Ionicons name="link" size={14} color="#fff" />
                      <Text style={styles.connectButtonText}>Connect Printer</Text>
                    </>
                  )}
                </Pressable>
              </View>
            )}
          </View>

          <ScrollView style={styles.receiptScroll} showsVerticalScrollIndicator={false}>
            <View style={styles.receipt}>
              <Text style={styles.restaurantName}>{restaurantName}</Text>
              <Text style={styles.metaLine}>{new Date().toLocaleString()}</Text>
              {kotNumber ? <Text style={styles.metaLine}>KOT {kotNumber}</Text> : null}
              <Text style={styles.metaLine}>{orderType}{tableName ? ` · ${tableName}` : ''}</Text>

              <View style={styles.dashedDivider} />

              {items.map((it, i) => (
                <View key={i} style={styles.itemRow}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.itemName}>{it.name}{it.variationName ? ` (${it.variationName})` : ''}</Text>
                    <Text style={styles.itemQtyPrice}>{it.quantity} x {currency}{Number(it.price).toFixed(2)}</Text>
                  </View>
                  <Text style={styles.itemTotal}>{currency}{(Number(it.price) * Number(it.quantity)).toFixed(2)}</Text>
                </View>
              ))}

              <View style={styles.dashedDivider} />

              <View style={styles.summaryRow}>
                <Text style={styles.summaryLabel}>Subtotal</Text>
                <Text style={styles.summaryValue}>{currency}{Number(subtotal).toFixed(2)}</Text>
              </View>
              {discount > 0 ? (
                <View style={styles.summaryRow}>
                  <Text style={[styles.summaryLabel, { color: colors.success }]}>
                    {couponCode ? `Coupon (${couponCode})` : 'Discount'}
                  </Text>
                  <Text style={[styles.summaryValue, { color: colors.success }]}>-{currency}{Number(discount).toFixed(2)}</Text>
                </View>
              ) : null}
              {tax > 0 ? (
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Tax{taxPercent ? ` (${taxPercent}%)` : ''}</Text>
                  <Text style={styles.summaryValue}>{currency}{Number(tax).toFixed(2)}</Text>
                </View>
              ) : null}
              <View style={[styles.summaryRow, styles.totalRow]}>
                <Text style={styles.totalLabel}>Total</Text>
                <Text style={styles.totalValue}>{currency}{Number(total).toFixed(2)}</Text>
              </View>
              {paymentMethod ? (
                <Text style={styles.paymentLine}>Payment: {paymentMethod}</Text>
              ) : null}

              <Text style={styles.thankYou}>Thank you!</Text>
            </View>
          </ScrollView>

          <View style={styles.actions}>
            <Pressable style={styles.closeButton} onPress={handleClose} disabled={status === 'printing'}>
              <Text style={styles.closeButtonText}>Close</Text>
            </Pressable>
            <Pressable style={styles.printButton} onPress={handlePrint} disabled={busy || setupOpen}>
              {busy ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <>
                  <Ionicons name={status === 'printed' ? 'checkmark' : 'print'} size={16} color="#fff" />
                  <Text style={styles.printButtonText}>{printButtonText}</Text>
                </>
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
    backgroundColor: 'rgba(29,27,38,0.5)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  sheet: {
    width: '100%',
    maxWidth: 380,
    maxHeight: '85%',
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  eyebrow: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  printerBar: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    marginBottom: spacing.sm,
    gap: spacing.sm,
  },
  printerInfo: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  printerIconWrap: {
    width: 26,
    height: 26,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
  },
  printerName: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.ink,
  },
  printerStatusText: {
    fontFamily: font.regular,
    fontSize: 11,
    marginTop: 1,
  },
  changeLink: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.primary,
  },
  typeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    marginBottom: spacing.sm,
  },
  typeChip: {
    paddingHorizontal: spacing.md,
    paddingVertical: 6,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
  },
  typeChipActive: {
    backgroundColor: colors.primary,
  },
  typeChipText: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
  },
  typeChipTextActive: {
    color: '#fff',
  },
  cancelSetup: {
    marginLeft: 'auto',
  },
  fieldRow: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  input: {
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 9,
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.ink,
  },
  printerHint: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginBottom: spacing.xs,
  },
  deviceSelected: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 8,
    marginBottom: spacing.xs,
  },
  deviceSelectedText: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.ink,
  },
  scanButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.primaryLight,
    borderRadius: radius.sm,
    paddingVertical: 8,
    marginBottom: spacing.xs,
  },
  scanButtonText: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.primary,
  },
  deviceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingVertical: 6,
  },
  deviceRowText: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.inkSoft,
  },
  setupMessage: {
    fontFamily: font.regular,
    fontSize: 11.5,
    marginTop: 2,
    marginBottom: spacing.xs,
  },
  connectButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.ink,
    borderRadius: radius.sm,
    paddingVertical: 10,
    marginTop: spacing.xs,
  },
  connectButtonText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: '#fff',
  },
  receiptScroll: {
    maxHeight: 360,
  },
  receipt: {
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    padding: spacing.lg,
  },
  restaurantName: {
    fontFamily: font.bold,
    fontSize: 16,
    color: colors.ink,
    textAlign: 'center',
  },
  metaLine: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    textAlign: 'center',
    marginTop: 2,
  },
  dashedDivider: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    borderStyle: 'dashed',
    marginVertical: spacing.sm,
  },
  itemRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    paddingVertical: 4,
  },
  itemName: {
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.ink,
  },
  itemQtyPrice: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  itemTotal: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 3,
  },
  summaryLabel: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  summaryValue: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.ink,
  },
  totalRow: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    marginTop: spacing.xs,
    paddingTop: spacing.sm,
  },
  totalLabel: {
    fontFamily: font.bold,
    fontSize: 14.5,
    color: colors.ink,
  },
  totalValue: {
    fontFamily: font.bold,
    fontSize: 15,
    color: colors.ink,
  },
  paymentLine: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
    textAlign: 'center',
    marginTop: spacing.sm,
  },
  thankYou: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.muted,
    textAlign: 'center',
    marginTop: spacing.md,
  },
  actions: {
    flexDirection: 'row',
    gap: spacing.sm,
    marginTop: spacing.lg,
  },
  closeButton: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    paddingVertical: 13,
  },
  closeButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.inkSoft,
  },
  printButton: {
    flex: 2,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 13,
  },
  printButtonText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: '#fff',
  },
});
