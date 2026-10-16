import { useEffect, useRef } from 'react';
import { Animated, StyleSheet, Text, View } from 'react-native';
import { colors, font } from '../theme';

// A light bar chart: bars grow up into place, the last bar (today) is highlighted, and
// tapping isn't needed — the value of the best day sits above its bar.
export default function MiniBars({ data, height = 120, valueLabel, dayLabel, highlightLast = true }) {
  const grow = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    grow.setValue(0);
    Animated.timing(grow, { toValue: 1, duration: 520, useNativeDriver: false }).start();
  }, [data, grow]);

  const max = Math.max(1, ...data.map((d) => Number(d.value) || 0));
  const bestIdx = data.reduce((b, d, i) => ((Number(d.value) || 0) > (Number(data[b]?.value) || 0) ? i : b), 0);

  return (
    <View>
      <View style={[styles.row, { height }]}>
        {data.map((d, i) => {
          const v = Number(d.value) || 0;
          const h = Math.max(v > 0 ? 6 : 3, (v / max) * (height - 20));
          const last = highlightLast && i === data.length - 1;
          return (
            <View key={d.key ?? i} style={styles.col}>
              {i === bestIdx && v > 0 ? (
                <View style={styles.bestWrap} pointerEvents="none">
                  <Text style={styles.best}>{valueLabel ? valueLabel(v) : v}</Text>
                </View>
              ) : null}
              <Animated.View
                style={[
                  styles.bar,
                  {
                    height: grow.interpolate({ inputRange: [0, 1], outputRange: [3, h] }),
                    backgroundColor: last ? colors.primary : v > 0 ? '#FFC9B9' : colors.border,
                  },
                ]}
              />
            </View>
          );
        })}
      </View>
      <View style={styles.labels}>
        {data.map((d, i) => (
          <Text key={d.key ?? i} style={[styles.label, i === data.length - 1 && styles.labelOn]} numberOfLines={1}>
            {dayLabel ? dayLabel(d, i) : ''}
          </Text>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: 4,
  },
  col: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'flex-end',
    height: '100%',
  },
  bar: {
    width: '78%',
    maxWidth: 22,
    borderRadius: 6,
  },
  // the best day's value, centred over its bar but allowed to be wider than it
  bestWrap: {
    position: 'absolute',
    top: 0,
    left: -40,
    right: -40,
    alignItems: 'center',
  },
  best: {
    fontFamily: font.semiBold,
    fontSize: 10,
    color: colors.inkSoft,
  },
  labels: {
    flexDirection: 'row',
    gap: 4,
    marginTop: 6,
  },
  label: {
    flex: 1,
    textAlign: 'center',
    fontFamily: font.medium,
    fontSize: 9.5,
    color: colors.muted,
  },
  labelOn: {
    color: colors.primary,
    fontFamily: font.semiBold,
  },
});
