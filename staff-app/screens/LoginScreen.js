import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Animated,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useAuth } from '../context/AuthContext';
import { DEMO_LOGIN } from '../config/api';
import { DEMO_STAFF, DEMO_STAFF_PASSWORD } from '../demo/DemoDeepLink';
import { colors, font, radius, shadow, spacing } from '../theme';

const RESEND_COOLDOWN_SECONDS = 60;
const LOGO = require('../assets/logo-wordmark.png'); // 1000 × 184
const NATIVE_DRIVER = Platform.OS !== 'web';

// What each role gets after logging in, shown as chips so staff know they're in the right app.
const ROLES = [
  ['restaurant-outline', 'Waiters'],
  ['flame-outline', 'Chefs'],
  ['briefcase-outline', 'Managers'],
];

export default function LoginScreen() {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const passwordRef = useRef(null);

  // Device-verification step — only appears the first time this phone logs in to a given
  // account. Once the server marks the device trusted, every later login skips straight through.
  const [otpRequired, setOtpRequired] = useState(false);
  const [maskedPhone, setMaskedPhone] = useState('');
  const [otpCode, setOtpCode] = useState('');
  const [resendCooldown, setResendCooldown] = useState(0);
  const cooldownTimer = useRef(null);

  // gentle entrance: logo fades in, the form slides up
  const enter = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.timing(enter, { toValue: 1, duration: 650, useNativeDriver: NATIVE_DRIVER }).start();
  }, [enter]);
  const heroAnim = { opacity: enter, transform: [{ translateY: enter.interpolate({ inputRange: [0, 1], outputRange: [14, 0] }) }] };
  const cardAnim = { transform: [{ translateY: enter.interpolate({ inputRange: [0, 1], outputRange: [60, 0] }) }] };

  useEffect(() => {
    return () => {
      if (cooldownTimer.current) clearInterval(cooldownTimer.current);
    };
  }, []);

  const startResendCooldown = () => {
    setResendCooldown(RESEND_COOLDOWN_SECONDS);
    if (cooldownTimer.current) clearInterval(cooldownTimer.current);
    cooldownTimer.current = setInterval(() => {
      setResendCooldown((s) => {
        if (s <= 1) {
          clearInterval(cooldownTimer.current);
          cooldownTimer.current = null;
          return 0;
        }
        return s - 1;
      });
    }, 1000);
  };

  const resetOtpState = () => {
    setOtpRequired(false);
    setOtpCode('');
    setMaskedPhone('');
    setInfo('');
    setResendCooldown(0);
    if (cooldownTimer.current) {
      clearInterval(cooldownTimer.current);
      cooldownTimer.current = null;
    }
  };

  const attemptLogin = async (codeToSend) => {
    setError('');
    setSubmitting(true);
    try {
      await login(username.trim(), password, codeToSend);
      // Success — AuthContext flips the app over to the staff tabs.
    } catch (e) {
      if (e.requiresOtp) {
        setOtpRequired(true);
        setMaskedPhone(e.maskedPhone);
        setInfo(e.message || 'A verification code was sent via WhatsApp.');
        startResendCooldown();
      } else {
        setError(e.message || 'Login failed');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const onSubmit = () => {
    if (!otpRequired) {
      if (!username.trim() || !password) {
        setError('Enter your email or phone number and your password');
        return;
      }
      attemptLogin(undefined);
      return;
    }
    if (!otpCode.trim()) {
      setError('Enter the code sent to your WhatsApp');
      return;
    }
    attemptLogin(otpCode.trim());
  };

  const onResend = () => {
    if (resendCooldown > 0 || submitting) return;
    setOtpCode('');
    attemptLogin(undefined);
  };

  return (
    <LinearGradient colors={[colors.primary, colors.primaryDark]} style={styles.fill}>
      <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          <Animated.View style={[styles.hero, heroAnim]}>
            <View style={[styles.logoPill, shadow.md]}>
              <Image source={LOGO} style={styles.logo} resizeMode="contain" accessibilityLabel="RestroGrow" />
              <View style={styles.staffTag}>
                <Text style={styles.staffTagText}>STAFF</Text>
              </View>
            </View>
            <Text style={styles.tagline}>Everything you need for your shift</Text>
            <View style={styles.roles}>
              {ROLES.map(([icon, label]) => (
                <View key={label} style={styles.roleChip}>
                  <Ionicons name={icon} size={14} color="#fff" />
                  <Text style={styles.roleText}>{label}</Text>
                </View>
              ))}
            </View>
          </Animated.View>

          <Animated.View style={[styles.card, shadow.lg, cardAnim]}>
            {!otpRequired ? (
              <>
                <Text style={styles.welcome}>Staff log in</Text>
                <Text style={styles.subtitle}>Use the email or phone number and password your manager set up for you.</Text>
                {DEMO_LOGIN ? (
                  <View style={styles.demoBox}>
                    <Text style={styles.demoText}>Live demo of Spice Garden Indiranagar. Tap a role to log in as that staff member.</Text>
                    <View style={styles.demoRow}>
                      {[['manager', 'Manager'], ['waiter', 'Waiter'], ['chef', 'Chef']].map(([key, label]) => (
                        <Pressable
                          key={key}
                          style={styles.demoBtn}
                          disabled={submitting}
                          onPress={() => {
                            setError('');
                            setSubmitting(true);
                            login(DEMO_STAFF[key], DEMO_STAFF_PASSWORD).catch((e) => setError(e.message || 'Could not log in')).finally(() => setSubmitting(false));
                          }}
                        >
                          <Text style={styles.demoBtnText}>{label}</Text>
                        </Pressable>
                      ))}
                    </View>
                  </View>
                ) : null}

                <View style={styles.field}>
                  <Ionicons name="person-outline" size={18} color={colors.muted} style={styles.fieldIcon} />
                  <TextInput
                    style={styles.input}
                    placeholder="Email or phone number"
                    placeholderTextColor={colors.muted}
                    autoCapitalize="none"
                    autoCorrect={false}
                    keyboardType="email-address"
                    returnKeyType="next"
                    value={username}
                    onChangeText={setUsername}
                    onSubmitEditing={() => passwordRef.current?.focus()}
                  />
                </View>

                <View style={styles.field}>
                  <Ionicons name="lock-closed-outline" size={18} color={colors.muted} style={styles.fieldIcon} />
                  <TextInput
                    ref={passwordRef}
                    style={styles.input}
                    placeholder="Password"
                    placeholderTextColor={colors.muted}
                    secureTextEntry={!showPassword}
                    returnKeyType="go"
                    value={password}
                    onChangeText={setPassword}
                    onSubmitEditing={onSubmit}
                  />
                  <Pressable onPress={() => setShowPassword((s) => !s)} hitSlop={10} accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}>
                    <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={18} color={colors.muted} />
                  </Pressable>
                </View>
              </>
            ) : (
              <>
                <Text style={styles.welcome}>Verify this phone</Text>
                <Text style={styles.subtitle}>
                  {maskedPhone ? `Enter the 6-digit code sent via WhatsApp to ${maskedPhone}.` : 'Enter the 6-digit code sent via WhatsApp.'}
                </Text>

                <View style={styles.field}>
                  <Ionicons name="shield-checkmark-outline" size={18} color={colors.muted} style={styles.fieldIcon} />
                  <TextInput
                    style={[styles.input, styles.otpInput]}
                    placeholder="6-digit code"
                    placeholderTextColor={colors.muted}
                    keyboardType="number-pad"
                    maxLength={6}
                    autoFocus
                    value={otpCode}
                    onChangeText={(v) => setOtpCode(v.replace(/\D/g, ''))}
                    onSubmitEditing={onSubmit}
                  />
                </View>

                {info ? (
                  <View style={styles.infoBox}>
                    <Ionicons name="information-circle" size={15} color={colors.info} />
                    <Text style={styles.infoText}>{info}</Text>
                  </View>
                ) : null}

                <View style={styles.otpActions}>
                  <Pressable onPress={resetOtpState} hitSlop={10}>
                    <Text style={styles.linkText}>Use a different account</Text>
                  </Pressable>
                  <Pressable onPress={onResend} disabled={resendCooldown > 0} hitSlop={10}>
                    <Text style={[styles.linkText, resendCooldown > 0 && styles.linkTextDisabled]}>
                      {resendCooldown > 0 ? `Resend code (${resendCooldown}s)` : 'Resend code'}
                    </Text>
                  </Pressable>
                </View>
              </>
            )}

            {error ? (
              <View style={styles.errorBox}>
                <Ionicons name="alert-circle" size={15} color={colors.danger} />
                <Text style={styles.error}>{error}</Text>
              </View>
            ) : null}

            <Pressable style={({ pressed }) => [styles.button, pressed && { opacity: 0.9 }]} onPress={onSubmit} disabled={submitting}>
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <>
                  <Text style={styles.buttonText}>{otpRequired ? 'Verify & Log In' : 'Start my shift'}</Text>
                  <Ionicons name="arrow-forward" size={18} color="#fff" />
                </>
              )}
            </Pressable>

            {!otpRequired ? (
              <View style={styles.help}>
                <Ionicons name="help-circle-outline" size={15} color={colors.muted} />
                <Text style={styles.helpText}>Forgot your password or no login yet? Ask your manager to reset it in the RestroGrow dashboard under Staff.</Text>
              </View>
            ) : null}
          </Animated.View>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  demoBox: { marginTop: spacing.md, padding: spacing.md, borderRadius: radius.md, backgroundColor: colors.primaryLight || '#FFF1EC', gap: spacing.sm },
  demoText: { fontFamily: font.medium, fontSize: 13, color: colors.text, lineHeight: 19 },
  demoRow: { flexDirection: 'row', gap: spacing.sm },
  demoBtn: { flex: 1, paddingVertical: 11, borderRadius: radius.md, backgroundColor: colors.primary, alignItems: 'center' },
  demoBtnText: { fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
  fill: { flex: 1 },
  scroll: {
    flexGrow: 1,
    justifyContent: 'flex-end',
  },
  hero: {
    alignItems: 'center',
    paddingTop: spacing.xxl * 2,
    paddingBottom: spacing.xxl,
    paddingHorizontal: spacing.xl,
  },
  logoPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: '#fff',
    borderRadius: 18,
    paddingHorizontal: 18,
    paddingVertical: 13,
  },
  logo: {
    width: 140,
    height: 26, // keeps the wordmark's 1000 × 184 shape
  },
  staffTag: {
    backgroundColor: colors.ink,
    borderRadius: 7,
    paddingHorizontal: 7,
    paddingVertical: 3,
  },
  staffTagText: {
    fontFamily: font.bold,
    fontSize: 10.5,
    letterSpacing: 1.2,
    color: '#fff',
  },
  tagline: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: 'rgba(255,255,255,0.92)',
    marginTop: spacing.md,
  },
  roles: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'center',
    gap: spacing.sm,
    marginTop: spacing.lg,
  },
  roleChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(255,255,255,0.18)',
    borderRadius: radius.pill,
    paddingHorizontal: 12,
    paddingVertical: 7,
  },
  roleText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: '#fff',
  },
  card: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.xxl,
    paddingBottom: spacing.xxl,
  },
  welcome: {
    fontFamily: font.bold,
    fontSize: 22,
    color: colors.ink,
  },
  subtitle: {
    fontFamily: font.regular,
    fontSize: 13.5,
    color: colors.muted,
    marginTop: 4,
    marginBottom: spacing.xl,
    lineHeight: 19,
  },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.bg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    marginBottom: spacing.md,
  },
  fieldIcon: {
    marginRight: spacing.sm,
  },
  input: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 15,
    color: colors.ink,
    paddingVertical: 14,
  },
  otpInput: {
    letterSpacing: 4,
  },
  infoBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    marginBottom: spacing.md,
  },
  infoText: {
    fontFamily: font.regular,
    color: colors.info,
    fontSize: 12.5,
    flexShrink: 1,
  },
  otpActions: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
  },
  linkText: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.primary,
  },
  linkTextDisabled: {
    color: colors.muted,
  },
  errorBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginBottom: spacing.md,
  },
  error: {
    fontFamily: font.regular,
    color: colors.danger,
    fontSize: 13,
    flexShrink: 1,
  },
  button: {
    flexDirection: 'row',
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 16,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    marginTop: spacing.sm,
  },
  buttonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 16,
  },
  help: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    marginTop: spacing.lg,
  },
  helpText: {
    flex: 1,
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    lineHeight: 17,
  },
});
