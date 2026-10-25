import { LayoutAnimation } from 'react-native';
import { feedback } from '../components/ui/Feedback';
import { apiPostForm } from '../config/api';
import { patchKot, refreshLive, removeCall } from './liveStore';

const smooth = () => LayoutAnimation.configureNext(LayoutAnimation.create(220, 'easeInEaseOut', 'opacity'));

const DONE_TEXT = { Preparing: 'Cooking started', Ready: 'Marked ready', Completed: 'Served' };
const BAR_DONE_TEXT = { Preparing: 'Started making' }; // a bar ticket (BOT)

// Move a kitchen (or bar) ticket along New → Cooking → Ready → Completed (served / picked up).
// The row moves at once; if the server refuses, the next refresh puts it back.
export async function moveKot(kot, to) {
  smooth();
  patchKot(kot.id, to);
  try {
    const res = await apiPostForm('/controllers/kot_operations.php', { action: 'update_kot_status', kotId: kot.id, status: to });
    if (!res.success) throw new Error(res.message || 'Could not update the ticket');
    feedback.success(`${kot.table_number ? `Table ${kot.table_number}` : kot.order_type || 'Ticket'}: ${(kot.station === 'bar' && BAR_DONE_TEXT[to]) || DONE_TEXT[to] || to}`);
    return true;
  } catch (e) {
    feedback.errorFrom(e, 'Could not update the ticket');
    return false;
  } finally {
    refreshLive();
  }
}

// A waiter call looked after.
export async function attendCall(call) {
  smooth();
  removeCall(call.id);
  try {
    const res = await apiPostForm('/controllers/waiter_request_operations.php', { action: 'mark_attended', requestId: call.id });
    if (!res.success) throw new Error(res.message || 'Could not update the call');
    feedback.success(`${call.table_number ? `Table ${call.table_number}` : 'Table'} looked after`);
    return true;
  } catch (e) {
    feedback.errorFrom(e, 'Could not update the call');
    return false;
  } finally {
    refreshLive();
  }
}
