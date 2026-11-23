/**
 * Light / dark theme. The inline head script (resources/views/partials/theme-script.blade.php) sets data-theme and
 * color-scheme on <html> before the first paint; this module only changes them afterwards, so it never fights it.
 *
 *   themeChoice()            // 'light' | 'dark' (stored in this browser) or 'system' (nothing stored)
 *   setThemeChoice('dark')   // store + apply; 'system' clears the stored choice and follows prefers-color-scheme again
 *   nextThemeChoice(choice)  // the toggle's cycle: system → light → dark → system
 *   followSystemTheme(fn?)   // re-apply (then call fn) on prefers-color-scheme changes while the choice is 'system'; returns the cleanup
 */
export type Theme = 'light' | 'dark';
export type ThemeChoice = Theme | 'system';

export const THEME_KEY = 'bridge-theme';
/** Fired on window after every setThemeChoice(), so several toggles on one page stay in step. */
export const THEME_EVENT = 'bridge:theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

export function storedTheme(): Theme | null {
  try {
    const value = window.localStorage.getItem(THEME_KEY);
    return value === 'light' || value === 'dark' ? value : null;
  } catch {
    return null;
  }
}

export function systemTheme(): Theme {
  return typeof window.matchMedia === 'function' && window.matchMedia(DARK_QUERY).matches ? 'dark' : 'light';
}

export function themeChoice(): ThemeChoice {
  return storedTheme() ?? 'system';
}

/** The theme a choice resolves to right now. */
export function resolveTheme(choice: ThemeChoice): Theme {
  return choice === 'system' ? systemTheme() : choice;
}

export function applyTheme(theme: Theme): void {
  const root = document.documentElement;
  if (root.getAttribute('data-theme') !== theme) root.setAttribute('data-theme', theme);
  root.style.colorScheme = theme;
}

export function setThemeChoice(choice: ThemeChoice): void {
  try {
    if (choice === 'system') window.localStorage.removeItem(THEME_KEY);
    else window.localStorage.setItem(THEME_KEY, choice);
  } catch {
    // Storage blocked (private mode): the choice still applies until the next page load.
  }
  applyTheme(resolveTheme(choice));
  window.dispatchEvent(new CustomEvent<ThemeChoice>(THEME_EVENT, { detail: choice }));
}

export function nextThemeChoice(choice: ThemeChoice): ThemeChoice {
  if (choice === 'system') return 'light';
  return choice === 'light' ? 'dark' : 'system';
}

export function followSystemTheme(onApplied?: () => void): () => void {
  if (typeof window.matchMedia !== 'function') return () => {};
  const query = window.matchMedia(DARK_QUERY);
  const onChange = () => {
    if (storedTheme() !== null) return;
    applyTheme(systemTheme());
    onApplied?.();
  };
  query.addEventListener('change', onChange);
  return () => query.removeEventListener('change', onChange);
}

/** Accessible name for a toggle showing `choice`: the current state and what a press switches to. */
export function themeToggleLabel(choice: ThemeChoice): string {
  const name = { light: 'Light', dark: 'Dark', system: 'System' } as const;
  const current = choice === 'system' ? `System (${resolveTheme('system') === 'dark' ? 'dark' : 'light'})` : name[choice];
  return `Theme: ${current}. Switch to ${name[nextThemeChoice(choice)].toLowerCase()}`;
}
