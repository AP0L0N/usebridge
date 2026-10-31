import { Head, Link } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { BusyButton, Callout, described, Field } from '../Components/Account/Fields';
import { TopBar } from '../Components/TopBar';
import { fieldErrors, go, requestJson } from '../lib/http';

type Column = { key: string; label: string; order: number };
type Props = {
  name: string;
  project: { name: string; slug: string } | null;
  hasToken: boolean;
  emailVerified: boolean;
  columns: Column[];
  /** null until web project creation (POST /projects) / column renaming exist (§8 onboarding, not built yet). */
  createProjectUrl: string | null;
  saveColumnsUrl: string | null;
};

type Step = 1 | 2 | 3 | 4;

const STEPS = [
  { n: 1, t: 'Create your first project', s: 'Name it; you can add more later' },
  { n: 2, t: 'Set up its board', s: 'Default columns' },
  { n: 3, t: 'Connect your Grok bot', s: 'Optional' },
] as const;

/** Onboarding (prototype onboarding.html): checklist + one step at a time. State is derived: has a project, has a token. */
export default function Onboarding(p: Props) {
  const first: Step = p.project === null ? 1 : p.hasToken ? 4 : 2;
  const [step, setStep] = useState<Step>(first);
  const [project, setProject] = useState(p.project);
  const goStep = (s: Step) => {
    setStep(s);
    requestAnimationFrame(() => document.getElementById(`h-s${s}`)?.focus());
  };
  const firstName = p.name.split(/\s+/)[0] ?? p.name;
  const done = (n: number) => n < step || (n === 1 && project !== null);

  return (
    <>
      <Head title="Welcome" />
      <TopBar />
      <main className="wrap">
        {step < 4 ? (
          <>
            <div className="dhead">
              <div>
                <h1>Welcome to Bridge, {firstName}</h1>
                <p className="summary">{project === null && 'Your portfolio is empty. '}Three steps and your first board is ready. You can change everything later.</p>
                <p className="prog">
                  <span className="bar" aria-hidden="true"><span style={{ width: `${((step - 1) / 3) * 100 + 8}%` }} /></span>
                  <span>Step {step} of 3</span>
                </p>
              </div>
            </div>
            <div className="obgrid">
              <ol className="olist" aria-label="Setup steps">
                {STEPS.map((s) => (
                  <li key={s.n} aria-current={s.n === step ? 'step' : undefined} className={done(s.n) && s.n !== step ? 'dn' : undefined}>
                    <span className="n" aria-hidden="true">{done(s.n) && s.n !== step ? '✓' : s.n}</span>
                    <span className="t">{s.t}</span>
                    <span className="s">{s.s}</span>
                    <span className="sr-only">{s.n === step ? ' (current step)' : done(s.n) ? ' (done)' : ''}</span>
                  </li>
                ))}
              </ol>
              {step === 1 && <StepProject p={p} onCreated={(pr) => { setProject(pr); goStep(2); }} onSkip={() => goStep(3)} />}
              {step === 2 && <StepBoard p={p} projectName={project?.name ?? 'your project'} onBack={() => goStep(1)} onNext={() => goStep(3)} />}
              {step === 3 && <StepBot p={p} onBack={() => goStep(project ? 2 : 1)} onSkip={() => goStep(4)} />}
            </div>
          </>
        ) : (
          <>
            <div className="dhead"><div><h1 id="h-s4" tabIndex={-1}>You're set up</h1><p className="summary">Your boards and bots, in one place.</p></div></div>
            {project ? (
              <Callout kind="ok" status style={{ marginBottom: 20 }}><span className="t">{project.name} is ready.</span> Open it to add your first tickets.</Callout>
            ) : (
              <Callout kind="info" style={{ marginBottom: 20 }}><span className="t">No project yet.</span> It shows on your Portfolio as soon as one exists.</Callout>
            )}
            <div className="btnrow">
              {project && <Link className="btn" href={`/projects/${project.slug}`}>Open {project.name}</Link>}
              <Link className={project ? 'abtn' : 'btn'} href="/">Go to Portfolio</Link>
              {!p.hasToken && <Link className="abtn" href="/account/tokens">Set up API tokens &amp; MCP</Link>}
            </div>
          </>
        )}
      </main>
    </>
  );
}

