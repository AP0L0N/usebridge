import { useRef, useState, type FormEvent } from 'react';
import { SettingsLayout, VerifyEmailBanner } from '../../Components/Account/SettingsLayout';
import { Button, Card, describedBy, Dialog, ErrorSummary, Field, Input, Notice, PasswordInput, Select, StrengthMeter, useToasts } from '../../Components/Account/redesign/Ui';
import { deviceLabel, initials, type Account, type DeviceSession } from '../../lib/account';
import { updateTime } from '../../lib/dates';
import { fieldErrors, go, requestJson } from '../../lib/http';
import { t, tn } from '../../lib/strings-account';

type Props = {
  account: Account;
  sessions: DeviceSession[];
  timezones: string[];
  minPassword: number;
  deletion: { graceDays: number; counts: { projects: number; tickets: number; tokens: number } };
  can: { updateProfile: boolean; resendEmailChange: boolean; cancelEmailChange: boolean };
};

type ToastFn = (text: string, sub?: string, error?: boolean) => void;

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const focusSoon = (el: { current: HTMLElement | null }) => requestAnimationFrame(() => el.current?.focus());

/**
 * Profile: PATCH /account {name, timezone, email, current_password}. Name / timezone save at once; a different email
 * asks for the current password (inline, only then) and stays pending until the link sent to it is opened.
 */
function Profile({ account, timezones, can, toast }: Pick<Props, 'account' | 'timezones' | 'can'> & { toast: ToastFn }) {
  const [saved, setSaved] = useState<Account>(account);
  const [name, setName] = useState(account.name);
  const [tz, setTz] = useState(account.timezone ?? 'Europe/Ljubljana');
  const [email, setEmail] = useState(account.pending_email ?? account.email);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [ok, setOk] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);
  const ro = !can.updateProfile;
  const pending = saved.pending_email;
  const emailChanged = email.trim().toLowerCase() !== saved.email.toLowerCase() && email.trim().toLowerCase() !== (pending ?? '').toLowerCase();

  const fail = (e: Record<string, string>) => { setErrors(e); setOk(false); focusSoon(summary); };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (ro) return;
    const local: Record<string, string> = {};
    if (name.trim() === '') local.name = t('acct.profile.errName');
    if (!EMAIL_RE.test(email.trim())) local.email = t('acct.profile.errEmail');
    if (emailChanged && password === '' && !local.email) local.current_password = t('acct.profile.errPassword');
    if (Object.keys(local).length) return fail(local);
    setBusy(true);
    const res = await requestJson<{ user: Account }>('PATCH', '/account', {
      name: name.trim(), timezone: tz, ...(emailChanged ? { email: email.trim(), current_password: password } : {}),
    });
    setBusy(false);
    if (!res.ok) return fail(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? t('acct.generic') });
    setErrors({});
    setPassword('');
    setSaved(res.data.user);
    setName(res.data.user.name);
    setEmail(res.data.user.pending_email ?? res.data.user.email);
    setOk(true);
  };

  const resend = async () => {
    const res = await requestJson('POST', '/account/email/resend');
    toast(res.ok ? t('acct.pending.resent', { email: pending ?? '' }) : res.status === 429 ? (res.data.message ?? t('acct.throttled')) : res.data.message ?? t('acct.generic'), undefined, !res.ok);
  };
  const keepOld = async () => {
    const res = await requestJson<{ user: Account }>('DELETE', '/account/email/pending');
    if (res.ok) { setSaved(res.data.user); setEmail(res.data.user.email); setOk(false); }
    toast(res.ok ? t('acct.pending.kept') : res.data.message ?? t('acct.generic'), undefined, !res.ok);
  };

  return (
    <Card id="profile" title={t('acct.profile.title')} sub={t('acct.profile.sub')}>
      <ErrorSummary ref={summary} errors={errors} what={t('acct.errors.profile')}
        fieldIds={{ name: 'p-name', timezone: 'p-tz', email: 'p-email', current_password: 'p-pw' }} />
      <div className="acx-avatar-row">
        <span className="acx-avatar" aria-hidden="true">{initials(name || saved.name)}</span>
        <div>
          <p className="acx-avatar-row__name">{saved.name}</p>
          <p className="acx-hint">{t('acct.profile.avatarHint')}</p>
        </div>
      </div>
      <form className="acx-form" onSubmit={submit} noValidate>
        <div className="acx-row2">
          <Field id="p-name" label={t('acct.profile.name')} error={errors.name}>
            <Input id="p-name" autoComplete="name" value={name} readOnly={ro} disabled={busy} maxLength={120}
              onChange={(e) => { setName(e.target.value); setOk(false); }} {...describedBy('p-name', errors.name)} />
          </Field>
          <Field id="p-tz" label={t('acct.profile.timezone')} error={errors.timezone} hint={t('acct.profile.timezoneHint')}>
            <Select id="p-tz" value={tz} disabled={ro || busy} onChange={(e) => { setTz(e.target.value); setOk(false); }} {...describedBy('p-tz', errors.timezone, true)}>
              {timezones.map((z) => <option key={z} value={z}>{z}</option>)}
            </Select>
          </Field>
        </div>
        <Field id="p-email" label={t('acct.profile.email')} error={errors.email} hint={pending && !emailChanged ? undefined : t('acct.profile.emailHint')}>
          <Input id="p-email" type="email" autoComplete="email" value={email} readOnly={ro} disabled={busy}
            onChange={(e) => { setEmail(e.target.value); setOk(false); }} {...describedBy('p-email', errors.email, !(pending && !emailChanged), pending ? 'p-pending' : undefined)} />
          {pending && !emailChanged && (
            <div id="p-pending">
              <Notice kind="violet" role="status">
                <p>{t('acct.pending.title')} {t('acct.pending.body', { new: pending, old: saved.email })}</p>
                {(can.resendEmailChange || can.cancelEmailChange) && (
                  <div className="acx-notice__links">
                    {can.resendEmailChange && <button type="button" className="acx-link" onClick={resend}>{t('acct.pending.resend')}</button>}
                    {can.cancelEmailChange && <button type="button" className="acx-link" onClick={keepOld}>{t('acct.pending.keep')}</button>}
                  </div>
                )}
              </Notice>
            </div>
          )}
        </Field>
        {emailChanged && !ro && (
          <Field id="p-pw" label={t('acct.profile.password')} error={errors.current_password} hint={t('acct.profile.passwordHint')}>
            <PasswordInput id="p-pw" what={t('acct.profile.password').toLowerCase()} autoComplete="current-password" value={password} disabled={busy}
              onChange={(e) => setPassword(e.target.value)} {...describedBy('p-pw', errors.current_password, true)} />
          </Field>
        )}
        <div className="acx-actions">
          <Button type="submit" busy={busy} busyLabel={t('acct.profile.saving')} disabled={ro}>{t('acct.profile.save')}</Button>
          <span role="status" className="acx-status">{ok && <><span className="acx-status__dot" aria-hidden="true" />{t('acct.profile.saved')}</>}</span>
        </div>
      </form>
    </Card>
  );
}

