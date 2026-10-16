import { useEffect, useRef } from 'react';
import { Animated, StyleSheet, View } from 'react-native';
import { colors, radius, spacing } from '../../theme';

// Grey placeholder blocks that gently pulse while a screen loads its data, so the layout
// appears instantly instead of a blank page with a spinner.
export function SkeletonBlock({ width = '100%', height = 14, r = 8, style }) {
  const pulse = useRef(new Animated.Value(0.55)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 650, useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 0.55, duration: 650, useNativeDriver: true }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);
  return <Animated.View style={[{ width, height, borderRadius: r, backgroundColor: colors.skeleton, opacity: pulse }, style]} />;
}

// A list of card-shaped placeholders (orders, menu rows, customers...).
export function SkeletonList({ rows = 5, gutter = spacing.xl, tall = false }) {
  return (
    <View style={{ paddingHorizontal: gutter, paddingTop: spacing.lg, gap: spacing.md }}>
      {Array.from({ length: rows }).map((_, i) => (
        <View key={i} style={styles.card}>
          <View style={styles.row}>
            <SkeletonBlock width={44} height={44} r={14} />
            <View style={{ flex: 1, gap: 8 }}>
              <SkeletonBlock width="60%" height={13} />
              <SkeletonBlock width="38%" height={11} />
            </View>
            <SkeletonBlock width={54} height={22} r={11} />
          </View>
          {tall ? <SkeletonBlock width="85%" height={11} style={{ marginTop: 14 }} /> : null}
        </View>
      ))}
    </View>
  );
}

// A grid of tile placeholders (POS items, tables).
export function SkeletonGrid({ columns = 2, rows = 3, gutter = spacing.xl, height = 96 }) {
  return (
    <View style={{ paddingHorizontal: gutter, paddingTop: spacing.md, gap: spacing.md }}>
      {Array.from({ length: rows }).map((_, r) => (
        <View key={r} style={{ flexDirection: 'row', gap: spacing.md }}>
          {Array.from({ length: columns }).map((__, c) => (
            <View key={c} style={[styles.card, { flex: 1, height }]}>
              <SkeletonBlock width="70%" height={12} />
              <SkeletonBlock width="40%" height={11} style={{ marginTop: 8 }} />
              <SkeletonBlock width={60} height={20} r={10} style={{ marginTop: 'auto' }} />
            </View>
          ))}
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
});
