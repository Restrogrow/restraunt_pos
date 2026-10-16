import { Platform } from 'react-native';

export { colors, statusStyles } from './colors';

export const radius = {
  sm: 10,
  md: 16,
  lg: 22,
  xl: 28,
  pill: 999,
};

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
};

export const font = {
  regular: 'Poppins_400Regular',
  medium: 'Poppins_500Medium',
  semiBold: 'Poppins_600SemiBold',
  bold: 'Poppins_700Bold',
};

// react-native-web deprecated the shadow* props in favor of the CSS
// boxShadow shorthand; native platforms still need shadow*/elevation.
function makeShadow(offsetY, opacity, blur, elevation) {
  return Platform.select({
    web: { boxShadow: `0px ${offsetY}px ${blur}px rgba(29, 27, 38, ${opacity})` },
    default: {
      shadowColor: '#1D1B26',
      shadowOffset: { width: 0, height: offsetY },
      shadowOpacity: opacity,
      shadowRadius: blur,
      elevation,
    },
  });
}

export const shadow = {
  sm: makeShadow(2, 0.06, 6, 2),
  md: makeShadow(8, 0.08, 16, 6),
  lg: makeShadow(14, 0.12, 28, 10),
};
