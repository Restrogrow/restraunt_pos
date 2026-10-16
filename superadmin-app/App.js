import { Ionicons } from '@expo/vector-icons';
import { Poppins_400Regular, Poppins_500Medium, Poppins_600SemiBold, Poppins_700Bold, useFonts } from '@expo-google-fonts/poppins';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { Component, useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Platform, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import ConnectionBanner from './components/ConnectionBanner';
import TabBar from './components/TabBar';
import { Button } from './components/kit';
import { FeedbackHost } from './components/ui/Feedback';
import { getAppLock, lockSupported, unlock } from './config/appLock';
import { AuthProvider, useAuth } from './context/AuthContext';
import { CONTENT_MAX } from './hooks/useLayout';
import { navigationRef, openFromNotification } from './navigationRef';
import { colors, font } from './theme';
import { notificationsAllowed, stopAlerts, syncBackgroundTask } from './utils/alerts';
import * as Notifications from 'expo-notifications';

import LoginScreen from './screens/LoginScreen';
import HomeScreen from './screens/HomeScreen';
import RestaurantsScreen from './screens/RestaurantsScreen';
import RestaurantDetailScreen from './screens/RestaurantDetailScreen';
import CreateRestaurantScreen from './screens/CreateRestaurantScreen';
import RenewalsScreen from './screens/RenewalsScreen';
import TicketsScreen from './screens/TicketsScreen';
import TicketScreen from './screens/TicketScreen';
import MoreScreen from './screens/MoreScreen';
import LeadsScreen from './screens/LeadsScreen';
import ChatLeadsScreen from './screens/ChatLeadsScreen';
import AnnouncementsScreen from './screens/AnnouncementsScreen';
import PaymentsScreen from './screens/PaymentsScreen';
import SecurityScreen from './screens/SecurityScreen';
import ActivityScreen from './screens/ActivityScreen';
import HealthScreen from './screens/HealthScreen';
import SettingsScreen from './screens/SettingsScreen';
import CouponsScreen from './screens/CouponsScreen';
import BranchAdminsScreen from './screens/BranchAdminsScreen';
import PaymentLinksScreen from './screens/PaymentLinksScreen';
import WebPanelScreen from './screens/WebPanelScreen';

SplashScreen.preventAutoHideAsync().catch(() => {});

// A render error anywhere below shows a way back instead of a blank screen.
class ErrorBoundary extends Component {
  state = { error: null };
  static getDerivedStateFromError(error) {
    return { error };
  }
  render() {
    if (this.state.error) {
      return (
        <View style={st.center}>
          <View style={[st.iconWrap, { backgroundColor: colors.dangerBg }]}>
            <Ionicons name="alert-circle-outline" size={34} color={colors.danger} />
          </View>
          <Text style={st.title}>Something went wrong</Text>
          <Text style={st.sub}>The app hit an unexpected problem. Tap below to try again.</Text>
          <Button title="Try again" icon="refresh" onPress={() => this.setState({ error: null })} />
        </View>
      );
    }
    return this.props.children;
  }
}

// Wide browser windows get the app as a centred column instead of stretched edge to edge.
function WebFrame({ children }) {
  const { width } = useWindowDimensions();
  if (width <= CONTENT_MAX) return children;
  return (
    <View style={{ flex: 1, alignItems: 'center', backgroundColor: colors.navyDark }}>
      <View style={{ flex: 1, width: '100%', maxWidth: CONTENT_MAX, overflow: 'hidden', ...Platform.select({ web: { boxShadow: '0 0 40px rgba(0,0,0,0.4)' }, default: {} }) }}>
        {children}
      </View>
    </View>
  );
}

const Tab = createBottomTabNavigator();
const Stack = createNativeStackNavigator();
const stackOptions = { headerShown: false, animation: 'slide_from_right', contentStyle: { backgroundColor: colors.bg } };

function makeStack(name, screens) {
  const S = createNativeStackNavigator();
  function StackScreen() {
    return (
      <S.Navigator screenOptions={stackOptions}>
        {screens.map(([n, c]) => (
          <S.Screen key={n} name={n} component={c} />
        ))}
      </S.Navigator>
    );
  }
  StackScreen.displayName = name;
  return StackScreen;
}

// Restaurant detail and ticket threads are shared, so they live on the root stack above the tabs.
const HomeStack = makeStack('HomeStack', [['HomeMain', HomeScreen]]);
const RestaurantsStack = makeStack('RestaurantsStack', [['RestaurantsList', RestaurantsScreen]]);
const RenewalsStack = makeStack('RenewalsStack', [['RenewalsList', RenewalsScreen]]);
const SupportStack = makeStack('SupportStack', [['TicketsList', TicketsScreen]]);
const MoreStack = makeStack('MoreStack', [
  ['MoreHome', MoreScreen],
  ['Leads', LeadsScreen],
  ['ChatLeads', ChatLeadsScreen],
  ['Announcements', AnnouncementsScreen],
  ['Payments', PaymentsScreen],
  ['Security', SecurityScreen],
  ['Activity', ActivityScreen],
  ['Health', HealthScreen],
  ['Settings', SettingsScreen],
  ['Coupons', CouponsScreen],
  ['BranchAdmins', BranchAdminsScreen],
  ['PaymentLinks', PaymentLinksScreen],
]);

function Tabs() {
  return (
    <Tab.Navigator tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false, animation: 'shift', sceneStyle: { backgroundColor: colors.bg } }}>
      <Tab.Screen name="Home" component={HomeStack} />
      <Tab.Screen name="Restaurants" component={RestaurantsStack} />
      <Tab.Screen name="Renewals" component={RenewalsStack} />
      <Tab.Screen name="Support" component={SupportStack} />
      <Tab.Screen name="More" component={MoreStack} />
    </Tab.Navigator>
  );
}

