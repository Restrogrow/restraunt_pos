import { Ionicons } from '@expo/vector-icons';
import { useEffect, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, spacing } from '../theme';
import PressableScale from './ui/PressableScale';
import Sheet from './ui/Sheet';

export function dateKey(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
export function fromKey(k) {
  const [y, m, d] = String(k).split('-').map(Number);
  return new Date(y, m - 1, d);
}
export function rangeLabel(start, end) {
  const s = fromKey(start);
  const e = fromKey(end);
  const opts = { day: 'numeric', month: 'short' };
  if (start === end) return s.toLocaleDateString(undefined, { ...opts, year: s.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined });
  const sameYear = s.getFullYear() === e.getFullYear();
  return `${s.toLocaleDateString(undefined, opts)} – ${e.toLocaleDateString(undefined, { ...opts, year: sameYear && e.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' })}`;
}

const WEEK = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

function shortcuts() {
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  const add = (d, n) => {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  };
  const monthStart = new Date(t.getFullYear(), t.getMonth(), 1);
  const lastMonthStart = new Date(t.getFullYear(), t.getMonth() - 1, 1);
  const lastMonthEnd = new Date(t.getFullYear(), t.getMonth(), 0);
  return [
    { label: 'Yesterday', start: add(t, -1), end: add(t, -1) },
    { label: 'This month', start: monthStart, end: t },
    { label: 'Last month', start: lastMonthStart, end: lastMonthEnd },
    { label: 'Last 90 days', start: add(t, -89), end: t },
  ];
}

// Pick a from–to range: tap the first day, then the last day. Shortcuts cover the common asks.
export default function DateRangeSheet({ visible, start, end, onClose, onApply }) {
  const today = useMemo(() => {
    const t = new Date();
    t.setHours(0, 0, 0, 0);
    return t;
  }, [visible]); // eslint-disable-line react-hooks/exhaustive-deps
  const [from, setFrom] = useState(start || null);
  const [to, setTo] = useState(end || null);
  const [month, setMonth] = useState(() => (start ? fromKey(start) : new Date()));

  useEffect(() => {
    if (visible) {
      setFrom(start || null);
      setTo(end || null);
      const m = start ? fromKey(start) : new Date();
      setMonth(new Date(m.getFullYear(), m.getMonth(), 1));
    }
  }, [visible]); // eslint-disable-line react-hooks/exhaustive-deps

  const cells = useMemo(() => {
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const days = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
    const out = Array.from({ length: first.getDay() }, () => null);
    for (let d = 1; d <= days; d += 1) out.push(new Date(month.getFullYear(), month.getMonth(), d));
    while (out.length % 7) out.push(null);
    return out;
  }, [month]);

  const tap = (d) => {
    const k = dateKey(d);
    if (!from || (from && to)) {
      setFrom(k);
      setTo(null);
    } else if (k < from) {
      setTo(from);
      setFrom(k);
    } else {
      setTo(k);
    }
  };

  const canNext = new Date(month.getFullYear(), month.getMonth() + 1, 1) <= today;
  const ready = from && to;

  return (
    <Sheet visible={visible} onClose={onClose} title="Choose dates" subtitle={from ? (to ? rangeLabel(from, to) : 'Now tap the last day') : 'Tap the first day, then the last day'}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingBottom: spacing.md }}>
        {shortcuts().map((s) => (
          <PressableScale
            key={s.label}
            feedback="select"
            style={styles.quick}
            onPress={() => {
              setFrom(dateKey(s.start));
              setTo(dateKey(s.end));
              setMonth(new Date(s.end.getFullYear(), s.end.getMonth(), 1));
            }}
          >
            <Text style={styles.quickText}>{s.label}</Text>
          </PressableScale>
        ))}
      </ScrollView>

      <View style={styles.monthRow}>
        <PressableScale style={styles.nav} onPress={() => setMonth((m) => new Date(m.getFullYear(), m.getMonth() - 1, 1))} accessibilityLabel="Previous month">
          <Ionicons name="chevron-back" size={18} color={colors.ink} />
        </PressableScale>
        <Text style={styles.monthText}>{month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</Text>
        <PressableScale style={styles.nav} onPress={() => canNext && setMonth((m) => new Date(m.getFullYear(), m.getMonth() + 1, 1))} disabled={!canNext} accessibilityLabel="Next month">
          <Ionicons name="chevron-forward" size={18} color={colors.ink} />
        </PressableScale>
      </View>

      <View style={styles.week}>
        {WEEK.map((w, i) => (
          <Text key={i} style={styles.weekText}>{w}</Text>
        ))}
      </View>
      <View style={styles.grid}>
        {cells.map((d, i) => {
          if (!d) return <View key={i} style={styles.cell} />;
          const k = dateKey(d);
          const future = d > today;
          const isEnd = k === from || k === to;
          const inside = from && to && k > from && k < to;
          return (
            <View key={i} style={[styles.cell, inside && styles.cellIn, k === from && to && styles.cellStart, k === to && styles.cellEnd]}>
              <PressableScale style={[styles.day, isEnd && styles.dayOn]} onPress={() => tap(d)} disabled={future} feedback="select" scale={0.9}>
                <Text style={[styles.dayText, isEnd && { color: '#fff' }, future && { color: colors.border }, k === dateKey(today) && !isEnd && { color: colors.primary }]}>{d.getDate()}</Text>
              </PressableScale>
            </View>
          );
        })}
      </View>

      <PressableScale style={[styles.apply, !ready && { opacity: 0.45 }]} disabled={!ready} onPress={() => onApply(from, to)} feedback="press">
        <Text style={styles.applyText}>{ready ? `Show ${rangeLabel(from, to)}` : 'Pick two days'}</Text>
      </PressableScale>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  quick: {
    height: 36,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  quickText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: colors.inkSoft,
  },
  monthRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
  },
  nav: {
    width: 38,
    height: 38,
    borderRadius: 12,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  monthText: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  week: {
    flexDirection: 'row',
  },
  weekText: {
    flex: 1,
    textAlign: 'center',
    fontFamily: font.semiBold,
    fontSize: 11.5,
    color: colors.muted,
    paddingVertical: 6,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
  },
  cell: {
    width: `${100 / 7}%`,
    height: 44,
    alignItems: 'center',
    justifyContent: 'center',
  },
  cellIn: {
    backgroundColor: colors.primaryLight,
  },
  cellStart: {
    backgroundColor: colors.primaryLight,
    borderTopLeftRadius: 22,
    borderBottomLeftRadius: 22,
  },
  cellEnd: {
    backgroundColor: colors.primaryLight,
    borderTopRightRadius: 22,
    borderBottomRightRadius: 22,
  },
  day: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayOn: {
    backgroundColor: colors.primary,
  },
  dayText: {
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  apply: {
    height: 52,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.md,
  },
  applyText: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: '#fff',
  },
});
