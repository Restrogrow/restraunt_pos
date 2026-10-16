import AsyncStorage from '@react-native-async-storage/async-storage';
import { useFocusEffect } from '@react-navigation/native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { network } from '../config/api';
import { isForeground, onAppForeground } from '../utils/foreground';

// Last good response per screen, kept in memory (instant when switching tabs) and on disk
// (instant on the next app start). The screen shows it straight away and refreshes quietly,
// instead of a blank page with a spinner every time it opens.
const memory = new Map();
const DISK_PREFIX = 'sa_apicache_v1:';

export function clearApiCache() {
  memory.clear();
  AsyncStorage.getAllKeys()
    .then((keys) => AsyncStorage.multiRemove(keys.filter((k) => k.startsWith(DISK_PREFIX))))
    .catch(() => {});
}

export function useApiData(fetcher, { pollInterval = 0, cacheKey = null, refetchOnFocus = false } = {}) {
  const cached = cacheKey ? memory.get(cacheKey) : undefined;
  const [data, setDataState] = useState(cached ?? null);
  const [loading, setLoading] = useState(cached === undefined);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const inFlight = useRef(false);
  const keyRef = useRef(cacheKey);
  keyRef.current = cacheKey;

  const setData = useCallback((next) => {
    setDataState((prev) => {
      const value = typeof next === 'function' ? next(prev) : next;
      if (keyRef.current && value != null) memory.set(keyRef.current, value);
      return value;
    });
  }, []);

  // mode: 'initial' shows the loading state (only when nothing is cached), 'manual' drives
  // pull-to-refresh, 'background' (polling / focus) updates in place and never touches
  // loading/refreshing/error — a flaky poll shouldn't blank a screen showing good data.
  const load = useCallback(async (mode = 'initial') => {
    const key = keyRef.current;
    const token = key ?? '__nokey';
    if (inFlight.current === token) return; // same data already loading
    inFlight.current = token;
    if (mode === 'manual') setRefreshing(true);
    else if (mode === 'initial' && !(key && memory.has(key))) setLoading(true);

    try {
      const result = await fetcher();
      if (keyRef.current !== key) return; // the screen moved on (other tab/date) meanwhile
      setDataState(result);
      if (key) {
        memory.set(key, result);
        AsyncStorage.setItem(DISK_PREFIX + key, JSON.stringify(result)).catch(() => {});
      }
      if (mode !== 'background') setError('');
    } catch (e) {
      if (mode !== 'background' && !(key && memory.has(key))) setError(e.message || 'Something went wrong');
    } finally {
      if (inFlight.current === token) {
        inFlight.current = null;
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, [fetcher]);

  // When the key changes (another tab / date), show that key's cached data at once.
  useEffect(() => {
    let cancelled = false;
    if (cacheKey && memory.has(cacheKey)) {
      setDataState(memory.get(cacheKey));
      setLoading(false);
      setError('');
      load('background');
      return undefined;
    }
    if (cacheKey) {
      AsyncStorage.getItem(DISK_PREFIX + cacheKey)
        .then((raw) => {
          if (cancelled || !raw) return;
          const value = JSON.parse(raw);
          if (!memory.has(cacheKey)) {
            memory.set(cacheKey, value);
            setDataState(value);
            setLoading(false);
          }
        })
        .catch(() => {});
    } else {
      setDataState(null);
    }
    load('initial');
    return () => {
      cancelled = true;
    };
  }, [load]); // eslint-disable-line react-hooks/exhaustive-deps

  // Keep screens live while they're open and the app is in front.
  useEffect(() => {
    if (!pollInterval) return undefined;
    const id = setInterval(() => {
      if (isForeground()) load('background');
    }, pollInterval);
    return () => clearInterval(id);
  }, [pollInterval, load]);

  // Back from another app or tab: catch up straight away instead of waiting for the next poll.
  useEffect(() => {
    if (!pollInterval && !refetchOnFocus) return undefined;
    return onAppForeground(() => load('background'));
  }, [pollInterval, refetchOnFocus, load]);

  // connection back: reload in place (and clear the error a failed first load left behind)
  const errorRef = useRef(error);
  errorRef.current = error;
  useEffect(() => {
    let was = network.isOnline();
    return network.subscribe(() => {
      const now = network.isOnline();
      if (now && !was) load(errorRef.current ? 'initial' : 'background');
      was = now;
    });
  }, [load]);

  const firstFocus = useRef(true);
  useFocusEffect(
    useCallback(() => {
      if (firstFocus.current) {
        firstFocus.current = false;
        return;
      }
      if (refetchOnFocus) load('background');
    }, [refetchOnFocus, load])
  );

  const refresh = useCallback(() => load('manual'), [load]);

  return { data, loading, refreshing, error, refresh, reload: load, setData };
}
