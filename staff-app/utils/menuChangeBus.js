// Lets MenuItemScreen report an optimistic create/update/delete back to
// MenuScreen without passing a function through navigation params (React
// Navigation warns on non-serializable param values, since they break state
// persistence/restoration).
let listener = null;

export function setMenuChangeListener(fn) {
  listener = fn;
}

export function emitMenuChange(type, payload) {
  if (listener) listener(type, payload);
}
