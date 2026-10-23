import { Ionicons } from '@expo/vector-icons';
import { useBottomTabBarHeight } from '@react-navigation/bottom-tabs';
import { useCallback, useMemo, useState } from 'react';
import { FlatList, Linking, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ScreenState';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import PressableScale from '../components/ui/PressableScale';
import { apiGet, apiPostForm } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';

const STATUS = {
  Pending: { fg: '#B7791F', bg: colors.warningBg, label: 'To confirm' },
  Confirmed: { fg: colors.info, bg: colors.infoBg, label: 'Confirmed' },
  'Checked In': { fg: colors.success, bg: colors.successBg, label: 'Seated' },
  Completed: { fg: colors.inkSoft, bg: colors.border, label: 'Done' },
  Cancelled: { fg: colors.danger, bg: colors.dangerBg, label: 'Cancelled' },
  'No Show': { fg: colors.danger, bg: colors.dangerBg, label: 'No show' },
};
// What each status can move to: [primary, secondary]
const NEXT = {
  Pending: [{ to: 'Confirmed', label: 'Confirm', icon: 'checkmark' }, { to: 'Cancelled', label: 'Decline', danger: true }],
  Confirmed: [{ to: 'Checked In', label: 'Seat them', icon: 'enter-outline' }, { to: 'No Show', label: 'No show', danger: true }],
  'Checked In': [{ to: 'Completed', label: 'Done', icon: 'checkmark-done' }],
};

function dayKey(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function ReservationsScreen({ navigation }) {
  const tabBarHeight = useBottomTabBarHeight();
  const { gutter } = useLayout();
  const days = useMemo(() => Array.from({ length: 7 }, (_, i) => {
    const d = new Date();
    d.setDate(d.getDate() + i);
    return d;
  }), []);
  const [day, setDay] = useState(dayKey(days[0]));
  const [busy, setBusy] = useState(null);

  const fetcher = useCallback(() => apiGet(`/api/get_reservations.php?date=${day}`), [day]);
  const { data, loading, refreshing, error, refresh, setData, reload } = useApiData(fetcher, { pollInterval: 30000, cacheKey: `bookings:${day}`, refetchOnFocus: true });
  const list = useMemo(
    () => [...(data?.data || [])].sort((a, b) => String(a.time_slot).localeCompare(String(b.time_slot))),
    [data]
  );
  const guests = list.filter((r) => !['Cancelled', 'No Show'].includes(r.status)).reduce((s, r) => s + (Number(r.no_of_guests) || 0), 0);

  const move = async (r, to) => {
    if (to === 'Cancelled' || to === 'No Show') {
      const ok = await feedback.confirm({
        title: to === 'Cancelled' ? `Decline ${r.customer_name}'s booking?` : `Mark ${r.customer_name} as no show?`,
        message: `${r.no_of_guests} guests at ${r.time_slot}.`,
        confirmText: to === 'Cancelled' ? 'Decline' : 'No show',
        danger: true,
      });
      if (!ok) return;
    }
    setBusy(r.id);
    try {
      // status only: re-sending the whole booking through 'update' ran it through the add/edit
      // form rules (10-digit phone, HTML-escaping the name again on every save)
      let res = await apiPostForm('/controllers/reservation_operations.php', {
        action: 'update_status',
        reservationId: r.id,
        status: to,
      });
      // a server from before update_status: fall back to the full update
      if (!res.success && /invalid action/i.test(res.message || '')) {
        res = await apiPostForm('/controllers/reservation_operations.php', {
          action: 'update',
          reservationId: r.id,
          reservationDate: r.reservation_date,
          timeSlot: r.time_slot,
          noOfGuests: r.no_of_guests,
          mealType: r.meal_type || 'Lunch',
          customerName: r.customer_name,
          phone: r.phone || '',
          email: r.email || '',
          specialRequest: r.special_request || '',
          selectTable: r.table_id || '',
          status: to,
        });
      }
      if (!res.success) throw new Error(res.message || 'Could not update the booking');
      setData((prev) => (prev ? { ...prev, data: (prev.data || []).map((x) => (x.id === r.id ? { ...x, status: to } : x)) } : prev));
      feedback.success(`${r.customer_name}: ${STATUS[to]?.label || to}`);
      reload('background');
    } catch (e) {
      feedback.errorFrom(e, 'Could not update the booking');
    } finally {
      setBusy(null);
    }
  };

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow={list.length ? `${list.length} booking${list.length > 1 ? 's' : ''} · ${guests} guests` : 'Table bookings'} title="Bookings" onBack={() => navigation.goBack()} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.dayScroll} contentContainerStyle={{ gap: spacing.sm, paddingHorizontal: gutter, paddingVertical: 4 }}>
        {days.map((d, i) => {
          const k = dayKey(d);
          const on = k === day;
          return (
            <PressableScale key={k} feedback="select" style={[styles.day, shadow.sm, on && styles.dayOn]} onPress={() => setDay(k)}>
              <Text style={[styles.dayName, on && { color: 'rgba(255,255,255,0.85)' }]}>
                {i === 0 ? 'Today' : i === 1 ? 'Tmrw' : d.toLocaleDateString(undefined, { weekday: 'short' })}
              </Text>
              <Text style={[styles.dayNum, on && { color: '#fff' }]}>{d.getDate()}</Text>
            </PressableScale>
          );
        })}
      </ScrollView>

      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <FlatList
          data={list}
          keyExtractor={(r) => String(r.id)}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: spacing.md, paddingBottom: tabBarHeight + spacing.xxl, gap: spacing.md, flexGrow: 1 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} colors={[colors.primary]} />}
          ListEmptyComponent={<EmptyState icon="calendar-outline" title="No bookings this day" subtitle="Bookings made on your website and in the dashboard show up here." />}
          renderItem={({ item: r, index }) => {
            const st = STATUS[r.status] || STATUS.Pending;
            const actions = NEXT[r.status] || [];
            return (
              <FadeIn index={index}>
                <View style={[styles.card, shadow.sm]}>
                  <View style={styles.top}>
                    <View style={styles.time}>
                      <Text style={styles.timeText}>{r.time_slot}</Text>
                      <Text style={styles.meal}>{r.meal_type}</Text>
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.name} numberOfLines={1}>{r.customer_name}</Text>
                      <Text style={styles.meta} numberOfLines={1}>
                        {r.no_of_guests} guest{Number(r.no_of_guests) > 1 ? 's' : ''}
                        {r.table_number ? ` · Table ${r.table_number}` : ' · No table yet'}
                      </Text>
                    </View>
                    <View style={[styles.status, { backgroundColor: st.bg }]}>
                      <Text style={[styles.statusText, { color: st.fg }]}>{st.label}</Text>
                    </View>
                  </View>
                  {r.special_request ? <Text style={styles.request}>“{r.special_request}”</Text> : null}
                  <View style={styles.actions}>
                    {r.phone ? (
                      <PressableScale style={styles.call} onPress={() => Linking.openURL(`tel:${r.phone}`).catch(() => {})} accessibilityLabel="Call guest">
                        <Ionicons name="call" size={16} color={colors.primary} />
                      </PressableScale>
                    ) : null}
                    <View style={{ flex: 1 }} />
                    {actions.map((a) => (
                      <PressableScale
                        key={a.to}
                        style={[styles.act, a.danger ? styles.actDanger : styles.actGo]}
                        onPress={() => move(r, a.to)}
                        disabled={busy === r.id}
                        feedback={a.danger ? 'warning' : 'press'}
                      >
                        {a.icon ? <Ionicons name={a.icon} size={15} color="#fff" /> : null}
                        <Text style={[styles.actText, a.danger && { color: colors.danger }]}>{a.label}</Text>
                      </PressableScale>
                    ))}
                  </View>
                </View>
              </FadeIn>
            );
          }}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  dayScroll: {
    flexGrow: 0,
    flexShrink: 0,
    height: 74,
    marginTop: -spacing.lg,
  },
  day: {
    width: 58,
    height: 64,
    borderRadius: 16,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayOn: {
    backgroundColor: colors.ink,
  },
  dayName: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.muted,
  },
  dayNum: {
    fontFamily: font.bold,
    fontSize: 19,
    color: colors.ink,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.sm,
  },
  top: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  time: {
    width: 64,
    paddingVertical: 8,
    borderRadius: 13,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
  },
  timeText: {
    fontFamily: font.bold,
    fontSize: 13,
    color: colors.primary,
  },
  meal: {
    fontFamily: font.medium,
    fontSize: 10,
    color: colors.primary,
  },
  name: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
    marginTop: 1,
  },
  status: {
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 3,
  },
  statusText: {
    fontFamily: font.semiBold,
    fontSize: 11,
  },
  request: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.inkSoft,
    backgroundColor: colors.bg,
    borderRadius: radius.sm,
    padding: spacing.sm,
  },
  actions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingTop: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  call: {
    width: 40,
    height: 40,
    borderRadius: 13,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  act: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    height: 40,
    paddingHorizontal: 14,
    borderRadius: 12,
  },
  actGo: {
    backgroundColor: colors.success,
  },
  actDanger: {
    borderWidth: 1.5,
    borderColor: colors.danger,
  },
  actText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: '#fff',
  },
});
