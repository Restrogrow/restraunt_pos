import { Platform, useWindowDimensions } from 'react-native';

// The browser demo runs inside a phone or tablet frame of that device's real size, so it
// lays out exactly like the installed app does on that device.
const NATIVE_LIKE = Platform.OS !== 'web';

// Content is capped and centred on wide screens (tablets, and the phone-shaped web preview),
// so cards and lists never stretch edge to edge. Grids pick their column count from the
// width that is actually available instead of assuming a phone.
export const CONTENT_MAX = NATIVE_LIKE ? 900 : 480;

export function useLayout() {
  const { width, height } = useWindowDimensions();
  const contentWidth = Math.min(width, CONTENT_MAX);
  const isTablet = Math.min(width, height) >= 600 && NATIVE_LIKE;
  const isSmall = contentWidth < 360;
  // padding on each side of screen content
  const gutter = isSmall ? 14 : isTablet ? 28 : 20;
  // how many columns a grid of tiles at least `min` wide fits
  const columns = (min, max = 6) => Math.max(1, Math.min(max, Math.floor((contentWidth - gutter * 2 + 12) / (min + 12))));
  return { width, height, contentWidth, isTablet, isSmall, gutter, columns };
}
