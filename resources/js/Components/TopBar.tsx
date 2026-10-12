import { Link } from '@inertiajs/react';
import { t } from '../lib/strings';

export function TopBar() {
  return (
    <header className="top">
      <div className="wrap topin">
        <Link className="brand" href="/"><span className="mark" aria-hidden="true" /><span>{t('app.name')}</span></Link>
        <span className="who">Tilen</span>
      </div>
    </header>
  );
}
