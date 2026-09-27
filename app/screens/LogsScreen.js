import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import ScreenHeader from '../components/ScreenHeader';
import { ErrorState, LoadingState } from '../components/ScreenState';
import { apiGet, apiPostForm } from '../config/api';
import { useApiData } from '../hooks/useApiData';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';

// Filter chips across the top — 'new_device' is a pseudo-outcome (it filters
// successful logins from never-seen-before devices).
const FILTERS = [
  { key: '', label: 'All', icon: 'list' },
  { key: 'success', label: 'Logins', icon: 'log-in-outline' },
  { key: 'failed', label: 'Failed', icon: 'close-circle-outline' },
  { key: 'locked_out', label: 'Lockouts', icon: 'lock-closed-outline' },
  { key: 'new_device', label: 'New device', icon: 'phone-portrait-outline' },
  { key: 'blocked_ip', label: 'Blocked', icon: 'ban-outline' },
];

const OUTCOME_STYLES = {
  success: { bg: colors.successBg, fg: colors.success, icon: 'log-in-outline', label: 'Login' },
  failed: { bg: colors.warningBg, fg: colors.warning, icon: 'close-circle-outline', label: 'Wrong password' },
  locked_out: { bg: colors.dangerBg, fg: colors.danger, icon: 'lock-closed-outline', label: 'Locked out' },
  blocked_ip: { bg: colors.dangerBg, fg: colors.danger, icon: 'ban-outline', label: 'Blocked IP' },
  logout: { bg: colors.border, fg: colors.inkSoft, icon: 'log-out-outline', label: 'Logout' },
};

function formatTime(ts) {
  if (!ts) return '';
  const d = new Date(String(ts).replace(' ', 'T'));
  if (isNaN(d.getTime())) return ts;
  const today = new Date();
  const sameDay = d.toDateString() === today.toDateString();
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (sameDay) return time;
  return `${d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })} ${time}`;
}

function LogRow({ log, canBlock, armed, onArm, onBlock, busy }) {
  const s = OUTCOME_STYLES[log.outcome] || OUTCOME_STYLES.failed;
  const who = log.actor_name || log.username_tried || 'Unknown';
  const isStaff = log.user_type === 'staff';
  const blockable = canBlock && (log.outcome === 'failed' || log.outcome === 'locked_out');
  return (
    <View style={[styles.row, shadow.sm]}>
      <View style={[styles.outcomeChip, { backgroundColor: s.bg }]}>
        <Ionicons name={s.icon} size={13} color={s.fg} />
      </View>
      <View style={styles.rowMain}>
        <View style={styles.rowTopLine}>
          <Text style={styles.who} numberOfLines={1}>{who}</Text>
          {isStaff ? <Text style={styles.staffTag}>Staff</Text> : null}
          {log.is_new_device == 1 ? (
            <View style={styles.newDeviceChip}>
              <Text style={styles.newDeviceText}>New device</Text>
            </View>
          ) : null}
        </View>
        <Text style={styles.meta} numberOfLines={1}>
          {s.label} · {log.device_label || 'Unknown device'}
          {log.ip_address ? ` · IP ${log.ip_address}` : ''}
        </Text>
        {blockable && log.ip_address ? (
          armed ? (
            <Pressable style={styles.blockConfirm} disabled={busy} onPress={onBlock}>
              {busy ? (
                <ActivityIndicator size="small" color="#fff" />
              ) : (
                <>
                  <Ionicons name="ban-outline" size={12} color="#fff" />
                  <Text style={styles.blockConfirmText}>Tap again to block {log.ip_address}</Text>
                </>
              )}
            </Pressable>
          ) : (
            <Pressable style={styles.blockArm} disabled={busy} onPress={onArm} hitSlop={6}>
              <Ionicons name="ban-outline" size={11} color={colors.danger} />
              <Text style={styles.blockArmText}>Block this IP</Text>
            </Pressable>
          )
        ) : null}
      </View>
      <Text style={styles.time}>{formatTime(log.created_at)}</Text>
    </View>
  );
}

