import { Ionicons } from '@expo/vector-icons';
import { StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, spacing } from '../theme';
import FadeIn from './ui/FadeIn';
import PressableScale from './ui/PressableScale';
import { SkeletonGrid, SkeletonList } from './ui/Skeleton';

// While a screen's first data loads: placeholder cards in the screen's own shape
// (`variant="grid"` for tile screens), not a spinner on a blank page.
export function LoadingState({ variant = 'list', rows, columns, gutter }) {
  return (
    <View style={{ flex: 1 }} accessibilityLabel="Loading" accessibilityRole="progressbar">
      {variant === 'grid' ? <SkeletonGrid columns={columns || 2} rows={rows || 3} gutter={gutter} /> : <SkeletonList rows={rows || 5} gutter={gutter} />}
    </View>
  );
}

export function ErrorState({ message, onRetry }) {
  return (
    <FadeIn style={styles.center}>
      <View style={styles.errorIconWrap}>
        <Ionicons name="cloud-offline-outline" size={28} color={colors.danger} />
      </View>
      <Text style={styles.errorTitle}>Couldn't load this</Text>
      <Text style={styles.errorMessage}>{message}</Text>
      {onRetry ? (
        <PressableScale onPress={onRetry} style={styles.retryButton} hitSlop={8}>
          <Ionicons name="refresh" size={15} color={colors.onPrimary} />
          <Text style={styles.retryText}>Try again</Text>
        </PressableScale>
      ) : null}
    </FadeIn>
  );
}

export function EmptyState({ icon = 'file-tray-outline', title, subtitle, action, onAction }) {
  return (
    <FadeIn style={styles.center}>
      <View style={styles.emptyIconWrap}>
        <Ionicons name={icon} size={30} color={colors.primary} />
      </View>
      <Text style={styles.emptyTitle}>{title}</Text>
      {subtitle ? <Text style={styles.emptySubtitle}>{subtitle}</Text> : null}
      {action && onAction ? (
        <PressableScale onPress={onAction} style={[styles.retryButton, { marginTop: spacing.lg }]}>
          <Text style={styles.retryText}>{action}</Text>
        </PressableScale>
      ) : null}
    </FadeIn>
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
    width: 64,
    height: 64,
    borderRadius: 32,
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
    lineHeight: 19,
  },
  retryButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginTop: spacing.md,
    paddingVertical: 11,
    paddingHorizontal: 24,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
  },
  retryText: {
    fontFamily: font.semiBold,
    fontSize: 14,
    color: colors.onPrimary,
  },
  emptyIconWrap: {
    width: 88,
    height: 88,
    borderRadius: 44,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.lg,
  },
  emptyTitle: {
    fontFamily: font.bold,
    fontSize: 17,
    color: colors.ink,
    marginBottom: 4,
    textAlign: 'center',
  },
  emptySubtitle: {
    fontFamily: font.regular,
    fontSize: 13.5,
    color: colors.muted,
    textAlign: 'center',
    lineHeight: 19,
    maxWidth: 320,
  },
});
