import { createNavigationContainerRef } from '@react-navigation/native';

// Lets code outside the navigator tree (OrderAlertWatcher, mounted at the
// app root) imperatively jump to a screen — used to pop the incoming-order
// Accept/Reject screen open the moment a new Pending order is detected,
// like an incoming-call interrupt.
export const navigationRef = createNavigationContainerRef();