/** PUT /account/password {current_password, password, password_confirmation}: other devices are logged out. */
function PasswordCard({ minPassword, toast }: { minPassword: number; toast: ToastFn }) {
  const [cur, setCur] = useState('');
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);
  const fail = (e: Record<string, string>) => { setErrors(e); focusSoon(summary); };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (cur === '') local.current_password = t('acct.pw.errCurrent');
    if (pw.length < minPassword) local.password = t('acct.pw.errShort', { n: minPassword });
    else if (pw !== pw2) local.password_confirmation = t('acct.pw.errMatch');
    if (Object.keys(local).length) return fail(local);
    setBusy(true);
    const res = await requestJson('PUT', '/account/password', { current_password: cur, password: pw, password_confirmation: pw2 });
    setBusy(false);
    if (!res.ok) return fail(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? t('acct.generic') });
    setErrors({});
    setCur(''); setPw(''); setPw2('');
    toast(t('acct.pw.done'), t('acct.pw.doneSub'));
  };

  return (
    <Card id="password" title={t('acct.pw.title')} sub={t('acct.pw.sub')}>
      <ErrorSummary ref={summary} errors={errors} what={t('acct.errors.password')}
        fieldIds={{ current_password: 'c-pw', password: 'a-pw', password_confirmation: 'a-pw2' }} />
      <form className="acx-form" onSubmit={submit} noValidate>
        <Field id="c-pw" label={t('acct.pw.current')} error={errors.current_password}>
          <PasswordInput id="c-pw" what={t('acct.pw.current').toLowerCase()} autoComplete="current-password" value={cur} disabled={busy}
            onChange={(e) => setCur(e.target.value)} {...describedBy('c-pw', errors.current_password)} />
        </Field>
        <Field id="a-pw" label={t('acct.pw.new')} error={errors.password}>
          <PasswordInput id="a-pw" what={t('acct.pw.new').toLowerCase()} autoComplete="new-password" value={pw} disabled={busy}
            onChange={(e) => setPw(e.target.value)} {...describedBy('a-pw', errors.password, false, 'a-meter')} />
          <StrengthMeter id="a-meter" value={pw} min={minPassword} />
        </Field>
        <Field id="a-pw2" label={t('acct.pw.confirm')} error={errors.password_confirmation}>
          <PasswordInput id="a-pw2" what={t('acct.pw.confirm').toLowerCase()} autoComplete="new-password" value={pw2} disabled={busy}
            onChange={(e) => setPw2(e.target.value)} {...describedBy('a-pw2', errors.password_confirmation)} />
        </Field>
        <div className="acx-actions acx-actions--wrap">
          <Button type="submit" kind="secondary" busy={busy} busyLabel={t('acct.pw.busy')}>{t('acct.pw.submit')}</Button>
          <a href="/forgot-password" className="acx-link">{t('acct.pw.forgot')}</a>
        </div>
      </form>
    </Card>
  );
}

