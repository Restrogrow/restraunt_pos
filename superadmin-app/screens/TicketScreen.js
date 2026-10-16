import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import ScreenHeader from '../components/ScreenHeader';
import { ErrorState, LoadingState } from '../components/ScreenState';
import { Card, Chips, Pill, Row } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import PressableScale from '../components/ui/PressableScale';
import { pf } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { useLayout } from '../hooks/useLayout';
import { colors, font, radius, shadow, spacing } from '../theme';
import { refreshBadges } from '../utils/badges';
import { dateTime, money, timeAgo } from '../utils/format';
import { TICKET_TONE } from './TicketsScreen';

const QUICK = [
  { label: '👋 Looking into it', text: "Hi! Thanks for reaching out. We're looking into this now and will update you shortly." },
  { label: '✅ Fixed', text: "This is fixed now. Please check and let us know if you still see the problem." },
  { label: '🔄 Refresh & retry', text: 'Please refresh the page (or close and reopen the app) and try again. If it still happens, send us a screenshot.' },
  { label: '📞 We will call', text: "We'll give you a call shortly to sort this out together." },
  { label: '🙏 Thanks', text: 'Thank you for your patience! Closing this for now. Reply here anytime if you need anything else.' },
];

export default function TicketScreen({ route }) {
  const { id, subject } = route.params || {};
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const { gutter, contentWidth } = useLayout();
  const fetcher = useCallback(() => pf.get('ticket', { id }), [id]);
  const { data, loading, error, refresh } = useApiData(fetcher, { cacheKey: `ticket:${id}`, pollInterval: 20000 });
  const [body, setBody] = useState('');
  const [after, setAfter] = useState('answered');
  const [sending, setSending] = useState(false);
  const scroll = useRef(null);
  const t = data?.ticket;

  useEffect(() => {
    if (data) refreshBadges(); // opening the ticket marks it read
  }, [data?.ticket?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const send = async (statusOnly) => {
    if (!statusOnly && !body.trim()) return;
    setSending(true);
    try {
      const res = await pf.post('replyTicket', { id, body: statusOnly ? '' : body.trim(), status: statusOnly || after });
      feedback.success(res.message);
      setBody('');
      await refresh();
      refreshBadges();
    } catch (e) {
      feedback.errorFrom(e);
    } finally {
      setSending(false);
    }
  };

  const changeStatus = async () => {
    const v = await feedback.choose({
      title: 'Ticket status',
      options: [
        { label: 'Open', value: 'open', icon: 'alert-circle-outline' },
        { label: 'Waiting on us', value: 'waiting', icon: 'time-outline' },
        { label: 'Answered', value: 'answered', icon: 'checkmark-circle-outline' },
        { label: 'Closed', value: 'closed', icon: 'archive-outline' },
      ],
    });
    if (v) send(v);
  };

  const inner = { width: '100%', maxWidth: contentWidth, alignSelf: 'center', paddingHorizontal: gutter };

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScreenHeader
        eyebrow={t ? `${t.restaurant_name || t.restaurant_id} · #${t.id}` : `Ticket #${id}`}
        title={t?.subject || subject || 'Ticket'}
        onBack={() => navigation.goBack()}
        right={
          t ? (
            <PressableScale onPress={changeStatus} hitSlop={6}>
              <Pill label={t.status} tone={TICKET_TONE[t.status] || 'muted'} icon="swap-vertical" />
            </PressableScale>
          ) : null
        }
      />
      {loading && !data ? (
        <LoadingState gutter={gutter} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={refresh} />
      ) : (
        <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
          <ScrollView
            ref={scroll}
            contentContainerStyle={[inner, { paddingTop: 16, paddingBottom: 20 }]}
            onContentSizeChange={() => scroll.current?.scrollToEnd({ animated: false })}
            keyboardShouldPersistTaps="handled"
          >
            {t ? (
              <Card padded={false} style={{ paddingHorizontal: spacing.lg, marginBottom: 16 }}>
                <Row
                  icon="storefront-outline"
                  iconTone="primary"
                  title={t.restaurant_name || t.restaurant_id}
                  subtitle={[t.phone, t.email].filter(Boolean).join(' · ') || 'Open restaurant'}
                  onPress={t.user_id ? () => navigation.navigate('RestaurantDetail', { id: t.user_id, name: t.restaurant_name }) : undefined}
                  chevron={!!t.user_id}
                  last={!data.recent_orders?.length && !data.recent_errors?.length}
                />
                {data.recent_orders?.length ? (
                  <Row
                    icon="receipt-outline"
                    title="Recent orders"
                    subtitle={data.recent_orders.slice(0, 3).map((o) => `#${o.order_number} ${o.order_status} ${money(o.total)}`).join('  ·  ')}
                    last={!data.recent_errors?.length}
                  />
                ) : null}
                {data.recent_errors?.length ? (
                  <Row icon="bug-outline" iconTone="danger" title="Recent errors" subtitle={data.recent_errors.slice(0, 2).map((e) => `${e.message} (${timeAgo(e.created_at)})`).join('\n')} last />
                ) : null}
              </Card>
            ) : null}

            {(data?.messages || []).map((m) => {
              const mine = m.sender === 'superadmin';
              return (
                <View key={m.id} style={[styles.bubbleWrap, mine ? { alignItems: 'flex-end' } : { alignItems: 'flex-start' }]}>
                  <View style={[styles.bubble, mine ? styles.mine : [styles.theirs, shadow.sm]]}>
                    <Text style={[styles.sender, mine && { color: 'rgba(255,255,255,0.75)' }]}>{m.sender_name || m.sender}</Text>
                    <Text style={[styles.text, mine && { color: '#fff' }]} selectable>
                      {m.body}
                    </Text>
                  </View>
                  <Text style={styles.when}>{dateTime(m.created_at)}</Text>
                </View>
              );
            })}
          </ScrollView>

          <View style={[styles.composer, { paddingBottom: insets.bottom + 10 }]}>
            <View style={inner}>
              <Chips
                value={after}
                onChange={setAfter}
                style={{ marginBottom: 8 }}
                options={[
                  { label: 'Reply & mark answered', value: 'answered' },
                  { label: 'Reply & close', value: 'closed' },
                ]}
              />
              {!body ? (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 6, paddingBottom: 8 }} keyboardShouldPersistTaps="handled">
                  {QUICK.map((q) => (
                    <PressableScale key={q.label} onPress={() => setBody(q.text)} style={styles.quick}>
                      <Text style={styles.quickText}>{q.label}</Text>
                    </PressableScale>
                  ))}
                </ScrollView>
              ) : null}
              <View style={styles.inputRow}>
                <TextInput
                  style={styles.input}
                  value={body}
                  onChangeText={setBody}
                  placeholder="Write a reply…"
                  placeholderTextColor={colors.muted}
                  multiline
                />
                <PressableScale onPress={() => send()} disabled={sending || !body.trim()} style={[styles.send, (!body.trim() || sending) && { opacity: 0.5 }]}>
                  {sending ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="send" size={18} color="#fff" />}
                </PressableScale>
              </View>
            </View>
          </View>
        </KeyboardAvoidingView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  bubbleWrap: { marginBottom: 12 },
  bubble: { maxWidth: '86%', borderRadius: radius.lg, paddingHorizontal: 14, paddingVertical: 10 },
  mine: { backgroundColor: colors.navy, borderBottomRightRadius: 6 },
  theirs: { backgroundColor: colors.surface, borderBottomLeftRadius: 6 },
  sender: { fontFamily: font.semiBold, fontSize: 11, color: colors.muted, marginBottom: 2 },
  text: { fontFamily: font.regular, fontSize: 14, color: colors.ink, lineHeight: 20 },
  when: { fontFamily: font.regular, fontSize: 10.5, color: colors.muted, marginTop: 3, marginHorizontal: 4 },
  composer: { backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 10 },
  inputRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
  input: {
    flex: 1,
    minHeight: 44,
    maxHeight: 130,
    backgroundColor: colors.bg,
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingTop: 11,
    paddingBottom: 11,
    fontFamily: font.regular,
    fontSize: 14,
    color: colors.ink,
    outlineStyle: 'none',
  },
  quick: { paddingHorizontal: 11, paddingVertical: 6, borderRadius: 999, backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border },
  quickText: { fontFamily: font.medium, fontSize: 12, color: colors.inkSoft },
  send: { width: 44, height: 44, borderRadius: 14, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center' },
});
