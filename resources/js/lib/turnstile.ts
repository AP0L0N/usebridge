// Cloudflare Turnstile for the sign-up form, loaded only when the user submits (never on page render).
// The widget runs with execution: 'execute' and appearance: 'interaction-only': nothing shows unless Cloudflare needs
// the person to act. No pre-clearance: the widget's Pre-clearance setting stays OFF in the Cloudflare dashboard, so
// no cf_clearance cookie is set (docs/MULTI-USER-PLAN.md §0).

type TurnstileApi = {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string;
  execute: (id: string) => void;
  reset: (id: string) => void;
  remove: (id: string) => void;
};

declare global {
  interface Window { turnstile?: TurnstileApi }
}

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let loading: Promise<TurnstileApi> | null = null;

/** Adds the Turnstile script once (first submit); later calls reuse it. */
function loadTurnstile(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  loading ??= new Promise<TurnstileApi>((resolve, reject) => {
    const s = document.createElement('script');
    s.src = SCRIPT_URL;
    s.async = true;
    s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('turnstile')));
    s.onerror = () => {
      loading = null;
      s.remove();
      reject(new Error('turnstile'));
    };
    document.head.appendChild(s);
  });
  return loading;
}

/**
 * A fresh Turnstile token for one submit: loads the script, renders the widget into `el` (interaction-only) and runs
 * the challenge. Rejects when the script cannot load or the challenge fails.
 */
export async function turnstileToken(siteKey: string, el: HTMLElement): Promise<string> {
  const api = await loadTurnstile();
  return new Promise<string>((resolve, reject) => {
    const prev = el.dataset.widgetId;
    if (prev) api.remove(prev);
    const id = api.render(el, {
      sitekey: siteKey,
      execution: 'execute',
      appearance: 'interaction-only',
      'response-field': false,
      callback: (token: string) => resolve(token),
      'error-callback': () => reject(new Error('turnstile')),
      'expired-callback': () => reject(new Error('turnstile')),
    });
    el.dataset.widgetId = id;
    api.execute(id);
  });
}