function StepProject({ p, onCreated, onSkip }: { p: Props; onCreated: (pr: { name: string; slug: string }) => void; onSkip: () => void }) {
  const [name, setName] = useState('');
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const off = p.createProjectUrl === null;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (off) return;
    if (name.trim() === '') { setErrors({ name: 'Give the project a name' }); return; }
    setBusy(true);
    const res = await requestJson<{ data?: { name: string; slug: string } }>('POST', p.createProjectUrl!, { name: name.trim(), description: desc.trim() || null, pm: 'me' });
    setBusy(false);
    if (!res.ok || !res.data.data) {
      setErrors(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? 'Something went wrong.' });
      return;
    }
    onCreated(res.data.data);
  };

  return (
    <section className="card" aria-labelledby="h-s1">
      <h2 id="h-s1" tabIndex={-1}>Create your first project</h2>
      <p className="sub">A project gets its own card on your Portfolio and its own board.</p>
      {off && (
        <Callout kind="info" style={{ marginBottom: 18 }}>
          <span className="t">Creating projects here isn't switched on yet.</span> It arrives with the next Bridge update. You can already connect your bot (step 3).
        </Callout>
      )}
      {errors.form && <Callout kind="bad" alert style={{ marginBottom: 18 }}>{errors.form}</Callout>}
      <form className="form2" onSubmit={submit} noValidate>
        <Field id="p-name" label="Project name" className="full" error={errors.name}>
          <input className="qin" id="p-name" name="name" autoComplete="off" required value={name} disabled={off || busy} placeholder="e.g. Garden planner"
            onChange={(e) => setName(e.target.value)} {...described('p-name', errors.name)} />
        </Field>
        <Field id="p-desc" label={<>Short description <span className="dim2">(optional)</span></>} className="full" hint="One line about what it is.">
          <textarea className="qta" id="p-desc" name="description" style={{ minHeight: 88 }} value={desc} disabled={off || busy}
            onChange={(e) => setDesc(e.target.value)} {...described('p-desc', undefined, true)} />
        </Field>
        <div className="cardfoot full">
          {off
            ? <button type="button" className="btn" onClick={onSkip}>Skip to step 3</button>
            : <BusyButton busy={busy} busyLabel="Creating…" className="btn">Create project</BusyButton>}
        </div>
      </form>
    </section>
  );
}

function StepBoard({ p, projectName, onBack, onNext }: { p: Props; projectName: string; onBack: () => void; onNext: () => void }) {
  const [labels, setLabels] = useState<Record<string, string>>(() => Object.fromEntries(p.columns.map((c) => [c.key, c.label])));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const editable = p.saveColumnsUrl !== null;

  const save = async () => {
    if (!editable) { onNext(); return; }
    setBusy(true);
    const res = await requestJson('PATCH', p.saveColumnsUrl!, { column_labels: labels });
    setBusy(false);
    if (res.ok) onNext();
    else setError(res.data.message ?? 'Something went wrong.');
  };

  return (
    <section className="card" aria-labelledby="h-s2">
      <h2 id="h-s2" tabIndex={-1}>Set up its board</h2>
      <p className="sub">The columns of {projectName}. {editable ? 'Rename them now or any time from the board.' : 'Every board has these columns for now; renaming them comes later.'}</p>
      {error && <Callout kind="bad" alert>{error}</Callout>}
      <div className="colprev c6" role="list" aria-label="Board columns">
        {p.columns.map((c, i) => (
          <div className="col" role="listitem" key={c.key}>
            <div className="colh">
              <span className={`dot c-${c.key}`} aria-hidden="true" />
              {editable ? (
                <>
                  <label className="sr-only" htmlFor={`col-${c.key}`}>Column {i + 1} name</label>
                  <input id={`col-${c.key}`} value={labels[c.key] ?? ''} onChange={(e) => setLabels((cur) => ({ ...cur, [c.key]: e.target.value }))} />
                </>
              ) : <span style={{ padding: '4px 6px' }}>{c.label}</span>}
              <span className="cnt">0</span>
            </div>
            <div className="ghosttk" aria-hidden="true" />
          </div>
        ))}
      </div>
      <p className="fhint">Tickets your bot creates land in the first column.</p>
      <div className="cardfoot">
        <button type="button" className="abtn" onClick={onBack}>Back</button>
        <BusyButton busy={busy} busyLabel="Saving…" className="btn" type="button" onClick={save}>{editable ? 'Save board' : 'Continue'}</BusyButton>
      </div>
    </section>
  );
}

function StepBot({ p, onBack, onSkip }: { p: Props; onBack: () => void; onSkip: () => void }) {
  return (
    <section className="card" aria-labelledby="h-s3">
      <h2 id="h-s3" tabIndex={-1}>Connect your Grok bot</h2>
      <p className="sub">Optional. Your bot can then read the board and move tickets for you.</p>
      {!p.emailVerified && <Callout kind="warn" icon="mail" style={{ marginBottom: 12 }}><span className="t">Verify your email first.</span> Tokens need a verified email; the link is in your inbox.</Callout>}
      <ol className="steps">
        <li><h3>Create a token</h3><p>Choose read, or read &amp; write if the bot should move tickets.</p></li>
        <li><h3>Add the Bridge MCP connector</h3><p>Paste the server URL and the token as the Authorization header.</p></li>
        <li><h3>Ask your bot “list my Bridge boards”</h3><p>It should answer with your projects.</p></li>
      </ol>
      <div className="cardfoot">
        <button type="button" className="abtn" onClick={onBack}>Back</button>
        <button type="button" className="btn" onClick={() => go('/account/tokens')}>Create a token</button>
        <button type="button" className="abtn" onClick={onSkip}>Skip for now</button>
        <p className="fine">You can do this later in Account, API tokens &amp; MCP.</p>
      </div>
    </section>
  );
}
