import { Ionicons } from '@expo/vector-icons';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';

import { MenuRow } from '../components/food';
import { Chip, EmptyState, Screen } from '../components/ui';
import { CATEGORIES, categoryOpenNow, hoursLabel, isVegetarian } from '../data/restaurant';
import { colors, font, radius } from '../theme';

export default function MenuScreen({ route }) {
  const params = route.params || {};
  const [query, setQuery] = useState('');
  const [vegOnly, setVegOnly] = useState(false);
  const [active, setActive] = useState(CATEGORIES[0].key);
  const scroller = useRef(null);
  const chips = useRef(null);
  const inputRef = useRef(null);
  const offsets = useRef({});
  const q = query.trim().toLowerCase();

  const sections = useMemo(
    () =>
      CATEGORIES.map((c) => ({
        ...c,
        shown: c.items.filter(
          (i) => (!vegOnly || isVegetarian(i)) && (!q || i.name.toLowerCase().includes(q) || i.desc.toLowerCase().includes(q))
        ),
      })).filter((c) => c.shown.length),
    [q, vegOnly]
  );

  const jump = (key) => {
    setActive(key);
    const y = offsets.current[key];
    if (y != null && scroller.current) scroller.current.scrollTo({ y: Math.max(0, y - 4), animated: true });
  };

  // coming from a Home tile or the search bar
  useEffect(() => {
    if (params.category) {
      setQuery('');
      const t = setTimeout(() => jump(params.category), 80);
      return () => clearTimeout(t);
    }
  }, [params.category, params.t]);

  useEffect(() => {
    if (params.focusSearch && inputRef.current) {
      const t = setTimeout(() => inputRef.current && inputRef.current.focus(), 120);
      return () => clearTimeout(t);
    }
  }, [params.focusSearch]);

  const onScroll = (e) => {
    const y = e.nativeEvent.contentOffset.y + 40;
    let cur = sections[0]?.key;
    sections.forEach((s) => {
      if (offsets.current[s.key] != null && offsets.current[s.key] <= y) cur = s.key;
    });
    if (cur && cur !== active) setActive(cur);
  };

  return (
    <Screen>
      <View style={styles.head}>
        <Text style={styles.title}>Menu</Text>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          <View style={styles.vegDot}><View style={styles.vegInner} /></View>
          <Text style={styles.vegLabel}>Veg only</Text>
          <Switch
            value={vegOnly}
            onValueChange={setVegOnly}
            trackColor={{ false: colors.borderStrong, true: colors.veg }}
            thumbColor="#fff"
            {...({ activeThumbColor: '#fff' })}
          />
        </View>
      </View>

      <View style={styles.search}>
        <Ionicons name="search" size={18} color={colors.muted} />
        <TextInput
          ref={inputRef}
          value={query}
          onChangeText={setQuery}
          placeholder="Search dishes"
          placeholderTextColor={colors.muted}
          style={styles.input}
          returnKeyType="search"
        />
        {query ? (
          <Pressable onPress={() => setQuery('')} hitSlop={8}>
            <Ionicons name="close-circle" size={18} color={colors.muted} />
          </Pressable>
        ) : null}
      </View>

      {!q ? (
        <ScrollView ref={chips} horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0, flexShrink: 0, height: 50 }} contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingBottom: 10, alignItems: "flex-start" }}>
          {CATEGORIES.map((c) => (
            <Chip key={c.key} label={c.name} active={active === c.key} onPress={() => jump(c.key)} />
          ))}
        </ScrollView>
      ) : null}

      <ScrollView ref={scroller} onScroll={onScroll} scrollEventThrottle={64} showsVerticalScrollIndicator={false} contentContainerStyle={{ paddingBottom: 170 }}>
        {sections.length === 0 ? (
          <EmptyState icon="search-outline" title="No dishes found" text={q ? `Nothing matches "${query}". Try another name.` : 'Nothing to show with these filters.'} />
        ) : (
          sections.map((s) => (
            <View key={s.key} onLayout={(e) => { offsets.current[s.key] = e.nativeEvent.layout.y; }}>
              <View style={styles.secHead}>
                <Text style={styles.secTitle}>{s.name}</Text>
                <Text style={styles.secCount}>{s.shown.length} {s.shown.length === 1 ? 'dish' : 'dishes'}</Text>
              </View>
              {!categoryOpenNow(s) ? (
                <View style={styles.hours}>
                  <Ionicons name="time-outline" size={15} color={colors.warning} />
                  <Text style={styles.hoursText}>Served {hoursLabel(s)}. You can see it now and order in that time.</Text>
                </View>
              ) : null}
              {s.shown.map((item) => (
                <MenuRow key={item.id} item={item} />
              ))}
            </View>
          ))
        )}
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingTop: 12, paddingBottom: 10 },
  title: { fontFamily: font.bold, fontSize: 24, color: colors.ink },
  vegDot: { width: 16, height: 16, borderRadius: 3, borderWidth: 1.5, borderColor: colors.veg, alignItems: 'center', justifyContent: 'center', backgroundColor: '#fff' },
  vegInner: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.veg },
  vegLabel: { fontFamily: font.medium, fontSize: 13, color: colors.inkSoft },
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, marginHorizontal: 16, marginBottom: 12, paddingHorizontal: 14, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  input: { flex: 1, fontFamily: font.regular, fontSize: 14.5, color: colors.ink, paddingVertical: 12, outlineStyle: 'none' },
  secHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', paddingHorizontal: 16, paddingTop: 20, paddingBottom: 10 },
  secTitle: { fontFamily: font.bold, fontSize: 19, color: colors.ink },
  secCount: { fontFamily: font.regular, fontSize: 12, color: colors.muted },
  hours: { flexDirection: 'row', alignItems: 'center', gap: 8, marginHorizontal: 16, marginBottom: 10, padding: 10, borderRadius: radius.md, backgroundColor: colors.warningBg },
  hoursText: { flex: 1, fontFamily: font.medium, fontSize: 12, color: colors.inkSoft },
});
