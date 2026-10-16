import { useNavigation } from '@react-navigation/native';
import { useCallback, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Card, Chips, Initials, Pill } from '../components/kit';
import FadeIn from '../components/ui/FadeIn';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { timeAgo } from '../utils/format';

export const TICKET_TONE = { open: 'danger', waiting: 'warning', answered: 'info', closed: 'muted' };

export default function TicketsScreen() {
  const navigation = useNavigation();
  const [status, setStatus] = useState('active');
  const fetcher = useCallback(() => pf.get('tickets', { status }), [status]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `tickets:${status}`, pollInterval: 30000, refetchOnFocus: true });
  const rows = data?.rows || [];
  const counts = data?.counts || {};

  return (
    <Screen
      tabs
      eyebrow={data ? `${data.unread} unread` : 'Help desk'}
      title="Support"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      <Chips
        value={status}
        onChange={setStatus}
        style={{ marginBottom: spacing.lg }}
        options={[
          { label: 'Needs reply', value: 'active', count: (Number(counts.open) || 0) + (Number(counts.waiting) || 0) },
          { label: 'Answered', value: 'answered', count: Number(counts.answered) || 0 },
          { label: 'Closed', value: 'closed', count: Number(counts.closed) || 0 },
          { label: 'All', value: 'all' },
        ]}
      />
      {data && !rows.length ? <EmptyState icon="chatbubbles-outline" title="All caught up" subtitle="No tickets here." /> : null}
      {rows.map((t, i) => (
        <FadeIn key={t.id} index={Math.min(i, 8)}>
          <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => navigation.navigate('Ticket', { id: t.id, subject: t.subject })}>
            <View style={styles.top}>
              <Initials name={t.restaurant_name || t.restaurant_id} size={38} tone={Number(t.unread_admin) ? 'primary' : 'muted'} />
              <View style={{ flex: 1 }}>
                <Text style={[styles.subject, Number(t.unread_admin) && { fontFamily: font.bold }]} numberOfLines={1}>
                  {t.subject || `Ticket #${t.id}`}
                </Text>
                <Text style={styles.meta} numberOfLines={1}>
                  {t.restaurant_name || t.restaurant_id} · #{t.id}
                </Text>
              </View>
              {Number(t.unread_admin) ? <View style={styles.unread} /> : null}
            </View>
            {t.last_message ? (
              <Text style={styles.last} numberOfLines={2}>
                {t.last_message}
              </Text>
            ) : null}
            <View style={styles.bottom}>
              <Pill small tone={TICKET_TONE[t.status] || 'muted'} label={t.status} />
              {t.category ? <Pill small tone="purple" label={t.category} /> : null}
              {t.priority && t.priority !== 'normal' ? <Pill small tone="danger" label={t.priority} /> : null}
              <View style={{ flex: 1 }} />
              <Text style={styles.time}>
                {t.messages} msg · {timeAgo(t.updated_at)}
              </Text>
            </View>
          </Card>
        </FadeIn>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  subject: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  unread: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.primary },
  last: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, marginTop: 10, lineHeight: 19 },
  bottom: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 10 },
  time: { fontFamily: font.medium, fontSize: 11, color: colors.muted },
});
