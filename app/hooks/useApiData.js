import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Platform } from 'react-native';

function isForeground() {
  if (Platform.OS === 'web') {
    return typeof document === 'undefined' || document.visibilityState !== 'hidden';
  }
  return AppState.currentState === 'active';
}

export function useApiData(fetcher, { pollInterval = 0 } = {}) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const inFlight = useRef(false);

  // mode: 'initial' shows the full-screen spinner, 'manual' drives the
  // pull-to-refresh indicator, 'background' (silent polling) updates data
  // in place and never touches loading/refreshing/error — a flaky poll
  // shouldn't flash a spinner or blank out a screen that's already showing
  // good data.
  const load = useCallback(async (mode = 'initial') => {
    if (inFlight.current) return;
    inFlight.current = true;
    if (mode === 'manual') setRefreshing(true);
    else if (mode === 'initial') setLoading(true);

    try {
      const result = await fetcher();
      setData(result);
      if (mode !== 'background') setError('');
    } catch (e) {
      if (mode !== 'background') setError(e.message || 'Something went wrong');
    } finally {
      setLoading(false);
      setRefreshing(false);
      inFlight.current = false;
    }
  }, [fetcher]);

  useEffect(() => {
    load('initial');
  }, [load]);

  // Auto-refetch in the background so screens stay live without the user
  // having to pull-to-refresh. Paused while the tab/app isn't in the
  // foreground so we don't burn requests on a screen nobody's looking at.
  useEffect(() => {
    if (!pollInterval) return undefined;

    const id = setInterval(() => {
      if (isForeground()) load('background');
    }, pollInterval);

    return () => clearInterval(id);
  }, [pollInterval, load]);

  const refresh = useCallback(() => load('manual'), [load]);

  return { data, loading, refreshing, error, refresh, reload: load };
}
