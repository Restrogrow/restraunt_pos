import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text } from 'react-native';
import Screen from '../components/Screen';
import SearchField from '../components/SearchField';
import { EmptyState } from '../components/ScreenState';
import { Card, Row } from '../components/kit';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { dateTime } from '../utils/format';

function iconFor(action) {
  if (/delete|disable|expire/i.test(action)) return ['trash-outline', 'danger'];
  if (/login/i.test(action)) return ['log-in-outline', 'info'];
  if (/create|save|add/i.test(action)) return ['add-circle-outline', 'success'];
  if (/password|otp|unlock|security/i.test(action)) return ['key-outline', 'warning'];
  if (/ticket/i.test(action)) return ['chatbubbles-outline', 'purple'];
  return ['create-outline', 'muted'];
}

// details is JSON from pfAudit; show it as "key: value · key: value"
function detailsText(d) {
  if (!d) return '';
  try {
    const o = JSON.parse(d);
    if (o && typeof o === 'object') return Object.entries(o).filter(([, v]) => v !== null && v !== '').map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' · ');
  } catch (e) {
    // plain text
  }
  return String(d);
}

export default function ActivityScreen() {
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setQuery(q.trim()), 350);
    return () => clearTimeout(t);
  }, [q]);
  const fetcher = useCallback(() => pf.get('auditLog', { q: query }), [query]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `audit:${query}` });
  const rows = data?.rows || [];

  return (
    <Screen
      back
      eyebrow="Every change made by a super admin"
      title="Activity log"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={<SearchField value={q} onChangeText={setQ} placeholder="Action, restaurant, admin" style={{ marginTop: spacing.lg }} />}
    >
      {data && !rows.length ? <EmptyState icon="list-outline" title="Nothing logged" subtitle={query ? 'No activity matches.' : 'Changes you make will be recorded here.'} /> : null}
      {rows.length ? (
        <Card padded={false} style={{ paddingHorizontal: spacing.lg }}>
          {rows.map((r, i) => {
            const [icon, tone] = iconFor(r.action);
            const det = detailsText(r.details);
            return (
              <Row
                key={r.id}
                icon={icon}
                iconTone={tone}
                title={`${r.action}${r.target ? ` · ${r.target}` : ''}`}
                subtitle={`${r.admin_name || 'system'} · ${dateTime(r.created_at)}${det ? `\n${det}` : ''}`}
                last={i === rows.length - 1}
              />
            );
          })}
        </Card>
      ) : null}
      {rows.length >= 300 ? <Text style={styles.more}>Showing the latest 300. Search to narrow down.</Text> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  more: { fontFamily: font.regular, fontSize: 12, color: colors.muted, textAlign: 'center', marginTop: 14 },
});
