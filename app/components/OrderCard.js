import { Ionicons } from '@expo/vector-icons';
import { memo } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, font, radius, shadow, spacing, statusStyles } from '../theme';
import { itemsSummary, money, realName, timeAgo } from '../utils/format';
import PressableScale from './ui/PressableScale';

// The one quick action a card offers for each live status. Pending opens the order instead
// (accepting needs a prep time, rejecting needs a reason).
export const CARD_ACTION = {
  Pending: { label: 'Review', icon: 'eye-outline', open: true },
  Scheduled: { label: 'View', icon: 'calendar-outline', open: true },
  Accepted: { label: 'Start cooking', icon: 'flame-outline', to: 'Preparing' },
  Preparing: { label: 'Mark ready', icon: 'checkmark-done-outline', to: 'Ready' },
  Ready: { label: 'Mark served', icon: 'restaurant-outline', to: 'Served' },
  Served: { label: 'Complete', icon: 'checkmark-circle-outline', to: 'Completed' },
};

const TYPE_ICON = { Delivery: 'bicycle-outline', 'Dine-in': 'restaurant-outline', Takeaway: 'bag-handle-outline' };

function OrderCard({ order, currency, onPress, onAction, onLongPress, busy, showStatus = true, selected, selecting, deleted, onDelete }) {
  const o = order;
  const st = statusStyles[o.order_status] || { fg: colors.inkSoft, bg: colors.border };
  const name = realName(o.customer_name);
  const place = o.order_type === 'Dine-in' && o.table_name ? `Table ${o.table_name}` : o.order_type || 'Order';
  const action = !deleted && !selecting ? CARD_ACTION[o.order_status] : null;
  const paid = o.payment_status === 'Paid';
  const isNew = o.order_status === 'Pending';

  return (
    <PressableScale
      style={[styles.card, shadow.sm, isNew && styles.cardNew, selected && styles.cardSelected, deleted && styles.cardDeleted]}
      onPress={onPress}
      onLongPress={onLongPress}
      scale={0.985}
      accessibilityLabel={`Order ${o.order_number}, ${o.order_status}, ${money(o.total, currency)}`}
    >
      <View style={styles.top}>
        {selecting ? (
          <Ionicons name={selected ? 'checkbox' : 'square-outline'} size={20} color={selected ? colors.primary : colors.muted} style={{ marginRight: 2 }} />
        ) : null}
        <Text style={[styles.number, deleted && styles.struck]} numberOfLines={1}>
          {o.order_number || `#${o.id}`}
        </Text>
        <View style={[styles.source, o.source === 'pos' ? styles.sourcePos : styles.sourceWeb]}>
          <Text style={[styles.sourceText, { color: o.source === 'pos' ? colors.primary : colors.info }]}>
            {o.source === 'pos' ? 'POS' : 'ONLINE'}
          </Text>
        </View>
        <View style={{ flex: 1 }} />
        <Text style={styles.ago} numberOfLines={1}>{timeAgo(o.created_at)}</Text>
      </View>

      <View style={styles.placeRow}>
        <Ionicons name={TYPE_ICON[o.order_type] || 'receipt-outline'} size={14} color={colors.inkSoft} />
        <Text style={styles.place} numberOfLines={1}>
          {place}
          {name ? <Text style={styles.name}>{`  ·  ${name}`}</Text> : null}
        </Text>
        {showStatus ? (
          <View style={[styles.status, { backgroundColor: deleted ? colors.dangerBg : st.bg }]}>
            <View style={[styles.dot, { backgroundColor: deleted ? colors.danger : st.fg }]} />
            <Text style={[styles.statusText, { color: deleted ? colors.danger : st.fg }]}>{deleted ? 'Deleted' : o.order_status}</Text>
          </View>
        ) : null}
      </View>

      {Array.isArray(o.items) && o.items.length ? (
        <Text style={styles.items} numberOfLines={2}>
          {itemsSummary(o.items)}
        </Text>
      ) : null}

      {deleted && (o.delete_reason || o.deleted_by) ? (
        <Text style={styles.deletedLine} numberOfLines={2}>
          {o.delete_reason ? `Reason: ${o.delete_reason}. ` : ''}
          {o.deleted_by ? `Deleted by ${o.deleted_by}` : ''}
        </Text>
      ) : null}

      <View style={styles.bottom}>
        <Text style={[styles.total, deleted && { color: colors.muted }]}>{money(o.total, currency)}</Text>
        <View style={[styles.pay, { backgroundColor: paid ? colors.successBg : colors.warningBg }]}>
          <Text style={[styles.payText, { color: paid ? colors.success : '#B7791F' }]}>{paid ? 'Paid' : 'Not paid'}</Text>
        </View>
        <View style={{ flex: 1 }} />
        {onDelete && !selecting ? (
          <PressableScale style={styles.deleteBtn} onPress={onDelete} hitSlop={6} accessibilityLabel="Delete order" feedback="warning">
            <Ionicons name="trash-outline" size={16} color={colors.danger} />
          </PressableScale>
        ) : null}
        {action ? (
          <PressableScale
            style={[styles.action, action.open && styles.actionOpen]}
            onPress={() => (action.open ? onPress?.() : onAction?.(o, action.to))}
            disabled={busy}
            feedback="press"
          >
            {busy ? (
              <ActivityIndicator size="small" color="#fff" />
            ) : (
              <>
                <Ionicons name={action.icon} size={14} color="#fff" />
                <Text style={styles.actionText}>{action.label}</Text>
              </>
            )}
          </PressableScale>
        ) : null}
      </View>
    </PressableScale>
  );
}