/** Sessions: this device + the others, log out the others (password) or every device. */
function Sessions({ initial, toast }: { initial: DeviceSession[]; toast: ToastFn }) {
  const [sessions, setSessions] = useState(initial);
  const [modal, setModal] = useState<null | 'others' | 'all'>(null);
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const now = new Date().toISOString();
  const others = sessions.filter((s) => !s.current);
  const current = sessions.find((s) => s.current);

  const close = () => { setModal(null); setPassword(''); setError(null); };
  const logoutHere = async () => { setBusy(true); await requestJson('POST', '/logout'); go('/login'); };
  const logoutOthers = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    const res = await requestJson<{ ended?: number }>('DELETE', '/account/sessions/others', { password });
    setBusy(false);
    if (!res.ok) { setError(fieldErrors(res.data.errors).password ?? res.data.message ?? t('acct.generic')); return; }
    close();
    const list = await requestJson<{ data: DeviceSession[] }>('GET', '/account/sessions');
    if (list.ok) setSessions(list.data.data);
    toast(t('acct.sess.othersDone'), res.data.ended ? tn('acct.sess.ended', res.data.ended) : undefined);
  };
  const logoutAll = async () => {
    setBusy(true);
    const res = await requestJson('DELETE', '/account/sessions');
    if (res.ok) go('/login?status=logged-out-everywhere');
    else { setBusy(false); setError(res.data.message ?? t('acct.generic')); }
  };

  return (
    <Card id="sessions" title={t('acct.sess.title')} sub={t('acct.sess.sub')}>
      <div className="acx-rows">
        <div className="acx-rowitem">
          <div>
            <p className="acx-rowitem__t">{t('acct.sess.this')}</p>
            <p className="acx-rowitem__d">{t('acct.sess.activeNow', { device: current ? deviceLabel(current.user_agent) : t('acct.sess.thisBrowser') })}</p>
          </div>
          <Button kind="ghost" onClick={logoutHere} disabled={busy}>{t('acct.sess.logout')}</Button>
        </div>
        {others.map((s) => (
          <div className="acx-rowitem" key={s.id}>
            <div>
              <p className="acx-rowitem__t">{deviceLabel(s.user_agent)}</p>
              <p className="acx-rowitem__d">{t('acct.sess.lastActive', { ip: s.ip ?? t('acct.sess.unknownIp'), when: updateTime(s.last_active, now) })}</p>
            </div>
          </div>
        ))}
        <div className="acx-rowitem">
          <div>
            <p className="acx-rowitem__t">{others.length === 0 ? t('acct.sess.none') : tn('acct.sess', others.length)}</p>
            <p className="acx-rowitem__d">{t('acct.sess.othersHint')}</p>
          </div>
        </div>
      </div>
      <div className="acx-actions acx-actions--wrap">
        {others.length > 0 && <Button kind="secondary" onClick={() => setModal('others')}>{t('acct.sess.logoutOthers')}</Button>}
        <Button kind="secondary" onClick={() => setModal('all')}>{t('acct.sess.logoutAll')}</Button>
      </div>

      {modal === 'others' && (
        <Dialog title={t('acct.sess.othersTitle')} onClose={close} describedBy="lo-d"
          footer={<><Button kind="secondary" onClick={close}>{t('acct.sess.cancel')}</Button><Button type="submit" form="lo-form" busy={busy} busyLabel={t('acct.sess.busy')} disabled={password === ''}>{t('acct.sess.logoutOthers')}</Button></>}>
          <p id="lo-d">{t('acct.sess.othersBody')}</p>
          <form id="lo-form" onSubmit={logoutOthers} noValidate>
            <Field id="lo-pw" label={t('acct.sess.yourPassword')} error={error ?? undefined}>
              <PasswordInput id="lo-pw" what={t('acct.sess.yourPassword').toLowerCase()} autoComplete="current-password" value={password}
                onChange={(e) => setPassword(e.target.value)} {...describedBy('lo-pw', error ?? undefined)} />
            </Field>
          </form>
        </Dialog>
      )}
      {modal === 'all' && (
        <Dialog title={t('acct.sess.allTitle')} onClose={close} alert describedBy="la-d"
          footer={<><Button kind="secondary" onClick={close} data-autofocus>{t('acct.sess.cancel')}</Button><Button onClick={logoutAll} busy={busy} busyLabel={t('acct.sess.busy')}>{t('acct.sess.allConfirm')}</Button></>}>
          <p id="la-d">{t('acct.sess.allBody')}</p>
          {error && <Notice kind="risk" role="alert"><p>{error}</p></Notice>}
        </Dialog>
      )}
    </Card>
  );
}

