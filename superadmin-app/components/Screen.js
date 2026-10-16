import { useNavigation } from '@react-navigation/native';
import { RefreshControl, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useLayout } from '../hooks/useLayout';
import { colors } from '../theme';
import ScreenHeader from './ScreenHeader';
import { ErrorState, LoadingState } from './ScreenState';

// Header + scrolling body used by every screen. `tabs` leaves room for the floating tab bar,
// `back` adds the back arrow. Pass `loading` / `error` to get the standard states.
export default function Screen({
  title,
  eyebrow,
  right,
  headerChildren,
  back,
  tabs,
  refreshing,
  onRefresh,
  loading,
  error,
  onRetry,
  children,
  scroll = true,
  footer,
  loadingVariant,
}) {
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const { gutter, contentWidth } = useLayout();
  const bottom = tabs ? 110 : insets.bottom + 24;
  const inner = { width: '100%', maxWidth: contentWidth, alignSelf: 'center', paddingHorizontal: gutter };

  let body;
  if (loading) body = <LoadingState variant={loadingVariant} gutter={gutter} />;
  else if (error) body = <ErrorState message={error} onRetry={onRetry} />;
  else if (!scroll) body = <View style={{ flex: 1 }}>{children}</View>;
  else {
    body = (
      <ScrollView
        contentContainerStyle={[inner, { paddingTop: 18, paddingBottom: bottom }]}
        keyboardShouldPersistTaps="handled"
        refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={colors.primary} colors={[colors.primary]} /> : undefined}
      >
        {children}
      </ScrollView>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScreenHeader title={title} eyebrow={eyebrow} right={right} onBack={back ? () => navigation.goBack() : undefined}>
        {headerChildren}
      </ScreenHeader>
      {body}
      {footer}
    </View>
  );
}

// For FlatList screens: the same width cap / padding as Screen's ScrollView.
export function useListPadding(tabs) {
  const insets = useSafeAreaInsets();
  const { gutter, contentWidth } = useLayout();
  return {
    contentContainerStyle: { width: '100%', maxWidth: contentWidth, alignSelf: 'center', paddingHorizontal: gutter, paddingTop: 16, paddingBottom: tabs ? 110 : insets.bottom + 24 },
    gutter,
  };
}
