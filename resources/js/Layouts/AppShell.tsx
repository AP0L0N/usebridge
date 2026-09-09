import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

type NavProject = { id: string; name: string; slug: string };

export function AppShell({ children }: PropsWithChildren) {
  const page = usePage<{ projectsNav?: NavProject[] }>();
  const url = page.url;

  function navClass(active: boolean) {
    return active ? 'active' : '';
  }

  function signOut() {
    router.post('/gate/lock');
  }

  return (
    <div className="shell">
      <div className="chrome">
        <div className="brand-bar">
          <div className="brand">
            <span className="brand-mark" aria-hidden="true" /> BRIDGE
          </div>
          <div className="brand-bar-right">
            <span className="env-chip">internal</span>
            <button type="button" className="sign-out sign-out-mobile" onClick={signOut}>
              Sign out
            </button>
          </div>
        </div>
        <nav className="nav-strip" aria-label="Primary">
          <div className="nav-links">
            <Link href="/" className={navClass(url === '/' || url.startsWith('/portfolio'))}>
              Portfolio
            </Link>
            <Link href="/ops" className={navClass(url.startsWith('/ops'))}>
              Ops
            </Link>
            <Link href="/agents" className={navClass(url.startsWith('/agents'))}>
              Agents
            </Link>
            <Link href="/queue" className={navClass(url.startsWith('/queue'))}>
              Queue
            </Link>
          </div>
          <div className="nav-user">
            <span>Captain William Diego</span>
            <span className="role-chip">CEO</span>
            <button type="button" className="sign-out" onClick={signOut}>
              Sign out
            </button>
          </div>
        </nav>
      </div>
      {children}
    </div>
  );
}
