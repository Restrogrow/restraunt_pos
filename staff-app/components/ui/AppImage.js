import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { colors } from '../../theme';

// Dish and logo photos: cached on disk and in memory (no reload flicker when a list scrolls
// or a screen reopens), cross-faded in, with an icon placeholder when there's no photo or
// it fails to load.
export default function AppImage({ uri, style, icon = 'restaurant-outline', iconSize = 22, iconColor = colors.muted, contentFit = 'cover' }) {
  const [failed, setFailed] = useState(false);
  if (!uri || failed) {
    return (
      <View style={[styles.placeholder, style]}>
        <Ionicons name={icon} size={iconSize} color={iconColor} />
      </View>
    );
  }
  return (
    <Image
      source={{ uri }}
      style={style}
      contentFit={contentFit}
      transition={180}
      cachePolicy="memory-disk"
      onError={() => setFailed(true)}
    />
  );
}

const styles = StyleSheet.create({
  placeholder: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
});
