import { Head, Link } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Badge } from '../Components/Auth/Badge';
import { Button, ButtonLink } from '../Components/Auth/Button';
import { Notice, TextField } from '../Components/Auth/TextField';
import { LABEL_MAX, saveColumnLabels } from '../Components/Kanban/ColumnLabels';
import { fillPlaceholders } from '../Components/SiteFooter';
import { TopBar } from '../Components/TopBar';
import { t } from '../lib/authCopy';
import { fieldErrors, requestJson } from '../lib/http';
import type { OnboardingCopy } from '../lib/onboardingCopy';

type Column = { key: string; label: string; order: number };
type Props = {
  name: string;
  project: { name: string; slug: string } | null;
  hasToken: boolean;
  emailVerified: boolean;
  /** The first project's columns (its own labels), else the defaults; BoardColumns order. */
  columns: Column[];
  defaultColumns: Column[];
  /** POST /projects; null would mean web creation is off (the form says so). */
  createProjectUrl: string | null;
  /** PUT /projects/{slug}/column-labels of the first project; null until there is one (step 1's answer brings it). */
  saveColumnsUrl: string | null;
  /** The MCP server on APP_URL (server-built). */
  mcpUrl: string;
  tokensUrl: string;
  portfolioUrl: string;
  copy: OnboardingCopy;
};

type Step = 1 | 2 | 3;
type Created = { name: string; slug: string; column_labels_url?: string };

/** The column bots move a ticket to when they need the user (drawn outlined, "Core column"). */
const CORE = 'waiting_tilen';

/**
 * Onboarding (Figma "Onboarding" step 1–3) inside the app shell: header + stepper, then one card per step. State is
 * derived: no project = step 1, else step 2. Done = the Portfolio with ?ready={slug} (banner + connect card).
 */
export default function Onboarding(p: Props) {
  const c = p.copy;
  const [step, setStep] = useState<Step>(p.project === null ? 1 : 2);
  const [project, setProject] = useState<Created | null>(p.project);
  const goStep = (s: Step) => {
    setStep(s);
    requestAnimationFrame(() => document.getElementById(`onb-h${s}`)?.focus());
  };
  const firstName = p.name.trim().split(/\s+/)[0] || p.name;

  return (
    <>
      <Head title={c.title} />
      <TopBar />
      <main className="onb">
        <div className="onb-col">
          <header className="onb-head">
            <p className="onb-overline">{t(c.overline, { n: step })}</p>
            <h1>{t(c.heading, { name: firstName })}</h1>
            <p className="onb-subline">{c.subline}</p>
          </header>
          <Stepper step={step} copy={c} />
          {step === 1 && <StepProject p={p} project={project} onCreated={(pr) => { setProject(pr); goStep(2); }} onNext={() => goStep(2)} onSkip={() => goStep(3)} />}
          {step === 2 && <StepBoard p={p} project={project} onBack={() => goStep(1)} onNext={() => goStep(3)} />}
          {step === 3 && <StepBot p={p} project={project} />}
        </div>
      </main>
    </>
  );
}

/** Project — Board — Connect your bot: done = ink check, current = lime fill, next = outline. Narrow: only the current label. */
function Stepper({ step, copy }: { step: Step; copy: OnboardingCopy }) {
  return (
    <ol className="onb-stepper" aria-label={copy.stepper.label}>
      {copy.stepper.steps.map((label, i) => {
        const n = i + 1;
        const state = n < step ? 'done' : n === step ? 'current' : 'next';
        return (
          <li key={n} className={`onb-step is-${state}`} aria-current={state === 'current' ? 'step' : undefined}>
            {i > 0 && <span className={`onb-hair${n <= step ? ' is-on' : ''}`} aria-hidden="true" />}
            <span className="onb-dot" aria-hidden="true">{state === 'done' ? '✓' : n}</span>
            <span className="onb-step-label">{label}</span>
            <span className="sr-only"> {state === 'current' ? copy.stepper.current : state === 'done' ? copy.stepper.done : ''}</span>
          </li>
        );
      })}
    </ol>
  );
}

function Card({ n, title, sub, tag, children }: { n: Step; title: string; sub: string; tag?: string; children: React.ReactNode }) {
  return (
    <section className="onb-card" aria-labelledby={`onb-h${n}`}>
      <div className="onb-card-head">
        {tag && <p className="onb-tag"><span className="onb-tag-dot" aria-hidden="true" />{tag}</p>}
        <h2 id={`onb-h${n}`} tabIndex={-1}>{title}</h2>
        <p className="onb-sub">{sub}</p>
      </div>
      {children}
    </section>
  );
}

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

const FIELD_ORDER = ['name', 'slug', 'ticket_prefix', 'description'];
const FIELD_IDS: Record<string, string> = { name: 'p-name', slug: 'p-slug', ticket_prefix: 'p-prefix', description: 'p-desc' };

/**
 * Step 1: name, optional description, the lead (always "You": web projects have no PM choice). Slug and ticket prefix
 * follow the name and show as one line; "Change" opens the two fields, and so does an error on either (e.g. the
 * globally-unique "already taken"). A created project shows instead of a second form (Back from step 2).
 */
