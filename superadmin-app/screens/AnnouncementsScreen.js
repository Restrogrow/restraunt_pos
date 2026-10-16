import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { StyleSheet, Switch, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Chips, Field, HeaderIcon, Pill, TONES, ToggleRow } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius } from '../theme';
import { shortDate } from '../utils/format';

const LEVELS = [
  { value: 'info', label: 'Info', tone: 'info', icon: 'information-circle' },
  { value: 'success', label: 'Good news', tone: 'success', icon: 'checkmark-circle' },
  { value: 'warning', label: 'Warning', tone: 'warning', icon: 'warning' },
  { value: 'danger', label: 'Urgent', tone: 'danger', icon: 'alert-circle' },
];
const level = (v) => LEVELS.find((l) => l.value === v) || LEVELS[0];

export default function AnnouncementsScreen() {
  const fetcher = useCallback(() => pf.get('announcements'), []);
  const { data, loading, error, refresh, refreshing, setData } = useApiData(fetcher, { cacheKey: 'announcements' });
  const [edit, setEdit] = useState(null);
  const rows = data?.rows || [];

  const toggle = async (a, on) => {
    setData((d) => ({ ...d, rows: d.rows.map((x) => (x.id === a.id ? { ...x, is_active: on ? 1 : 0 } : x)) }));
    try {
      await pf.post('toggleAnnouncement', { id: a.id, is_active: on ? 1 : 0 });
      feedback.success(on ? 'Showing to restaurants' : 'Stopped');
    } catch (e) {
      feedback.errorFrom(e);
      refresh();
    }
  };

  return (
    <Screen
      back
      eyebrow="Banners in restaurant dashboards"
      title="Announcements"
      right={<HeaderIcon icon="add" label="New announcement" onPress={() => setEdit({})} />}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      {data && !rows.length ? (
        <EmptyState icon="megaphone-outline" title="No announcements" subtitle="Post a banner every restaurant sees on their dashboard: new features, downtime, offers." action="New announcement" onAction={() => setEdit({})} />
      ) : null}
      {rows.map((a, i) => {
        const l = level(a.level);
        const t = TONES[l.tone];
        return (
          <FadeIn key={a.id} index={Math.min(i, 8)}>
            <Card style={{ padding: 0, marginBottom: 10, overflow: 'hidden' }} onPress={() => setEdit(a)}>
              <View style={[styles.stripe, { backgroundColor: t.fg }]} />
              <View style={{ padding: 14, paddingLeft: 18 }}>
                <View style={styles.top}>
                  <Ionicons name={l.icon} size={18} color={t.fg} />
                  <Text style={styles.title} numberOfLines={2}>
                    {a.title}
                  </Text>
                  <Switch value={!!Number(a.is_active)} onValueChange={(on) => toggle(a, on)} trackColor={{ false: '#DDD8D4', true: '#FFB4A3' }} thumbColor={Number(a.is_active) ? colors.primary : '#fff'} />
                </View>
                {a.body ? (
                  <Text style={styles.body} numberOfLines={3}>
                    {a.body}
                  </Text>
                ) : null}
                <View style={styles.meta}>
                  <Pill small tone={Number(a.target_all) ? 'muted' : 'purple'} label={Number(a.target_all) ? 'All restaurants' : `${String(a.target_ids || '').split(',').filter(Boolean).length} restaurants`} />
                  {a.ends_at ? <Pill small tone="muted" icon="time-outline" label={`Until ${shortDate(a.ends_at)}`} /> : null}
                  <Text style={styles.small}>{a.dismissed} dismissed</Text>
                </View>
              </View>
            </Card>
          </FadeIn>
        );
      })}
      <EditSheet item={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </Screen>
  );
}

function EditSheet({ item, onClose, onSaved }) {
  const [f, setF] = useState({});
  const [last, setLast] = useState(null);
  const [busy, setBusy] = useState(false);
  const [options, setOptions] = useState(null);
  if (item !== last) {
    setLast(item);
    setF(
      item
        ? {
            id: item.id,
            title: item.title || '',
            body: item.body || '',
            level: item.level || 'info',
            target_all: item.id ? !!Number(item.target_all) : true,
            target_ids: String(item.target_ids || '').split(',').filter(Boolean),
            ends_at: item.ends_at ? String(item.ends_at).slice(0, 10) : '',
            dismissible: item.id ? !!Number(item.dismissible) : true,
            is_active: item.id ? !!Number(item.is_active) : true,
          }
        : {}
    );
    if (item && !options) pf.get('restaurantOptions').then((r) => setOptions(r.rows)).catch(() => {});
  }
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    if (!String(f.title || '').trim()) return feedback.error('Give it a title');
    if (!f.target_all && !f.target_ids.length) return feedback.error('Pick at least one restaurant, or send to all');
    setBusy(true);
    try {
      await pf.post('saveAnnouncement', { ...f, ends_at: f.ends_at ? `${f.ends_at} 23:59:59` : null, is_active: f.is_active ? 1 : 0, dismissible: f.dismissible ? 1 : 0 });
      feedback.success('Announcement saved');
      onClose();
      onSaved();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!(await feedback.confirm({ title: 'Delete announcement?', message: 'It disappears from every dashboard.', confirmText: 'Delete', danger: true }))) return;
    try {
      await pf.post('deleteAnnouncement', { id: f.id });
      feedback.success('Deleted');
      onClose();
      onSaved();
    } catch (e) {
      feedback.errorFrom(e);
    }
  };

  const toggleTarget = (rid) => setF((x) => ({ ...x, target_ids: x.target_ids.includes(rid) ? x.target_ids.filter((y) => y !== rid) : [...x.target_ids, rid] }));

  return (
    <Sheet scroll visible={!!item} onClose={onClose} title={f.id ? 'Edit announcement' : 'New announcement'}>
      <Field label="Title *" value={f.title} onChangeText={set('title')} autoCapitalize="sentences" placeholder="e.g. New: Google sign-in" />
      <Field label="Message" value={f.body} onChangeText={set('body')} multiline autoCapitalize="sentences" placeholder="A line or two of detail" />
      <Text style={styles.label}>Style</Text>
      <Chips value={f.level} onChange={set('level')} options={LEVELS} style={{ marginTop: 8, marginBottom: 14 }} />
      <Text style={styles.label}>Show until (optional)</Text>
      <Field value={f.ends_at} onChangeText={set('ends_at')} placeholder="YYYY-MM-DD" style={{ marginTop: 6 }} />
      <Card padded={false} style={{ paddingHorizontal: 14, backgroundColor: colors.bg, marginBottom: 14 }}>
        <ToggleRow title="Send to all restaurants" value={f.target_all} onChange={set('target_all')} />
        <ToggleRow title="Restaurants can dismiss it" value={f.dismissible} onChange={set('dismissible')} />
        <ToggleRow title="Showing now" value={f.is_active} onChange={set('is_active')} last />
      </Card>
      {!f.target_all ? (
        <View style={styles.pickWrap}>
          {(options || []).map((o) => {
            const on = f.target_ids.includes(o.restaurant_id);
            return (
              <Text key={o.id} onPress={() => toggleTarget(o.restaurant_id)} style={[styles.pick, on && styles.pickOn]}>
                {on ? '✓ ' : ''}
                {o.restaurant_name || o.restaurant_id}
              </Text>
            );
          })}
          {!options ? <Text style={styles.small}>Loading restaurants…</Text> : null}
        </View>
      ) : null}
      <Button title="Save" icon="checkmark" onPress={save} loading={busy} />
      {f.id ? <Button title="Delete" icon="trash-outline" variant="ghost" tone="danger" onPress={remove} style={{ marginTop: 6 }} /> : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  stripe: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 5 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { flex: 1, fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  body: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, marginTop: 6, lineHeight: 19 },
  meta: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6, marginTop: 10 },
  small: { fontFamily: font.medium, fontSize: 11, color: colors.muted },
  label: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
  pickWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 14 },
  pick: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
    backgroundColor: colors.bg,
    borderRadius: radius.pill,
    paddingHorizontal: 11,
    paddingVertical: 6,
    overflow: 'hidden',
  },
  pickOn: { backgroundColor: colors.navy, color: '#fff' },
});
