import { useRef, useState, type FormEvent } from 'react';
import { BusyButton, Callout, described, ErrorSummary, Field, IconCheck, PasswordInput, StrengthMeter } from '../../Components/Account/Fields';
import { Modal, useToasts } from '../../Components/Account/Overlay';
import { SettingsLayout, VerifyEmailBanner } from '../../Components/Account/SettingsLayout';
import { deviceLabel, initials, type Account, type DeviceSession } from '../../lib/account';
import { updateTime } from '../../lib/dates';
import { fieldErrors, go, requestJson } from '../../lib/http';

type Props = {
  account: Account;
  sessions: DeviceSession[];
  timezones: string[];
  minPassword: number;
  deletion: { graceDays: number; counts: { projects: number; tickets: number; tokens: number } };
  can: { updateProfile: boolean; resendEmailChange: boolean; cancelEmailChange: boolean };
};

const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

/** Profile: PATCH /account {name, timezone, email} once that route exists (§9 step 1); read-only until then. */
function Profile({ account, timezones, can, toast }: Pick<Props, 'account' | 'timezones' | 'can'> & { toast: (t: string, sub?: string) => void }) {
  const [name, setName] = useState(account.name);
  const [tz, setTz] = useState(account.timezone ?? 'Europe/Ljubljana');
  const [email, setEmail] = useState(account.email);
  const [pending, setPending] = useState(account.pending_email);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);
  const ro = !can.updateProfile;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (ro) return;
    const local: Record<string, string> = {};
    if (name.trim() === '') local.name = 'Enter your name';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) local.email = 'Enter an email like name@example.com';
    setSaved(false);
    if (Object.keys(local).length) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setBusy(true);
    const res = await requestJson<{ user?: Account }>('PATCH', '/account', { name: name.trim(), timezone: tz, email: email.trim() });
    setBusy(false);
    if (!res.ok) {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? 'Something went wrong.' });
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setErrors({});
    setSaved(true);
    const newPending = res.data.user?.pending_email ?? null;
    setPending(newPending);
    setEmail(res.data.user?.email ?? account.email);
    toast('Profile saved', newPending ? `Check ${newPending} to confirm the new email.` : undefined);
  };

  const emailAction = async (method: 'POST' | 'DELETE', url: string, done: string) => {
    const res = await requestJson(method, url);
    if (res.ok && method === 'DELETE') setPending(null);
    toast(res.ok ? done : res.data.message ?? 'Something went wrong.');
  };

  return (
    <section className="card" id="profile" aria-labelledby="h-profile">
      <h2 id="h-profile">Profile</h2>
      <p className="sub">How you appear on your boards and to your bots.</p>
      <ErrorSummary ref={summary} errors={errors} fieldIds={{ name: 'p-name', email: 'p-email' }} what="save your profile" level={3} />
      <div className="prof">
        <span className="avi xl" aria-hidden="true">{initials(name || account.name)}</span>
        <div>
          <b>{account.name}</b>
          <p>Your initials are your avatar. They update when you change your name.</p>
        </div>
      </div>
      <form className="form2" onSubmit={submit} noValidate>
        <Field id="p-name" label="Name" error={errors.name}>
          <input className="qin" id="p-name" autoComplete="name" value={name} readOnly={ro} disabled={busy}
            onChange={(e) => setName(e.target.value)} {...described('p-name', errors.name)} />
        </Field>
        <Field id="p-tz" label="Timezone" error={errors.timezone} hint="Display only for now: Usage days stay on Europe/Ljubljana.">
          <select className="qsel" id="p-tz" value={tz} disabled={ro || busy} onChange={(e) => setTz(e.target.value)} {...described('p-tz', errors.timezone, true)}>
            {timezones.map((z) => <option key={z} value={z}>{z}</option>)}
          </select>
        </Field>
        <Field id="p-email" label="Email" className="full" error={errors.email}
          hint={pending ? undefined : 'Changing it sends a link to the new address. You keep logging in with the current email until you confirm.'}>
          <input className="qin" id="p-email" type="email" autoComplete="email" value={email} readOnly={ro} disabled={busy}
            onChange={(e) => setEmail(e.target.value)} {...described('p-email', errors.email, !pending)} />
          {pending && (
            <div className="callout warn" role="status">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>
              <p>
                <span className="t">Verify your new email.</span> We sent a link to <b>{pending}</b>. Until you confirm, Bridge keeps using {account.email}.
                {can.resendEmailChange && <> <button type="button" className="lnk" style={{ minHeight: 0, padding: 0, fontSize: 'inherit' }} onClick={() => emailAction('POST', '/account/email/resend', `A new link is on its way to ${pending}.`)}>Resend link</button></>}
                {can.cancelEmailChange && <> · <button type="button" className="lnk" style={{ minHeight: 0, padding: 0, fontSize: 'inherit' }} onClick={() => emailAction('DELETE', '/account/email/pending', 'Kept your current email.')}>Keep the old email</button></>}
              </p>
            </div>
          )}
        </Field>
        <div className="cardfoot full">
          {ro ? (
            <p className="fine">Editing your name, timezone and email arrives with the next update.</p>
          ) : (
            <>
              <BusyButton busy={busy} busyLabel="Saving…" className="btn">Save profile</BusyButton>
              {saved && <span className="ok-line" role="status"><IconCheck />Profile saved</span>}
            </>
          )}
        </div>
      </form>
    </section>
  );
}

