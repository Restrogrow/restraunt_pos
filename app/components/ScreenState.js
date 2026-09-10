import { Ionicons } from '@expo/vector-icons';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, font, spacing } from '../theme';

export function LoadingState() {
  return (
    <View style={styles.center}>
      <ActivityIndicator size="large" color={colors.primary} />
    </View>
  );
}

export function ErrorState({ message }) {
  return (
    <View style={styles.center}>
      <View style={styles.errorIconWrap}>
        <Ionicons name="cloud-offline-outline" size={28} color={colors.danger} />
      </View>
      <Text style={styles.errorTitle}>Couldn't load this</Text>
      <Text style={styles.errorMessage}>{message}</Text>
    </View>
  );
}

export function EmptyState({ icon = 'file-tray-outline', title, subtitle }) {
  return (
    <View style={styles.center}>
      <View style={styles.emptyIconWrap}>
        <Ionicons name={icon} size={26} color={colors.muted} />
      </View>
      <Text style={styles.emptyTitle}>{title}</Text>
      {subtitle ? <Text style={styles.emptySubtitle}>{subtitle}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.xxl,
  },
  errorIconWrap: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.dangerBg,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  errorTitle: {
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
    marginBottom: 4,
  },
  errorMessage: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
  },
  emptyIconWrap: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  emptyTitle: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.inkSoft,
    marginBottom: 2,
  },
  emptySubtitle: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
    textAlign: 'center',
  },
});
