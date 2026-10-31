import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { AuthCopy } from '../../lib/authCopy';
import { SiteFooter } from '../SiteFooter';
import { GuestTopBar } from './GuestTopBar';
import { Stage } from './Stage';

export type AuthPageProps = { copy: AuthCopy };

/**
 * Logged-out screens (Figma "Auth"): the Stage on the left and the 400px form column on the right on wide screens;
 * on narrow ones the GuestTopBar on top and the form full width. The site footer closes the form column.
 */
export function AuthLayout({ title, children }: { title: string; children: ReactNode }) {
  const { copy } = usePage<AuthPageProps>().props;
  return (
    <>
      <Head title={title} />
      <div className="auth">
        <Stage copy={copy} />
        <div className="auth-side">
          <GuestTopBar copy={copy} />
          <main className="auth-main">
            <div className="auth-col">{children}</div>
          </main>
          <SiteFooter />
        </div>
      </div>
    </>
  );
}

/** Page.layout for the auth pages: AuthLayout already brings the footer (app.tsx would add a second one). */
export const ownLayout = (page: ReactNode) => page;
