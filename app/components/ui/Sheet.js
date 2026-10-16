import { useEffect, useRef, useState } from 'react';
import {
  Animated,
  BackHandler,
  Dimensions,
  KeyboardAvoidingView,
  Modal,
  PanResponder,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, font, radius, spacing } from '../../theme';

// One bottom sheet for every popup in the app: slides up over a dimmed backdrop, can be
// dragged down (or the backdrop tapped / Back pressed) to close, and always keeps its
// content above the phone's navigation bar / gesture area and the keyboard.
//
// `inline` renders it as an overlay View inside the current window instead of a native
// Modal — used by the app-wide dialog host so a confirm can appear on top of a screen that
// is itself a full-screen Modal (the POS cart), which a second native Modal can't do on iOS.
export default function Sheet({ visible, onClose, title, subtitle, children, footer, inline = false, dismissable = true, maxHeight = 0.88 }) {
  const insets = useSafeAreaInsets();
  const [mounted, setMounted] = useState(visible);
  const fade = useRef(new Animated.Value(0)).current;
  const slide = useRef(new Animated.Value(1)).current; // 0 = open, 1 = off-screen
  const drag = useRef(new Animated.Value(0)).current;
  const screenH = Dimensions.get('window').height;

  useEffect(() => {
    if (visible) {
      setMounted(true);
      drag.setValue(0);
      Animated.parallel([
        Animated.timing(fade, { toValue: 1, duration: 180, useNativeDriver: true }),
        Animated.spring(slide, { toValue: 0, useNativeDriver: true, damping: 22, stiffness: 220, mass: 0.9 }),
      ]).start();
    } else if (mounted) {
      Animated.parallel([
        Animated.timing(fade, { toValue: 0, duration: 160, useNativeDriver: true }),
        Animated.timing(slide, { toValue: 1, duration: 200, useNativeDriver: true }),
      ]).start(() => setMounted(false));
    }
  }, [visible]); // eslint-disable-line react-hooks/exhaustive-deps

  // Android Back closes an inline sheet (a Modal handles it via onRequestClose).
  useEffect(() => {
    if (!inline || !visible) return undefined;
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (dismissable) onClose?.();
      return true;
    });
    return () => sub.remove();
  }, [inline, visible, dismissable, onClose]);

  const pan = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, g) => dismissable && g.dy > 6 && Math.abs(g.dy) > Math.abs(g.dx),
      onPanResponderMove: (_, g) => drag.setValue(Math.max(0, g.dy)),
      onPanResponderRelease: (_, g) => {
        if (g.dy > 90 || g.vy > 1.2) onClose?.();
        else Animated.spring(drag, { toValue: 0, useNativeDriver: true, bounciness: 4 }).start();
      },
    })
  ).current;

  if (!mounted) return null;

  const translateY = Animated.add(slide.interpolate({ inputRange: [0, 1], outputRange: [0, screenH] }), drag);
  const body = (
    <View style={StyleSheet.absoluteFill} pointerEvents="box-none">
      <Animated.View style={[styles.backdrop, { opacity: fade }]}>
        <Pressable style={StyleSheet.absoluteFill} onPress={() => dismissable && onClose?.()} accessibilityLabel="Close" />
      </Animated.View>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.anchor}
        pointerEvents="box-none"
      >
        <Animated.View
          style={[
            styles.panel,
            { maxHeight: screenH * maxHeight, paddingBottom: Math.max(insets.bottom, spacing.md) + spacing.sm, transform: [{ translateY }] },
          ]}
        >
          <View {...pan.panHandlers} style={styles.grabArea}>
            <View style={styles.handle} />
            {title ? (
              <View style={styles.head}>
                <Text style={styles.title}>{title}</Text>
                {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
              </View>
            ) : null}
          </View>
          <View style={styles.content}>{children}</View>
          {footer ? <View style={styles.footer}>{footer}</View> : null}
        </Animated.View>
      </KeyboardAvoidingView>
    </View>
  );

  if (inline) return <View style={[StyleSheet.absoluteFill, { zIndex: 1000, elevation: 1000 }]} pointerEvents="box-none">{body}</View>;

  return (
    <Modal
      visible
      transparent
      animationType="none"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={() => dismissable && onClose?.()}
    >
      {body}
      {/* toasts and confirms raised while this sheet is open appear on top of it */}
      <SheetFeedbackHost />
    </Modal>
  );
}

// Feedback.js imports this file, so its host is loaded lazily here to keep the import one-way.
function SheetFeedbackHost() {
  const { FeedbackHost } = require('./Feedback');
  return <FeedbackHost />;
}

const styles = StyleSheet.create({
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.overlay,
  },
  anchor: {
    flex: 1,
    justifyContent: 'flex-end',
    alignItems: 'center',
  },
  panel: {
    width: '100%',
    maxWidth: 560,
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.xl,
    borderTopRightRadius: radius.xl,
    overflow: 'hidden',
  },
  grabArea: {
    paddingTop: 10,
    paddingHorizontal: spacing.xl,
  },
  handle: {
    alignSelf: 'center',
    width: 40,
    height: 5,
    borderRadius: 3,
    backgroundColor: colors.border,
    marginBottom: spacing.md,
  },
  head: {
    marginBottom: spacing.sm,
  },
  title: {
    fontFamily: font.bold,
    fontSize: 18,
    color: colors.ink,
  },
  subtitle: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.inkSoft,
    marginTop: 4,
    lineHeight: 19,
  },
  content: {
    flexShrink: 1,
    paddingHorizontal: spacing.xl,
  },
  footer: {
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
  },
});