function StepProject({ p, project, onCreated, onNext, onSkip }: {
  p: Props; project: Created | null; onCreated: (pr: Created) => void; onNext: () => void; onSkip: () => void;
}) {
  const c = p.copy.project;
  const e = p.copy.errors;
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [prefix, setPrefix] = useState('');
  const [touched, setTouched] = useState({ slug: false, prefix: false });
  const [open, setOpen] = useState(false);
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const off = p.createProjectUrl === null;

  if (project !== null) {
    return (
      <Card n={1} title={c.title} sub={c.sub}>
        <Notice kind="ok" role="status"><b>{t(c.created, { name: project.name })}</b> {c.created_body}</Notice>
        <div className="onb-actions">
          <Button type="button" onClick={onNext}>{c.continue}</Button>
        </div>
      </Card>
    );
  }

  const onName = (v: string) => {
    setName(v);
    if (!touched.slug) setSlug(slugify(v));
    if (!touched.prefix) setPrefix(prefixFor(v));
  };

  const fail = (errs: Record<string, string>) => {
    setErrors(errs);
    if (errs.slug || errs.ticket_prefix) setOpen(true);
    const first = FIELD_ORDER.find((k) => errs[k]);
    requestAnimationFrame(() => document.getElementById(first ? FIELD_IDS[first]! : 'onb-h1')?.focus());
  };

  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    if (off) return;
    const local: Record<string, string> = {};
    if (name.trim() === '') local.name = e.name_required;
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug.trim())) local.slug = slug.trim() === '' ? e.slug_required : e.slug_regex;
    if (!/^[A-Z][A-Z0-9]{0,7}$/.test(prefix.trim())) local.ticket_prefix = prefix.trim() === '' ? e.prefix_required : e.prefix_regex;
    if (Object.keys(local).length) { fail(local); return; }
    setBusy(true);
    const res = await requestJson<{ data?: Created }>('POST', p.createProjectUrl!, {
      name: name.trim(), slug: slug.trim(), ticket_prefix: prefix.trim(), description: desc.trim() || null,
    });
    setBusy(false);
    if (!res.ok || !res.data.data) {
      fail(res.status === 422 ? fieldErrors(res.data.errors) : { form: res.data.message ?? e.generic });
      return;
    }
    setErrors({});
    onCreated(res.data.data);
  };

  const key = `${prefix || 'GP'}-1`;
  return (
    <Card n={1} title={c.title} sub={c.sub}>
      {off && <Notice kind="info">{c.off}</Notice>}
      {errors.form && <Notice kind="risk" role="alert">{errors.form}</Notice>}
      <form className="onb-form" onSubmit={submit} noValidate aria-busy={busy || undefined}>
        <TextField id="p-name" name="name" label={c.name} autoComplete="off" required value={name} disabled={off || busy} placeholder={c.name_placeholder}
          maxLength={255} error={errors.name} onChange={(ev) => onName(ev.target.value)}
          below={!open && (
            <p className="onb-address">
              <span>{fillPlaceholders(c.address, { slug: <b>/projects/{slug || 'garden-planner'}</b>, key: <b>{key}</b> })}</span>
              <button type="button" className="onb-linkbtn" aria-expanded={false} aria-controls="p-address" onClick={() => setOpen(true)} disabled={off || busy}>{c.address_change}</button>
            </p>
          )} />
        {open && (
          <div className="onb-pair" id="p-address">
            <TextField id="p-slug" name="slug" label={c.slug} autoComplete="off" required value={slug} disabled={off || busy} spellCheck={false} maxLength={64}
              error={errors.slug} hint={t(c.slug_help, { slug: slug || 'garden-planner' })}
              onChange={(ev) => { setSlug(ev.target.value.toLowerCase()); setTouched((x) => ({ ...x, slug: true })); }} />
            <TextField id="p-prefix" name="ticket_prefix" label={c.prefix} autoComplete="off" required value={prefix} disabled={off || busy} spellCheck={false}
              maxLength={8} error={errors.ticket_prefix} hint={t(c.prefix_help, { key })} className="onb-prefix"
              onChange={(ev) => { setPrefix(ev.target.value.toUpperCase()); setTouched((x) => ({ ...x, prefix: true })); }} />
          </div>
        )}
        <TextField id="p-desc" name="description" label={c.description} autoComplete="off" value={desc} disabled={off || busy} placeholder={c.description_placeholder}
          maxLength={500} error={errors.description} hint={c.description_help} onChange={(ev) => setDesc(ev.target.value)} />
        <TextField id="p-lead" label={c.lead} value={c.lead_you} readOnly hint={c.lead_help} className="onb-readonly" />
        <div className="onb-actions">
          {off
            ? <Button type="button" onClick={onSkip}>{c.skip_to_bot}</Button>
            : <Button busy={busy} busyLabel={c.busy}>{c.submit}</Button>}
        </div>
      </form>
    </Card>
  );
}

