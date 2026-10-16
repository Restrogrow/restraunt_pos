import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text } from 'react-native';
import Screen from '../components/Screen';
import { Button, Card, Field, Row, SectionTitle, ToggleRow } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import { pf } from '../config/api';
import { getAppLock, lockSupported, setAppLock, unlock } from '../config/appLock';
import { useAuth } from '../context/AuthContext';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';

const NUMBERS = ['trial_days', 'plan_price', 'plan_price_yearly', 'renewal_notice_days', 'gst_percent'];

export default function SettingsScreen() {
  const { user, logout } = useAuth();
  const fetcher = useCallback(() => pf.get('getSettings'), []);
  const { data, loading, error, refresh } = useApiData(fetcher, { cacheKey: 'settings' });
  const [f, setF] = useState(null);
  const [busy, setBusy] = useState('');
  const [testTo, setTestTo] = useState('');
  const [lock, setLock] = useState(false);
  const [canLock, setCanLock] = useState(false);

  useEffect(() => {
    if (data?.settings && !f) setF(data.settings);
  }, [data, f]);
  useEffect(() => {
    getAppLock().then(setLock);
    lockSupported().then(setCanLock);
  }, []);
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    for (const k of NUMBERS) if (f[k] !== '' && Number.isNaN(Number(f[k]))) return feedback.error(`${k.replace(/_/g, ' ')} must be a number`);
    if (f.biz_gstin && !/^[0-9]{2}[A-Z0-9]{13}$/i.test(f.biz_gstin.trim())) return feedback.error('GSTIN should be 15 characters, like 29ABCDE1234F1Z5');
    setBusy('save');
    try {
      const r = await pf.post('saveSettings', f);
      setF(r.settings);
      feedback.success('Settings saved');
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const testEmail = async () => {
    setBusy('email');
    try {
      const r = await pf.post('testEmail', { to: testTo.trim() });
      feedback.success(r.message, 6000);
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy('');
    }
  };

  const toggleLock = async (on) => {
    if (on && !(await unlock())) return;
    await setAppLock(on);
    setLock(on);
    feedback.success(on ? 'App lock on' : 'App lock off');
  };

  return (
    <Screen back eyebrow="Platform & app" title="Settings" loading={loading && !data} error={!data ? error : ''} onRetry={refresh}>
      {f ? (
        <>
          <SectionTitle title="Plans & trial" style={{ marginTop: 0 }} />
          <Card>
            <Field label="Free trial (days)" value={String(f.trial_days)} onChangeText={set('trial_days')} keyboardType="number-pad" hint="For restaurants created from now on" />
            <Field label="Monthly price (₹)" value={String(f.plan_price)} onChangeText={set('plan_price')} keyboardType="decimal-pad" />
            <Field label="Yearly price (₹)" value={String(f.plan_price_yearly)} onChangeText={set('plan_price_yearly')} keyboardType="decimal-pad" />
            <Field label="Renewal reminder (days before)" value={String(f.renewal_notice_days)} onChangeText={set('renewal_notice_days')} keyboardType="number-pad" />
            <ToggleRow title="Auto renewal" subtitle="Restaurants can renew themselves online" value={f.auto_renewal === '1'} onChange={(on) => set('auto_renewal')(on ? '1' : '0')} last />
          </Card>

          <SectionTitle title="Invoices & GST" />
          <Card>
            <Field label="Business name" value={f.biz_name} onChangeText={set('biz_name')} autoCapitalize="words" />
            <Field label="Address" value={f.biz_address} onChangeText={set('biz_address')} multiline autoCapitalize="sentences" />
            <Field label="GSTIN" value={f.biz_gstin} onChangeText={(v) => set('biz_gstin')(v.toUpperCase())} autoCapitalize="characters" />
            <Field label="Billing email" value={f.biz_email} onChangeText={set('biz_email')} keyboardType="email-address" />
            <Field label="Billing phone" value={f.biz_phone} onChangeText={set('biz_phone')} keyboardType="phone-pad" />
            <Field label="GST %" value={String(f.gst_percent)} onChangeText={set('gst_percent')} keyboardType="decimal-pad" />
            <Field label="Invoice prefix" value={f.invoice_prefix} onChangeText={set('invoice_prefix')} autoCapitalize="characters" />
            <ToggleRow title="Prices include GST" value={f.prices_include_gst === '1'} onChange={(on) => set('prices_include_gst')(on ? '1' : '0')} last />
          </Card>
          <Button title="Save settings" icon="save-outline" onPress={save} loading={busy === 'save'} style={{ marginTop: spacing.lg }} />

          <SectionTitle title="Email check" />
          <Card>
            <Field label="Send a test email to" value={testTo} onChangeText={setTestTo} keyboardType="email-address" placeholder="you@example.com" hint="Uses the live SMTP settings from main/.env" />
            <Button title="Send test email" icon="mail-outline" variant="soft" tone="info" onPress={testEmail} loading={busy === 'email'} />
          </Card>
        </>
      ) : null}

      <SectionTitle title="This app" />
      <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
        {canLock ? <ToggleRow icon="finger-print" iconTone="primary" title="App lock" subtitle="Ask for fingerprint / Face ID when opening" value={lock} onChange={toggleLock} /> : null}
        <Row icon="person-circle-outline" title="Signed in as" value={user?.username} />
        <Row
          icon="log-out-outline"
          danger
          title="Sign out"
          onPress={async () => {
            if (await feedback.confirm({ title: 'Sign out?', confirmText: 'Sign out', danger: true })) logout();
          }}
          last
        />
      </Card>
      <Text style={styles.foot}>Changes here apply to the whole platform and are recorded in the Activity log.</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  foot: { fontFamily: font.regular, fontSize: 12, color: colors.muted, textAlign: 'center', marginTop: 18 },
});
