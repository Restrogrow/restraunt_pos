import AsyncStorage from '@react-native-async-storage/async-storage';
import { Ionicons } from '@expo/vector-icons';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import { Component, useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Dimensions, Platform, Pressable, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import * as ScreenOrientation from 'expo-screen-orientation';
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
import LiveFeed from './components/LiveFeed';
import OrderAlertWatcher from './components/OrderAlertWatcher';
import WebAudioUnlockBanner from './components/WebAudioUnlockBanner';
import { FeedbackHost } from './components/ui/Feedback';
import ConnectionBanner from './components/ConnectionBanner';
import { CONTENT_MAX } from './hooks/useLayout';
import { getBiometricLockEnabled } from './config/biometricSettings';
import { navigationRef } from './navigationRef';
import DemoDeepLink from './demo/DemoDeepLink';
import { colors, font } from './theme';
import { authenticate, isBiometricSupported } from './utils/biometricAuth';
import { keepPrinterConnected, stopKeepingPrinterConnected } from './utils/bluetoothPrinter';
import { getPrinterSettings } from './config/printerSettings';
import LoginScreen from './screens/LoginScreen';
import WelcomeScreen from './screens/WelcomeScreen';
import { DEMO, DEMO_LOGIN } from './config/api';
import { staffRole } from './utils/roles';
import HomeScreen from './screens/HomeScreen';
import ShiftHomeScreen from './screens/ShiftHomeScreen';
import RiderHomeScreen from './screens/RiderHomeScreen';
import RidersScreen from './screens/RidersScreen';
import OrdersScreen from './screens/OrdersScreen';
import OrderDetailScreen from './screens/OrderDetailScreen';
import POSScreen from './screens/POSScreen';
import MenuScreen from './screens/MenuScreen';
import MenuItemScreen from './screens/MenuItemScreen';
import MoreScreen from './screens/MoreScreen';
import KitchenScreen from './screens/KitchenScreen';
import TablesScreen from './screens/TablesScreen';
import WaiterCallsScreen from './screens/WaiterCallsScreen';
import ReservationsScreen from './screens/ReservationsScreen';
import CustomersScreen from './screens/CustomersScreen';
import ReportsScreen from './screens/ReportsScreen';
import SettingsScreen, { SettingsSectionScreen } from './screens/SettingsScreen';
import LogsScreen from './screens/LogsScreen';

SplashScreen.preventAutoHideAsync().catch(() => {});

// A render error anywhere below this (a bad API response shaped
// unexpectedly, a null field the UI didn't guard for, ...) used to take the
// whole app down to a blank/crashed screen with no way back short of force-
// closing and reopening. Error boundaries only catch render-time errors —
// not the try/catch'd async ones already handled per-screen — so this is
// specifically the "something I didn't anticipate" safety net.
class ErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  render() {
    if (this.state.error) {
      return (
        <View style={crashStyles.wrap}>
          <View style={crashStyles.iconWrap}>
            <Ionicons name="alert-circle-outline" size={34} color={colors.danger} />
          </View>
          <Text style={crashStyles.title}>Something went wrong</Text>
          <Text style={crashStyles.subtitle}>
            The app hit an unexpected problem. Tap below to try again — if it keeps happening, let support know.
          </Text>
          <Pressable
            style={({ pressed }) => [crashStyles.button, pressed && { opacity: 0.9 }]}
            onPress={() => this.setState({ error: null })}
          >
            <Ionicons name="refresh" size={16} color="#fff" />
            <Text style={crashStyles.buttonText}>Try Again</Text>
          </Pressable>
        </View>
      );
    }
    return this.props.children;
  }
}

