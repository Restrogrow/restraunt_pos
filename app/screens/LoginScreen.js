import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
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
import { colors, font, radius, shadow, spacing } from '../theme';

const RESEND_COOLDOWN_SECONDS = 60;

export default function LoginScreen() {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Device-verification step — only appears the first time this install
  // logs in to a given account. Once the server marks the device trusted,
  // every later login skips straight through.
  const [otpRequired, setOtpRequired] = useState(false);
  const [maskedPhone, setMaskedPhone] = useState('');
  const [otpCode, setOtpCode] = useState('');
  const [resendCooldown, setResendCooldown] = useState(0);
  const cooldownTimer = useRef(null);

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
      await login(username, password, codeToSend);
      // Success — AuthContext flips the app over to the dashboard.
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
      if (!username || !password) {
        setError('Enter your username and password');
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
      <KeyboardAvoidingView
        style={styles.fill}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      >
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.hero}>
            <View style={styles.logoMark}>
              <Ionicons name="restaurant" size={26} color="#fff" />
            </View>
            <Text style={styles.brand}>Restrogrow</Text>
            <Text style={styles.tagline}>Run your restaurant from anywhere</Text>
          </View>

          <View style={[styles.card, shadow.lg]}>
            {!otpRequired ? (
              <>
                <Text style={styles.welcome}>Welcome back</Text>
                <Text style={styles.subtitle}>Log in to manage today's orders</Text>

                <View style={styles.field}>
                  <Ionicons name="person-outline" size={18} color={colors.muted} style={styles.fieldIcon} />
                  <TextInput
                    style={styles.input}
                    placeholder="Username or email"
                    placeholderTextColor={colors.muted}
                    autoCapitalize="none"
                    autoCorrect={false}
                    value={username}
                    onChangeText={setUsername}
                  />
                </View>

                <View style={styles.field}>
                  <Ionicons name="lock-closed-outline" size={18} color={colors.muted} style={styles.fieldIcon} />
                  <TextInput
                    style={styles.input}
                    placeholder="Password"
                    placeholderTextColor={colors.muted}
                    secureTextEntry={!showPassword}
                    value={password}
                    onChangeText={setPassword}
                  />
                  <Pressable onPress={() => setShowPassword((s) => !s)} hitSlop={10}>
                    <Ionicons
                      name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                      size={18}
                      color={colors.muted}
                    />
                  </Pressable>
                </View>
              </>
            ) : (
              <>
                <Text style={styles.welcome}>Verify this device</Text>
                <Text style={styles.subtitle}>
                  {maskedPhone
                    ? `Enter the 6-digit code sent via WhatsApp to ${maskedPhone}.`
                    : 'Enter the 6-digit code sent via WhatsApp.'}
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

            <Pressable
              style={({ pressed }) => [styles.button, pressed && { opacity: 0.9 }]}
              onPress={onSubmit}
              disabled={submitting}
            >
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <>
                  <Text style={styles.buttonText}>{otpRequired ? 'Verify & Log In' : 'Log In'}</Text>
                  <Ionicons name="arrow-forward" size={18} color="#fff" />
                </>
              )}
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  scroll: {
    flexGrow: 1,
    justifyContent: 'flex-end',
  },
  hero: {
    alignItems: 'center',
    paddingTop: spacing.xxl * 2,
    paddingBottom: spacing.xl,
    paddingHorizontal: spacing.xl,
  },
  logoMark: {
    width: 56,
    height: 56,
    borderRadius: 20,
    backgroundColor: 'rgba(255,255,255,0.2)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  brand: {
    fontFamily: font.bold,
    fontSize: 26,
    color: '#fff',
  },
  tagline: {
    fontFamily: font.regular,
    fontSize: 13.5,
    color: 'rgba(255,255,255,0.85)',
    marginTop: 4,
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
});
