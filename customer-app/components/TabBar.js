import { Ionicons } from '@expo/vector-icons';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { CartBar } from './food';
import { useApp } from '../store/AppContext';
import { colors, CONTENT_MAX, font, shadow } from '../theme';
import { tap } from '../utils/haptics';

const ICONS = {
  Home: ['home', 'home-outline'],
  Menu: ['restaurant', 'restaurant-outline'],
  Cart: ['bag', 'bag-outline'],
  Orders: ['receipt', 'receipt-outline'],
  Profile: ['person', 'person-outline'],
};

export default function TabBar({ state, navigation }) {
  const insets = useSafeAreaInsets();
  const { cartCount } = useApp();
  const current = state.routes[state.index].name;
  const barHeight = 62 + Math.max(insets.bottom, 6);

  return (
    <View style={styles.outer} pointerEvents="box-none">
      {current === 'Home' || current === 'Menu' ? (
        <CartBar bottom={barHeight + 10} onPress={() => navigation.navigate('Cart')} />
      ) : null}
      <View style={[styles.bar, { paddingBottom: Math.max(insets.bottom, 6), height: barHeight }]}>
        {state.routes.map((route, index) => {
          const focused = state.index === index;
          const [on, off] = ICONS[route.name];
          return (
            <Pressable
              key={route.key}
              onPress={() => {
                tap();
                const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
                if (!focused && !event.defaultPrevented) navigation.navigate(route.name);
              }}
              style={styles.tab}
              accessibilityLabel={route.name}
            >
              <View>
                <Ionicons name={focused ? on : off} size={23} color={focused ? colors.primary : colors.muted} />
                {route.name === 'Cart' && cartCount > 0 ? (
                  <View style={styles.badge}>
                    <Text style={styles.badgeText}>{cartCount > 99 ? '99+' : cartCount}</Text>
                  </View>
                ) : null}
              </View>
              <Text style={[styles.label, focused && { color: colors.primary, fontFamily: font.semiBold }]}>{route.name}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  outer: { position: 'absolute', left: 0, right: 0, bottom: 0, alignItems: 'center' },
  bar: { width: '100%', maxWidth: CONTENT_MAX, flexDirection: 'row', backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 8, ...shadow.lg },
  tab: { flex: 1, alignItems: 'center', gap: 3 },
  label: { fontFamily: font.medium, fontSize: 11, color: colors.muted },
  badge: { position: 'absolute', top: -5, right: -10, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 1.5, borderColor: '#fff' },
  badgeText: { fontFamily: font.bold, fontSize: 10, color: colors.onPrimary },
});