const crashStyles = StyleSheet.create({
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
    backgroundColor: colors.dangerBg,
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

const APP_NAME = 'RestroGrow Staff';

// Deliberately NOT persisting react-navigation's state anymore. Saving the
// stack meant an auto-opened OrderDetail (new-order alert) got saved as the
// active route — so every subsequent app launch reopened that same order
// detail screen before anything else, even long after it was handled.
// Cold start now always lands on the Orders tab, which is what counter
// staff expect.

// Wide screens (a desktop browser, a big tablet in landscape) get the app as a centred
// column (CONTENT_MAX) instead of stretching cards, grids and the floating bars edge to
// edge. Phones are untouched — they're always narrower than the cap.
function WebFrame({ children }) {
  const { width } = useWindowDimensions();
  if (width <= CONTENT_MAX) {
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
    maxWidth: CONTENT_MAX,
    overflow: 'hidden',
    ...Platform.select({ web: { boxShadow: '0 0 40px rgba(0,0,0,0.35)' }, default: {} }),
  },
});

// Phones stay upright (the layouts are built for portrait); tablets may rotate freely.
function useOrientationPolicy() {
  useEffect(() => {
    if (Platform.OS === 'web') return;
    const { width, height } = Dimensions.get('screen');
    const tablet = Math.min(width, height) >= 600;
    (tablet
      ? ScreenOrientation.unlockAsync()
      : ScreenOrientation.lockAsync(ScreenOrientation.OrientationLock.PORTRAIT_UP)
    ).catch(() => {});
  }, []);
}

const Tab = createBottomTabNavigator();
const OrdersStackNav = createNativeStackNavigator();
const MenuStackNav = createNativeStackNavigator();
const MoreStackNav = createNativeStackNavigator();

// Pushed screens slide in from the side, like any native app.
const stackOptions = { headerShown: false, animation: 'slide_from_right', contentStyle: { backgroundColor: colors.bg } };

// Orders tab is its own stack so tapping an order card can push a detail
// screen (with a real back button/gesture) instead of the tab just
// re-rendering in place.
function OrdersStack() {
  return (
    <OrdersStackNav.Navigator screenOptions={stackOptions}>
      <OrdersStackNav.Screen name="OrdersList" component={OrdersScreen} />
      <OrdersStackNav.Screen name="OrderDetail" component={OrderDetailScreen} />
    </OrdersStackNav.Navigator>
  );
}

// Same idea for Menu — tapping an item pushes a detail/edit screen, and the
// header "+" pushes the same screen in create mode.
function MenuStack() {
  return (
    <MenuStackNav.Navigator screenOptions={stackOptions}>
      <MenuStackNav.Screen name="MenuList" component={MenuScreen} />
      <MenuStackNav.Screen name="MenuItem" component={MenuItemScreen} />
    </MenuStackNav.Navigator>
  );
}

// Which pages each role gets, as bottom tabs and inside More. A page a role's login can't use
// (the server would refuse it) isn't shown at all, rather than opening onto an error.
//   chef:    Home · Kitchen · Orders · More
//   waiter:  Home · Tables · POS · Orders · More
//   manager: Home · Orders · POS · Menu · More   (the partner app's layout, sales included)
const LAYOUT = {
  chef: { tabs: ['Home', 'Kitchen', 'Orders', 'More'], more: ['Tables'] },
  waiter: { tabs: ['Home', 'Tables', 'POS', 'Orders', 'More'], more: ['Kitchen', 'WaiterCalls', 'Reservations', 'Customers'] },
  manager: { tabs: ['Home', 'Orders', 'POS', 'Menu', 'More'], more: ['Kitchen', 'Tables', 'WaiterCalls', 'Reservations', 'Customers', 'Riders', 'Reports', 'Logs'] },
  rider: { tabs: ['Home', 'More'], more: [] },
};

const MORE_SCREENS = {
  Kitchen: KitchenScreen,
  Tables: TablesScreen,
  WaiterCalls: WaiterCallsScreen,
  Reservations: ReservationsScreen,
  Customers: CustomersScreen,
  Reports: ReportsScreen,
  Logs: LogsScreen,
  Riders: RidersScreen,
};

// "More": everything beyond the everyday tabs, one tap from a tidy grid.
function MoreStack({ route }) {
  const pages = LAYOUT[route.params?.role || 'manager'].more;
  return (
    <MoreStackNav.Navigator screenOptions={stackOptions}>
      <MoreStackNav.Screen name="MoreHome" component={MoreScreen} />
      {pages.map((name) => (
        <MoreStackNav.Screen key={name} name={name} component={MORE_SCREENS[name]} />
      ))}
      <MoreStackNav.Screen name="Settings" component={SettingsScreen} />
      <MoreStackNav.Screen name="SettingsSection" component={SettingsSectionScreen} />
    </MoreStackNav.Navigator>
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
  const signedIn = !checkingSession && !!user;
  const [welcomeSeen, setWelcomeSeen] = useState(null);
  useEffect(() => {
    WelcomeScreen.seen().then(setWelcomeSeen);
  }, []);

  // keep the saved Bluetooth printer connected while someone is signed in
  useEffect(() => {
    if (!signedIn) return undefined;
    keepPrinterConnected(async () => {
      const p = await getPrinterSettings();
      return p.type === 'bluetooth' ? p.btAddress : '';
    });
    return stopKeepingPrinterConnected;
  }, [signedIn]);

  if (checkingSession || welcomeSeen === null) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  if (!user) {
    // first launch: the welcome slides, then login (the browser demo goes straight to login)
    if (!welcomeSeen && !DEMO && !DEMO_LOGIN) return <WelcomeScreen onDone={() => setWelcomeSeen(true)} />;
    return <LoginScreen />;
  }

  const role = staffRole(user);
  const tabs = {
    Home: role === 'manager' ? HomeScreen : role === 'rider' ? RiderHomeScreen : ShiftHomeScreen,
    Kitchen: KitchenScreen,
    Tables: TablesScreen,
    Orders: OrdersStack,
    POS: POSScreen,
    Menu: MenuStack,
    More: MoreStack,
  };

  // Mounted as soon as there's a logged-in user — independent of which tab
  // is open or whether the fingerprint lock screen is up — so what this role
  // must not miss still beeps no matter where in the app they are:
  //   chef: new kitchen tickets · waiter: online orders, waiter calls, food ready · manager: orders, calls
  return (
    <>
      {role !== 'chef' && role !== 'rider' ? <OrderAlertWatcher /> : null}
      {role !== 'rider' ? <LiveFeed role={role} /> : null}
      {role !== 'rider' ? <WebAudioUnlockBanner /> : null}
      {checkingLock ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
          <ActivityIndicator size="large" color={colors.primary} />
        </View>
      ) : locked ? (
        <LockScreen onRetry={retry} retrying={retrying} />
      ) : (
        <Tab.Navigator
          key={role}
          tabBar={(props) => <TabBar {...props} />}
          screenOptions={{
            headerShown: false,
            // tabs slide a little and cross-fade instead of snapping
            animation: 'shift',
            sceneStyle: { backgroundColor: colors.bg },
          }}
        >
          {LAYOUT[role].tabs.map((name) => (
            <Tab.Screen key={name} name={name} component={tabs[name]} initialParams={name === 'More' ? { role } : undefined} />
          ))}
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
  useOrientationPolicy();
  const [fontsLoaded, fontError] = useFonts({
    Poppins_400Regular,
    Poppins_500Medium,
    Poppins_600SemiBold,
    Poppins_700Bold,
  });

  // One-time cleanup of the stale nav-state blob saved by versions that
  // persisted the navigation stack (see comment above APP_NAME). Without
  // this the old JSON just rots in AsyncStorage forever.
  useEffect(() => {
    AsyncStorage.removeItem('navigationState_v1').catch(() => {});
  }, []);

  // The startup logo goes as soon as the fonts are ready. It used to wait for the navigator's onReady,
  // which never fires while the first screen is the welcome or login screen, so on a phone the app
  // stayed on the logo (then a black screen). A font that fails to load no longer blocks the app
  // (the phone's own font is used), and the logo always goes after 6 seconds whatever happens.
  const fontsReady = fontsLoaded || !!fontError;
  useEffect(() => {
    if (fontsReady) SplashScreen.hideAsync().catch(() => {});
  }, [fontsReady]);
  useEffect(() => {
    const t = setTimeout(() => SplashScreen.hideAsync().catch(() => {}), 6000);
    return () => clearTimeout(t);
  }, []);
  const onLayoutRootView = useCallback(() => {
    SplashScreen.hideAsync().catch(() => {});
  }, []);

  if (!fontsReady) {
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
          <NavigationContainer
            ref={navigationRef}
            onReady={onLayoutRootView}
            documentTitle={{
              formatter: (options, route) => options?.title ?? route?.name ?? APP_NAME,
            }}
          >
            <StatusBar style="light" />
            <DemoDeepLink />
            <ErrorBoundary>
              <RootNavigator />
            </ErrorBoundary>
            <ConnectionBanner />
            <FeedbackHost />
          </NavigationContainer>
        </AuthProvider>
      </SafeAreaProvider>
    </WebFrame>
  );
}
