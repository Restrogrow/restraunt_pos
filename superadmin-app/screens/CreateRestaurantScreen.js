import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Share, StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { Button, Card, Chips, Field } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import { SITE_URL, sa } from '../config/api';
import { colors, font, radius, spacing } from '../theme';

const COUNTRIES = ['India', 'United Arab Emirates', 'United Kingdom', 'United States', 'Nepal', 'Sri Lanka'];

function suggestPassword() {
  return Math.random().toString(36).slice(2, 7) + '@' + Math.floor(1000 + Math.random() * 9000);
}

export default function CreateRestaurantScreen() {
  const navigation = useNavigation();
  const [form, setForm] = useState({ restaurant_name: '', username: '', password: suggestPassword(), email: '', country: 'India' });
  const [busy, setBusy] = useState(false);
  const [created, setCreated] = useState(null);
  const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));

  // username follows the name until someone types their own
  const [userEdited, setUserEdited] = useState(false);
  const onName = (v) => {
    setForm((f) => ({ ...f, restaurant_name: v, username: userEdited ? f.username : v.toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 20) }));
  };

  const submit = async () => {
    if (!form.restaurant_name.trim() || !form.username.trim() || !form.password) return feedback.error('Name, username and password are needed');
    if (form.password.length < 6) return feedback.error('Password needs at least 6 characters');
    setBusy(true);
    try {
      const res = await sa.post('createRestaurant', { ...form, restaurant_name: form.restaurant_name.trim(), username: form.username.trim(), email: form.email.trim() });
      setCreated({ ...form, restaurant_id: res.restaurant_id });
      feedback.success('Restaurant created');
    } catch (e) {
      if (/Duplicate/i.test(e?.message || '')) feedback.error('That username is already taken');
      else feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  if (created) {
    return (
      <Screen back eyebrow="Done" title="Restaurant created">
        <Card style={{ alignItems: 'center', paddingVertical: 28 }}>
          <View style={styles.okIcon}>
            <Ionicons name="checkmark" size={34} color="#fff" />
          </View>
          <Text style={styles.okTitle}>{created.restaurant_name}</Text>
          <Text style={styles.okSub}>is on a free trial and can sign in now.</Text>
          <View style={styles.creds}>
            <Cred label="Restaurant ID" value={created.restaurant_id} />
            <Cred label="Username" value={created.username} />
            <Cred label="Password" value={created.password} last />
          </View>
          <Text style={styles.okNote}>Send these to the owner. They sign in at restrogrow.com or in the RestroGrow Partner app.</Text>
        </Card>
        <View style={{ gap: 10, marginTop: spacing.lg }}>
          <Button title="Share login details" icon="share-social-outline" onPress={() => shareCreds(created)} />
          <Button title="Add another" icon="add" variant="soft" onPress={() => { setCreated(null); setUserEdited(false); setForm({ restaurant_name: '', username: '', password: suggestPassword(), email: '', country: 'India' }); }} />
          <Button title="Done" tone="dark" onPress={() => navigation.goBack()} />
        </View>
      </Screen>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen back eyebrow="Onboard" title="New restaurant">
        <Card>
          <Field label="Restaurant name *" value={form.restaurant_name} onChangeText={onName} placeholder="e.g. Spice Garden" autoCapitalize="words" />
          <Field
            label="Username *"
            value={form.username}
            onChangeText={(v) => {
              setUserEdited(true);
              set('username')(v.replace(/\s/g, ''));
            }}
            placeholder="Used to sign in"
          />
          <Field label="Password *" value={form.password} onChangeText={set('password')} hint="Suggested for you. Change it if you like." />
          <Field label="Owner email" value={form.email} onChangeText={set('email')} placeholder="owner@example.com" keyboardType="email-address" hint="Needed for them to reset a forgotten password." />
          <Text style={styles.label}>Country</Text>
          <Chips value={form.country} onChange={set('country')} options={COUNTRIES.map((c) => ({ label: c, value: c }))} style={{ marginTop: 8 }} />
          <Text style={styles.hint}>Sets their currency. Trial length comes from Settings.</Text>
        </Card>
        <Button title="Create restaurant" icon="storefront" onPress={submit} loading={busy} style={{ marginTop: spacing.lg }} />
      </Screen>
    </KeyboardAvoidingView>
  );
}

async function shareCreds(c) {
  const site = SITE_URL.startsWith('http://') ? 'https://restrogrow.com' : SITE_URL;
  const text = `Welcome to RestroGrow! 🎉

Your restaurant ${c.restaurant_name} is ready.

Sign in: ${site}/main/admin/login.php
Username: ${c.username}
Password: ${c.password}

You can also use the RestroGrow Partner app. Please change your password after your first sign-in.`;
  try {
    if (Platform.OS === 'web') {
      if (navigator.share) await navigator.share({ text });
      else {
        await navigator.clipboard.writeText(text);
        feedback.success('Copied to clipboard');
      }
    } else {
      await Share.share({ message: text });
    }
  } catch (e) {
    // closed the share sheet
  }
}

function Cred({ label, value, last }) {
  return (
    <View style={[styles.credRow, !last && { borderBottomWidth: 1, borderBottomColor: colors.border }]}>
      <Text style={styles.credLabel}>{label}</Text>
      <Text style={styles.credValue} selectable>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  label: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
  hint: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, marginTop: 10 },
  okIcon: { width: 68, height: 68, borderRadius: 34, backgroundColor: colors.success, alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  okTitle: { fontFamily: font.bold, fontSize: 19, color: colors.ink, textAlign: 'center' },
  okSub: { fontFamily: font.regular, fontSize: 13, color: colors.muted, marginTop: 2 },
  creds: { alignSelf: 'stretch', backgroundColor: colors.bg, borderRadius: radius.md, paddingHorizontal: 14, marginTop: 20 },
  credRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 12 },
  credLabel: { fontFamily: font.medium, fontSize: 13, color: colors.muted },
  credValue: { fontFamily: font.bold, fontSize: 14, color: colors.ink },
  okNote: { fontFamily: font.regular, fontSize: 12, color: colors.muted, textAlign: 'center', marginTop: 14, lineHeight: 17 },
});