/** PUT /account/password {current_password, password}: other devices are logged out (server). */
function PasswordCard({ minPassword, toast }: { minPassword: number; toast: (t: string, sub?: string) => void }) {
  const [cur, setCur] = useState('');
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (cur === '') local.current_password = 'Enter your current password';
    if (pw.length < minPassword) local.password = `Use at least ${minPassword} characters for the new password`;
    else if (pw !== pw2) local.password_confirmation = "The two new passwords don't match";
    if (Object.keys(local).length) {
      setErrors(local);
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setBusy(true);
    const res = await requestJson('PUT', '/account/password', { current_password: cur, password: pw });
    setBusy(false);
    if (!res.ok) {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? 'Something went wrong.' });
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    setErrors({});
    setCur(''); setPw(''); setPw2('');
    toast('Password changed', 'Other devices were logged out.');
  };

  return (
    <section className="card" id="password" aria-labelledby="h-pw">
      <h2 id="h-pw">Password</h2>
      <p className="sub">Changing it logs you out everywhere else.</p>
      <ErrorSummary ref={summary} errors={errors} fieldIds={{ current_password: 'c-pw', password: 'a-pw', password_confirmation: 'a-pw2' }} what="change your password" level={3} />
      <form className="form2" onSubmit={submit} noValidate>
        <Field id="c-pw" label="Current password" className="full" error={errors.current_password}>
          <PasswordInput id="c-pw" autoComplete="current-password" showLabel="current password" value={cur} disabled={busy}
            onChange={(e) => setCur(e.target.value)} {...described('c-pw', errors.current_password)} />
        </Field>
        <Field id="a-pw" label="New password" error={errors.password}>
          <PasswordInput id="a-pw" autoComplete="new-password" showLabel="new password" value={pw} disabled={busy}
            onChange={(e) => setPw(e.target.value)} {...described('a-pw', errors.password, false, 'a-meter')} />
          <StrengthMeter id="a-meter" value={pw} min={minPassword} />
        </Field>
        <Field id="a-pw2" label="Confirm new password" error={errors.password_confirmation}>
          <PasswordInput id="a-pw2" autoComplete="new-password" showLabel="confirmed password" value={pw2} disabled={busy}
            onChange={(e) => setPw2(e.target.value)} {...described('a-pw2', errors.password_confirmation)} />
        </Field>
        <div className="cardfoot full">
          <BusyButton busy={busy} busyLabel="Changing…" className="btn">Change password</BusyButton>
          <a href="/forgot-password" className="lnk">Forgot your current password?</a>
        </div>
      </form>
    </section>
  );
}

