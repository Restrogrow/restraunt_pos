import { Ionicons } from '@expo/vector-icons';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import {
  useFonts,
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
} from '@expo-google-fonts/poppins';
import * as SplashScreen from 'expo-splash-screen';

import { AuthProvider, useAuth } from './context/AuthContext';
import TabBar from './components/TabBar';
import OrderAlertWatcher from './components/OrderAlertWatcher';
import { getBiometricLockEnabled } from './config/biometricSettings';
import { navigationRef } from './navigationRef';
import { colors, font } from './theme';
import { authenticate, isBiometricSupported } from './utils/biometricAuth';
import LoginScreen from './screens/LoginScreen';
import OrdersScreen from './screens/OrdersScreen';
import OrderDetailScreen from './screens/OrderDetailScreen';
import POSScreen from './screens/POSScreen';
import MenuScreen from './screens/MenuScreen';
import MenuItemScreen from './screens/MenuItemScreen';
import ReportsScreen from './screens/ReportsScreen';
import SettingsScreen from './screens/SettingsScreen';

SplashScreen.preventAutoHideAsync().catch(() => {});

// This is a phone-shaped app — on native it always gets the full device
// width anyway, but on web (a desktop browser window) that same layout was
// stretching edge-to-edge and breaking (absolute-positioned bars spanning
// the whole window, grids over-widening, etc). Cap it to a phone-sized,
// centered column on web only; native is untouched.
function WebFrame({ children }) {
  if (Platform.OS !== 'web') {
    return children;
  }
  return (
    <View style={webFrameStyles.outer}>
      <View style={webFrameStyles.frame}>{children}</View>
    </View>
  );
}

const webFrameStyles = StyleSheet.create({
  outer: {
    flex: 1,
    alignItems: 'center',
    backgroundColor: '#12111A',
  },
  frame: {
    flex: 1,
    width: '100%',
    maxWidth: 480,
    boxShadow: '0 0 40px rgba(0,0,0,0.35)',
  },
});

const Tab = createBottomTabNavigator();
const OrdersStackNav = createNativeStackNavigator();
const MenuStackNav = createNativeStackNavigator();

// Orders tab is its own stack so tapping an order card can push a detail
// screen (with a real back button/gesture) instead of the tab just
// re-rendering in place.
function OrdersStack() {
  return (
    <OrdersStackNav.Navigator screenOptions={{ headerShown: false }}>
      <OrdersStackNav.Screen name="OrdersList" component={OrdersScreen} />
      <OrdersStackNav.Screen name="OrderDetail" component={OrderDetailScreen} />
    </OrdersStackNav.Navigator>
  );
}

// Same idea for Menu — tapping an item pushes a detail/edit screen, and the
// header "+" pushes the same screen in create mode.
function MenuStack() {
  return (
    <MenuStackNav.Navigator screenOptions={{ headerShown: false }}>
      <MenuStackNav.Screen name="MenuList" component={MenuScreen} />
      <MenuStackNav.Screen name="MenuItem" component={MenuItemScreen} />
    </MenuStackNav.Navigator>
  );
}

// Re-checks the fingerprint/Face ID gate whenever the authenticated area
// first mounts (covers the common case where the session cookie is still
// valid and the app would otherwise walk straight back in) and again every
// time the app returns to the foreground from background/inactive — so
// "reopening the app" is exactly when it re-locks, not just cold start.
function useAppLock(active) {
  const [locked, setLocked] = useState(false);
  const [checking, setChecking] = useState(true);
  const [retrying, setRetrying] = useState(false);
  const appState = useRef(AppState.currentState);

  const runLock = useCallback(async () => {
    const on = await getBiometricLockEnabled();
    const supported = on && (await isBiometricSupported());
    if (!supported) {
      setLocked(false);
      setChecking(false);
      return;
    }
    setChecking(false);
    setLocked(true);
    const ok = await authenticate();
    setLocked(!ok);
  }, []);

  // Only starts once there's an actual session to protect — otherwise a
  // fresh install would get a pointless fingerprint prompt before the
  // person has even logged in once.
  useEffect(() => {
    if (!active) return;
    runLock();
  }, [active, runLock]);

  useEffect(() => {
    if (!active) return;
    const sub = AppState.addEventListener('change', (next) => {
      const wasBackground = appState.current.match(/inactive|background/);
      appState.current = next;
      if (wasBackground && next === 'active') {
        runLock();
      }
    });
    return () => sub.remove();
  }, [active, runLock]);

  const retry = useCallback(async () => {
    setRetrying(true);
    const ok = await authenticate();
    setLocked(!ok);
    setRetrying(false);
  }, []);

  return { locked, checking, retry, retrying };
}

