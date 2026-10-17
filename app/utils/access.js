import { Ionicons } from '@expo/vector-icons';
import { StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../context/AuthContext';
import { colors, font, spacing } from '../theme';

// Sections Super Admin can switch off per restaurant (Restaurants › Access, main/config/access_control.php).
// get_session.php sends the switched-off dashboard page ids as user.access_off and the app re-reads it every
// 20 seconds, so a change in Super Admin shows up here without a new app. Screens not listed here
// (Home, Orders, Menu, Settings, Security) are always on, like on the website.
// Keep this file the same in app/ and staff-app/.
export const SCREEN_PAGE = {
  POS: 'posPage',
  Kitchen: 'kotPage',
  Tables: 'tablesPage',
  WaiterCalls: 'waiterRequestsPage',
  Reservations: 'reservationsPage',
  Customers: 'customersPage',
  Reports: 'reportsPage',
  Loyalty: 'growthSettingsPage',
  Riders: 'ridersPage',
};

export function isOff(user, screen) {
  const page = SCREEN_PAGE[screen];
  return !!page && Array.isArray(user?.access_off) && user.access_off.includes(page);
}

/** can('Kitchen') -> false when that section is switched off for this restaurant */
export function useAccess() {
  const { user } = useAuth();
  return (screen) => !isOff(user, screen);
}

/** The screen, or a "not switched on" page while its section is off (also when it is switched off while open). */
export function withAccess(Component, screen) {
  function Gated(props) {
    const { user } = useAuth();
    if (isOff(user, screen)) return <Locked />;
    return <Component {...props} />;
  }
  Gated.displayName = `Access(${screen})`;
  return Gated;
}

function Locked() {
  return (
    <View style={styles.wrap}>
      <View style={styles.icon}>
        <Ionicons name="lock-closed" size={30} color={colors.primary} />
      </View>
      <Text style={styles.title}>Not switched on</Text>
      <Text style={styles.text}>This section isn't part of your restaurant's plan. Contact RestroGrow to turn it on.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl, backgroundColor: colors.bg, gap: spacing.sm },
  icon: { width: 64, height: 64, borderRadius: 32, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primaryLight, marginBottom: spacing.sm },
  title: { fontFamily: font.bold, fontSize: 18, color: colors.ink },
  text: { fontFamily: font.regular, fontSize: 14, color: colors.inkSoft, textAlign: 'center', maxWidth: 300, lineHeight: 20 },
});
