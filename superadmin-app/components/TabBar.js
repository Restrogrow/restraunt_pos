import { Ionicons } from '@expo/vector-icons';
import { BottomTabBarHeightCallbackContext } from '@react-navigation/bottom-tabs';
import { useContext, useEffect, useRef } from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, font, radius, shadow, spacing } from '../theme';
import { useBadges } from '../utils/badges';
import { haptic } from '../utils/haptics';

// [active icon, inactive icon, label]
const TABS = {
  Home: ['speedometer', 'speedometer-outline', 'Overview'],
  Restaurants: ['storefront', 'storefront-outline', 'Restaurants'],
  Renewals: ['refresh-circle', 'refresh-circle-outline', 'Renewals'],
  Support: ['chatbubbles', 'chatbubbles-outline', 'Support'],
  More: ['grid', 'grid-outline', 'More'],
};

function TabItem({ route, focused, onPress, badge }) {
  const [on, off, label] = TABS[route.name] || ['ellipse', 'ellipse-outline', route.name];
  const v = useRef(new Animated.Value(focused ? 1 : 0)).current;
  useEffect(() => {
    Animated.spring(v, { toValue: focused ? 1 : 0, useNativeDriver: true, speed: 22, bounciness: 8 }).start();
  }, [focused, v]);
  return (
    <Pressable
      onPress={onPress}
      style={styles.item}
      accessibilityRole="tab"
      accessibilityState={{ selected: focused }}
      accessibilityLabel={badge ? `${label}, ${badge} new` : label}
      hitSlop={4}
    >
      <View style={styles.iconBox}>
        <Animated.View style={[styles.pill, { opacity: v, transform: [{ scaleX: v.interpolate({ inputRange: [0, 1], outputRange: [0.4, 1] }) }] }]} />
        <Animated.View style={{ transform: [{ scale: v.interpolate({ inputRange: [0, 1], outputRange: [1, 1.08] }) }] }}>
          <Ionicons name={focused ? on : off} size={21} color={focused ? colors.primary : colors.muted} />
        </Animated.View>
        {badge ? (
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{badge > 99 ? '99+' : badge}</Text>
          </View>
        ) : null}
      </View>
      <Text style={[styles.label, focused && styles.labelOn]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  );
}

export default function TabBar({ state, descriptors, navigation }) {
  const insets = useSafeAreaInsets();
  const badges = useBadges();
  const setTabBarHeight = useContext(BottomTabBarHeightCallbackContext);

  const focusedOptions = descriptors[state.routes[state.index].key].options;
  if (focusedOptions.tabBarStyle?.display === 'none') return null;

  return (
    <View style={[styles.wrap, { paddingBottom: Math.max(insets.bottom, spacing.sm) }]} onLayout={(e) => setTabBarHeight?.(e.nativeEvent.layout.height)}>
      <View style={[styles.bar, shadow.lg]}>
        {state.routes.map((route, index) => {
          const focused = state.index === index;
          const badge = route.name === 'Support' ? badges.tickets : route.name === 'Renewals' ? badges.renewals : route.name === 'More' ? badges.leads : 0;
          const onPress = () => {
            const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            haptic.select();
            if (!focused && !event.defaultPrevented) {
              navigation.navigate(route.name);
            } else if (focused) {
              const nested = route.state;
              if (nested && nested.index > 0) navigation.navigate(route.name, { screen: nested.routeNames[0] });
            }
          };
          return <TabItem key={route.key} route={route} focused={focused} badge={badge} onPress={onPress} />;
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingTop: spacing.xs,
    backgroundColor: 'transparent',
  },
  bar: {
    width: '100%',
    maxWidth: 560,
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    paddingHorizontal: spacing.xs,
    paddingVertical: 6,
  },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 2, minHeight: 52 },
  iconBox: { width: 52, height: 30, alignItems: 'center', justifyContent: 'center' },
  pill: { ...StyleSheet.absoluteFillObject, borderRadius: 15, backgroundColor: colors.primaryLight },
  label: { fontFamily: font.medium, fontSize: 10.5, color: colors.muted, marginTop: 2 },
  labelOn: { fontFamily: font.semiBold, color: colors.primary },
  badge: {
    position: 'absolute',
    top: -3,
    right: 4,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    backgroundColor: colors.danger,
    borderWidth: 2,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontFamily: font.bold, fontSize: 9.5, color: '#fff' },
});