/** Danger zone: export (GET /account/export) and delete with a typed email (POST /account/delete, 7-day grace). */
function DangerZone({ account, deletion }: Pick<Props, 'account' | 'deletion'>) {
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const matches = typed.trim().toLowerCase() === account.email.toLowerCase();
  const c = deletion.counts;
  const close = () => { setOpen(false); setTyped(''); setError(null); };

  const confirm = async () => {
    setBusy(true);
    const res = await requestJson('POST', '/account/delete', { email: typed.trim() });
    if (res.ok) { go('/login?status=deletion-requested'); return; }
    setBusy(false);
    setError(fieldErrors(res.data.errors).email ?? res.data.message ?? t('acct.generic'));
  };

  return (
    <Card id="danger" danger title={t('acct.dz.title')} sub={t('acct.dz.sub')}>
      <div className="acx-rows acx-rows--plain">
        <div className="acx-rowitem">
          <div><p className="acx-rowitem__t">{t('acct.dz.export')}</p><p className="acx-hint">{t('acct.dz.exportHint')}</p></div>
          <a className="acx-btn acx-btn--secondary" href="/account/export" download>{t('acct.dz.exportBtn')}</a>
        </div>
        <div className="acx-rowitem">
          <div><p className="acx-rowitem__t">{t('acct.dz.delete')}</p><p className="acx-hint">{t('acct.dz.deleteHint', { days: deletion.graceDays })}</p></div>
          <Button kind="danger" onClick={() => setOpen(true)}>{t('acct.dz.deleteBtn')}</Button>
        </div>
      </div>
      {open && (
        <Dialog title={t('acct.del.title')} onClose={close} describedBy="del-d"
          footer={<><Button kind="secondary" onClick={close}>{t('acct.del.keep')}</Button><Button kind="danger" disabled={!matches} busy={busy} busyLabel={t('acct.del.busy')} onClick={confirm}>{t('acct.del.confirm')}</Button></>}>
          <div id="del-d" className="acx-dialog__text">
            <p>
              {t('acct.del.lead')}<b>{tn('acct.count.project', c.projects)}</b>, <b>{tn('acct.count.ticket', c.tickets)}</b>{t('acct.del.and')}<b>{tn('acct.count.token', c.tokens)}</b>{t('acct.del.after')}
            </p>
            <p>{t('acct.del.grace', { days: deletion.graceDays })} <a className="acx-link" href="/account/export" download>{t('acct.del.exportFirst')}</a></p>
          </div>
          <Field id="del-confirm" label={<>{t('acct.del.typePre')}{account.email}{t('acct.del.typePost')}</>} error={error ?? undefined} hint={t('acct.del.hint')}>
            <Input id="del-confirm" autoComplete="off" spellCheck={false} value={typed} onChange={(e) => setTyped(e.target.value)} {...describedBy('del-confirm', error ?? undefined, true)} />
          </Field>
        </Dialog>
      )}
    </Card>
  );
}

/** Account settings (Figma redesign): Profile, Password, Sessions, Danger zone. */
export default function Settings(p: Props) {
  const { push, region } = useToasts();
  const toast: ToastFn = (text, sub, error) => push({ text, sub, error });
  return (
    <SettingsLayout page="account" title={t('acct.title')} toasts={region}>
      {!p.account.email_verified && (
        <VerifyEmailBanner email={p.account.email} why={t('acct.verify.whyAccount')} onResent={(m) => toast(m)} />
      )}
      <Profile account={p.account} timezones={p.timezones} can={p.can} toast={toast} />
      <PasswordCard minPassword={p.minPassword} toast={toast} />
      <Sessions initial={p.sessions} toast={toast} />
      <DangerZone account={p.account} deletion={p.deletion} />
    </SettingsLayout>
  );
}
