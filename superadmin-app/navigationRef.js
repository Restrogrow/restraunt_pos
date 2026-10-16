import { createNavigationContainerRef } from '@react-navigation/native';

export const navigationRef = createNavigationContainerRef();

// Opens the screen a notification points at.
export function openFromNotification(screen) {
  if (!navigationRef.isReady()) return false;
  if (screen === 'Leads') navigationRef.navigate('Tabs', { screen: 'More', params: { screen: 'Leads', initial: false } });
  else if (screen === 'Support' || screen === 'Renewals') navigationRef.navigate('Tabs', { screen });
  return true;
}
