import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, StyleSheet, Text, View } from 'react-native';
import Screen from '../components/Screen';
import SearchField from '../components/SearchField';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Chips, Initials, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font, radius, spacing } from '../theme';
import { dateTime, timeAgo } from '../utils/format';

const STATUSES = [
  { value: 'new', label: 'New', tone: 'danger' },
  { value: 'contacted', label: 'Contacted', tone: 'info' },
  { value: 'qualified', label: 'Qualified', tone: 'success' },
  { value: 'closed', label: 'Closed', tone: 'muted' },
];
const toneOf = (s) => (STATUSES.find((x) => x.value === s) || {}).tone || 'muted';

export default function ChatLeadsScreen() {
  const [status, setStatus] = useState('all');
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setQuery(q.trim()), 350);
    return () => clearTimeout(t);
  }, [q]);
  const fetcher = useCallback(() => pf.get('chatLeads', { status, q: query }), [status, query]);
  const { data, loading, error, refresh, refreshing } = useApiData(fetcher, { cacheKey: `chat:${status}:${query}` });
  const [open, setOpen] = useState(null);
  const counts = data?.counts || {};
  const rows = data?.rows || [];

  return (
    <Screen
      back
      eyebrow="Website chatbot"
      title="Chat leads"
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
      headerChildren={<SearchField value={q} onChangeText={setQ} placeholder="Search name, restaurant, phone" style={{ marginTop: spacing.lg }} />}
    >
      <Chips
        value={status}
        onChange={setStatus}
        style={{ marginBottom: spacing.lg }}
        options={[{ value: 'all', label: 'All' }, ...STATUSES.map((s) => ({ ...s, count: Number(counts[s.value]) || 0 }))]}
      />
      {data && !rows.length ? <EmptyState icon="chatbox-ellipses-outline" title="No conversations" subtitle="People who chat with the bot on restrogrow.com show up here." /> : null}
      {rows.map((l, i) => (
        <FadeIn key={l.id} index={Math.min(i, 8)}>
          <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => setOpen(l.id)}>
            <View style={styles.top}>
              <Initials name={l.name || 'Visitor'} size={38} tone={toneOf(l.status)} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {l.name || 'Visitor'}
                  {l.restaurant_name ? <Text style={styles.meta}>  · {l.restaurant_name}</Text> : null}
                </Text>
                <Text style={styles.meta} numberOfLines={1}>
                  {[l.phone, l.email].filter(Boolean).join(' · ') || 'No contact left'}
                </Text>
              </View>
              <Pill small tone={toneOf(l.status)} label={l.status} />
            </View>
            {l.last_message ? (
              <Text style={styles.last} numberOfLines={2}>
                {l.last_message}
              </Text>
            ) : null}
            <Text style={styles.time}>
              {l.message_count} messages · {timeAgo(l.updated_at)}
            </Text>
          </Card>
        </FadeIn>
      ))}
      <ConversationSheet id={open} onClose={() => setOpen(null)} onChanged={refresh} />
    </Screen>
  );
}

function ConversationSheet({ id, onClose, onChanged }) {
  const [data, setData] = useState(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!id) return;
    setData(null);
    pf.get('chatConversation', { id })
      .then(setData)
      .catch((e) => {
        feedback.errorFrom(e);
        onClose();
      });
  }, [id]); // eslint-disable-line react-hooks/exhaustive-deps

  const setStatus = async (status) => {
    setBusy(true);
    try {
      await pf.post('updateChatLead', { id, status });
      setData((d) => ({ ...d, lead: { ...d.lead, status } }));
      feedback.success('Status updated');
      onChanged();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setBusy(false);
    }
  };

  const lead = data?.lead;
  const phone = String(lead?.phone || '').replace(/[^\d]/g, '');
  return (
    <Sheet scroll visible={!!id} onClose={onClose} title={lead?.name || 'Conversation'} subtitle={lead ? [lead.restaurant_name, lead.phone, lead.email].filter(Boolean).join(' · ') : ''}>
      {!data ? (
        <ActivityIndicator color={colors.primary} style={{ marginVertical: 30 }} />
      ) : (
        <>
          <Chips value={lead.status} onChange={setStatus} options={STATUSES} style={{ marginBottom: 14, opacity: busy ? 0.5 : 1 }} />
          {data.messages.map((m, i) => {
            const bot = m.sender !== 'visitor';
            return (
              <View key={i} style={{ alignItems: bot ? 'flex-start' : 'flex-end', marginBottom: 8 }}>
                <View style={[styles.bubble, bot ? styles.bot : styles.user]}>
                  <Text style={[styles.text, !bot && { color: '#fff' }]}>{m.message}</Text>
                </View>
                <Text style={styles.when}>{dateTime(m.created_at)}</Text>
              </View>
            );
          })}
          {phone ? (
            <Button
              title="Message on WhatsApp"
              icon="logo-whatsapp"
              tone="success"
              style={{ marginTop: 10 }}
              onPress={() => Linking.openURL(`https://wa.me/${phone.length === 10 ? '91' + phone : phone}?text=${encodeURIComponent(`Hi ${lead.name || ''}, this is RestroGrow following up on your chat.`)}`)}
            />
          ) : null}
        </>
      )}
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 14.5, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  last: { fontFamily: font.regular, fontSize: 13, color: colors.inkSoft, marginTop: 10, lineHeight: 19 },
  time: { fontFamily: font.medium, fontSize: 11, color: colors.muted, marginTop: 8 },
  bubble: { maxWidth: '88%', borderRadius: radius.md, paddingHorizontal: 12, paddingVertical: 8 },
  bot: { backgroundColor: colors.bg },
  user: { backgroundColor: colors.navy },
  text: { fontFamily: font.regular, fontSize: 13.5, color: colors.ink, lineHeight: 19 },
  when: { fontFamily: font.regular, fontSize: 10, color: colors.muted, marginTop: 2 },
});
