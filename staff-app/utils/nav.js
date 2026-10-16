// The same screen can be a tab's first page (Kitchen for a chef, Tables for a waiter) or a page
// pushed from More (a manager). Only show the header's back arrow when there is a page to go back to.
export function backIfStacked(navigation) {
  const state = navigation.getState?.();
  return state && state.type === 'stack' && state.index > 0 ? () => navigation.goBack() : undefined;
}

// The bottom-tab navigator, from any screen (a tab's first page or a page inside a tab's stack).
function tabNavigator(navigation) {
  let n = navigation;
  while (n) {
    if (n.getState?.()?.type === 'tab') return n;
    n = n.getParent?.();
  }
  return navigation;
}

// Opens a page by name whatever the role's layout: its own tab when it has one (Kitchen for a
// chef, Tables for a waiter), otherwise the same page inside More (Kitchen for a manager).
export function hasScreen(navigation, screen) {
  return (tabNavigator(navigation).getState()?.routeNames || []).includes(screen);
}

export function goTo(navigation, screen, params) {
  const tabs = tabNavigator(navigation);
  // `at` makes the same shortcut work twice in a row (Kitchen › Ready, then Ready again)
  if (params) params = { ...params, at: Date.now() };
  if (hasScreen(navigation, screen)) tabs.navigate(screen, params);
  else tabs.navigate('More', { screen, params, initial: false });
}

export function goOrders(navigation, tab) {
  tabNavigator(navigation).navigate('Orders', { screen: 'OrdersList', params: { tab } });
}
