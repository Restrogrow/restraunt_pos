import { useRef } from 'react';
import { Animated, Pressable, StyleSheet } from 'react-native';
import { haptic } from '../../utils/haptics';

// Styles that place the button in its parent's layout belong on the outer Pressable;
// everything else (colours, padding, borders) on the animated inner view.
const OUTER_KEYS = [
  'flex', 'flexGrow', 'flexShrink', 'flexBasis', 'alignSelf', 'width', 'minWidth', 'maxWidth',
  'margin', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight', 'marginHorizontal', 'marginVertical',
  'position', 'top', 'left', 'right', 'bottom', 'zIndex',
];
function splitStyle(style) {
  const flat = StyleSheet.flatten(style) || {};
  const outer = {};
  const inner = {};
  Object.keys(flat).forEach((k) => {
    if (OUTER_KEYS.includes(k)) outer[k] = flat[k];
    else inner[k] = flat[k];
  });
  // the inner view fills the outer box when the outer one is sized by its parent
  if (outer.flex != null || outer.flexGrow != null || outer.width != null || outer.alignSelf === 'stretch') inner.flexGrow = 1;
  return { outer, inner };
}

// A Pressable that gently shrinks while held and gives a light haptic tick on press —
// the small physical response that makes taps feel real. `feedback="none"` skips the
// haptic (e.g. rapid steppers), `scale` tunes how far it shrinks.
export default function PressableScale({
  children,
  style,
  onPress,
  disabled,
  scale = 0.97,
  feedback = 'tap',
  hitSlop,
  onLongPress,
  accessibilityLabel,
  accessibilityRole = 'button',
  testID,
}) {
  const v = useRef(new Animated.Value(1)).current;
  const to = (value) =>
    Animated.spring(v, { toValue: value, useNativeDriver: true, speed: 40, bounciness: value === 1 ? 6 : 0 }).start();

  const resolve = (pressed) => splitStyle(typeof style === 'function' ? style({ pressed }) : style);
  const { outer } = resolve(false);

  return (
    <Pressable
      style={outer}
      onPress={(e) => {
        if (feedback !== 'none') haptic[feedback]?.();
        onPress?.(e);
      }}
      onLongPress={onLongPress}
      onPressIn={() => to(scale)}
      onPressOut={() => to(1)}
      disabled={disabled}
      hitSlop={hitSlop}
      accessibilityLabel={accessibilityLabel}
      accessibilityRole={accessibilityRole}
      accessibilityState={{ disabled: !!disabled }}
      testID={testID}
    >
      {({ pressed }) => (
        <Animated.View
          style={[
            resolve(pressed).inner,
            { transform: [{ scale: v }] },
            disabled && { opacity: 0.5 },
          ]}
        >
          {typeof children === 'function' ? children({ pressed }) : children}
        </Animated.View>
      )}
    </Pressable>
  );
}
