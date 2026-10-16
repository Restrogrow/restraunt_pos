import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Linking, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Button, Field } from '../components/kit';
import FadeIn from '../components/ui/FadeIn';
import { BASE_URL } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';

export default function LoginScreen() {
  const { login } = useAuth();
  const insets = useSafeAreaInsets();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const submit = async () => {
    if (!username.trim() || !password) {
      setError('Enter your username and password');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await login(username.trim(), password);
    } catch (e) {
      setError(e.message || 'Could not sign in');
    } finally {
      setBusy(false);
    }
  };

  return (
    <LinearGradient colors={[colors.navySoft, colors.navy, colors.navyDark]} style={{ flex: 1 }}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={[styles.scroll, { paddingTop: insets.top + 40, paddingBottom: insets.bottom + 30 }]}
          keyboardShouldPersistTaps="handled"
        >
          <FadeIn style={styles.brand}>
            <View style={styles.logoWrap}>
              <Image source={require('../assets/restrogrow-logo.png')} style={styles.logo} resizeMode="contain" />
            </View>
            <Text style={styles.appName}>RestroGrow</Text>
            <View style={styles.tag}>
              <Ionicons name="shield-checkmark" size={13} color={colors.primary} />
              <Text style={styles.tagText}>SUPER ADMIN</Text>
            </View>
            <Text style={styles.lead}>Every restaurant on the platform, in your pocket.</Text>
          </FadeIn>

          <FadeIn index={1} style={[styles.card, shadow.lg]}>
            <Text style={styles.cardTitle}>Sign in</Text>
            <Text style={styles.cardSub}>Use your Super Admin username and password.</Text>
            <Field label="Username" value={username} onChangeText={setUsername} placeholder="Username" textContentType="username" autoComplete="username" returnKeyType="next" />
            <Field
              label="Password"
              value={password}
              onChangeText={setPassword}
              placeholder="Password"
              secure
              textContentType="password"
              autoComplete="password"
              returnKeyType="go"
              onSubmitEditing={submit}
            />
            {error ? (
              <View style={styles.error}>
                <Ionicons name="alert-circle" size={16} color={colors.danger} />
                <Text style={styles.errorText}>{error}</Text>
              </View>
            ) : null}
            <Button title="Sign in" icon="log-in-outline" onPress={submit} loading={busy} style={{ marginTop: 4 }} />
            <Text style={styles.forgot} onPress={() => Linking.openURL(`${BASE_URL}/superadmin/forgot_password.php`)}>
              Forgot password?
            </Text>
          </FadeIn>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  scroll: { flexGrow: 1, justifyContent: 'center', paddingHorizontal: spacing.xl, maxWidth: 480, width: '100%', alignSelf: 'center' },
  brand: { alignItems: 'center', marginBottom: 28 },
  logoWrap: {
    width: 84,
    height: 84,
    borderRadius: 26,
    backgroundColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  logo: { width: 60, height: 60 },
  appName: { fontFamily: font.bold, fontSize: 28, color: '#fff' },
  tag: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginTop: 8,
    paddingHorizontal: 12,
    paddingVertical: 5,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(255,90,60,0.14)',
  },
  tagText: { fontFamily: font.bold, fontSize: 11, letterSpacing: 1.6, color: colors.primary },
  lead: { fontFamily: font.regular, fontSize: 14, color: 'rgba(255,255,255,0.7)', marginTop: 14, textAlign: 'center' },
  card: { backgroundColor: '#fff', borderRadius: radius.xl, padding: spacing.xl },
  cardTitle: { fontFamily: font.bold, fontSize: 20, color: colors.ink },
  cardSub: { fontFamily: font.regular, fontSize: 13, color: colors.muted, marginTop: 2, marginBottom: 18 },
  error: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.dangerBg,
    borderRadius: 12,
    padding: 12,
    marginBottom: 12,
  },
  errorText: { flex: 1, fontFamily: font.medium, fontSize: 12.5, color: colors.danger },
  forgot: { fontFamily: font.semiBold, fontSize: 13, color: colors.primary, textAlign: 'center', marginTop: 16 },
});
