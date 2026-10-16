import { Ionicons } from '@expo/vector-icons';
import { forwardRef } from 'react';
import { StyleSheet, TextInput, View } from 'react-native';
import { colors, font, spacing } from '../theme';
import PressableScale from './ui/PressableScale';

// The one search box used across the app: compact, with a clear button once there's text.
const SearchField = forwardRef(function SearchField({ value, onChangeText, placeholder = 'Search', autoFocus, style }, ref) {
  return (
    <View style={[styles.wrap, style]}>
      <Ionicons name="search" size={16} color={colors.muted} />
      <TextInput
        ref={ref}
        style={styles.input}
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.muted}
        autoFocus={autoFocus}
        autoCorrect={false}
        returnKeyType="search"
        numberOfLines={1}
      />
      {value ? (
        <PressableScale onPress={() => onChangeText('')} hitSlop={10} accessibilityLabel="Clear search">
          <Ionicons name="close-circle" size={17} color={colors.muted} />
        </PressableScale>
      ) : null}
    </View>
  );
});

export default SearchField;

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    height: 42,
    backgroundColor: colors.surface,
    borderRadius: 12,
    paddingHorizontal: 12,
  },
  input: {
    flex: 1,
    height: 42,
    paddingVertical: 0,
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
    outlineStyle: 'none',
  },
});