// Optional fingerprint / Face ID lock (Settings): asks on open and whenever the app comes back.
function useAppLock(active) {
  const [locked, setLocked] = useState(false);
  const [checking, setChecking] = useState(true);
  const last = useRef(AppState.currentState);
  const run = useCallback(async () => {
    const on = (await getAppLock()) && (await lockSupported());
    setChecking(false);
    if (!on) return setLocked(false);
    setLocked(true);
    setLocked(!(await unlock()));
  }, []);
  useEffect(() => {
    if (active) run();
    else setChecking(false);
  }, [active, run]);
  useEffect(() => {
    if (!active) return undefined;
    const sub = AppState.addEventListener('change', (next) => {
      if (/inactive|background/.test(last.current) && next === 'active') run();
      last.current = next;
    });
    return () => sub.remove();
  }, [active, run]);
  return { locked, checking, retry: async () => setLocked(!(await unlock())) };
}

// After sign-in: ask once for notification permission and start the background checks.
// Tapping a notification opens its screen. Signing out stops the checks.
function useAlerts(userId) {
  const was = useRef(false);
  useEffect(() => {
    if (Platform.OS === 'web') return undefined;
    if (!userId) {
      if (was.current) stopAlerts();
      was.current = false;
      return undefined;
    }
    was.current = true;
    notificationsAllowed(true).then(() => syncBackgroundTask());
    const open = (r) => {
      const screen = r?.notification?.request?.content?.data?.screen;
      if (screen) setTimeout(() => openFromNotification(screen), 300);
    };
    Notifications.getLastNotificationResponseAsync().then(open).catch(() => {});
    const sub = Notifications.addNotificationResponseReceivedListener(open);
    return () => sub.remove();
  }, [userId]);
}

function Root() {
  const { user, checkingSession } = useAuth();
  const { locked, checking, retry } = useAppLock(!checkingSession && !!user);
  useAlerts(checkingSession ? null : user?.id);

  if (checkingSession || (user && checking)) {
    return (
      <View style={[st.center, { backgroundColor: colors.navy }]}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }
  if (!user) return <LoginScreen />;
  if (locked) {
    return (
      <View style={st.center}>
        <View style={st.iconWrap}>
          <Ionicons name="finger-print" size={34} color={colors.primary} />
        </View>
        <Text style={st.title}>App locked</Text>
        <Text style={st.sub}>Unlock with your fingerprint or Face ID to continue</Text>
        <Button title="Unlock" icon="lock-open-outline" onPress={retry} />
      </View>
    );
  }
  return (
    <Stack.Navigator screenOptions={stackOptions}>
      <Stack.Screen name="Tabs" component={Tabs} />
      <Stack.Screen name="RestaurantDetail" component={RestaurantDetailScreen} />
      <Stack.Screen name="CreateRestaurant" component={CreateRestaurantScreen} options={{ animation: 'slide_from_bottom' }} />
      <Stack.Screen name="Ticket" component={TicketScreen} />
      <Stack.Screen name="WebPanel" component={WebPanelScreen} />
    </Stack.Navigator>
  );
}

function useRemoveWebInputOutline() {
  useEffect(() => {
    if (Platform.OS !== 'web') return undefined;
    const style = document.createElement('style');
    style.textContent = 'input, textarea { outline: none !important; }';
    document.head.appendChild(style);
    return () => style.remove();
  }, []);
}

export default function App() {
  useRemoveWebInputOutline();
  const [fontsLoaded, fontError] = useFonts({ Poppins_400Regular, Poppins_500Medium, Poppins_600SemiBold, Poppins_700Bold });
  // The startup logo goes as soon as the fonts are ready (not on the navigator's onReady, which doesn't
  // fire while the sign-in screen is shown), a failed font doesn't block the app, and the logo always
  // goes after 6 seconds.
  const fontsReady = fontsLoaded || !!fontError;
  useEffect(() => {
    if (fontsReady) SplashScreen.hideAsync().catch(() => {});
  }, [fontsReady]);
  useEffect(() => {
    const t = setTimeout(() => SplashScreen.hideAsync().catch(() => {}), 6000);
    return () => clearTimeout(t);
  }, []);
  const onReady = useCallback(() => {
    SplashScreen.hideAsync().catch(() => {});
  }, []);

  if (!fontsReady) {
    return (
      <View style={[st.center, { backgroundColor: colors.navy }]}>
        <ActivityIndicator size="large" color={colors.primary} />
      </View>
    );
  }

  return (
    <WebFrame>
      <SafeAreaProvider>
        <AuthProvider>
          <NavigationContainer ref={navigationRef} onReady={onReady} documentTitle={{ formatter: () => 'RestroGrow Super Admin' }}>
            <StatusBar style="light" />
            <ErrorBoundary>
              <Root />
            </ErrorBoundary>
            <ConnectionBanner />
            <FeedbackHost />
          </NavigationContainer>
        </AuthProvider>
      </SafeAreaProvider>
    </WebFrame>
  );
}

const st = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg, paddingHorizontal: 40 },
  iconWrap: {
    width: 76,
    height: 76,
    borderRadius: 38,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 20,
  },
  title: { fontFamily: font.semiBold, fontSize: 17, color: colors.ink, marginBottom: 6 },
  sub: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, textAlign: 'center', marginBottom: 26, lineHeight: 19 },
});
