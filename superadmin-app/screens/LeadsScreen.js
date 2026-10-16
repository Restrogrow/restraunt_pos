import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Chips, Field, HeaderIcon, Initials, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, spacing } from '../theme';
import { refreshBadges } from '../utils/badges';
import { shortDate, timeAgo } from '../utils/format';

const STAGES = [
  { value: 'new', label: 'New', tone: 'danger' },
  { value: 'contacted', label: 'Contacted', tone: 'info' },
  { value: 'demo', label: 'Demo', tone: 'purple' },
  { value: 'won', label: 'Won', tone: 'success' },
  { value: 'lost', label: 'Lost', tone: 'muted' },
];
const toneOf = (s) => (STAGES.find((x) => x.value === s) || {}).tone || 'muted';
const today = () => new Date().toISOString().slice(0, 10);

export default function LeadsScreen() {
  const [stage, setStage] = useState('all');
  const fetcher = useCallback(() => pf.get('leads', { stage }), [stage]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `leads:${stage}`, refetchOnFocus: true });
  const [edit, setEdit] = useState(null);
  const counts = data?.counts || {};
  const rows = data?.rows || [];
  const total = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0);

  return (
    <Screen
      back
      eyebrow="Sales pipeline"
      title="Leads"
      right={<HeaderIcon icon="add" label="Add lead" onPress={() => setEdit({ stage: 'new' })} />}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      <Chips
        value={stage}
        onChange={setStage}
        style={{ marginBottom: spacing.lg }}
        options={[{ value: 'all', label: 'All', count: total }, ...STAGES.map((s) => ({ ...s, count: Number(counts[s.value]) || 0 }))]}
      />
      {data && !rows.length ? <EmptyState icon="person-add-outline" title="No leads here" subtitle="Enquiries from the website contact form show up here." /> : null}
      {rows.map((l, i) => {
        const due = l.follow_up_on && l.follow_up_on <= today() && !['won', 'lost'].includes(l.stage);
        return (
          <FadeIn key={l.id} index={Math.min(i, 8)}>
            <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => setEdit(l)}>
              <View style={styles.top}>
                <Initials name={l.name} size={38} tone={toneOf(l.stage)} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.name} numberOfLines={1}>
                    {l.name}
                    {l.restaurant_name_hint ? <Text style={styles.hint}>  · {l.restaurant_name_hint}</Text> : null}
                  </Text>
                  <Text style={styles.meta} numberOfLines={1}>
                    {[l.phone, l.email].filter(Boolean).join(' · ') || 'No contact'}
                  </Text>
                </View>
                <Pill small tone={toneOf(l.stage)} label={l.stage} />
              </View>
              {l.message ? (
                <Text style={styles.msg} numberOfLines={3}>
                  {l.message}
                </Text>
              ) : null}
              {l.lead_notes ? (
                <Text style={styles.notes} numberOfLines={2}>
                  📝 {l.lead_notes}
                </Text>
              ) : null}
              <View style={styles.bottom}>
                <Text style={styles.time}>{timeAgo(l.created_at)}</Text>
                {l.follow_up_on ? <Pill small tone={due ? 'warning' : 'muted'} icon="alarm-outline" label={`Follow up ${shortDate(l.follow_up_on)}`} /> : null}
                <View style={{ flex: 1 }} />
                {l.phone ? (
                  <Ionicons name="call" size={18} color={colors.success} onPress={() => Linking.openURL(`tel:${l.phone}`)} style={{ padding: 4 }} />
                ) : null}
                {l.phone ? (
                  <Ionicons
                    name="logo-whatsapp"
                    size={18}
                    color={colors.success}
                    style={{ padding: 4 }}
                    onPress={() => {
                      const p = String(l.phone).replace(/[^\d]/g, '');
                      Linking.openURL(`https://wa.me/${p.length === 10 ? '91' + p : p}?text=${encodeURIComponent(`Hi ${l.name}, this is RestroGrow. Thanks for reaching out!`)}`);
                    }}
                  />
                ) : null}
                {l.email ? <Ionicons name="mail" size={18} color={colors.info} style={{ padding: 4 }} onPress={() => Linking.openURL(`mailto:${l.email}`)} /> : null}
              </View>
            </Card>
          </FadeIn>
        );
      })}
      <LeadSheet lead={edit} onClose={() => setEdit(null)} onSaved={() => { refresh(); refreshBadges(); }} />
    </Screen>
  );
}

