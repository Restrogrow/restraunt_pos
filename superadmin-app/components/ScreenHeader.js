import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, spacing } from '../theme';
import PressableScale from './ui/PressableScale';

// The navy gradient header at the top of every main screen. Clears the status bar /
// camera cut-out, centres its content on tablets, and shows a back arrow on pushed screens.
export default function ScreenHeader({ eyebrow, title, right, onBack, children }) {
  const insets = useSafeAreaInsets();
  const { gutter, contentWidth } = useLayout();

  return (
    <LinearGradient
      colors={[colors.navySoft, colors.navy, colors.navyDark]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[styles.wrap, { paddingTop: insets.top + spacing.md }]}
    >
      <View style={{ width: '100%', maxWidth: contentWidth, alignSelf: 'center', paddingHorizontal: gutter }}>
        <View style={styles.row}>
          {onBack ? (
            <PressableScale style={styles.back} onPress={onBack} hitSlop={8} accessibilityLabel="Back">
              <Ionicons name="arrow-back" size={20} color="#fff" />
            </PressableScale>
          ) : null}
          <View style={{ flex: 1 }}>
            {eyebrow ? <Text style={styles.eyebrow} numberOfLines={1}>{eyebrow}</Text> : null}
            <Text style={styles.title} numberOfLines={1} adjustsFontSizeToFit minimumFontScale={0.8}>
              {title}
            </Text>
          </View>
          {right}
        </View>
        {children}
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  wrap: {
    paddingBottom: spacing.xl,
    borderBottomLeftRadius: radius.xl,
    borderBottomRightRadius: radius.xl,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  back: {
    width: 38,
    height: 38,
    borderRadius: 13,
    backgroundColor: 'rgba(255,255,255,0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  eyebrow: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: 'rgba(255,255,255,0.8)',
    marginBottom: 4,
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  title: {
    fontFamily: font.bold,
    fontSize: 24,
    color: '#fff',
  },
});