/** Sessions: this device + the others (GET /account/sessions), log out the others (password) or every device. */
function Sessions({ initial, toast }: { initial: DeviceSession[]; toast: (t: string, sub?: string) => void }) {
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
    if (!res.ok) {
      setError(fieldErrors(res.data.errors).password ?? res.data.message ?? 'Something went wrong.');
      return;
    }
    close();
    const list = await requestJson<{ data: DeviceSession[] }>('GET', '/account/sessions');
    if (list.ok) setSessions(list.data.data);
    toast('Other devices were logged out', res.data.ended ? `${plural(res.data.ended, 'session')} ended.` : undefined);
  };
  const logoutAll = async () => {
    setBusy(true);
    const res = await requestJson('DELETE', '/account/sessions');
    if (res.ok) go('/login?status=logged-out-everywhere');
    else { setBusy(false); setError(res.data.message ?? 'Something went wrong.'); }
  };

  return (
    <section className="card" id="sessions" aria-labelledby="h-sess">
      <h2 id="h-sess">Sessions</h2>
      <p className="sub">Where you're logged in. API tokens for bots are separate; see API tokens &amp; MCP.</p>
      <div className="rowact">
        <div><p><b>This device</b></p><p className="d">{current ? `${deviceLabel(current.user_agent)} · active now` : 'This browser · active now'}</p></div>
        <button className="abtn" type="button" onClick={logoutHere} disabled={busy}>Log out</button>
      </div>
      {others.map((s) => (
        <div className="rowact" key={s.id}>
          <div><p><b>{deviceLabel(s.user_agent)}</b></p><p className="d">{s.ip ?? 'Unknown IP'} · last active {updateTime(s.last_active, now)}</p></div>
        </div>
      ))}
      <div className="rowact">
        <div>
          <p><b>{others.length === 0 ? 'No other devices' : plural(others.length, 'other device')}</b></p>
          <p className="d">Log out everywhere if a device is lost or you used a shared computer.</p>
        </div>
        <div className="btnrow">
          {others.length > 0 && <button className="abtn" type="button" onClick={() => setModal('others')}>Log out other devices</button>}
          <button className="abtn" type="button" onClick={() => setModal('all')}>Log out of all devices</button>
        </div>
      </div>

      {modal === 'others' && (
        <Modal title="Log out other devices?" onClose={close} describedBy="lo-d"
          footer={<><button type="button" className="abtn" onClick={close}>Cancel</button><button type="submit" form="lo-form" className="btn" disabled={busy || password === ''}>{busy ? 'Logging out…' : 'Log out other devices'}</button></>}>
          <p id="lo-d">Every other browser is logged out. This one stays logged in.</p>
          <form id="lo-form" onSubmit={logoutOthers} noValidate>
            <Field id="lo-pw" label="Your password" error={error ?? undefined}>
              <PasswordInput id="lo-pw" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} {...described('lo-pw', error ?? undefined)} />
            </Field>
          </form>
        </Modal>
      )}
      {modal === 'all' && (
        <Modal title="Log out of all devices?" onClose={close} alert describedBy="la-d"
          footer={<><button type="button" className="abtn" onClick={close} data-autofocus>Cancel</button><button type="button" className="btn" onClick={logoutAll} disabled={busy}>{busy ? 'Logging out…' : 'Log out everywhere'}</button></>}>
          <p id="la-d">Every browser is logged out, this one too, and “Keep me logged in” ends. API tokens keep working.</p>
          {error && <Callout kind="bad" alert>{error}</Callout>}
        </Modal>
      )}
    </section>
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
    if (res.ok) {
      go('/login?status=deletion-requested');
      return;
    }
    setBusy(false);
    setError(fieldErrors(res.data.errors).email ?? res.data.message ?? 'Something went wrong.');
  };

  return (
    <section className="card dz" id="danger" aria-labelledby="h-dz">
      <h2 id="h-dz">Danger zone</h2>
      <p className="sub">Deleting your account removes your projects, boards, tickets, usage history and API tokens. Bots using your tokens stop working at once.</p>
      <div className="rowact">
        <div><p><b>Export your data</b></p><p className="d">One JSON file: projects, tickets, comments, statuses and usage.</p></div>
        <a className="abtn" href="/account/export" download>Download export</a>
      </div>
      <div className="rowact">
        <div><p><b>Delete account</b></p><p className="d">After {deletion.graceDays} days it can't be undone. Log in before then to cancel. Export anything you need first.</p></div>
        <button className="btn danger" type="button" onClick={() => setOpen(true)}>Delete account…</button>
      </div>
      {open && (
        <Modal title="Delete your account?" onClose={close} describedBy="del-d"
          footer={<><button type="button" className="abtn" onClick={close}>Keep my account</button><button type="button" className="btn danger" disabled={!matches || busy} onClick={confirm}>{busy ? 'Deleting…' : 'Delete account'}</button></>}>
          <p id="del-d">
            This deletes <b>{plural(c.projects, 'project')}</b>, <b>{plural(c.tickets, 'ticket')}</b>, your usage history and <b>{plural(c.tokens, 'API token')}</b>.
            You are logged out everywhere and your bots stop at once. You have {deletion.graceDays} days to change your mind: log in and cancel. After that it can't be undone.
          </p>
          <p><a href="/account/export" download>Download an export first</a></p>
          <Field id="del-confirm" label={<>Type <b>{account.email}</b> to confirm</>} error={error ?? undefined} hint="The delete button turns on when it matches.">
            <input className="qin" id="del-confirm" autoComplete="off" spellCheck={false} value={typed} onChange={(e) => setTyped(e.target.value)} {...described('del-confirm', error ?? undefined, true)} />
          </Field>
        </Modal>
      )}
    </section>
  );
}

/** Account settings (prototype account.html): Profile, Password, Sessions, Danger zone. */
export default function Settings(p: Props) {
  const { push, region } = useToasts();
  const toast = (text: string, sub?: string) => push({ text, sub });
  return (
    <SettingsLayout page="account" title="Account" toasts={region}>
      {!p.account.email_verified && (
        <VerifyEmailBanner email={p.account.email} why="Boards work now; API tokens and MCP need a verified email." onResent={(m) => toast(m)} />
      )}
      <Profile account={p.account} timezones={p.timezones} can={p.can} toast={toast} />
      <PasswordCard minPassword={p.minPassword} toast={toast} />
      <Sessions initial={p.sessions} toast={toast} />
      <DangerZone account={p.account} deletion={p.deletion} />
    </SettingsLayout>
  );
}
