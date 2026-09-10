import { Ionicons } from '@expo/vector-icons';
import { StyleSheet, Text, View } from 'react-native';
import { font, radius, shadow, spacing } from '../theme';

export default function StatCard({ label, value, icon, tint, tintBg }) {
  return (
    <View style={[styles.card, shadow.sm]}>
      <View style={[styles.iconWrap, { backgroundColor: tintBg }]}>
        <Ionicons name={icon} size={18} color={tint} />
      </View>
      <Text style={styles.value} numberOfLines={1}>{value}</Text>
      <Text style={styles.label} numberOfLines={1}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    flexBasis: '47%',
    flexGrow: 1,
    backgroundColor: '#fff',
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  iconWrap: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  value: {
    fontFamily: font.bold,
    fontSize: 22,
    color: '#1D1B26',
  },
  label: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: '#9997A6',
    marginTop: 3,
  },
});
