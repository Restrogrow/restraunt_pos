import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import AutomationsTab from '../components/loyalty/AutomationsTab';
import OffersTab from '../components/loyalty/OffersTab';
import PerformanceTab from '../components/loyalty/PerformanceTab';
import ProgramTab from '../components/loyalty/ProgramTab';
import ScreenHeader from '../components/ScreenHeader';
import PressableScale from '../components/ui/PressableScale';
import { useAuth } from '../context/AuthContext';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';

// The app's version of the website's Growth › Loyalty & Rewards (assets/js/loyalty-studio*.js),
// all from api/loyalty_admin.php. Simple settings and on/off switches live here; the bigger
// editors (points rates, plan and challenge builders, messages) stay on the website.
const TABS = [
  { key: 'program', label: 'Program', icon: 'ribbon' },
  { key: 'performance', label: 'Performance', icon: 'trending-up' },
  { key: 'automations', label: 'Autopilot', icon: 'flash' },
  { key: 'offers', label: 'Offers', icon: 'pricetags' },
];

export default function LoyaltyScreen({ navigation, route }) {
  const { user } = useAuth();
  const currency = user?.currency_symbol || '₹';
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter, contentWidth } = useLayout();
  const wide = contentWidth >= 480;
  const [tab, setTab] = useState(route?.params?.tab || 'program');

  const contentStyle = {
    width: '100%',
    maxWidth: contentWidth,
    alignSelf: 'center',
    paddingHorizontal: gutter,
    paddingTop: spacing.sm,
    paddingBottom: tabBarHeight + spacing.xxl,
    gap: spacing.lg,
  };
  const props = { currency, contentStyle };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Growth" title="Loyalty & rewards" onBack={navigation?.canGoBack() ? () => navigation.goBack() : undefined} />

      <View style={[styles.segWrap, { maxWidth: contentWidth - gutter * 2 }, shadow.sm]}>
        {TABS.map((t) => {
          const on = t.key === tab;
          return (
            <PressableScale key={t.key} feedback="select" style={[styles.seg, on && styles.segOn]} onPress={() => setTab(t.key)} accessibilityLabel={t.label}>
              {wide ? <Ionicons name={t.icon} size={15} color={on ? '#fff' : colors.primary} /> : null}
              <Text style={[styles.segText, on && { color: '#fff' }]} numberOfLines={1}>{t.label}</Text>
            </PressableScale>
          );
        })}
      </View>

      <View style={styles.fill}>
        {tab === 'program' ? <ProgramTab {...props} /> : null}
        {tab === 'performance' ? <PerformanceTab {...props} /> : null}
        {tab === 'automations' ? <AutomationsTab {...props} /> : null}
        {tab === 'offers' ? <OffersTab {...props} /> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  segWrap: {
    flexDirection: 'row',
    alignSelf: 'center',
    width: '100%',
    marginTop: -spacing.lg - 4,
    marginBottom: spacing.sm,
    padding: 4,
    gap: 4,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
  },
  seg: {
    flexGrow: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    height: 38,
    paddingHorizontal: 8,
    borderRadius: radius.pill,
  },
  segOn: {
    backgroundColor: colors.ink,
  },
  segText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
});
