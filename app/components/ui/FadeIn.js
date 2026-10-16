import { useEffect, useRef } from 'react';
import { Animated } from 'react-native';

// Fades and lifts its content into place on first render. `index` staggers items in a list
// (capped, so a long list doesn't make the last rows wait).
export default function FadeIn({ children, index = 0, style, distance = 10 }) {
  const v = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.timing(v, {
      toValue: 1,
      duration: 260,
      delay: Math.min(index, 8) * 35,
      useNativeDriver: true,
    }).start();
  }, [v, index]);
  return (
    <Animated.View
      style={[
        style,
        { opacity: v, transform: [{ translateY: v.interpolate({ inputRange: [0, 1], outputRange: [distance, 0] }) }] },
      ]}
    >
      {children}
    </Animated.View>
  );
}
