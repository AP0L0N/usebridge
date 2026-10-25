import '../css/bridge.css';
import '../css/usage.css';
import '../css/polymarket.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Bridge';

createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx');
    const importPage = pages[`./Pages/${name}.tsx`];
    if (!importPage) {
      throw new Error(`Page not found: ${name}`);
    }
    const module = (await importPage()) as { default: React.ComponentType };
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