function LockScreen({ onRetry, retrying }) {
  return (
    <View style={lockStyles.wrap}>
      <View style={lockStyles.iconWrap}>
        <Ionicons name="finger-print" size={34} color={colors.primary} />
      </View>
      <Text style={lockStyles.title}>App Locked</Text>
      <Text style={lockStyles.subtitle}>Unlock with your fingerprint or Face ID to continue</Text>
      <Pressable style={({ pressed }) => [lockStyles.button, pressed && { opacity: 0.9 }]} onPress={onRetry} disabled={retrying}>
        {retrying ? (
          <ActivityIndicator color="#fff" size="small" />
        ) : (
          <>
            <Ionicons name="lock-open-outline" size={16} color="#fff" />
            <Text style={lockStyles.buttonText}>Try Again</Text>
          </>
        )}
      </Pressable>
    </View>
  );
}

const lockStyles = StyleSheet.create({
  wrap: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
    paddingHorizontal: 40,
  },
  iconWrap: {
    width: 76,
    height: 76,
    borderRadius: 38,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 20,
  },
  title: {
    fontFamily: font.semiBold,
    fontSize: 17,
    color: colors.ink,
    marginBottom: 6,
  },
  subtitle: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.inkSoft,
    textAlign: 'center',
    marginBottom: 26,
    lineHeight: 19,
  },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.primary,
    borderRadius: 999,
    paddingHorizontal: 28,
    paddingVertical: 13,
  },
  buttonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 14,
  },
});

function RootNavigator() {
  const { user, checkingSession } = useAuth();
  const { locked, checking: checkingLock, retry, retrying } = useAppLock(!checkingSession && !!user);

  if (checkingSession) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  if (!user) {
    return <LoginScreen />;
  }

  // Mounted as soon as there's a logged-in user — independent of which tab
  // is open or whether the fingerprint lock screen is up — so a new order
  // still beeps no matter where in the app you are.
  return (
    <>
      <OrderAlertWatcher />
      {checkingLock ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
          <ActivityIndicator size="large" color={colors.primary} />
        </View>
      ) : locked ? (
        <LockScreen onRetry={retry} retrying={retrying} />
      ) : (
        <Tab.Navigator
          tabBar={(props) => <TabBar {...props} />}
          screenOptions={{
            headerShown: false,
            sceneStyle: { backgroundColor: colors.bg },
          }}
        >
          <Tab.Screen name="Orders" component={OrdersStack} />
          <Tab.Screen name="POS" component={POSScreen} />
          <Tab.Screen name="Menu" component={MenuStack} />
          <Tab.Screen name="Reports" component={ReportsScreen} />
          <Tab.Screen name="Settings" component={SettingsScreen} />
        </Tab.Navigator>
      )}
    </>
  );
}

// RN Web's TextInput renders a real <input>/<textarea>, so on focus the
// browser draws its own default focus ring — a thick black outline in
// Chromium — on top of every text field in the app. There's no RN style
// prop for this (StyleSheet's outlineStyle isn't reliably picked up on
// every input across react-native-web versions), so it's neutralized once
// globally instead of chasing it field-by-field.
function useRemoveWebInputOutline() {
  useEffect(() => {
    if (Platform.OS !== 'web') return;
    const style = document.createElement('style');
    style.textContent = 'input, textarea { outline: none !important; }';
    document.head.appendChild(style);
    return () => style.remove();
  }, []);
}

export default function App() {
  useRemoveWebInputOutline();
  const [fontsLoaded] = useFonts({
    Poppins_400Regular,
    Poppins_500Medium,
    Poppins_600SemiBold,
    Poppins_700Bold,
  });

  const onLayoutRootView = useCallback(async () => {
    if (fontsLoaded) {
      await SplashScreen.hideAsync().catch(() => {});
    }
  }, [fontsLoaded]);

  if (!fontsLoaded) {
    return (
      <WebFrame>
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
          <ActivityIndicator size="large" color={colors.primary} />
        </View>
      </WebFrame>
    );
  }

  return (
    <WebFrame>
      <SafeAreaProvider>
        <AuthProvider>
          <NavigationContainer ref={navigationRef} onReady={onLayoutRootView}>
            <StatusBar style="light" />
            <RootNavigator />
          </NavigationContainer>
        </AuthProvider>
      </SafeAreaProvider>
    </WebFrame>
  );
}
