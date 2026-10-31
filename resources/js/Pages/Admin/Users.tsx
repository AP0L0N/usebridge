import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Modal, useToasts } from '../../Components/Account/Overlay';
import { TopBar } from '../../Components/TopBar';
import { initials, type AdminUser } from '../../lib/account';
import { longDate, updateTime } from '../../lib/dates';
import { requestJson } from '../../lib/http';

type Props = { users: AdminUser[]; meId: number };

const STATUS: Record<AdminUser['status'], { label: string; cls: string }> = {
  active: { label: 'Active', cls: 'usr' },
  disabled: { label: 'Disabled', cls: 'off' },
  deletion_pending: { label: 'Deletion pending', cls: 'off' },
};

/**
 * Admin · Users (owner only; RequireAdmin 404s everyone else): name, email, created, last login, status and
 * disable / enable. Nothing from another workspace: no project or agent counts (relayed decision 2), no invited state.
 */
export default function Users({ users: initial, meId }: Props) {
  const [users, setUsers] = useState(initial);
  const [confirm, setConfirm] = useState<AdminUser | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const { push, region } = useToasts();
  const now = new Date().toISOString();
  const active = users.filter((u) => u.status === 'active').length;
  const disabled = users.filter((u) => u.status === 'disabled').length;

  const act = async (u: AdminUser, action: 'disable' | 'enable') => {
    setBusy(u.id);
    const res = await requestJson<{ data: AdminUser }>('POST', `/admin/users/${u.id}/${action}`);
    setBusy(null);
    setConfirm(null);
    if (!res.ok) {
      push({ text: res.data.message ?? `Could not ${action} ${u.name}.`, error: true });
      return;
    }
    setUsers((cur) => cur.map((x) => (x.id === u.id ? res.data.data : x)));
    push(action === 'disable'
      ? { text: `${u.name} is disabled`, sub: 'Logged out everywhere; their API tokens are blocked until you enable them.' }
      : { text: `${u.name} is enabled`, sub: 'They can log in again and their API tokens work.' });
  };

  return (
    <>
      <Head title="Admin · Users" />
      <TopBar />
      <main className="wrap">
        <div className="dhead">
          <div>
            <h1>Admin · Users</h1>
            <p className="summary"><b>{users.length}</b> {users.length === 1 ? 'user' : 'users'} · <b>{active}</b> active · <b>{disabled}</b> disabled</p>
          </div>
        </div>
        <section aria-labelledby="h-users">
          <h2 className="sect" id="h-users" style={{ marginTop: 0 }}>Users <span className="cnt">{users.length}</span></h2>
          <div className="card">
            <div className="tscroll">
              <table className="tbl stack">
                <caption className="sr-only">Everyone with a Bridge account. Disabling blocks sign-in and API tokens; nothing is deleted.</caption>
                <thead><tr>
                  <th scope="col">User</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col">Last login</th><th scope="col"><span className="sr-only">Action</span></th>
                </tr></thead>
                <tbody>
                  {users.map((u) => (
                    <tr key={u.id}>
                      <th scope="row"><span className="who3"><span className="avi" aria-hidden="true">{initials(u.name)}</span><span>{u.name}<small>{u.email}</small></span></span></th>
                      <td className="l" data-label="Status">{u.id === meId ? <span className="role own">Owner</span> : <span className={`role ${STATUS[u.status].cls}`}>{STATUS[u.status].label}</span>}</td>
                      <td data-label="Created">{longDate(u.created_at)}</td>
                      <td data-label="Last login">{u.last_login_at ? updateTime(u.last_login_at, now) : <span className="dim2">Never</span>}</td>
                      <td className="act">
                        {u.id === meId ? <span className="dim2">You</span>
                          : u.status === 'disabled'
                            ? <button type="button" className="abtn" aria-label={`Enable ${u.name}`} disabled={busy === u.id} onClick={() => act(u, 'enable')}>{busy === u.id ? 'Enabling…' : 'Enable'}</button>
                            : <button type="button" className="abtn bad" aria-label={`Disable ${u.name}`} disabled={busy === u.id} onClick={() => setConfirm(u)}>Disable</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="foot">Disabling logs the person out everywhere and blocks their API tokens. Their projects and usage stay, and you can't see them; enable them again at any time.</p>
          </div>
        </section>
      </main>
      {confirm && (
        <Modal title={`Disable ${confirm.name}?`} onClose={() => setConfirm(null)} alert describedBy="d-dis"
          footer={<><button type="button" className="abtn" onClick={() => setConfirm(null)} data-autofocus>Cancel</button><button type="button" className="btn danger" disabled={busy !== null} onClick={() => act(confirm, 'disable')}>{busy ? 'Disabling…' : 'Disable'}</button></>}>
          <p id="d-dis">{confirm.email} is logged out on every device and their bots' tokens stop working. Nothing is deleted; enable them to undo.</p>
        </Modal>
      )}
      {region}
    </>
  );
}
