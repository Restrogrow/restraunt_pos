import { Ionicons } from '@expo/vector-icons';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, shadow, spacing } from '../theme';

const WEEKDAYS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const MONTH_FORMAT = { month: 'long', year: 'numeric' };

function startOfDay(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return d;
}

function sameDay(a, b) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function buildGrid(monthDate) {
  const year = monthDate.getFullYear();
  const month = monthDate.getMonth();
  const firstOfMonth = new Date(year, month, 1);
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const leadingBlanks = firstOfMonth.getDay();

  const cells = [];
  for (let i = 0; i < leadingBlanks; i++) cells.push(null);
  for (let day = 1; day <= daysInMonth; day++) cells.push(new Date(year, month, day));
  while (cells.length % 7 !== 0) cells.push(null);
  return cells;
}

export default function DatePickerModal({ visible, viewDate, selectedDate, onChangeMonth, onSelectDay, onClose, maxDate }) {
  const viewMonth = viewDate || selectedDate || new Date();
  const max = maxDate ? startOfDay(maxDate) : null;
  const today = startOfDay(new Date());
  const cells = buildGrid(viewMonth);

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose}>
        <Pressable style={[styles.card, shadow.lg]} onPress={() => {}}>
          <View style={styles.header}>
            <Pressable style={styles.navButton} onPress={() => onChangeMonth(-1)} hitSlop={8}>
              <Ionicons name="chevron-back" size={18} color={colors.ink} />
            </Pressable>
            <Text style={styles.monthLabel}>{viewMonth.toLocaleDateString(undefined, MONTH_FORMAT)}</Text>
            <Pressable style={styles.navButton} onPress={() => onChangeMonth(1)} hitSlop={8}>
              <Ionicons name="chevron-forward" size={18} color={colors.ink} />
            </Pressable>
          </View>

          <View style={styles.weekRow}>
            {WEEKDAYS.map((w, i) => (
              <Text key={i} style={styles.weekday}>{w}</Text>
            ))}
          </View>

          <View style={styles.grid}>
            {cells.map((cell, i) => {
              if (!cell) return <View key={i} style={styles.cell} />;
              const disabled = max ? startOfDay(cell) > max : false;
              const isSelected = selectedDate && sameDay(cell, selectedDate);
              const isToday = sameDay(cell, today);
              return (
                <Pressable
                  key={i}
                  style={styles.cell}
                  disabled={disabled}
                  onPress={() => onSelectDay(cell)}
                >
                  <View style={[styles.dayCircle, isSelected && styles.dayCircleSelected]}>
                    <Text
                      style={[
                        styles.dayText,
                        disabled && styles.dayTextDisabled,
                        isSelected && styles.dayTextSelected,
                        isToday && !isSelected && styles.dayTextToday,
                      ]}
                    >
                      {cell.getDate()}
                    </Text>
                  </View>
                </Pressable>
              );
            })}
          </View>

          <Pressable style={styles.todayButton} onPress={() => onSelectDay(new Date())}>
            <Text style={styles.todayButtonText}>Today</Text>
          </Pressable>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(29,27,38,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  card: {
    width: '100%',
    maxWidth: 340,
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    padding: spacing.lg,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
  },
  navButton: {
    width: 34,
    height: 34,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  monthLabel: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  weekRow: {
    flexDirection: 'row',
    marginBottom: spacing.xs,
  },
  weekday: {
    flex: 1,
    textAlign: 'center',
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.muted,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  cell: {
    width: `${100 / 7}%`,
    aspectRatio: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayCircle: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayCircleSelected: {
    backgroundColor: colors.primary,
  },
  dayText: {
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  dayTextDisabled: {
    color: colors.border,
  },
  dayTextSelected: {
    color: '#fff',
    fontFamily: font.semiBold,
  },
  dayTextToday: {
    color: colors.primary,
    fontFamily: font.semiBold,
  },
  todayButton: {
    alignSelf: 'center',
    marginTop: spacing.sm,
    paddingVertical: 8,
    paddingHorizontal: 16,
  },
  todayButtonText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
});
