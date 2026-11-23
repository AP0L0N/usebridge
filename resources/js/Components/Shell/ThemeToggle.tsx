/**
 * Light / dark / system toggle (lib/theme.ts): one 44px button that cycles system → light → dark → system. The icon shows
 * the current choice; the accessible name says it and what a press switches to. Colours come from the bar it sits on
 * (currentColor), so it works on the ink top bars and on light pages alike.
 *
 *   <ThemeToggle />                          // AppTopBar (.atb-right), TopBar, AuthLayout
 *   <ThemeToggle className="auth-theme" />   // extra class for placement
 */
import { useEffect, useState } from 'react';
import { applyTheme, followSystemTheme, nextThemeChoice, resolveTheme, setThemeChoice, THEME_EVENT, THEME_KEY, themeChoice, themeToggleLabel, type ThemeChoice } from '../../lib/theme';

const P = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

export const IconSun = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
    <circle cx="10" cy="10" r="3.5" {...P} />
    <path d="M10 2.5V4M10 16V17.5M2.5 10H4M16 10H17.5M4.7 4.7L5.76 5.76M14.24 14.24L15.3 15.3M4.7 15.3L5.76 14.24M14.24 5.76L15.3 4.7" {...P} />
  </svg>
);
export const IconMoon = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
    <path d="M16.5 11.9A6.75 6.75 0 1 1 8.1 3.5a5.25 5.25 0 0 0 8.4 8.4Z" {...P} />
  </svg>
);
export const IconSystem = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">
    <rect x="2.75" y="3.75" width="14.5" height="10" rx="1.75" {...P} />
    <path d="M7 16.75H13M10 13.75V16.75" {...P} />
  </svg>
);

export function ThemeToggle({ className }: { className?: string }) {
  const [choice, setChoice] = useState<ThemeChoice>(themeChoice);
  useEffect(() => {
    const sync = () => setChoice(themeChoice());
    // Another tab changed the stored choice: apply it here too.
    const onStorage = (e: StorageEvent) => { if (e.key === THEME_KEY || e.key === null) { applyTheme(resolveTheme(themeChoice())); sync(); } };
    window.addEventListener(THEME_EVENT, sync);
    window.addEventListener('storage', onStorage);
    const stopFollowing = followSystemTheme(sync);
    return () => { window.removeEventListener(THEME_EVENT, sync); window.removeEventListener('storage', onStorage); stopFollowing(); };
  }, []);
  const label = themeToggleLabel(choice);
  return (
    <button type="button" className={className ? `theme-toggle ${className}` : 'theme-toggle'} aria-label={label} title={label}
      data-theme-choice={choice} onClick={() => setThemeChoice(nextThemeChoice(choice))}>
      {choice === 'light' ? <IconSun /> : choice === 'dark' ? <IconMoon /> : <IconSystem />}
    </button>
  );
}
