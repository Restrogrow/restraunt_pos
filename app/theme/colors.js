export const colors = {
  primary: '#FF5A3C',
  primaryDark: '#E14526',
  primaryLight: '#FFE8E1',

  ink: '#1D1B26',
  inkSoft: '#585667',
  muted: '#9997A6',

  bg: '#FAF7F5',
  surface: '#FFFFFF',
  border: '#EFEBE8',

  success: '#22A06B',
  successBg: '#E4F6EE',
  warning: '#F5A623',
  warningBg: '#FEF1DC',
  info: '#3D7BF0',
  infoBg: '#E7EFFE',
  purple: '#8B5CF6',
  purpleBg: '#F1EBFE',
  teal: '#0EA5A5',
  tealBg: '#E1F5F5',
  danger: '#E5484D',
  dangerBg: '#FCE6E6',

  white: '#FFFFFF',
};

export const statusStyles = {
  Pending: { fg: colors.warning, bg: colors.warningBg },
  Accepted: { fg: colors.info, bg: colors.infoBg },
  Preparing: { fg: colors.purple, bg: colors.purpleBg },
  Ready: { fg: colors.success, bg: colors.successBg },
  Served: { fg: colors.teal, bg: colors.tealBg },
  Completed: { fg: colors.inkSoft, bg: colors.border },
  Cancelled: { fg: colors.danger, bg: colors.dangerBg },
  Rejected: { fg: colors.danger, bg: colors.dangerBg },
};
