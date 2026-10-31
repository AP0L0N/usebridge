// Copy of the logged-out screens: lang/en/auth_ui.php, sent by AuthPageController as the `copy` prop.

type Row = { kind: 'agent' | 'you'; who: string; what: string };

export type AuthCopy = {
  brand: string;
  home: string;
  stage: { label: string; hero: string; subline: string; activity_label: string; activity: Row[]; sample: string };
  topbar: { sample: string };
  common: Record<
    'email' | 'password' | 'show' | 'hide' | 'show_label' | 'hide_label' | 'please_wait' | 'back_to_login' | 'summary_one' | 'summary_many'
    | 'network' | 'generic' | 'session_expired', string>;
  errors: Record<'name_required' | 'email_invalid' | 'email_taken' | 'password_short' | 'password_mismatch' | 'accept_terms', string>;
  strength: { empty: string; line: string; line_no_tip: string; words: string[]; tips: string[]; match: string };
  signup: Record<string, string>;
  login: Record<string, string> & { status: Record<string, string> };
  forgot: Record<string, string>;
  reset: Record<string, string>;
  verify: Record<string, string>;
};

/** ":min characters" + {min: 10} -> "10 characters" (plain text; for links use fillPlaceholders). */
export function t(text: string, vars: Record<string, string | number> = {}): string {
  return text.replace(/:([a-z_]+)/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