function LeadSheet({ lead, onClose, onSaved }) {
  const isNew = lead && !lead.id;
  const [f, setF] = useState({});
  const [busy, setBusy] = useState(false);
  const [last, setLast] = useState(null);
  if (lead !== last) {
    setLast(lead);
    setF(lead ? { stage: 'new', lead_notes: '', follow_up_on: '', restaurant_name_hint: '', name: '', phone: '', email: '', message: '', ...lead } : {});
  }
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    if (isNew && !String(f.name || '').trim()) return feedback.error('Name is required');
    if (f.follow_up_on && !/^\d{4}-\d{2}-\d{2}$/.test(f.follow_up_on)) return feedback.error('Follow-up date should look like 2026-10-20');
    setBusy(true);
    try {
      await pf.post('saveLead', f);
      feedback.success('Lead saved');
      onClose();
      onSaved();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!(await feedback.confirm({ title: 'Delete this lead?', message: 'This cannot be undone.', confirmText: 'Delete', danger: true }))) return;
    try {
      await pf.post('deleteLead', { id: lead.id });
      feedback.success('Lead deleted');
      onClose();
      onSaved();
    } catch (e) {
      feedback.errorFrom(e);
    }
  };

  const plusDays = (n) => {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  };

  return (
    <Sheet scroll visible={!!lead} onClose={onClose} title={isNew ? 'New lead' : lead?.name} subtitle={isNew ? 'Someone you spoke to directly' : `Lead #${lead?.id} · ${shortDate(lead?.created_at, true)}`}>
      {isNew ? (
        <>
          <Field label="Name *" value={f.name} onChangeText={set('name')} autoCapitalize="words" />
          <Field label="Phone" value={f.phone} onChangeText={set('phone')} keyboardType="phone-pad" />
          <Field label="Email" value={f.email} onChangeText={set('email')} keyboardType="email-address" />
        </>
      ) : null}
      <Text style={styles.label}>Stage</Text>
      <Chips value={f.stage} onChange={set('stage')} options={STAGES} style={{ marginTop: 8, marginBottom: 14 }} />
      <Field label="Restaurant" value={f.restaurant_name_hint} onChangeText={set('restaurant_name_hint')} placeholder="Their restaurant's name" autoCapitalize="words" />
      <Field label="Notes" value={f.lead_notes} onChangeText={set('lead_notes')} multiline autoCapitalize="sentences" placeholder="What was discussed, next step…" />
      <Text style={styles.label}>Follow up on</Text>
      <Chips
        value={f.follow_up_on}
        onChange={set('follow_up_on')}
        style={{ marginTop: 8, marginBottom: 8 }}
        options={[
          { label: 'None', value: '' },
          { label: 'Tomorrow', value: plusDays(1) },
          { label: 'In 3 days', value: plusDays(3) },
          { label: 'Next week', value: plusDays(7) },
        ]}
      />
      <Field value={f.follow_up_on} onChangeText={set('follow_up_on')} placeholder="YYYY-MM-DD" />
      <Button title="Save lead" icon="checkmark" onPress={save} loading={busy} />
      {!isNew ? <Button title="Delete lead" icon="trash-outline" variant="ghost" tone="danger" onPress={remove} style={{ marginTop: 6 }} /> : null}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  hint: { fontFamily: font.regular, fontSize: 12.5, color: colors.muted },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  msg: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, marginTop: 10, lineHeight: 19 },
  notes: { fontFamily: font.regular, fontSize: 12.5, color: colors.inkSoft, marginTop: 6, backgroundColor: colors.bg, padding: 8, borderRadius: 10 },
  bottom: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 10 },
  time: { fontFamily: font.medium, fontSize: 11, color: colors.muted },
  label: { fontFamily: font.semiBold, fontSize: 12.5, color: colors.inkSoft },
});
