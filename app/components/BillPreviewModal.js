import { Ionicons } from '@expo/vector-icons';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { captureRef } from 'react-native-view-shot';
import { colors, font, radius, shadow, spacing } from '../theme';
import { getPrinterSettings, savePrinterSettings } from '../config/printerSettings';
import {
  connectToPrinter,
  isPrinterConnected,
  listPairedPrinters,
  writeToPrinter,
} from '../utils/bluetoothPrinter';
import { convertReceiptImageToEscPos, printToNetworkPrinter, testNetworkPrinter } from '../utils/receiptPrinter';
import Receipt, { FitWidth, receiptWidth } from './Receipt';

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
  // Paper width drives how wide the printed receipt image is rendered:
  // 58mm printers take a 384-dot raster, 80mm ones take 576. Wrong setting
  // = receipt printed small/centered with big margins (58 setting on an
  // 80mm printer) or cropped at the edges (80 setting on a 58mm printer).
  const [paperWidth, setPaperWidth] = useState('58');
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
      setPaperWidth(s.paperWidth || '58');
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

  // What's on screen is exactly what prints: handlePrint captures the
  // <Receipt> view below as a picture, so any script (Hindi etc.) and the
  // website-style layout come out as seen. The data contract is documented
  // at the top of components/Receipt.js.
  const receiptRef = useRef(null);
  // Width of the grey desk the slip sits on; FitWidth shrinks an 80mm slip
  // to it on small phones.
  const [deskWidth, setDeskWidth] = useState(0);

  if (!data) return null;
  const { title } = data;

  const currentPrinter = () => ({
    type,
    ip: ip.trim(),
    port: port.trim() || '9100',
    btAddress,
    btName,
    paperWidth,
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
      // Capture the exact receipt view on screen — real Unicode text and
      // all — and have the server turn it into an ESC/POS raster image
      // instead of encoding it as printer text, which is what makes any
      // script/language print correctly.
      const base64Png = await captureRef(receiptRef, { format: 'png', quality: 1, result: 'base64' });
      // 58mm/203dpi = 384 printable dots; 80mm/203dpi = 576. The server
      // scales the captured bitmap to exactly this many dots across.
      const dotWidth = paperWidth === '80' ? 576 : 384;
      const bytes = await convertReceiptImageToEscPos({ base64Png, dotWidth });
      if (cfg.type === 'bluetooth') {
        await writeToPrinter(cfg.btAddress, bytes);
      } else {
        await printToNetworkPrinter({ ip: cfg.ip, port: cfg.port, bytes });
      }
      setStatus('printed');
      // the printer stays connected for the next bill
      setTimeout(onClose, 900);
    } catch (e) {
      setStatus('error');
      setStatusMessage(e.message);
    }
  };

  const handleClose = () => onClose();

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
    <Modal visible={visible} transparent animationType="fade" statusBarTranslucent navigationBarTranslucent onRequestClose={handleClose}>
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

                {/* Paper width selector — shared by both printer types.
                    Getting this wrong prints the receipt small/centered
                    (58 setting on 80mm paper) or cropped (80 on 58mm). */}
                <View style={styles.paperRow}>
                  <Text style={styles.paperLabel}>Paper width</Text>
                  <Pressable
                    style={[styles.paperChip, paperWidth === '58' && styles.paperChipActive]}
                    onPress={() => { setPaperWidth('58'); setStatus('idle'); }}
                  >
                    <Text style={[styles.paperChipText, paperWidth === '58' && styles.paperChipTextActive]}>58mm</Text>
                  </Pressable>
                  <Pressable
                    style={[styles.paperChip, paperWidth === '80' && styles.paperChipActive]}
                    onPress={() => { setPaperWidth('80'); setStatus('idle'); }}
                  >
                    <Text style={[styles.paperChipText, paperWidth === '80' && styles.paperChipTextActive]}>80mm</Text>
                  </Pressable>
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
                    {pairedDevices.length > 0 ? (
                      <ScrollView style={styles.deviceListScroll} showsVerticalScrollIndicator={false} nestedScrollEnabled>
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
                      </ScrollView>
                    ) : null}
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

          {/* The paper sits on a grey desk so its white edge reads as a
              slip, and keeps its print width (58mm / 80mm) while the
              sheet scrolls. */}
          <ScrollView
            style={styles.receiptScroll}
            contentContainerStyle={styles.receiptDesk}
            showsVerticalScrollIndicator={false}
            onLayout={(e) => setDeskWidth(e.nativeEvent.layout.width)}
          >
            <FitWidth available={deskWidth - spacing.sm * 2} contentWidth={receiptWidth(paperWidth)}>
              {/* collapsable={false} keeps Android's native view-flattening
                  optimization from dropping this node, which would otherwise
                  make react-native-view-shot capture a blank/wrong view. */}
              <View ref={receiptRef} collapsable={false} style={[styles.receiptCapture, shadow.sm]}>
                <Receipt data={data} paperWidth={paperWidth} />
              </View>
            </FitWidth>
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
    padding: spacing.lg,
  },
  sheet: {
    width: '100%',
    maxWidth: 400,
    maxHeight: '90%',
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
  paperRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    marginBottom: spacing.sm,
  },
  paperLabel: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
    marginRight: 2,
  },
  paperChip: {
    paddingHorizontal: spacing.md,
    paddingVertical: 5,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  paperChipActive: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  paperChipText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.inkSoft,
  },
  paperChipTextActive: {
    color: '#fff',
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
  // minWidth 0 lets the IP/port pair shrink to the sheet; web inputs
  // otherwise keep their intrinsic ~20-character width and overflow it.
  input: {
    minWidth: 0,
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
  // Capped so a phone with many paired Bluetooth devices scrolls its own
  // short list instead of pushing "Connect Printer" and the rest of the
  // sheet off the bottom, past this modal's own maxHeight.
  deviceListScroll: {
    maxHeight: 140,
    marginBottom: spacing.xs,
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
  // Shrinks to whatever the sheet's maxHeight leaves after the printer bar
  // and buttons, so a long bill scrolls instead of pushing them off screen.
  receiptScroll: {
    flexGrow: 0,
    flexShrink: 1,
    backgroundColor: '#ECE8E4',
    borderRadius: radius.md,
  },
  receiptDesk: {
    alignItems: 'center',
    paddingVertical: spacing.lg,
    paddingHorizontal: spacing.sm,
  },
  // This exact view is captured as a bitmap and printed (see handlePrint) —
  // it must stay pure white so the server's black/white threshold only
  // picks up the receipt's own ink.
  receiptCapture: {
    backgroundColor: '#fff',
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
