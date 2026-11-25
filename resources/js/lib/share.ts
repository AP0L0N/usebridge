// Public ticket share links: pure helpers for the drawer's "Public links" list and the public page (tested in
// tests/js/share.test.ts).
import { t } from './strings-share.ts';

export const DEFAULT_DAYS = 7;
export const MAX_DAYS = 365;
/** Expiry picker choices: a number of days, "custom" (type the days) or "never". */
export const EXPIRY_CHOICES = ['1', '7', '30', '90', 'custom', 'never'] as const;
export type ExpiryChoice = (typeof EXPIRY_CHOICES)[number];

export interface ShareLink {
  id: number;
  url: string;
  /** null = never expires. */
  expires_at: string | null;
  /** Not expired (revoked links are not listed). */
  active: boolean;
  allow_comments: boolean;
  created_at: string | null;
}

/** "Never expires" / "Expired" / "Expires within a day" / "Expires in 5 days" (whole days, rounded up). */
export function expiryLabel(expiresAt: string | null, nowIso: string): string {
  if (expiresAt === null) return t('exp.never');
  const ms = Date.parse(expiresAt) - Date.parse(nowIso);
  if (!(ms > 0)) return t('exp.expired');
  const days = Math.ceil(ms / 86_400_000);
  if (days <= 1) return t('exp.day');
  return t('exp.days', { n: days });
}

/**
 * The request body for an expiry choice: {never_expires: true} or {expires_in_days: n}; for "custom" the typed days
 * (whole number 1–365). Returns an error message instead when the custom days are not valid.
 */
export function expiryBody(choice: ExpiryChoice, customDays: string): { never_expires: true } | { expires_in_days: number } | { error: string } {
  if (choice === 'never') return { never_expires: true };
  const raw = choice === 'custom' ? customDays.trim() : choice;
  const n = /^\d{1,3}$/.test(raw) ? Number(raw) : NaN;
  if (!Number.isInteger(n) || n < 1 || n > MAX_DAYS) return { error: t('side.daysErr', { max: MAX_DAYS }) };
  return { expires_in_days: n };
}

/** Label of an expiry choice in the picker. */
export function expiryChoiceLabel(choice: ExpiryChoice): string {
  if (choice === 'never') return t('side.expiry.never');
  if (choice === 'custom') return t('side.expiry.custom');
  return choice === '1' ? t('side.expiry.option1') : t('side.expiry.option', { n: choice });
}

/**
 * On the public page a link may only lead away from Bridge: an absolute http(s) or mailto URL on another origin. Relative
 * URLs, `#…` and links to the app's own origin render as plain text, so the page never links into the app.
 */
export function isExternalHref(href: string | null | undefined, origin: string): href is string {
  if (!href) return false;
  if (/^mailto:/i.test(href)) return true;
  if (!/^https?:\/\//i.test(href)) return false;
  try {
    return new URL(href).origin !== new URL(origin).origin;
  } catch {
    return false;
  }
}
