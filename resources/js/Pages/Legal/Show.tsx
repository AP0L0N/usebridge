import { Head } from '@inertiajs/react';
import { TopBar } from '../../Components/TopBar';

type Props = { id: string; locale: 'en' | 'sl'; title: string; prevails: string; html: string };

/**
 * /terms, /privacy, /imprint and /sl/pogoji, /sl/zasebnost, /sl/impresum. `html` is rendered on the server from the
 * legal markdown with raw HTML stripped and unsafe links dropped (LegalPages), so it is safe to insert.
 */
export default function LegalShow({ locale, title, prevails, html }: Props) {
  return (
    <>
      <Head title={title} />
      <TopBar nav={false} />
      <main className="legal" lang={locale}>
        <p className="legal-prevails">{prevails}</p>
        <article className="legal-body" dangerouslySetInnerHTML={{ __html: html }} />
      </main>
    </>
  );
}
