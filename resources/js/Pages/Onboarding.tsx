import { Head, Link } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { BusyButton, Callout, described, ErrorSummary, Field } from '../Components/Account/Fields';
import { checkLabels, LABEL_MAX, saveColumnLabels } from '../Components/Kanban/ColumnLabels';
import { AppTopBar } from '../Components/Shell/AppTopBar';
import { fieldErrors, go, requestJson } from '../lib/http';

type Column = { key: string; label: string; order: number };
type Props = {
  name: string;
  project: { name: string; slug: string } | null;
  hasToken: boolean;
  emailVerified: boolean;
  /** The first project's columns (its own labels), else the defaults. */
  columns: Column[];
  defaultColumns: Column[];
  /** POST /projects; null would mean web creation is off (the form says so). */
  createProjectUrl: string | null;
  /** PUT /projects/{slug}/column-labels of the first project; null until there is one (step 1's answer brings it). */
  saveColumnsUrl: string | null;
};

type Step = 1 | 2 | 3 | 4;

const STEPS = [
  { n: 1, t: 'Create your first project', s: 'Name it; you can add more later' },
  { n: 2, t: 'Set up its board', s: 'Name its columns' },
  { n: 3, t: 'Connect your Grok bot', s: 'Optional' },
] as const;

/** Onboarding (prototype onboarding.html): checklist + one step at a time. State is derived: has a project, has a token. */
export default function Onboarding(p: Props) {
  const first: Step = p.project === null ? 1 : p.hasToken ? 4 : 2;
  const [step, setStep] = useState<Step>(first);
  const [project, setProject] = useState<Created | null>(p.project);
  const [justCreated, setJustCreated] = useState(false);
  const goStep = (s: Step) => {
    setStep(s);
    requestAnimationFrame(() => document.getElementById(`h-s${s}`)?.focus());
  };
  const firstName = p.name.split(/\s+/)[0] ?? p.name;
  const done = (n: number) => n < step || (n === 1 && project !== null);

  return (
    <>
      <Head title="Welcome" />
      <AppTopBar />
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
              {step === 1 && <StepProject p={p} project={project} onCreated={(pr) => { setProject(pr); setJustCreated(true); goStep(2); }} onNext={() => goStep(2)} onSkip={() => goStep(3)} />}
              {step === 2 && <StepBoard p={p} project={project} justCreated={justCreated} onBack={() => goStep(1)} onNext={() => { setJustCreated(false); goStep(3); }} />}
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

type Created = { name: string; slug: string; column_labels_url?: string };

/** "Acme Studio" -> "acme-studio" (the server checks the same pattern). */
function slugify(name: string): string {
  return name.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64).replace(/-+$/, '');
}

/** Initials like the server's TicketPrefix ("Acme Studio" -> AS, "Acme" -> AC); the user can change it. */
function prefixFor(name: string): string {
  const words = name.normalize('NFKD').replace(/[̀-ͯ]/g, '').toUpperCase().split(/[^A-Z]+/).filter(Boolean);
  if (words.length === 0) return '';
  return words.length === 1 ? words[0]!.slice(0, 2) : words.slice(0, 4).map((w) => w[0]).join('');
}

const PROJECT_FIELDS = { name: 'p-name', slug: 'p-slug', ticket_prefix: 'p-prefix', description: 'p-desc' };

/**
 * Step 1 (prototype onboarding.html step1): name, slug, ticket prefix, optional description. Slug and prefix follow the
 * name until edited. States: empty, errors (summary + per field, "already taken" from the server), loading (Creating…),
 * created (a project exists: going Back shows it instead of a second form).
 */
function StepProject({ p, project, onCreated, onNext, onSkip }: {
  p: Props; project: Created | null; onCreated: (pr: Created) => void; onNext: () => void; onSkip: () => void;
}) {
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [prefix, setPrefix] = useState('');
  const [touched, setTouched] = useState({ slug: false, prefix: false });
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const summary = useRef<HTMLDivElement>(null);
  const off = p.createProjectUrl === null;

  const onName = (v: string) => {
    setName(v);
    if (!touched.slug) setSlug(slugify(v));
    if (!touched.prefix) setPrefix(prefixFor(v));
  };

  const fail = (errs: Record<string, string>) => {
    setErrors(errs);
    requestAnimationFrame(() => summary.current?.focus());
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (off) return;
    const local: Record<string, string> = {};
    if (name.trim() === '') local.name = 'Give the project a name.';
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug.trim())) local.slug = slug.trim() === '' ? 'Choose a slug for the board address.' : 'Use lowercase letters, digits and single dashes (e.g. garden-planner).';
    if (!/^[A-Z][A-Z0-9]{0,7}$/.test(prefix.trim())) local.ticket_prefix = prefix.trim() === '' ? 'Choose a ticket prefix.' : 'Use 1–8 capital letters or digits, starting with a letter (e.g. GP).';
    if (Object.keys(local).length) { fail(local); return; }
    setBusy(true);
    const res = await requestJson<{ data?: Created }>('POST', p.createProjectUrl!, {
      name: name.trim(), slug: slug.trim(), ticket_prefix: prefix.trim(), description: desc.trim() || null,
    });
    setBusy(false);
    if (!res.ok || !res.data.data) {
      fail(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? 'Something went wrong. Try again.' });
      return;
    }
    setErrors({});
    onCreated(res.data.data);
  };

  if (project !== null) {
    return (
      <section className="card" aria-labelledby="h-s1">
        <h2 id="h-s1" tabIndex={-1}>Create your first project</h2>
        <Callout kind="ok" status><span className="t">{project.name} is created.</span> It has its own card on your Portfolio and its own board. Add more projects later.</Callout>
        <div className="cardfoot">
          <Link className="abtn" href={`/projects/${project.slug}`}>Open {project.name}</Link>
          <button type="button" className="btn" onClick={onNext}>Continue to the board</button>
        </div>
      </section>
    );
  }

  const fieldErr = (k: string) => errors[k];

  return (
    <section className="card" aria-labelledby="h-s1">
      <h2 id="h-s1" tabIndex={-1}>Create your first project</h2>
      <p className="sub">A project gets its own card on your Portfolio and its own board.</p>
      {off && (
        <Callout kind="info" style={{ marginBottom: 18 }}>
          <span className="t">Creating projects here isn't switched on yet.</span> It arrives with the next Bridge update. You can already connect your bot (step 3).
        </Callout>
      )}
      <ErrorSummary ref={summary} errors={errors} fieldIds={PROJECT_FIELDS} what="create the project" level={3} />
      <form className="form2" onSubmit={submit} noValidate aria-busy={busy || undefined}>
        <Field id="p-name" label="Project name" className="full" error={fieldErr('name')}>
          <input className="qin" id="p-name" name="name" autoComplete="off" required value={name} disabled={off || busy} placeholder="e.g. Garden planner" maxLength={255}
            onChange={(e) => onName(e.target.value)} {...described('p-name', fieldErr('name'))} />
        </Field>
        <Field id="p-slug" label="Slug" error={fieldErr('slug')} hint={<>The board's address: /projects/<b>{slug || 'garden-planner'}</b></>}>
          <input className="qin" id="p-slug" name="slug" autoComplete="off" required value={slug} disabled={off || busy} spellCheck={false} maxLength={64}
            onChange={(e) => { setSlug(e.target.value.toLowerCase()); setTouched((t) => ({ ...t, slug: true })); }} {...described('p-slug', fieldErr('slug'), true)} />
        </Field>
        <Field id="p-prefix" label="Ticket prefix" error={fieldErr('ticket_prefix')} hint={<>Ticket keys look like <b>{prefix || 'GP'}-1</b>. Up to 8 letters or digits.</>}>
          <input className="qin" id="p-prefix" name="ticket_prefix" autoComplete="off" required value={prefix} disabled={off || busy} spellCheck={false} maxLength={8}
            style={{ textTransform: 'uppercase' }}
            onChange={(e) => { setPrefix(e.target.value.toUpperCase()); setTouched((t) => ({ ...t, prefix: true })); }} {...described('p-prefix', fieldErr('ticket_prefix'), true)} />
        </Field>
        <Field id="p-desc" label={<>Short description <span className="dim2">(optional)</span></>} className="full" error={fieldErr('description')}
          hint="One line about what it is. It shows under the name on the board.">
          <textarea className="qta" id="p-desc" name="description" style={{ minHeight: 88 }} value={desc} disabled={off || busy} maxLength={500}
            onChange={(e) => setDesc(e.target.value)} {...described('p-desc', fieldErr('description'), true)} />
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

/** Step 2: rename the columns of the project from step 1 (PUT /projects/{slug}/column-labels). */
function StepBoard({ p, project, justCreated, onBack, onNext }: {
  p: Props; project: Created | null; justCreated: boolean; onBack: () => void; onNext: () => void;
}) {
  const [labels, setLabels] = useState<Record<string, string>>(() => Object.fromEntries(p.columns.map((c) => [c.key, c.label])));
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const url = project?.column_labels_url ?? p.saveColumnsUrl;
  const editable = url !== null;
  const defaultOf = (k: string) => p.defaultColumns.find((d) => d.key === k)?.label ?? k;

  const save = async () => {
    if (!editable) { onNext(); return; }
    const local = checkLabels(labels);
    setErrors(local);
    if (Object.keys(local).length) { document.getElementById(`col-${Object.keys(local)[0]}`)?.focus(); return; }
    setBusy(true);
    const res = await saveColumnLabels(url!, Object.fromEntries(Object.entries(labels).map(([k, v]) => [k, v.trim()])));
    setBusy(false);
    if (res.ok) onNext();
    else setErrors(res.errors);
  };

  const projectName = project?.name ?? 'your project';
  return (
    <section className="card" aria-labelledby="h-s2">
      <h2 id="h-s2" tabIndex={-1}>Set up its board</h2>
      {justCreated && project && <Callout kind="ok" status style={{ marginBottom: 14 }}><span className="t">{project.name} is created.</span> Now name its columns.</Callout>}
      <p className="sub">The columns of {projectName}. {editable ? 'Rename them now or any time from the board (Column labels).' : 'Create the project first (step 1) to rename them.'}</p>
      {errors.form && <Callout kind="bad" alert>{errors.form}</Callout>}
      <div className="colprev c6" role="list" aria-label="Board columns">
        {p.columns.map((c, i) => (
          <div className="col" role="listitem" key={c.key}>
            <div className="colh">
              <span className={`dot c-${c.key}`} aria-hidden="true" />
              {editable ? (
                <>
                  <label className="sr-only" htmlFor={`col-${c.key}`}>Column {i + 1} name (default {defaultOf(c.key)})</label>
                  <input id={`col-${c.key}`} value={labels[c.key] ?? ''} maxLength={LABEL_MAX} disabled={busy}
                    aria-invalid={errors[c.key] ? true : undefined} aria-describedby={errors[c.key] ? `col-${c.key}-err` : undefined}
                    onChange={(e) => setLabels((cur) => ({ ...cur, [c.key]: e.target.value }))} />
                </>
              ) : <span style={{ padding: '4px 6px' }}>{c.label}</span>}
              <span className="cnt">0</span>
            </div>
            {errors[c.key] && <p className="ferr" id={`col-${c.key}-err`}>{errors[c.key]}</p>}
            <div className="ghosttk" aria-hidden="true" />
          </div>
        ))}
      </div>
      <p className="fhint">Select a column name to rename it (up to {LABEL_MAX} characters). Tickets your bot creates land in the first column.</p>
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
