import { StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, statusStyles } from '../theme';

export default function Badge({ label, tone }) {
  const { fg, bg } = statusStyles[label] || { fg: colors.inkSoft, bg: colors.border };
  const dotColor = tone || fg;

  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <View style={[styles.dot, { backgroundColor: dotColor }]} />
      <Text style={[styles.text, { color: fg }]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 5,
    gap: 6,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  text: {
    fontFamily: font.semiBold,
    fontSize: 12,
  },
});
