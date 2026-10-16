import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { Card, Row, SectionTitle, TONES } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import { BASE_URL } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow } from '../theme';
import { useBadges } from '../utils/badges';

const TILES = [
  { screen: 'Leads', icon: 'person-add', tone: 'purple', title: 'Leads', sub: 'Website enquiries', badge: 'leads' },
  { screen: 'ChatLeads', icon: 'chatbox-ellipses', tone: 'info', title: 'Chatbot', sub: 'Chat conversations' },
  { screen: 'Payments', icon: 'wallet', tone: 'success', title: 'Payments', sub: 'Subscriptions & invoices' },
  { screen: 'PaymentLinks', icon: 'link', tone: 'teal', title: 'Payment links', sub: 'Collect a subscription' },
  { screen: 'Coupons', icon: 'pricetags', tone: 'primary', title: 'Coupons', sub: 'Discount codes' },
  { screen: 'BranchAdmins', icon: 'git-branch', tone: 'purple', title: 'Branch admins', sub: 'Chains & multi-outlet' },
  { screen: 'Announcements', icon: 'megaphone', tone: 'primary', title: 'Announcements', sub: 'Banners to restaurants' },
  { screen: 'Security', icon: 'shield-checkmark', tone: 'danger', title: 'Security', sub: 'Sign-ins & blocked IPs' },
  { screen: 'Activity', icon: 'list', tone: 'teal', title: 'Activity log', sub: 'Who changed what' },
  { screen: 'Health', icon: 'pulse', tone: 'warning', title: 'Platform health', sub: 'DB, disk, WhatsApp, errors' },
  { screen: 'Settings', icon: 'settings', tone: 'muted', title: 'Settings', sub: 'Pricing, invoices, app' },
];

// sections of superadmin/dashboard.php that open inside the app, already signed in
const PANEL = [
  { page: 'menus', icon: 'restaurant-outline', tone: 'primary', title: 'Menus', sub: "Edit any restaurant's menu, or build one from photos" },
  { page: 'settlements', icon: 'swap-horizontal-outline', tone: 'success', title: 'Settlements', sub: 'UPI payouts' },
  { page: 'invoiceGen', icon: 'document-text-outline', tone: 'info', title: 'Invoice generator', sub: 'Make and download invoices' },
  { page: 'errorMonitor', icon: 'bug-outline', tone: 'danger', title: 'Error monitor', sub: 'Errors from every restaurant' },
  { page: 'dashboard', icon: 'desktop-outline', tone: 'muted', title: 'Full web panel', sub: 'Everything else' },
];

export default function MoreScreen() {
  const navigation = useNavigation();
  const { user, logout } = useAuth();
  const badges = useBadges();
  const { columns } = useLayout();
  const cols = columns(150, 4);

  const signOut = async () => {
    if (await feedback.confirm({ title: 'Sign out?', message: 'You can sign back in any time.', confirmText: 'Sign out', danger: true })) logout();
  };

  return (
    <Screen tabs eyebrow={`Signed in as ${user?.username || ''}`} title="More">
      <View style={styles.grid}>
        {TILES.map((t) => {
          const tone = TONES[t.tone];
          const count = t.badge ? badges[t.badge] : 0;
          return (
            <PressableScale key={t.screen} onPress={() => navigation.navigate(t.screen)} style={[styles.tile, shadow.sm, { flexBasis: `${100 / cols - 4}%` }]}>
              <View style={[styles.tileIcon, { backgroundColor: tone.bg }]}>
                <Ionicons name={t.icon} size={20} color={tone.fg} />
              </View>
              <Text style={styles.tileTitle}>{t.title}</Text>
              <Text style={styles.tileSub} numberOfLines={1}>
                {t.sub}
              </Text>
              {count ? (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{count}</Text>
                </View>
              ) : null}
            </PressableScale>
          );
        })}
      </View>

      <SectionTitle title="Web panel" />
      <Card padded={false} style={{ paddingHorizontal: 16 }}>
        {PANEL.map((p) => (
          <Row
            key={p.page || 'all'}
            icon={p.icon}
            iconTone={p.tone}
            title={p.title}
            subtitle={p.sub}
            onPress={() => navigation.navigate('WebPanel', { url: `${BASE_URL}/superadmin/dashboard.php`, title: p.title, eyebrow: 'Web panel', page: p.page })}
            chevron
          />
        ))}
        <Row icon="log-out-outline" danger title="Sign out" onPress={signOut} last />
      </Card>
      <Text style={styles.version}>RestroGrow Super Admin · v1.1.0</Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  tile: { flexGrow: 1, backgroundColor: colors.surface, borderRadius: radius.lg, padding: 14, minHeight: 116 },
  tileIcon: { width: 40, height: 40, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  tileTitle: { fontFamily: font.semiBold, fontSize: 14, color: colors.ink },
  tileSub: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, marginTop: 1 },
  badge: {
    position: 'absolute',
    top: 12,
    right: 12,
    minWidth: 22,
    height: 22,
    borderRadius: 11,
    paddingHorizontal: 6,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontFamily: font.bold, fontSize: 11, color: '#fff' },
  version: { fontFamily: font.regular, fontSize: 11.5, color: colors.muted, textAlign: 'center', marginTop: 24 },
});
