/**
 * Cloudflare Turnstile, loaded only when the sign-up form is submitted (never on page render): no Cloudflare request,
 * cookie or storage before the user acts. The widget runs with execution 'execute' + appearance 'interaction-only',
 * so it stays invisible unless Cloudflare needs an interaction. No pre-clearance: the widget's "Pre-clearance" setting
 * must stay OFF in the Cloudflare dashboard (it would set a cf_clearance cookie). This module is the only place that
 * references the script URL; tests/Feature/MultiUser/TurnstileLazyTest.php checks that.
 */

type TurnstileApi = {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string;
  execute: (widget: string | HTMLElement) => void;
  reset: (widget?: string | HTMLElement) => void;
  remove: (widget: string | HTMLElement) => void;
};

declare global {
  interface Window { turnstile?: TurnstileApi }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let loading: Promise<TurnstileApi> | null = null;
let current: string | null = null;

function loadScript(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  if (loading) return loading;
  loading = new Promise<TurnstileApi>((resolve, reject) => {
    const s = document.createElement('script');
    s.src = SCRIPT_SRC;
    s.async = true;
    s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('turnstile missing')));
    s.onerror = () => {
      loading = null;
      s.remove();
      reject(new Error('turnstile failed to load'));
    };
    document.head.appendChild(s);
  });
  return loading;
}

/**
 * Called from the submit handler only: loads the script (first time), renders an execute-mode widget into `container`
 * and resolves with its token. Rejects when the script cannot load or the challenge fails / expires.
 */
export async function turnstileToken(siteKey: string, container: HTMLElement): Promise<string> {
  const ts = await loadScript();
  // A token is single-use: every submit gets a fresh widget.
  if (current !== null) ts.remove(current);
  current = null;
  container.replaceChildren();
  return new Promise<string>((resolve, reject) => {
    const widget = current = ts.render(container, {
      sitekey: siteKey,
      execution: 'execute',
      appearance: 'interaction-only',
      'refresh-expired': 'manual',
      callback: (token: string) => resolve(token),
      'error-callback': () => reject(new Error('turnstile error')),
      'expired-callback': () => reject(new Error('turnstile expired')),
      'timeout-callback': () => reject(new Error('turnstile timeout')),
    });
    ts.execute(widget);
  });
}
