import '../css/bridge.css';
import '../css/usage.css';
import '../css/polymarket.css';
import '../css/kanban.css';
import '../css/account.css';
import '../css/account-redesign.css';
import '../css/legal.css';
import '../css/auth.css';
import { createInertiaApp } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { SiteLayout } from './Components/SiteFooter';

const appName = import.meta.env.VITE_APP_NAME || 'Bridge';

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx');
    const importPage = pages[`./Pages/${name}.tsx`];
    if (!importPage) {
      throw new Error(`Page not found: ${name}`);
    }
    const module = (await importPage()) as { default: React.ComponentType & { layout?: (page: ReactNode) => ReactNode } };
    // Every page gets the site footer (login, sign-up, gate, onboarding, app shell, legal pages).
    module.default.layout ??= (page: ReactNode) => <SiteLayout>{page}</SiteLayout>;
    return module.default;
  },
  setup({ el, App, props }) {
    if (!el) {
      return;
    }
    createRoot(el).render(<App {...props} />);
  },
  progress: {
    color: '#3DE0D0',
  },
});
