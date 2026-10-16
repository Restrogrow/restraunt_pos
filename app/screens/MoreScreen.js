import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import Avatar from '../components/Avatar';
import ScreenHeader from '../components/ScreenHeader';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { restaurantLogoUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { useLiveCounts } from '../utils/liveCounts';

const OWNER_ROLES = ['admin', 'administrator', 'manager', 'branch admin'];

export default function MoreScreen({ navigation }) {
  const { user, logout } = useAuth();
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, columns, contentWidth } = useLayout();
  const { calls } = useLiveCounts();
  const isOwnerish =
    OWNER_ROLES.includes(String(user?.role || '').toLowerCase()) || ['admin', 'branch_admin'].includes(String(user?.user_type || ''));

  const groups = [
    {
      title: 'Service',
      items: [
        { key: 'Kitchen', icon: 'flame', label: 'Kitchen', sub: 'Tickets to cook', tint: colors.primary, bg: colors.primaryLight },
        { key: 'Tables', icon: 'grid', label: 'Tables', sub: 'Live floor', tint: colors.success, bg: colors.successBg },
        { key: 'WaiterCalls', icon: 'hand-left', label: 'Waiter calls', sub: calls ? `${calls} waiting` : 'From table QR', tint: colors.warning, bg: colors.warningBg, badge: calls },
        { key: 'Reservations', icon: 'calendar', label: 'Bookings', sub: 'Reservations', tint: colors.purple, bg: colors.purpleBg },
      ],
    },
    {
      title: 'Business',
      items: [
        { key: 'Customers', icon: 'people', label: 'Customers', sub: 'Visits and spend', tint: colors.info, bg: colors.infoBg },
        { key: 'Reports', icon: 'bar-chart', label: 'Reports', sub: 'Sales, top dishes', tint: colors.teal, bg: colors.tealBg },
        isOwnerish ? { key: 'Logs', icon: 'shield-checkmark', label: 'Security', sub: 'Logins, devices', tint: colors.inkSoft, bg: colors.border } : null,
      ].filter(Boolean),
    },
    {
      title: 'Settings',
      items: [
        { key: 'printer', section: true, icon: 'print', label: 'Printer', sub: 'Bills, KOT', tint: colors.teal, bg: colors.tealBg },
        isOwnerish ? { key: 'hours', section: true, icon: 'time', label: 'Hours', sub: 'Open times', tint: colors.info, bg: colors.infoBg } : null,
        isOwnerish ? { key: 'ordering', section: true, icon: 'bag-handle', label: 'Ordering', sub: 'Order types', tint: colors.success, bg: colors.successBg } : null,
        isOwnerish ? { key: 'tax', section: true, icon: 'receipt', label: 'Tax and bill', sub: 'GST, bill', tint: colors.purple, bg: colors.purpleBg } : null,
        { key: 'Settings', icon: 'settings', label: 'All settings', sub: 'Everything else', tint: colors.inkSoft, bg: colors.border },
      ].filter(Boolean),
    },
  ];

  const cols = columns(160, 4);
  const tileW = Math.floor((contentWidth - gutter * 2 - spacing.sm * (cols - 1)) / cols);

  const doLogout = async () => {
    const ok = await feedback.confirm({ title: 'Log out?', message: 'You will need your username and password to log in again.', confirmText: 'Log out', danger: true });
    if (ok) logout();
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Everything else" title="More" />
      <ScrollView contentContainerStyle={{ paddingHorizontal: gutter, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.md }} showsVerticalScrollIndicator={false}>
        <FadeIn>
          <PressableScale style={[styles.profile, shadow.sm]} onPress={() => navigation.navigate('Settings')} scale={0.99}>
            <Avatar name={user?.restaurant_name || user?.username} uri={restaurantLogoUrl(user)} size={48} bg={colors.primaryLight} color={colors.primary} />
            <View style={{ flex: 1 }}>
              <Text style={styles.profileName} numberOfLines={1}>{user?.restaurant_name || user?.username}</Text>
              <Text style={styles.profileRole} numberOfLines={1}>
                {user?.staff_name || user?.username} · {user?.role || 'Staff'}
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.muted} />
          </PressableScale>
        </FadeIn>

        {groups.map((g, gi) => (
          <FadeIn key={g.title} index={gi + 1}>
            <Text style={styles.groupTitle}>{g.title}</Text>
            <View style={[styles.grid, { gap: spacing.sm }]}>
              {g.items.map((it) => (
                <PressableScale
                  key={it.key}
                  style={[styles.tile, shadow.sm, { width: tileW }]}
                  onPress={() => (it.section ? navigation.navigate('SettingsSection', { section: it.key }) : navigation.navigate(it.key))}
                  accessibilityLabel={it.label}
                >
                  <View style={[styles.tileIcon, { backgroundColor: it.bg }]}>
                    <Ionicons name={it.icon} size={19} color={it.tint} />
                    {it.badge ? (
                      <View style={styles.badge}>
                        <Text style={styles.badgeText}>{it.badge}</Text>
                      </View>
                    ) : null}
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.tileLabel} numberOfLines={1}>{it.label}</Text>
                    <Text style={styles.tileSub} numberOfLines={1}>{it.sub}</Text>
                  </View>
                </PressableScale>
              ))}
            </View>
          </FadeIn>
        ))}

        <FadeIn index={5}>
          <PressableScale style={[styles.logout, shadow.sm]} onPress={doLogout} feedback="warning">
            <Ionicons name="log-out-outline" size={19} color={colors.danger} />
            <Text style={styles.logoutText}>Log out</Text>
          </PressableScale>
        </FadeIn>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    marginTop: -spacing.lg,
  },
  profileName: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  profileRole: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 1,
    textTransform: 'capitalize',
  },
  groupTitle: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.muted,
    textTransform: 'uppercase',
    letterSpacing: 0.7,
    marginBottom: spacing.sm,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  tile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingHorizontal: 12,
    paddingVertical: 12,
    minHeight: 64,
  },
  tileIcon: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileLabel: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  tileSub: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  badge: {
    position: 'absolute',
    top: -6,
    right: -6,
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    paddingHorizontal: 5,
    backgroundColor: colors.danger,
    borderWidth: 2,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: {
    fontFamily: font.bold,
    fontSize: 10,
    color: '#fff',
  },
  logout: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    paddingVertical: 15,
  },
  logoutText: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.danger,
  },
});