export default function LogsScreen() {
  const { user } = useAuth();
  const isAdmin = String(user?.role || '').toLowerCase() === 'admin' || String(user?.user_type || '') === 'admin' || String(user?.user_type || '') === 'branch_admin';
  const [filter, setFilter] = useState('');
  const [days, setDays] = useState(7);
  const [busyIp, setBusyIp] = useState(null);
  const [armedIp, setArmedIp] = useState(null);

  const fetcher = useCallback(
    () => apiGet(`/api/get_login_logs.php?outcome=${encodeURIComponent(filter)}&days=${days}`),
    [filter, days]
  );
  const { data, loading, refreshing, error, refresh, reload } = useApiData(fetcher, {
    pollInterval: 30000,
  });

  const logs = data?.logs || [];
  const summary = data?.summary || {};
  const blockedIps = data?.blocked_ips || [];
  const policy = data?.policy || { max_attempts: 3, lockout_minutes: 30 };

  const toggleBlock = async (ip, isBlocked) => {
    setBusyIp(ip);
    try {
      const res = await apiPostForm('/api/security_operations.php', {
        action: isBlocked ? 'unblock_ip' : 'block_ip',
        ip,
        reason: isBlocked ? '' : 'Blocked from Logs screen',
      });
      if (!res.success) throw new Error(res.message || 'Request failed');
      setArmedIp(null);
      reload('background');
    } catch (e) {
      console.warn('block/unblock failed:', e.message);
    } finally {
      setBusyIp(null);
    }
  };

  // Two-tap confirm on a log row (same pattern as order delete —
  // Alert.alert is a no-op on web). First tap arms, second blocks.
  const armBlock = (ip) => setArmedIp((cur) => (cur === ip ? null : ip));

  return (
    <View style={styles.fill}>
      <ScreenHeader eyebrow="Security" title="Logs" />

      <ScrollView
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.primary} />}
        showsVerticalScrollIndicator={false}
      >
        {/* Summary strip */}
        <View style={styles.summaryRow}>
          <View style={[styles.summaryCard, { backgroundColor: colors.successBg }]}>
            <Text style={[styles.summaryNum, { color: colors.success }]}>{summary.success_count ?? 0}</Text>
            <Text style={styles.summaryLabel}>Logins</Text>
          </View>
          <View style={[styles.summaryCard, { backgroundColor: colors.warningBg }]}>
            <Text style={[styles.summaryNum, { color: colors.warning }]}>{summary.failed_count ?? 0}</Text>
            <Text style={styles.summaryLabel}>Failed</Text>
          </View>
          <View style={[styles.summaryCard, { backgroundColor: colors.dangerBg }]}>
            <Text style={[styles.summaryNum, { color: colors.danger }]}>{summary.locked_count ?? 0}</Text>
            <Text style={styles.summaryLabel}>Lockouts</Text>
          </View>
          <View style={[styles.summaryCard, { backgroundColor: colors.purpleBg }]}>
            <Text style={[styles.summaryNum, { color: colors.purple }]}>{summary.new_device_count ?? 0}</Text>
            <Text style={styles.summaryLabel}>New device</Text>
          </View>
        </View>

        <Text style={styles.policyNote}>
          After {policy.max_attempts} wrong passwords, that network is locked out for {policy.lockout_minutes} minutes.
        </Text>

        {/* Outcome filters */}
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filterRow}>
          {FILTERS.map((f) => {
            const active = filter === f.key;
            return (
              <Pressable key={f.key} style={[styles.filterChip, active && styles.filterChipActive]} onPress={() => setFilter(f.key)}>
                <Ionicons name={f.icon} size={13} color={active ? '#fff' : colors.inkSoft} />
                <Text style={[styles.filterText, active && styles.filterTextActive]}>{f.label}</Text>
              </Pressable>
            );
          })}
        </ScrollView>

        {/* Blocked IPs (admin can unblock; managers see the list) */}
        {blockedIps.length > 0 ? (
          <View style={[styles.blockedCard, shadow.sm]}>
            <Text style={styles.blockedTitle}>Blocked IPs</Text>
            {blockedIps.map((b) => (
              <View key={b.id} style={styles.blockedRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.blockedIp}>{b.ip_address}</Text>
                  <Text style={styles.blockedMeta} numberOfLines={1}>
                    {b.reason || 'No reason given'} · by {b.blocked_by || '—'}
                  </Text>
                </View>
                {isAdmin ? (
                  <Pressable
                    style={styles.unblockButton}
                    disabled={busyIp === b.ip_address}
                    onPress={() => toggleBlock(b.ip_address, true)}
                  >
                    {busyIp === b.ip_address ? (
                      <ActivityIndicator size="small" color={colors.inkSoft} />
                    ) : (
                      <Text style={styles.unblockText}>Unblock</Text>
                    )}
                  </Pressable>
                ) : null}
              </View>
            ))}
          </View>
        ) : null}

        {/* Log list */}
        {loading ? (
          <LoadingState />
        ) : error ? (
          <ErrorState message={error} onRetry={refresh} />
        ) : logs.length === 0 ? (
          <View style={styles.emptyWrap}>
            <Ionicons name="shield-checkmark-outline" size={40} color={colors.muted} />
            <Text style={styles.emptyTitle}>No activity</Text>
            <Text style={styles.emptySub}>Login attempts will appear here</Text>
          </View>
        ) : (
          logs.map((log) => (
            <LogRow
              key={log.id}
              log={log}
              canBlock={isAdmin}
              armed={armedIp === log.ip_address && log.ip_address}
              onArm={() => armBlock(log.ip_address)}
              onBlock={() => toggleBlock(log.ip_address, false)}
              busy={busyIp === log.ip_address}
            />
          ))
        )}

        {/* Inline block affordance is on each failed/locked row (admin only) */}
        {isAdmin ? null : null}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  list: {
    padding: spacing.xl,
    paddingTop: spacing.lg,
    paddingBottom: 120,
    gap: spacing.md,
  },
  summaryRow: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  summaryCard: {
    flex: 1,
    borderRadius: radius.md,
    alignItems: 'center',
    paddingVertical: spacing.md,
  },
  summaryNum: {
    fontFamily: font.bold,
    fontSize: 20,
  },
  summaryLabel: {
    fontFamily: font.medium,
    fontSize: 10.5,
    color: colors.inkSoft,
    marginTop: 2,
  },
  policyNote: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
    textAlign: 'center',
  },
  filterRow: {
    gap: spacing.sm,
    paddingVertical: 2,
  },
  filterChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    backgroundColor: colors.surface,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 7,
  },
  filterChipActive: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
  filterText: {
    fontFamily: font.medium,
    fontSize: 12,
    color: colors.inkSoft,
  },
  filterTextActive: {
    color: '#fff',
  },
  blockedCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    gap: spacing.sm,
    borderWidth: 1,
    borderColor: colors.dangerBg,
  },
  blockedTitle: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.danger,
  },
  blockedRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingTop: spacing.xs,
  },
  blockedIp: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.ink,
  },
  blockedMeta: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    marginTop: 1,
  },
  unblockButton: {
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 6,
  },
  unblockText: {
    fontFamily: font.semiBold,
    fontSize: 12,
    color: colors.inkSoft,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  outcomeChip: {
    width: 30,
    height: 30,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rowMain: {
    flex: 1,
    gap: 2,
  },
  rowTopLine: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  who: {
    fontFamily: font.semiBold,
    fontSize: 13.5,
    color: colors.ink,
  },
  staffTag: {
    fontFamily: font.medium,
    fontSize: 10,
    color: colors.info,
    backgroundColor: colors.infoBg,
    borderRadius: 5,
    paddingHorizontal: 4,
    paddingVertical: 1,
    overflow: 'hidden',
  },
  newDeviceChip: {
    backgroundColor: colors.purpleBg,
    borderRadius: 5,
    paddingHorizontal: 5,
    paddingVertical: 1,
  },
  newDeviceText: {
    fontFamily: font.bold,
    fontSize: 9.5,
    color: colors.purple,
  },
  meta: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
  },
  time: {
    fontFamily: font.medium,
    fontSize: 10.5,
    color: colors.muted,
  },
  emptyWrap: {
    alignItems: 'center',
    paddingVertical: spacing.xxl * 2,
    gap: spacing.xs,
  },
  emptyTitle: {
    fontFamily: font.semiBold,
    fontSize: 15,
    color: colors.ink,
    marginTop: spacing.sm,
  },
  emptySub: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.muted,
  },
  hint: {
    fontFamily: font.regular,
    fontSize: 11.5,
    color: colors.muted,
    textAlign: 'center',
  },
  blockArm: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    alignSelf: 'flex-start',
    marginTop: 3,
  },
  blockArmText: {
    fontFamily: font.medium,
    fontSize: 10.5,
    color: colors.danger,
  },
  blockConfirm: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    alignSelf: 'flex-start',
    backgroundColor: colors.danger,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.sm,
    paddingVertical: 5,
    marginTop: 3,
  },
  blockConfirmText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
    color: '#fff',
  },
});
