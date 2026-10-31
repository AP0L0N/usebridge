from lib import *

USERS = [
    ('MN', 'Mia Novak', 'mia@example.com', 'owner', 3, 3, 'Today, 09:02', 'You'),
    ('SR', 'Sam Rivera', 'sam@example.com', 'user', 6, 6, 'Today, 08:10', 'Disable'),
    ('AK', 'Alex Kim', 'alex@example.com', 'user', 1, 1, 'Yesterday, 18:30', 'Disable'),
    ('JL', 'Jordan Lee', 'jordan@example.com', 'off', 2, 0, 'Mon 12 Oct', 'Enable'),
    ('PS', 'Priya Shah', 'priya@example.com', 'user', 0, 0, 'Never · invited Fri 30 Oct', 'Disable'),
]


def users_table():
    role = {'owner': '<span class="role-chip">Owner</span>', 'user': '<span class="chip">User</span>', 'off': '<span class="chip l-rose">Disabled</span>'}
    rows = ''
    for ini, name, mail, r, pr, ag, last, act in USERS:
        if act == 'You':
            a = '<span class="muted">You</span>'
        elif act == 'Enable':
            a = '<button type="button" class="btn btn-sm">Enable</button>'
        else:
            a = f'<button type="button" class="btn btn-sm btn-danger-quiet" aria-label="Disable {name}">Disable</button>'
        rows += f'''<tr><th scope="row"><span class="who"><span class="avatar">{ini}</span><span><b>{name}</b><span>{mail}</span></span></span></th>
<td class="l" data-label="Role">{role[r]}</td><td data-label="Projects">{pr}</td><td data-label="Agents">{ag}</td><td data-label="Last active">{last}</td><td data-label="Action">{a}</td></tr>'''
    return f'''<section class="card" aria-labelledby="h-users">
  <div class="card-head"><h2 id="h-users">Users</h2><span class="count">5</span><span class="sub">Everyone with a Bridge account. Disabling blocks sign-in and API tokens; nothing is deleted.</span></div>
  <div class="table-wrap"><table class="table stack">
    <thead><tr><th class="l" scope="col">User</th><th class="l" scope="col">Role</th><th scope="col">Projects</th><th scope="col">Agents</th><th scope="col">Last active</th><th scope="col">Action</th></tr></thead>
    <tbody>{rows}</tbody>
  </table></div>
  <p class="foot">Disabling signs the person out everywhere and stops their API tokens. Their projects and usage stay; you can enable them again.</p>
</section>'''


def build():
    cards = ''.join(pcard(p) for p in [PROJECTS[2], PROJECTS[3], PROJECTS[5]])
    body = f'''{topbar('portfolio')}
<main id="main" class="page">
  <div data-hide="admin">
    <div class="page-head">
      <div><h1 class="title">Portfolio</h1>
        <p class="summary"><b>3</b> active projects · <b>1</b> waiting input<span data-show="owner owner-menu"> · your projects only</span></p></div>
      <div class="tools"><span class="sub">View as</span>
        <div class="seg" role="group" aria-label="View as"><button type="button" data-goto="user">Regular user (Sam)</button><button type="button" data-goto="owner">Owner (Mia)</button></div></div>
    </div>
    <div class="pgrid">{cards}</div>
  </div>
  <div data-show="admin">
    <div class="page-head"><div><p class="crumb" style="margin:0">Admin</p><h1 class="title">Users</h1><p class="summary"><b>5</b> users · <b>4</b> active · <b>1</b> disabled</p></div></div>
    {users_table()}
  </div>
  <section class="callout" style="margin-top:var(--s-8)">{ic('info')}<div>
    <p><b>What differs by role.</b> Regular user: nav is Portfolio and Usage only; Portfolio, boards and Usage show only their own projects and agents. <code>/polymarket</code> and <code>/admin</code> respond 404.</p>
    <p>Owner: adds Polymarket and Admin to the nav and an Owner chip in the account menu. Admin lists users with role, projects, agents and last active, and can disable and enable.</p>
    <p>Mobile (≤720px): the nav collapses to the current page ▾ menu left of the theme toggle; the account name and Sample data chip hide, the avatar stays.</p>
  </div></section>
</main>'''
    script = '''window.onProtoState = function (id) {
  var owner = ['owner', 'admin', 'owner-menu'].indexOf(id) >= 0;
  document.querySelector('header.topbar').dataset.active = id === 'admin' ? 'admin' : 'portfolio';
  window.protoRole(owner ? 'owner' : 'user');
  document.querySelectorAll('.seg [data-goto]').forEach(function (b) { b.setAttribute('aria-pressed', String((b.dataset.goto === 'owner') === owner)); });
  if (id === 'user-menu' || id === 'owner-menu') window.protoOpenMenu('.acct');
  if (id === 'mobile-nav') window.protoOpenMenu('.navm');
};'''
    page('shell.html', 'Shell and roles', body,
         states='user:Regular user (Sam)|owner:Owner (Mia)|admin:Admin · Users|user-menu:User account menu open|owner-menu:Owner account menu open|mobile-nav:Mobile nav open (≤720px)',
         script=script)