/**
 * Step 2: the board's columns in BoardColumns order (01…), each renamed inline; Save board sends them to
 * PUT /projects/{slug}/column-labels. The waiting column is drawn outlined with a "Core column" badge. No "Add column"
 * or drag handle: the column set and its order are fixed (BoardColumns).
 */
function StepBoard({ p, project, onBack, onNext }: { p: Props; project: Created | null; onBack: () => void; onNext: () => void }) {
  const c = p.copy.board;
  const e = p.copy.errors;
  const columns = [...p.columns].sort((a, b) => a.order - b.order);
  const [labels, setLabels] = useState<Record<string, string>>(() => Object.fromEntries(columns.map((col) => [col.key, col.label])));
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const url = project?.column_labels_url ?? p.saveColumnsUrl;
  const editable = url !== null;
  const defaultOf = (k: string) => p.defaultColumns.find((d) => d.key === k)?.label ?? k;
  const shown = (k: string) => labels[k]?.trim() || defaultOf(k);

  const save = async () => {
    if (!editable) { onNext(); return; }
    const local: Record<string, string> = {};
    for (const [k, v] of Object.entries(labels)) {
      if (v.trim() === '') local[k] = e.column_required;
      else if (v.trim().length > LABEL_MAX) local[k] = t(e.column_max, { max: LABEL_MAX });
    }
    setErrors(local);
    if (Object.keys(local).length) { document.getElementById(`col-${columns.find((col) => local[col.key])?.key}`)?.focus(); return; }
    setBusy(true);
    const res = await saveColumnLabels(url!, Object.fromEntries(Object.entries(labels).map(([k, v]) => [k, v.trim()])));
    setBusy(false);
    if (res.ok) onNext();
    else setErrors(res.errors);
  };

  return (
    <Card n={2} title={c.title} sub={c.sub}>
      {!editable && <Notice kind="info">{c.no_project}</Notice>}
      {errors.form && <Notice kind="risk" role="alert">{errors.form}</Notice>}
      <ol className="onb-cols" aria-label={c.list_label}>
        {columns.map((col, i) => {
          const core = col.key === CORE;
          const err = errors[col.key];
          const num = String(i + 1).padStart(2, '0');
          return (
            <li key={col.key}>
              <div className={`onb-colrow${core ? ' is-core' : ''}${err ? ' is-error' : ''}`}>
                {editable ? (
                  <>
                    <label className="sr-only" htmlFor={`col-${col.key}`}>{t(c.rename_label, { n: i + 1, default: defaultOf(col.key) })}</label>
                    <input id={`col-${col.key}`} value={labels[col.key] ?? ''} maxLength={LABEL_MAX} disabled={busy} autoComplete="off"
                      aria-invalid={err ? true : undefined} aria-describedby={err ? `col-${col.key}-err` : undefined}
                      onChange={(ev) => setLabels((cur) => ({ ...cur, [col.key]: ev.target.value }))} />
                  </>
                ) : <span className="onb-colname">{col.label}</span>}
                {core && <Badge kind="signal">{c.core}</Badge>}
                <span className="onb-colnum" aria-hidden="true">{num}</span>
              </div>
              {err && <p className="auth-msg auth-msg--error" id={`col-${col.key}-err`}><span className="auth-dot" aria-hidden="true" />{err}</p>}
            </li>
          );
        })}
      </ol>
      <p className="onb-help">{t(c.help, { first: shown(columns[0]?.key ?? ''), waiting: shown(CORE) })}</p>
      <div className="onb-actions onb-actions--split">
        <Button type="button" variant="secondary" onClick={onBack} disabled={busy}>{c.back}</Button>
        <Button type="button" busy={busy} busyLabel={c.busy} onClick={save}>{editable ? c.save : c.continue}</Button>
      </div>
    </Card>
  );
}

/** Step 3 (optional): the three violet steps with the server-built MCP URL; Create a token -> the tokens page. */
function StepBot({ p, project }: { p: Props; project: Created | null }) {
  const c = p.copy.bot;
  const done = project ? `${p.portfolioUrl}?ready=${encodeURIComponent(project.slug)}` : p.portfolioUrl;
  const vars = [{}, { url: <span className="onb-url">{p.mcpUrl}</span> }, { project: project?.name ?? c.your_projects }];
  return (
    <Card n={3} title={c.title} sub={c.sub} tag={c.tag}>
      {!p.emailVerified && <Notice kind="warn">{c.verify}</Notice>}
      <ol className="onb-botsteps">
        {c.steps.map((s, i) => (
          <li key={i}>
            <span className="onb-botn" aria-hidden="true">{i + 1}</span>
            <div>
              <h3>{s.title}</h3>
              <p>{fillPlaceholders(s.body, vars[i] ?? {})}</p>
            </div>
          </li>
        ))}
      </ol>
      <div className="onb-actions onb-actions--split">
        <Link className="auth-btn auth-btn--ghost" href={done}>{c.skip}</Link>
        <ButtonLink href={p.tokensUrl}>{c.create_token}</ButtonLink>
      </div>
      <p className="onb-help">{c.later}</p>
    </Card>
  );
}
