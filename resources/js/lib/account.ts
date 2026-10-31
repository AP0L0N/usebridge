// Multi-user screens (docs/MULTI-USER-PLAN.md §8): shared prop types and small helpers.

export type AuthUser = { id: number; name: string; email: string; initials: string; is_admin: boolean; email_verified: boolean };
export type Shared = {
  auth?: { user: AuthUser | null };
  features?: { polymarket?: boolean; admin?: boolean };
  flash?: { success?: string | null; error?: string | null };
  footer?: Footer;
};

/** LegalPages::footer: the site footer (copy from lang/{en,sl}/legal.php); switch only on the legal pages. */
export type Footer = {
  locale: 'en' | 'sl';
  navLabel: string;
  links: { label: string; href: string }[];
  source: { label: string; href: string };
  copyright: string;
  getSource: string;
  switch: { label: string; en: string; sl: string } | null;
};

/** The account as LoginController::user returns it (own account only). */
export type Account = {
  id: number; name: string; email: string; email_verified: boolean; pending_email: string | null;
  timezone: string | null; is_admin: boolean; delete_requested_at: string | null;
};

export type DeviceSession = { id: string; ip: string | null; user_agent: string | null; last_active: string; current: boolean };

export type TokenScope = 'read' | 'write' | 'full' | 'custom';
/** ApiTokens::present: never the plaintext. */
export type ApiToken = {
  id: number; name: string; suffix: string | null; masked: string | null; legacy: boolean; scope: TokenScope;
  abilities: string[]; created_at: string | null; last_used_at: string | null; expires_at: string | null;
};

export type AdminUser = { id: number; name: string; email: string; created_at: string | null; last_login_at: string | null; status: 'active' | 'disabled' | 'deletion_pending' };

export const initials = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');

/** "Firefox on macOS" from a user agent (best effort; unknown parts are left out). */
export function deviceLabel(ua: string | null): string {
  if (!ua) return 'Unknown device';
  const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\/|Opera/.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox'
    : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : /curl|python|Go-http|okhttp/i.test(ua) ? 'Script' : 'Browser';
  const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac OS X|Macintosh/.test(ua) ? 'macOS'
    : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : '';
  return os ? `${browser} on ${os}` : browser;
}

export type Strength = { score: 0 | 1 | 2 | 3 | 4; word: string; tip: string };

/**
 * Strength meter (DESIGN.md: segment count + word + tip, never colour alone). Length first (the server's only rule is
 * the minimum), a bonus for mixing character kinds, a penalty for one repeated character or a common word.
 */
export function strength(pw: string, min: number): Strength {
  if (pw.length < min) return { score: 0, word: 'Too short', tip: `use at least ${min} characters` };
  let score = pw.length >= 20 ? 4 : pw.length >= 16 ? 3 : pw.length >= 12 ? 2 : 1;
  const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(pw)).length;
  if (kinds >= 3) score += 1;
  if (/^(.)\1+$/.test(pw) || /password|qwerty|123456|letmein|bridge/i.test(pw)) score = 1;
  const s = Math.min(4, score) as Strength['score'];
  return [
    { score: 0, word: 'Too short', tip: '' },
    { score: 1, word: 'Weak', tip: 'add a few more words' },
    { score: 2, word: 'Fair', tip: 'longer is better' },
    { score: 3, word: 'Good', tip: 'longer is even better' },
    { score: 4, word: 'Strong', tip: 'nice' },
  ][s] as Strength;
}

export const SCOPE_LABEL: Record<TokenScope, string> = { read: 'read only', write: 'read & write', full: 'full (legacy)', custom: 'custom' };
