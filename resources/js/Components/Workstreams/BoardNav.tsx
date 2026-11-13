/**
 * Board workstream navigation (README §1.3; frames 73:176, 73:584, 73:817).
 * Desktop: "WORKSTREAM" + tabs (custom order, then the default, then "+") and "⋯ {Workstream} actions" on the right.
 * Phone: a workstream select under the header with an "N others" dot; it opens a bottom sheet of all workstreams.
 * Writes are optimistic: the local rows change first and roll back with an error toast when the save fails.
 */
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Sheet, useToasts } from '../Kanban/Bits';
import { send } from '../../lib/kanban';
import { t, tn } from '../../lib/strings-workstreams';
import { boardUrl, othersWithChanges, type WorkstreamRow } from '../../lib/workstreams';
import { DeleteWorkstreamDialog, errorText, NameDialog } from './Dialogs';
import { ActionMenu, CountBadge, IcDown, IcEdit, IcPlus, IcRight, IcStar, IcTrash, IcUp, type MenuItem } from './Ui';

type Dialog = { kind: 'add' } | { kind: 'rename'; ws: WorkstreamRow } | { kind: 'delete'; ws: WorkstreamRow } | null;

export function BoardWorkstreamNav({ slug, projectName, rows: initial, current, readOnly, mobile }: {
  slug: string; projectName: string; rows: WorkstreamRow[]; current: string; readOnly: boolean; mobile: boolean;
}) {
  const toasts = useToasts();
  const [rows, setRows] = useState(initial);
  const pending = useRef(0);
  useEffect(() => { if (pending.current === 0) setRows(initial); }, [initial]);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [sheet, setSheet] = useState(false);
  const cur = rows.find((w) => w.id === current) ?? rows[0];
  const others = othersWithChanges(rows, current);

  const mutate = useCallback(async (change: (r: WorkstreamRow[]) => WorkstreamRow[], save: () => Promise<unknown>, done?: string, rethrow = false) => {
    let before: WorkstreamRow[] = [];
    setRows((r) => { before = r; return change(r); });
    pending.current++;
    try {
      await save();
      pending.current--;
      if (done) toasts.push({ body: done });
      router.reload({ only: ['workstreams'] } as never);
    } catch (e) {
      pending.current--;
      setRows(before);
      if (rethrow) throw e;
      toasts.push({ body: errorText(e), tone: 'err' });
    }
  }, [toasts]);

  if (!cur) return null;
  const custom = rows.filter((w) => !w.isDefault);
  const i = custom.findIndex((w) => w.id === cur.id);
  const reorder = (direction: 'up' | 'down') => mutate((list) => {
    const a = list.findIndex((w) => w.id === cur.id), b = direction === 'up' ? a - 1 : a + 1;
    if (b < 0 || b >= list.length || list[b].isDefault) return list;
    const next = [...list];
    [next[a], next[b]] = [next[b], next[a]];
    return next;
  }, () => send('POST', `/projects/${slug}/workstreams/${cur.id}/reorder`, { direction }));
  const items: MenuItem[] = [
    { label: t('fd.menu.rename'), icon: <IcEdit />, onSelect: () => setDialog({ kind: 'rename', ws: cur }) },
    ...(!cur.isDefault ? [
      { label: t('fd.menu.moveUp'), icon: <IcUp />, disabled: i <= 0, onSelect: () => reorder('up') },
      { label: t('fd.menu.moveDown'), icon: <IcDown />, disabled: i >= custom.length - 1, onSelect: () => reorder('down') },
      { label: t('fd.menu.makeDefault'), icon: <IcStar />, onSelect: () => mutate((list) => list.map((w) => ({ ...w, isDefault: w.id === cur.id })), () => send('POST', `/projects/${slug}/workstreams/${cur.id}/default`)) },
    ] : []),
    'sep',
    { label: t('fd.menu.deleteWs'), icon: <IcTrash />, danger: true, onSelect: () => setDialog({ kind: 'delete', ws: cur }) },
  ];

  const dialogs = (
    <>
      {dialog?.kind === 'add' && (
        <NameDialog title={t('dlg.newWs.title')} label={t('dlg.newWs.label')} submitLabel={t('dlg.newWs.submit')} onClose={() => setDialog(null)}
          onSubmit={async (name) => {
            const r = await send<{ data: { id: string } }>('POST', `/projects/${slug}/workstreams`, { name });
            setDialog(null);
            router.visit(boardUrl(slug, r.data.id));
          }} />
      )}
      {dialog?.kind === 'rename' && (
        <NameDialog title={t('dlg.renameWs.title')} label={t('dlg.newWs.label')} initial={dialog.ws.name} onClose={() => setDialog(null)}
          onSubmit={async (name) => {
            await mutate((list) => list.map((w) => (w.id === dialog.ws.id ? { ...w, name } : w)),
              () => send('PATCH', `/projects/${slug}/workstreams/${dialog.ws.id}`, { name }), t('toast.renamedWs', { name }), true);
            setDialog(null);
          }} />
      )}
      {dialog?.kind === 'delete' && (
        <DeleteWorkstreamDialog ws={dialog.ws} others={rows.filter((w) => w.id !== dialog.ws.id)} projectName={projectName} onClose={() => setDialog(null)}
          onSubmit={async (body) => {
            const r = await send<{ data: { target: { id: string } | null } }>('DELETE', `/projects/${slug}/workstreams/${dialog.ws.id}`, body);
            setDialog(null);
            toasts.push({ body: t('toast.deletedWs', { name: dialog.ws.name }) });
            const next = r.data.target?.id ?? rows.find((w) => w.id !== dialog.ws.id)?.id;
            router.visit(next ? boardUrl(slug, next) : `/projects/${slug}`);
          }} />
      )}
    </>
  );

  if (mobile) {
    return (
      <>
        <button type="button" className="kb-wsselect" aria-haspopup="dialog" onClick={() => setSheet(true)}>
          <span className="lbl">{t('bd.select.label')}</span>
          <span className="nm">{cur.name}</span>
          {others > 0 && <span className="kb-others" aria-label={t('bd.others.aria', { n: others })}><i aria-hidden="true" />{tn(others, 'bd.others.one', 'bd.others.many')}</span>}
          <IcDown />
        </button>
        {sheet && (
          <Sheet label={t('bd.sheet.title')} className="kb-wssheet" onClose={() => setSheet(false)}>
            <div className="shh">
              <div><h3>{t('bd.sheet.title')}</h3><p className="sub2">{t('bd.sheet.sub', { project: projectName, n: rows.length })}</p></div>
              {!readOnly && <button type="button" className="fd-sq" aria-label={t('bd.addWs')} onClick={() => { setSheet(false); setDialog({ kind: 'add' }); }}><IcPlus /></button>}
            </div>
            <ul className="fd-list">
              {rows.map((w) => (
                <li key={w.id}>
                  <Link href={boardUrl(slug, w.id)} className={`fd-wrow${w.id === cur.id ? ' on' : ''}`} aria-current={w.id === cur.id ? 'page' : undefined} onClick={() => setSheet(false)}>
                    <span className="fd-rtext">
                      <span className="fd-rname">{w.name}{w.isDefault && <span className="fd-chip">{t('fd.ws.default')}</span>}</span>
                      <span className="fd-rmeta">{[tn(w.tickets, 'fd.ws.tickets.one', 'fd.ws.tickets.many'), w.forYou ? t('fd.ws.forYou', { n: w.forYou }) : null].filter(Boolean).join(' · ')}</span>
                    </span>
                    {!readOnly && w.id !== cur.id && <CountBadge n={w.badge} />}
                    <span className="fd-chev"><IcRight /></span>
                  </Link>
                </li>
              ))}
            </ul>
            {!readOnly && (
              <div className="shf">
                <button type="button" className="abtn" onClick={() => { setSheet(false); setDialog({ kind: 'rename', ws: cur }); }}>{t('bd.sheet.rename', { name: cur.name })}</button>
                <button type="button" className="gbtn" onClick={() => { setSheet(false); setDialog({ kind: 'delete', ws: cur }); }}>{t('bd.sheet.delete')}</button>
              </div>
            )}
          </Sheet>
        )}
        {dialogs}
      </>
    );
  }

  return (
    <div className="kb-wsnav">
      <span className="kb-wslabel" id="kb-wslabel">{t('bd.workstream')}</span>
      <nav className="kb-tabs" aria-labelledby="kb-wslabel">
        {rows.map((w) => (
          <Link key={w.id} href={boardUrl(slug, w.id)} className="kb-tab" aria-current={w.id === cur.id ? 'page' : undefined} preserveScroll>
            {w.name}{!readOnly && w.id !== cur.id && <CountBadge n={w.badge} />}
          </Link>
        ))}
        {!readOnly && <button type="button" className="kb-tabadd" aria-label={t('bd.addWs')} onClick={() => setDialog({ kind: 'add' })}><IcPlus /></button>}
      </nav>
      {!readOnly && <ActionMenu className="kb-wsact" label={t('bd.actions', { name: cur.name })} buttonText={t('bd.actions', { name: cur.name })} items={items} />}
      {dialogs}
    </div>
  );
}
