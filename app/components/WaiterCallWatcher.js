import { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import { apiGet } from '../config/api';
import { setLiveCount } from '../utils/liveCounts';
import { feedback } from './ui/Feedback';

const POLL_MS = 15000;

// Keeps the "More" tab's badge in step with open waiter calls, and says when a new one comes
// in ("Table T4 is calling") wherever you are in the app.
export default function WaiterCallWatcher() {
  const seen = useRef(null);

  useEffect(() => {
    let cancelled = false;
    const poll = async () => {
      if (AppState.currentState !== 'active') return;
      try {
        const res = await apiGet('/api/get_waiter_requests.php');
        if (cancelled || !res?.success) return;
        const list = res.requests || [];
        setLiveCount('calls', list.length);
        const ids = new Set(list.map((r) => r.id));
        if (seen.current) {
          const fresh = list.filter((r) => !seen.current.has(r.id));
          if (fresh.length === 1) {
            const r = fresh[0];
            feedback.info(`${r.table_number ? `Table ${r.table_number}` : 'A table'} is calling${r.request_type && r.request_type !== 'General' ? `: ${r.request_type}` : ''}`);
          } else if (fresh.length > 1) {
            feedback.info(`${fresh.length} tables are calling a waiter`);
          }
        }
        seen.current = ids;
      } catch (e) {
        // background poll — stay quiet
      }
    };
    poll();
    const id = setInterval(poll, POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, []);

  return null;
}
