import { useCallback, useState } from 'react';
import { StyleSheet, Switch, Text, View } from 'react-native';
import RestaurantPicker from '../components/RestaurantPicker';
import Screen from '../components/Screen';
import { EmptyState } from '../components/ScreenState';
import { Button, Card, Field, HeaderIcon, Initials, Pill } from '../components/kit';
import { feedback } from '../components/ui/Feedback';
import FadeIn from '../components/ui/FadeIn';
import Sheet from '../components/ui/Sheet';
import { sa } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { colors, font } from '../theme';
import { haptic } from '../utils/haptics';
import { shortDate } from '../utils/format';

// Branch admins: one login that manages several restaurants (a chain's owner or area manager).
export default function BranchAdminsScreen() {
  const fetcher = useCallback(() => sa.get('getBranchAdmins'), []);
  const { data, loading, error, refresh, refreshing, setData } = useApiData(fetcher, { cacheKey: 'branchAdmins' });
  const [edit, setEdit] = useState(null);
  const rows = data?.admins || [];

  const toggle = async (a, on) => {
    haptic.select();
    setData((d) => ({ ...d, admins: d.admins.map((x) => (x.id === a.id ? { ...x, is_active: on ? 1 : 0 } : x)) }));
    try {
      const r = await sa.post('toggleBranchAdmin', { id: a.id, is_active: on ? 1 : 0 });
      feedback.success(r.message);
    } catch (e) {
      feedback.errorFrom(e);
      refresh();
    }
  };

  return (
    <Screen
      back
      eyebrow="One login for several restaurants"
      title="Branch admins"
      right={<HeaderIcon icon="add" label="New branch admin" onPress={() => setEdit({})} />}
      loading={loading && !data}
      error={!data ? error : ''}
      onRetry={refresh}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      {data && !rows.length ? (
        <EmptyState icon="git-branch-outline" title="No branch admins" subtitle="Give a chain owner one login that switches between their restaurants." action="New branch admin" onAction={() => setEdit({})} />
      ) : null}
      {rows.map((a, i) => (
        <FadeIn key={a.id} index={Math.min(i, 8)}>
          <Card style={{ padding: 14, marginBottom: 10 }} onPress={() => setEdit(a)}>
            <View style={styles.top}>
              <Initials name={a.display_name || a.username} tone={Number(a.is_active) ? 'purple' : 'muted'} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name} numberOfLines={1}>
                  {a.display_name || a.username}
                </Text>
                <Text style={styles.meta}>
                  @{a.username} · since {shortDate(a.created_at)}
                </Text>
              </View>
              <Switch value={!!Number(a.is_active)} onValueChange={(on) => toggle(a, on)} trackColor={{ false: '#DDD8D4', true: '#FFB4A3' }} thumbColor={Number(a.is_active) ? colors.primary : '#fff'} />
            </View>
            <View style={styles.links}>
              {(a.links || []).map((l) => (
                <Pill key={l.restaurant_id} small tone="purple" label={l.restaurant_name || l.restaurant_id} />
              ))}
              {!a.links?.length ? <Text style={styles.meta}>No restaurants linked</Text> : null}
            </View>
          </Card>
        </FadeIn>
      ))}
      <AdminSheet item={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </Screen>
  );
}

function AdminSheet({ item, onClose, onSaved }) {
  const [f, setF] = useState({});
  const [last, setLast] = useState(null);
  const [busy, setBusy] = useState(false);
  if (item !== last) {
    setLast(item);
    setF(item ? { id: item.id, username: item.username || '', display_name: item.display_name || '', password: '', restaurant_ids: (item.links || []).map((l) => l.restaurant_id) } : {});
  }
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    if (!f.restaurant_ids?.length) return feedback.error('Pick at least one restaurant');
    setBusy(true);
    try {
      if (f.id) {
        await sa.post('updateBranchLinks', { id: f.id, restaurant_ids: f.restaurant_ids });
        feedback.success('Restaurants updated');
      } else {
        if (!f.username.trim() || f.password.length < 6) {
          setBusy(false);
          return feedback.error('Username and a password of 6+ characters are needed');
        }
        await sa.post('createBranchAdmin', { ...f, username: f.username.trim() });
        feedback.success('Branch admin created');
      }
      onClose();
      onSaved();
    } catch (e) {
      feedback.error(/Duplicate/i.test(e.message) ? 'That username is taken' : e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet scroll visible={!!item} onClose={onClose} title={f.id ? f.display_name || f.username : 'New branch admin'} subtitle={f.id ? `@${f.username} · choose the restaurants they manage` : 'They sign in at the normal restaurant login.'}>
      {!f.id ? (
        <>
          <Field label="Name" value={f.display_name} onChangeText={set('display_name')} autoCapitalize="words" placeholder="e.g. Ravi (Spice Garden chain)" />
          <Field label="Username" value={f.username} onChangeText={(v) => set('username')(v.replace(/\s/g, ''))} />
          <Field label="Password" value={f.password} onChangeText={set('password')} secure hint="At least 6 characters. Share it with them yourself." />
        </>
      ) : null}
      <RestaurantPicker label="Restaurants" multiple value={f.restaurant_ids} onChange={set('restaurant_ids')} placeholder="Choose restaurants" />
      <Button title={f.id ? 'Save restaurants' : 'Create branch admin'} icon="checkmark" onPress={save} loading={busy} />
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  name: { fontFamily: font.semiBold, fontSize: 15, color: colors.ink },
  meta: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  links: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 12 },
});