export default memo(OrderCard);

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    paddingHorizontal: spacing.lg,
    gap: 8,
  },
  cardNew: {
    borderWidth: 1.5,
    borderColor: colors.primary,
  },
  cardSelected: {
    borderWidth: 1.5,
    borderColor: colors.primary,
    backgroundColor: colors.primaryLight,
  },
  cardDeleted: {
    backgroundColor: colors.bg,
    borderWidth: 1,
    borderColor: colors.border,
  },
  top: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  number: {
    fontFamily: font.semiBold,
    fontSize: 14.5,
    color: colors.ink,
    flexShrink: 1,
  },
  struck: {
    color: colors.muted,
    textDecorationLine: 'line-through',
  },
  source: {
    borderRadius: 6,
    paddingHorizontal: 6,
    paddingVertical: 1,
  },
  sourcePos: { backgroundColor: colors.primaryLight },
  sourceWeb: { backgroundColor: colors.infoBg },
  sourceText: {
    fontFamily: font.bold,
    fontSize: 9,
    letterSpacing: 0.5,
  },
  ago: {
    flexShrink: 0,
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.muted,
  },
  placeRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  place: {
    flex: 1,
    fontFamily: font.medium,
    fontSize: 13,
    color: colors.inkSoft,
  },
  name: {
    fontFamily: font.regular,
    color: colors.muted,
  },
  status: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    borderRadius: radius.pill,
    paddingHorizontal: 9,
    paddingVertical: 3,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  statusText: {
    fontFamily: font.semiBold,
    fontSize: 11,
  },
  items: {
    fontFamily: font.regular,
    fontSize: 12.5,
    color: colors.ink,
    lineHeight: 18,
  },
  deletedLine: {
    fontFamily: font.regular,
    fontSize: 12,
    color: colors.muted,
  },
  bottom: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingTop: 10,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  total: {
    fontFamily: font.bold,
    fontSize: 15.5,
    color: colors.ink,
  },
  pay: {
    borderRadius: radius.pill,
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  payText: {
    fontFamily: font.semiBold,
    fontSize: 10.5,
  },
  deleteBtn: {
    width: 34,
    height: 34,
    borderRadius: 11,
    backgroundColor: colors.dangerBg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  action: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 36,
    backgroundColor: colors.success,
    borderRadius: radius.pill,
    paddingHorizontal: 14,
  },
  actionOpen: {
    backgroundColor: colors.primary,
  },
  actionText: {
    fontFamily: font.semiBold,
    fontSize: 12.5,
    color: '#fff',
  },
});
