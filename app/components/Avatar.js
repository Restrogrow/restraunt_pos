import { useEffect, useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { font } from '../theme';

function initials(name) {
  if (!name) return '?';
  // letters/digits only, so "Spice Garden (Demo)" gives "SG" and not "S("
  const parts = String(name).replace(/\(.*?\)/g, ' ').split(/[^\p{L}\p{N}]+/u).filter(Boolean);
  if (!parts.length) return '?';
  const first = parts[0]?.[0] || '';
  const second = parts.length > 1 ? parts[parts.length - 1][0] : '';
  return (first + second).toUpperCase();
}

export default function Avatar({ name, size = 44, bg = 'rgba(255,255,255,0.24)', color = '#fff', uri }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [uri]);

  return (
    <View
      style={[
        styles.wrap,
        { width: size, height: size, borderRadius: size / 2, backgroundColor: bg },
      ]}
    >
      {uri && !failed ? (
        <Image
          source={{ uri }}
          style={{ width: size, height: size, borderRadius: size / 2 }}
          onError={() => setFailed(true)}
        />
      ) : (
        <Text style={[styles.text, { color, fontSize: size * 0.38 }]}>{initials(name)}</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  text: {
    fontFamily: font.semiBold,
  },
});
