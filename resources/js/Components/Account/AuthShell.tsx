import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { TopBar } from '../TopBar';

/** Logged-out screens: brand-only top bar + the centred .gcard. */
export function AuthShell({ title, children }: { title: string; children: ReactNode }) {
  return (
    <>
      <Head title={title} />
      <TopBar nav={false} />
      <main className="authpage">{children}</main>
    </>
  );
}
