import { Platform } from 'react-native';
import { BRAND } from '../config/brand';

export const colors = {
  primary: BRAND.primary,
  primaryDark: BRAND.primaryDark,
  primaryLight: BRAND.primaryLight,
  primarySoft: BRAND.primarySoft,
  onPrimary: BRAND.onPrimary,

  ink: '#1D1B26',
  inkSoft: '#585667',
  muted: '#9997A6',

  bg: '#FAF8F6',
  surface: '#FFFFFF',
  border: '#EFEBE8',
  borderStrong: '#E1DBD6',

  veg: '#1E9E4A',
  nonveg: '#C62828',
  success: '#22A06B',
  successBg: '#E4F6EE',
  warning: '#F5A623',
  warningBg: '#FEF1DC',
  danger: '#E5484D',
  dangerBg: '#FCE6E6',
  info: '#3D7BF0',
  infoBg: '#E7EFFE',
  star: '#F5A623',

  white: '#FFFFFF',
  overlay: 'rgba(20,18,28,0.55)',
  skeleton: '#EFEAE6',
};

export const radius = { sm: 10, md: 14, lg: 20, xl: 28, pill: 999 };
export const spacing = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 };

export const font = {
  regular: 'Poppins_400Regular',
  medium: 'Poppins_500Medium',
  semiBold: 'Poppins_600SemiBold',
  bold: 'Poppins_700Bold',
};

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
  md: makeShadow(8, 0.09, 16, 6),
  lg: makeShadow(14, 0.14, 28, 10),
};

// the app is a centred column on tablets and in the browser
export const CONTENT_MAX = 600;
