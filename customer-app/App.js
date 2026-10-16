import { Ionicons } from '@expo/vector-icons';
import { NavigationContainer } from '@react-navigation/native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { StatusBar } from 'expo-status-bar';
import * as SplashScreen from 'expo-splash-screen';
import { Component, useEffect } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import {
  useFonts,
  Poppins_400Regular,
  Poppins_500Medium,
  Poppins_600SemiBold,
  Poppins_700Bold,
} from '@expo-google-fonts/poppins';

import TabBar from './components/TabBar';
import { Toast } from './components/ui';
import { BRAND } from './config/brand';
import { navigationRef } from './navigationRef';
import { AppProvider, useApp } from './store/AppContext';
import { colors, font } from './theme';

import { LoginScreen, OnboardingScreen } from './screens/AuthScreens';
import HomeScreen from './screens/HomeScreen';
import MenuScreen from './screens/MenuScreen';
import CartScreen from './screens/CartScreen';
import OrdersScreen from './screens/OrdersScreen';
import ProfileScreen from './screens/ProfileScreen';
import ItemScreen from './screens/ItemScreen';
import CheckoutScreen from './screens/CheckoutScreen';
import TrackScreen from './screens/TrackScreen';
import { AboutScreen, AddressesScreen, FavoritesScreen, OffersScreen, ReserveScreen } from './screens/MoreScreens';

SplashScreen.preventAutoHideAsync().catch(() => {});

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();

class ErrorBoundary extends Component {
  state = { error: null };
  static getDerivedStateFromError(error) {
    return { error };
  }
  render() {
    if (this.state.error) {
      return (
        <View style={styles.crash}>
          <Ionicons name="alert-circle-outline" size={44} color={colors.danger} />
          <Text style={styles.crashTitle}>Something went wrong</Text>
          <Text style={styles.crashText}>Please try again. If it keeps happening, contact the restaurant.</Text>
          <Pressable onPress={() => this.setState({ error: null })} style={styles.crashBtn}>
            <Text style={styles.crashBtnText}>Try again</Text>
          </Pressable>
        </View>
      );
    }
    return this.props.children;
  }
}

function Tabs() {
  return (
    <Tab.Navigator tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false }}>
      <Tab.Screen name="Home" component={HomeScreen} />
      <Tab.Screen name="Menu" component={MenuScreen} />
      <Tab.Screen name="Cart" component={CartScreen} />
      <Tab.Screen name="Orders" component={OrdersScreen} />
      <Tab.Screen name="Profile" component={ProfileScreen} />
    </Tab.Navigator>
  );
}

function Root() {
  const { user, onboarded, ready, toast } = useApp();

  if (!ready) {
    return (
      <View style={styles.boot}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  return (
    <View style={{ flex: 1 }}>
      <NavigationContainer ref={navigationRef} documentTitle={{ formatter: () => BRAND.name }}>
        <Stack.Navigator screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg }, animation: 'slide_from_right' }}>
          {!user ? (
            <>
              {!onboarded ? <Stack.Screen name="Onboarding" component={OnboardingScreen} /> : null}
              <Stack.Screen name="Login" component={LoginScreen} />
            </>
          ) : (
            <>
              <Stack.Screen name="Main" component={Tabs} />
              <Stack.Screen
                name="Item"
                component={ItemScreen}
                options={{ presentation: 'transparentModal', animation: 'fade', contentStyle: { backgroundColor: 'transparent' } }}
              />
              <Stack.Screen name="Checkout" component={CheckoutScreen} />
              <Stack.Screen name="Track" component={TrackScreen} />
              <Stack.Screen name="Offers" component={OffersScreen} />
              <Stack.Screen name="Favorites" component={FavoritesScreen} />
              <Stack.Screen name="Addresses" component={AddressesScreen} />
              <Stack.Screen name="Reserve" component={ReserveScreen} />
              <Stack.Screen name="About" component={AboutScreen} />
            </>
          )}
        </Stack.Navigator>
      </NavigationContainer>
      <Toast toast={toast} />
    </View>
  );
}

export default function App() {
  const [fontsLoaded] = useFonts({ Poppins_400Regular, Poppins_500Medium, Poppins_600SemiBold, Poppins_700Bold });

  useEffect(() => {
    if (fontsLoaded) SplashScreen.hideAsync().catch(() => {});
  }, [fontsLoaded]);

  if (!fontsLoaded) return null;

  return (
    <SafeAreaProvider>
      <ErrorBoundary>
        <AppProvider>
          <StatusBar style="dark" />
          <Root />
        </AppProvider>
      </ErrorBoundary>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  boot: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
  crash: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg, padding: 40 },
  crashTitle: { fontFamily: font.semiBold, fontSize: 18, color: colors.ink, marginTop: 14 },
  crashText: { fontFamily: font.regular, fontSize: 13.5, color: colors.inkSoft, textAlign: 'center', marginTop: 6 },
  crashBtn: { marginTop: 20, backgroundColor: colors.primary, paddingVertical: 12, paddingHorizontal: 26, borderRadius: 999 },
  crashBtnText: { fontFamily: font.semiBold, color: colors.onPrimary },
});
