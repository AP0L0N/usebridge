import assert from 'node:assert/strict';
import { test } from 'node:test';
import { followSystemTheme, nextThemeChoice, setThemeChoice, THEME_KEY, themeChoice } from '../../resources/js/lib/theme.ts';

// Minimal browser stand-ins: localStorage, a controllable prefers-color-scheme query and <html>.
const store = new Map<string, string>();
let systemDark = false;
const listeners = new Set<() => void>();
const attrs = new Map<string, string>();
const root = { style: { colorScheme: '' }, getAttribute: (k: string) => attrs.get(k) ?? null, setAttribute: (k: string, v: string) => attrs.set(k, v) };
Object.assign(globalThis, {
  window: Object.assign(new EventTarget(), {
    localStorage: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => store.set(k, v), removeItem: (k: string) => store.delete(k) },
    matchMedia: () => ({ get matches() { return systemDark; }, addEventListener: (_: string, fn: () => void) => listeners.add(fn), removeEventListener: (_: string, fn: () => void) => listeners.delete(fn) }),
  }),
  document: { documentElement: root },
});
const flipSystem = (dark: boolean) => { systemDark = dark; listeners.forEach((fn) => fn()); };

test('the toggle cycles system → light → dark → system', () => {
  assert.equal(nextThemeChoice('system'), 'light');
  assert.equal(nextThemeChoice('light'), 'dark');
  assert.equal(nextThemeChoice('dark'), 'system');
});

test('a choice is stored and applied; system clears it and follows the preference', () => {
  systemDark = true;
  setThemeChoice('light');
  assert.equal(store.get(THEME_KEY), 'light');
  assert.equal(themeChoice(), 'light');
  assert.deepEqual([attrs.get('data-theme'), root.style.colorScheme], ['light', 'light']);

  setThemeChoice('system');
  assert.equal(store.has(THEME_KEY), false);
  assert.equal(themeChoice(), 'system');
  assert.deepEqual([attrs.get('data-theme'), root.style.colorScheme], ['dark', 'dark']);
});

test('system changes apply only while nothing is stored', () => {
  const stop = followSystemTheme();
  setThemeChoice('system');
  flipSystem(false);
  assert.equal(attrs.get('data-theme'), 'light');

  setThemeChoice('dark');
  flipSystem(false);
  flipSystem(true);
  flipSystem(false);
  assert.equal(attrs.get('data-theme'), 'dark', 'a stored choice wins over the system');
  stop();
});
