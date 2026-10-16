import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { Image } from 'expo-image';
import { useRef, useState } from 'react';
import { Animated, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BrandMark, Button } from '../components/ui';
import { BRAND, DEMO } from '../config/brand';
import { BANNERS, HERO_IMAGE, IMAGES } from '../data/restaurant';
import { useApp } from '../store/AppContext';
import { colors, CONTENT_MAX, font, radius } from '../theme';

const SLIDES = [
  { image: IMAGES['chicken-biryani'], title: 'Biryani, hot from the dum', text: 'Your favourite dishes from ' + BRAND.name + ', ordered in a few taps.' },
  { image: IMAGES['paneer-tikka'], title: 'Delivery, takeaway or dine-in', text: 'Order your way. Scan, skip the queue, or get it at your door.' },
  { image: IMAGES['gulab-jamun'], title: 'Offers and points on every order', text: 'Earn ' + BRAND.loyaltyName + ' and unlock deals made for regulars.' },
];

export function OnboardingScreen({ navigation }) {
  const insets = useSafeAreaInsets();
  const { width, height } = useWindowDimensions();
  const w = Math.min(width, CONTENT_MAX);
  const [index, setIndex] = useState(0);
  const ref = useRef(null);

  const next = () => {
    if (index < SLIDES.length - 1) {
      ref.current && ref.current.scrollTo({ x: w * (index + 1), animated: true });
      setIndex(index + 1);
    } else navigation.navigate('Login');
  };

  return (
    <View style={styles.dark}>
      <View style={{ flex: 1, width: '100%', maxWidth: CONTENT_MAX }}>
        <ScrollView
          ref={ref}
          horizontal
          pagingEnabled
          showsHorizontalScrollIndicator={false}
          onMomentumScrollEnd={(e) => setIndex(Math.round(e.nativeEvent.contentOffset.x / w))}
          style={{ flex: 1 }}
        >
          {SLIDES.map((s, i) => (
            <View key={i} style={{ width: w, height }}>
              <Image source={s.image} style={StyleSheet.absoluteFill} contentFit="cover" />
              <LinearGradient colors={['rgba(0,0,0,0.25)', 'rgba(0,0,0,0.05)', 'rgba(10,8,14,0.92)']} locations={[0, 0.35, 0.85]} style={StyleSheet.absoluteFill} />
              <View style={{ flex: 1, justifyContent: 'flex-end', paddingHorizontal: 28, paddingBottom: 170 }}>
                <Text style={styles.slideTitle}>{s.title}</Text>
                <Text style={styles.slideText}>{s.text}</Text>
              </View>
            </View>
          ))}
        </ScrollView>

        <View style={[styles.topBar, { top: insets.top + 12 }]}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
            <BrandMark size={38} light />
            <View>
              <Text style={styles.brandName}>{BRAND.name}</Text>
              <Text style={styles.brandTag}>{BRAND.area}</Text>
            </View>
          </View>
          <Pressable onPress={() => navigation.navigate('Login')} hitSlop={10}>
            <Text style={styles.skip}>Skip</Text>
          </Pressable>
        </View>

        <View style={[styles.bottom, { paddingBottom: Math.max(insets.bottom, 16) + 8 }]}>
          <View style={{ flexDirection: 'row', gap: 6 }}>
            {SLIDES.map((_, i) => (
              <View key={i} style={[styles.dotBase, i === index && { width: 24, backgroundColor: colors.primary }]} />
            ))}
          </View>
          <Button title={index === SLIDES.length - 1 ? 'Get started' : 'Next'} onPress={next} icon="arrow-forward" style={{ alignSelf: 'stretch' }} />
          {DEMO ? <Text style={styles.powered}>Powered by RestroGrow</Text> : null}
        </View>
      </View>
    </View>
  );
}

