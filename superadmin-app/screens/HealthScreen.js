import { Ionicons } from '@expo/vector-icons';
import { useCallback } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { Card, Metric, Row, SectionTitle } from '../components/kit';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, spacing } from '../theme';
import { num, timeAgo } from '../utils/format';

export default function HealthScreen() {
  const fetcher = useCallback(() => pf.get('health'), []);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: 'health', pollInterval: 60000 });
  const checks = data?.checks || [];
  const bad = checks.filter((c) => !c.ok).length;

  return (
    <Screen
      back
      eyebrow={data ? `Server time ${data.server_time}` : 'Platform'}
      title="Health"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={
        data ? (
          <View style={[styles.banner, { backgroundColor: bad ? 'rgba(229,72,77,0.2)' : 'rgba(34,160,107,0.2)' }]}>
            <Ionicons name={bad ? 'warning' : 'checkmark-circle'} size={22} color={bad ? '#FF8A80' : '#7EE2B8'} />
            <Text style={styles.bannerText}>{bad ? `${bad} check${bad > 1 ? "s need" : " needs"} attention` : "All systems normal"}</Text>
          </View>
        ) : null
      }
    >
      {data ? (
        <>
          <Card padded={false} style={styles.list}>
            {checks.map((c, i) => (
              <Row
                key={c.label}
                icon={c.ok ? 'checkmark-circle' : 'alert-circle'}
                iconTone={c.ok ? 'success' : 'danger'}
                title={c.label}
                subtitle={c.note}
                last={i === checks.length - 1}
              />
            ))}
          </Card>

          <SectionTitle title="Numbers" />
          <View style={styles.grid}>
            <Metric icon="server" tone="info" label="Database" value={`${data.db_mb} MB`} />
            <Metric icon="folder" tone="purple" label="Uploads" value={`${data.uploads.mb} MB`} sub={`${num(data.uploads.files)}${data.uploads.more ? '+' : ''} files`} />
            <Metric icon="notifications" tone="teal" label="Push devices" value={num(data.push.devices)} sub={`${num(data.push.restaurants)} restaurants`} />
            <Metric icon="bug" tone="danger" label="Errors · 7d" value={num(data.errors?.d7)} sub={`${num(data.errors?.unread)} unread`} />
            <Metric icon="receipt" tone="primary" label="Last order" value={data.last_order ? timeAgo(data.last_order) : 'Never'} />
            <Metric icon="logo-php" tone="muted" label="PHP" value={data.php} />
          </View>

          <SectionTitle title="WhatsApp queue · 7 days" />
          <Card padded={false} style={styles.list}>
            {Object.entries(data.whatsapp.by_status_7d || {}).map(([k, v]) => (
              <Row key={k} icon="logo-whatsapp" iconTone={/fail|error/.test(k) ? 'danger' : k === 'sent' ? 'success' : 'muted'} title={k} value={num(v)} />
            ))}
            <Row icon="time-outline" title="Stuck over 30 min" value={num(data.whatsapp.stuck)} />
            <Row icon="paper-plane-outline" title="Last sent" value={data.whatsapp.last_sent ? timeAgo(data.whatsapp.last_sent) : 'Never'} last={!data.whatsapp.failed?.length} />
            {(data.whatsapp.failed || []).slice(0, 6).map((f, i, arr) => (
              <Row key={f.id} icon="close-circle-outline" iconTone="danger" title={`${f.restaurant_name || f.restaurant_id} · ${f.template_name || f.kind}`} subtitle={`${f.error || 'failed'} · ${timeAgo(f.created_at)}`} last={i === arr.length - 1} />
            ))}
          </Card>

          <SectionTitle title="Largest tables" />
          <Card padded={false} style={styles.list}>
            {(data.db_tables || []).map((t, i, arr) => (
              <View key={t.t} style={[styles.tableRow, i < arr.length - 1 && styles.divider]}>
                <Text style={styles.tableName} numberOfLines={1}>
                  {t.t}
                </Text>
                <Text style={styles.tableRows}>{num(t.r)} rows</Text>
                <Text style={styles.tableMb}>{t.mb} MB</Text>
              </View>
            ))}
          </Card>

          {data.backups?.length ? (
            <>
              <SectionTitle title="Backups" />
              <Card padded={false} style={styles.list}>
                {data.backups.map((b, i, arr) => (
                  <Row key={b.file} icon="archive-outline" title={b.file} subtitle={b.at} value={`${b.mb} MB`} last={i === arr.length - 1} />
                ))}
              </Card>
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: spacing.lg, padding: 14, borderRadius: radius.md },
  bannerText: { fontFamily: font.semiBold, fontSize: 14, color: '#fff' },
  list: { paddingHorizontal: spacing.lg },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  tableRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, gap: 10 },
  divider: { borderBottomWidth: 1, borderBottomColor: colors.border },
  tableName: { flex: 1, fontFamily: font.medium, fontSize: 13, color: colors.ink },
  tableRows: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  tableMb: { width: 64, textAlign: 'right', fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
});
