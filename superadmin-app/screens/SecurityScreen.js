import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import SearchField from '../components/SearchField';
import { Button, Card, Chips, Metric, Row, SectionTitle } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { num, timeAgo } from '../utils/format';

const OUTCOME_TONE = { success: 'success', failed: 'danger', locked_out: 'warning', blocked_ip: 'warning' };

export default function SecurityScreen() {
  const [outcome, setOutcome] = useState('');
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setQuery(q.trim()), 350);
    return () => clearTimeout(t);
  }, [q]);
  const fetcher = useCallback(() => pf.get('security', { outcome, q: query }), [outcome, query]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `security:${outcome}:${query}` });
  const [busy, setBusy] = useState(0);
  const s = data?.summary || {};

  const unblock = async (b) => {
    if (!(await feedback.confirm({ title: `Unblock ${b.ip_address}?`, message: 'Sign-ins from this address will be allowed again.', confirmText: 'Unblock' }))) return;
    setBusy(b.id);
    try {
      const r = await pf.post('unblockIp', { id: b.id });
      feedback.success(r.message);
      refresh();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(0);
    }
  };

  return (
    <Screen
      back
      eyebrow="Sign-ins across every restaurant"
      title="Security"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={<SearchField value={q} onChangeText={setQ} placeholder="Username, IP or restaurant" style={{ marginTop: spacing.lg }} />}
    >
      <View style={styles.grid}>
        <Metric icon="checkmark-circle" tone="success" label="Sign-ins · 24h" value={num(s.success_24h)} />
        <Metric icon="close-circle" tone="danger" label="Failed · 24h" value={num(s.failed_24h)} />
        <Metric icon="lock-closed" tone="warning" label="Locked · 24h" value={num(s.locked_24h)} />
        <Metric icon="phone-portrait" tone="info" label="New devices · 7d" value={num(s.new_devices_7d)} />
      </View>

      {data?.suspicious?.length ? (
        <>
          <SectionTitle title="Suspicious addresses · 24h" />
          <Card padded={false} style={styles.list}>
            {data.suspicious.map((x, i, arr) => (
              <Row key={x.ip_address} icon="warning-outline" iconTone="danger" title={x.ip_address} subtitle={`Tried: ${x.tried}`} value={`${x.n} fails`} last={i === arr.length - 1} />
            ))}
          </Card>
        </>
      ) : null}

      {data?.blocked?.length ? (
        <>
          <SectionTitle title={`Blocked IPs · ${data.blocked.length}`} />
          <Card padded={false} style={styles.list}>
            {data.blocked.map((b, i, arr) => (
              <Row
                key={b.id}
                icon="ban-outline"
                iconTone="danger"
                title={b.ip_address}
                subtitle={`${b.restaurant_name || b.restaurant_id || 'Platform'}${b.reason ? ` · ${b.reason}` : ''}`}
                right={<Button small title="Unblock" variant="soft" tone="info" loading={busy === b.id} onPress={() => unblock(b)} />}
                last={i === arr.length - 1}
              />
            ))}
          </Card>
        </>
      ) : null}

      <SectionTitle title="Recent sign-ins" />
      <Chips
        value={outcome}
        onChange={setOutcome}
        style={{ marginBottom: spacing.md }}
        options={[
          { value: '', label: 'All' },
          { value: 'success', label: 'Success' },
          { value: 'failed', label: 'Failed' },
          { value: 'locked_out', label: 'Locked out' },
        ]}
      />
      <Card padded={false} style={styles.list}>
        {(data?.logins || []).slice(0, 100).map((l, i, arr) => (
          <Row
            key={l.id}
            icon={l.outcome === 'success' ? 'log-in-outline' : 'alert-circle-outline'}
            iconTone={OUTCOME_TONE[l.outcome] || 'muted'}
            title={`${l.actor_name || l.username_tried || '—'}${l.restaurant_name ? ` · ${l.restaurant_name}` : ''}`}
            subtitle={`${l.outcome}${Number(l.is_new_device) ? ' · new device' : ''} · ${l.device_label || ''} ${l.ip_address || ''}`}
            value={timeAgo(l.created_at)}
            last={i === arr.length - 1}
          />
        ))}
        {data && !data.logins?.length ? <Text style={styles.empty}>No sign-ins match.</Text> : null}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  list: { paddingHorizontal: spacing.lg },
  empty: { fontFamily: font.regular, fontSize: 13, color: colors.muted, paddingVertical: 18, textAlign: 'center' },
});