export function LoginScreen({ navigation }) {
  const { actions } = useApp();
  const insets = useSafeAreaInsets();
  const [step, setStep] = useState('phone');
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [otp, setOtp] = useState('');
  const [error, setError] = useState('');

  const sendOtp = () => {
    if (phone.replace(/\D/g, '').length !== 10) return setError('Enter a 10-digit mobile number');
    setError('');
    setStep('otp');
  };
  const verify = () => {
    if (otp.length < 4) return setError('Enter the 4-digit code');
    setError('');
    setStep('name');
  };
  const finish = () => {
    actions.login({ name: name.trim() || 'Friend', phone: phone.replace(/\D/g, ''), guest: false });
  };

  return (
    <View style={styles.light}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, width: '100%', maxWidth: CONTENT_MAX }}>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1 }} showsVerticalScrollIndicator={false}>
          <View style={styles.loginHero}>
            <Image source={HERO_IMAGE} style={StyleSheet.absoluteFill} contentFit="cover" />
            <LinearGradient colors={['rgba(0,0,0,0.3)', 'rgba(0,0,0,0.65)']} style={StyleSheet.absoluteFill} />
            <View style={{ paddingTop: insets.top + 24, paddingHorizontal: 24, paddingBottom: 56 }}>
              <BrandMark size={54} light />
              <Text style={styles.loginName}>{BRAND.name}</Text>
              <Text style={styles.loginTag}>{BRAND.tagline}</Text>
            </View>
          </View>

          <View style={styles.sheet}>
            {step === 'phone' && (
              <>
                <Text style={styles.h2}>Log in or sign up</Text>
                <Text style={styles.p}>We will text you a code to confirm your number.</Text>
                <View style={styles.phoneRow}>
                  <Text style={styles.code}>+91</Text>
                  <TextInput
                    value={phone}
                    onChangeText={(t) => setPhone(t.replace(/\D/g, '').slice(0, 10))}
                    placeholder="Mobile number"
                    placeholderTextColor={colors.muted}
                    keyboardType="number-pad"
                    style={styles.input}
                    onSubmitEditing={sendOtp}
                  />
                </View>
                {error ? <Text style={styles.error}>{error}</Text> : null}
                <Button title="Send code" onPress={sendOtp} style={{ marginTop: 16 }} />
              </>
            )}
            {step === 'otp' && (
              <>
                <Text style={styles.h2}>Enter the code</Text>
                <Text style={styles.p}>Sent to +91 {phone}.{DEMO ? ' This is a demo, so any 4 digits work.' : ''}</Text>
                <TextInput
                  value={otp}
                  onChangeText={(t) => setOtp(t.replace(/\D/g, '').slice(0, 4))}
                  placeholder="----"
                  placeholderTextColor={colors.borderStrong}
                  keyboardType="number-pad"
                  style={styles.otp}
                  onSubmitEditing={verify}
                  autoFocus
                />
                {error ? <Text style={styles.error}>{error}</Text> : null}
                <Button title="Verify" onPress={verify} style={{ marginTop: 16 }} />
                <Pressable onPress={() => { setStep('phone'); setOtp(''); }} style={{ alignSelf: 'center', marginTop: 14 }}>
                  <Text style={styles.link}>Change number</Text>
                </Pressable>
              </>
            )}
            {step === 'name' && (
              <>
                <Text style={styles.h2}>Almost there</Text>
                <Text style={styles.p}>What should we call you?</Text>
                <TextInput value={name} onChangeText={setName} placeholder="Your name" placeholderTextColor={colors.muted} style={[styles.input, styles.inputBox]} autoFocus onSubmitEditing={finish} />
                <Button title="Start ordering" onPress={finish} icon="restaurant" style={{ marginTop: 16 }} />
              </>
            )}

            {step === 'phone' && (
              <Pressable onPress={() => actions.login({ name: 'Guest', phone: '', guest: true })} style={styles.guest}>
                <Text style={styles.guestText}>Continue as guest</Text>
                <Ionicons name="arrow-forward" size={15} color={colors.inkSoft} />
              </Pressable>
            )}
            <Text style={styles.terms}>By continuing you agree to our Terms of Service and Privacy Policy.</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  dark: { flex: 1, backgroundColor: '#0E0C12', alignItems: 'center' },
  light: { flex: 1, backgroundColor: colors.bg, alignItems: 'center' },
  slideTitle: { fontFamily: font.bold, fontSize: 30, lineHeight: 38, color: '#fff' },
  slideText: { fontFamily: font.regular, fontSize: 15, lineHeight: 23, color: 'rgba(255,255,255,0.82)', marginTop: 10 },
  topBar: { position: 'absolute', left: 20, right: 20, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  brandName: { fontFamily: font.semiBold, fontSize: 15, color: '#fff' },
  brandTag: { fontFamily: font.regular, fontSize: 11.5, color: 'rgba(255,255,255,0.75)' },
  skip: { fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
  bottom: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingHorizontal: 24, gap: 18, alignItems: 'flex-start' },
  dotBase: { height: 6, width: 6, borderRadius: 3, backgroundColor: 'rgba(255,255,255,0.4)' },
  powered: { alignSelf: 'center', fontFamily: font.medium, fontSize: 11.5, color: 'rgba(255,255,255,0.55)' },

  loginHero: { overflow: 'hidden' },
  loginName: { fontFamily: font.bold, fontSize: 28, color: '#fff', marginTop: 16 },
  loginTag: { fontFamily: font.regular, fontSize: 14, color: 'rgba(255,255,255,0.85)', marginTop: 2 },
  sheet: { flex: 1, backgroundColor: colors.bg, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, marginTop: -28, padding: 24 },
  h2: { fontFamily: font.bold, fontSize: 22, color: colors.ink },
  p: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft, marginTop: 4, marginBottom: 18, lineHeight: 20 },
  phoneRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.borderStrong, paddingHorizontal: 14 },
  code: { fontFamily: font.semiBold, fontSize: 16, color: colors.ink, paddingRight: 12, marginRight: 12, borderRightWidth: 1, borderRightColor: colors.border },
  input: { flex: 1, fontFamily: font.medium, fontSize: 16, color: colors.ink, paddingVertical: 14, outlineStyle: 'none' },
  inputBox: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.borderStrong, paddingHorizontal: 14, flex: 0 },
  otp: { fontFamily: font.bold, fontSize: 30, letterSpacing: 18, textAlign: 'center', color: colors.ink, backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1.5, borderColor: colors.borderStrong, paddingVertical: 14, outlineStyle: 'none' },
  error: { fontFamily: font.medium, fontSize: 12.5, color: colors.danger, marginTop: 8 },
  link: { fontFamily: font.semiBold, fontSize: 13.5, color: colors.primary },
  guest: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 22, paddingVertical: 12 },
  guestText: { fontFamily: font.semiBold, fontSize: 14, color: colors.inkSoft },
  terms: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, textAlign: 'center', marginTop: 10, lineHeight: 17 },
});
