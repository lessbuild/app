const focusableSelector = 'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';

const isVisible = element => element.getClientRects().length > 0;
const focusableIn = element => [...element.querySelectorAll(focusableSelector)].filter(isVisible);
const sheetStates = new Map();

function stateFor(sheet) {
  let state = sheetStates.get(sheet);

  if (!state) {
    state = { sheet, openedBy: null, backdrop: null, bodyWasLocked: false };
    sheetStates.set(sheet, state);
  }

  return state;
}

function openStates() {
  return [...sheetStates.values()].filter(({ sheet }) => !sheet.classList.contains('hidden'));
}

function updateTriggers(sheet, openTrigger = null) {
  document.querySelectorAll('[data-sheet-open]').forEach((trigger) => {
    if (trigger.dataset.sheetOpen === sheet.id) {
      trigger.setAttribute('aria-expanded', String(trigger === openTrigger));
    }
  });
}

function closeSheet(state, restoreFocus = true) {
  const { sheet } = state;

  if (sheet.classList.contains('hidden')) return;

  sheet.classList.add('hidden');
  sheet.setAttribute('aria-hidden', 'true');
  updateTriggers(sheet);
  state.backdrop?.remove();
  state.backdrop = null;

  const anotherOverlayOpen = openStates().length > 0
    || Boolean(document.querySelector('[data-mobile-drawer][aria-hidden="false"], [data-app-mobile-drawer][aria-hidden="false"], dialog[open]'));

  if (!state.bodyWasLocked && !anotherOverlayOpen) document.body.classList.remove('overflow-hidden');
  if (restoreFocus && state.openedBy?.isConnected) state.openedBy.focus();
  state.openedBy = null;
}

function openSheet(state, trigger) {
  const { sheet } = state;
  const alreadyOpen = openStates();

  alreadyOpen.forEach((other) => {
    if (other !== state) closeSheet(other, false);
  });

  if (!sheet.classList.contains('hidden')) return;

  state.bodyWasLocked = document.body.classList.contains('overflow-hidden');
  state.openedBy = trigger;
  sheet.classList.remove('hidden');
  sheet.setAttribute('aria-hidden', 'false');
  updateTriggers(sheet, trigger);
  document.body.classList.add('overflow-hidden');

  const backdrop = document.createElement('button');
  backdrop.type = 'button';
  backdrop.tabIndex = -1;
  backdrop.className = 'ui-sheet-backdrop';
  backdrop.dataset.sheetBackdrop = sheet.id;
  backdrop.setAttribute('aria-label', 'Close side sheet');
  state.backdrop = backdrop;
  document.body.append(backdrop);

  window.requestAnimationFrame(() => {
    if (sheet.classList.contains('hidden')) return;
    (focusableIn(sheet)[0] || sheet).focus();
  });
}

function sheetForTrigger(trigger) {
  const sheetId = trigger.dataset.sheetOpen;
  const sheet = typeof sheetId === 'string' ? document.getElementById(sheetId) : null;

  return sheet instanceof HTMLElement ? sheet : null;
}

document.querySelectorAll('[data-sheet-open]').forEach((trigger) => {
  trigger.setAttribute('aria-expanded', 'false');
});

document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return;

  const trigger = event.target.closest('[data-sheet-open]');

  if (trigger) {
    const sheet = sheetForTrigger(trigger);

    if (!sheet) return;

    if (trigger instanceof HTMLButtonElement) event.preventDefault();
    openSheet(stateFor(sheet), trigger);

    return;
  }

  const closeTrigger = event.target.closest('[data-sheet-close]');

  if (closeTrigger) {
    const sheet = closeTrigger.closest('[data-signal-side-sheet]');

    if (sheet instanceof HTMLElement) closeSheet(stateFor(sheet));

    return;
  }

  const backdrop = event.target.closest('[data-sheet-backdrop]');

  if (backdrop) {
    const sheet = document.getElementById(backdrop.dataset.sheetBackdrop);

    if (sheet instanceof HTMLElement) closeSheet(stateFor(sheet));
  }
});

document.addEventListener('keydown', (event) => {
  const state = openStates().at(-1);

  if (!state) return;

  if (event.key === 'Escape') {
    event.preventDefault();
    closeSheet(state);

    return;
  }

  if (event.key !== 'Tab') return;

  const focusable = focusableIn(state.sheet);

  if (!focusable.length) {
    event.preventDefault();
    state.sheet.focus();

    return;
  }

  const first = focusable[0];
  const last = focusable.at(-1);

  if (event.shiftKey && (document.activeElement === first || !state.sheet.contains(document.activeElement))) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && (document.activeElement === last || !state.sheet.contains(document.activeElement))) {
    event.preventDefault();
    first.focus();
  }
});
