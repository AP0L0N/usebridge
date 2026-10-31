from lib import *

LBL = {'Home': 'l-navy', 'Idea': 'l-grey', 'Design': 'l-rose', 'Onboarding': 'l-violet', 'Copy': 'l-amber', 'Frontend': 'l-green',
       'Cart': 'l-navy', 'Backend': 'l-grey', 'Review': 'l-navy', 'QA': 'l-amber', 'Decision': 'l-navy'}
AV = {'J': 'Juno (PM)', 'A': 'Alex Kim', 'S': 'Sam Rivera'}

T = {
    'NP-131': dict(title='Late renewal request in the Home module', labels=['Home', 'Idea']),
    'NP-128': dict(title='Billing page: tax icons on invoice items', labels=['Design'], cmts=1, av='S'),
    'NP-133': dict(title='Reminder to customers 24 h before the link closes', labels=['Onboarding', 'Idea']),
    'NP-127': dict(title='Welcome email: German strings', labels=['Copy', 'Frontend'], flag=('block', 'Blocked · Waiting on Priya’s DE copy'), av='A'),
    'NP-124': dict(title='Cart: Done closes the sheet', labels=['Cart', 'Frontend'], prio='P2', av='A'),
    'NP-129': dict(title='Empty state: no upcoming orders', labels=['Design'], av='S'),
    'NP-118': dict(title='Single-user share-link form', labels=['Onboarding', 'Frontend'], flag=('new', 'New reply from Alex'), prio='P1', due='Fri 30 Oct', cmts=5, av='A'),
    'NP-121': dict(title='First-run intro: 2 direction starts', labels=['Design'], cmts=2, av='S'),
    'NP-122': dict(title='Dashboard module API', labels=['Backend', 'Home'], cmts=1, av='A'),
    'NP-119': dict(title='Sign off the cart flow on staging', labels=['Cart', 'Review'], ask='Review staging and approve, or list fixes.', flag=('input', 'Your input · not picked up yet'), heat=('w2', '4d'), cmts=4, av='J'),
    'NP-115': dict(title='Team invite link: close at first sign-in, or after 48 h?', labels=['Onboarding', 'Decision'], ask='Which default should the team invite link use at launch?', heat=('w1', '1d'), prio='P1', due='Fri 30 Oct', cmts=3, av='J'),
    'NP-116': dict(title='Cart: items always listed (Hide items removed)', labels=['Cart', 'QA'], cmts=2, av='S'),
    'NP-117': dict(title='Landing copy: single-user intro', labels=['Copy'], av='J'),
    'NP-110': dict(title='Multi-user link path /team/invite', labels=['Onboarding'], av='A', done=True),
    'NP-112': dict(title='Review screen: required confirm checkbox', labels=['Onboarding'], cmts=2, av='A', done=True),
    'NP-108': dict(title='Placeholder names in all mocks', labels=['Design'], av='S', done=True),
}
BOARD = [('backlog', ['NP-131', 'NP-128', 'NP-133']), ('todo', ['NP-127', 'NP-124', 'NP-129']), ('progress', ['NP-118', 'NP-121', 'NP-122']),
         ('waiting', ['NP-119', 'NP-115']), ('review', ['NP-116', 'NP-117']), ('done', ['NP-110', 'NP-112', 'NP-108'])]
COLNAME = dict(COLS)
EMPTY_COPY = {'backlog': 'Nothing in the backlog', 'todo': 'Nothing to do', 'progress': 'Nothing in progress', 'review': 'Nothing in review', 'done': 'Nothing done in the last 7 days'}


def tcard(key):
    t = T[key]
    chips = ''.join(f'<span class="chip {LBL[l]}">{l}</span>' for l in t['labels'])
    ask = f'<p class="tcard-ask"><b>Ask:</b> {t["ask"]}</p>' if t.get('ask') else ''
    flag = ''
    if t.get('flag'):
        k, txt = t['flag']
        icon = {'block': ic('block'), 'input': ic('reply'), 'new': ''}[k]
        flag = f'<p class="tcard-flag is-{k}">{icon}{txt}</p>'
    meta = [f'<span class="tid">{key}</span>']
    if t.get('heat'):
        meta.append(heat_chip(*t['heat']))
    if t.get('prio'):
        meta.append(f'<span class="meta-i prio {t["prio"].lower()}">{ic("flag")}{t["prio"]}</span>')
    if t.get('due'):
        meta.append(f'<span class="meta-i due">{ic("cal")}{t["due"]}</span>')
    if t.get('cmts'):
        meta.append(f'<span class="meta-i" aria-label="{t["cmts"]} comments">{ic("cmt")}{t["cmts"]}</span>')
    meta.append(f'<button type="button" class="btn btn-sm move-btn" data-goto="move-sheet">{ic("arrow-right")}Move</button>')
    if t.get('av'):
        meta.append(f'<span class="avatar xs" title="{AV[t["av"]]}" aria-label="Assignee {AV[t["av"]]}">{t["av"]}</span>')
    else:
        meta.append('<span class="avatar xs ghost" title="Unassigned" aria-label="Unassigned">?</span>')
    cls = ' is-done' if t.get('done') else ''
    return f'''<article class="tcard{cls}"><div class="chips">{chips}</div>
<h3 class="tcard-title"><a href="ticket.html">{t["title"]}</a></h3>{ask}{flag}
<div class="tcard-meta">{''.join(meta)}</div></article>'''


def column(key, keys, empty_mode=False, filtering=False):
    name = COLNAME[key]
    n = len(keys)
    sub = ''
    if key == 'waiting':
        sub = '<p class="col-sub">Oldest first · your answers go to the PM</p>'
    if key == 'done':
        sub = '<p class="col-sub">Last 7 days</p>'
    if keys:
        body = ''.join(tcard(k) for k in keys)
    elif filtering:
        body = '<div class="col-empty">No matching tickets</div>'
    elif key == 'waiting':
        body = '<div class="col-empty"><b>Nothing waiting on you</b>The PM moves tickets here when they need your call.</div>'
    else:
        body = f'<div class="col-empty"><b>{EMPTY_COPY[key]}</b>{"" if key == "done" else "Drag a ticket here to start it."}</div>'
    foot = ''
    if key in ('backlog', 'todo') and not filtering:
        foot = f'<button type="button" class="add-ticket">{ic("plus") .replace("<svg", "<svg width=14 height=14")}Add a ticket</button>'
    if key == 'done' and keys and not filtering:
        foot = '<button type="button" class="link show-older">Show 9 older</button>'
    w = ' is-waiting' if key == 'waiting' else ''
    return f'''<section class="col{w}" data-col="{key}" aria-label="{name.replace('<span data-me>', '').replace('</span>', '')}">
<h2 class="col-head"><i class="dot c-{key}" style="background:var(--c)"></i><span>{name}</span><span class="count{' is0' if not n else ''}">{n}</span></h2>{sub}
<div class="col-list">{body}</div>{foot}</section>'''


def tabs(counts, sel='waiting', show=''):
    btns = ''.join(f'<button type="button" role="tab" data-col="{k}" aria-selected="{str(k == sel).lower()}"><span>{COLNAME[k]}</span><span class="count{" is0" if not counts[k] else ""}">{counts[k]}</span></button>' for k, _ in BOARD)
    dots = ''.join('<i></i>' for _ in BOARD)
    return f'<div class="col-tabs" role="tablist" aria-label="Columns"{show}>{btns}</div>', f'<div class="col-dots" aria-hidden="true"{show}>{dots}</div>'


def board(variant='default'):
    if variant == 'filter-result':
        cols = [(k, ['NP-118'] if k == 'progress' else []) for k, _ in BOARD]
        filt = True
    elif variant == 'empty':
        cols = [(k, v if k not in ('waiting', 'review') else []) for k, v in BOARD]
        filt = False
    else:
        cols, filt = BOARD, False
    counts = {k: len(v) for k, v in cols}
    t, d = tabs(counts, 'progress' if variant == 'filter-result' else 'waiting')
    return t + f'<div class="board">{"".join(column(k, v, filtering=filt) for k, v in cols)}</div>' + d


def loading_board():
    col = '<section class="col" aria-hidden="true"><div class="col-head"><span class="sk" style="width:90px"></span></div>' + \
          '<div class="tcard"><span class="sk w40"></span><span class="sk w80"></span><span class="sk w60"></span></div>' * 2 + '</section>'
    return f'<div class="board" aria-busy="true">{col * 6}</div><p class="sr-only" role="status">Loading the board…</p>'


def status_menu():
    return f'''<div class="pop smenu" role="dialog" aria-label="Set project status" data-show="status-menu status-error">
  <h2>Set project status</h2>
  <div class="radio-cards">
    <label class="radio-card"><input type="radio" name="ps"><b>Smooth sailing</b></label>
    <label class="radio-card"><input type="radio" name="ps" checked autofocus><b>Waiting input</b></label>
    <div class="sep"></div>
    <label class="radio-card"><input type="radio" name="ps"><b>Archived</b></label>
  </div>
  <div class="field">
    <label class="label" for="ps-note">Note <span class="opt">(optional)</span></label>
    <p class="hint">Juno’s note: “Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.”</p>
    <textarea id="ps-note" class="textarea" placeholder="Why? (optional)" maxlength="280" rows="2"></textarea>
    <p class="hint end">0/280</p>
  </div>
  <p class="ferr" role="alert" data-show="status-error">Couldn’t save the status. Your note is kept.</p>
  <div class="pop-foot"><button type="button" class="btn btn-primary" data-hide="status-error" disabled>Save status</button><button type="button" class="btn btn-primary" data-show="status-error">Save status</button><button type="button" class="btn" data-goto="default" data-close="default">Cancel</button></div>
  <p class="hint" style="margin-top:var(--s-3)">Saved as set by you. Juno sees it in History.</p>
</div>'''


def history_pop():
    rows = [('w1', '“Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.”', 'Juno (PM) · Today, 07:40 · via API'),
            ('ok', '“Invite build on track, nothing needed.”', 'Juno (PM) · Mon 26 Oct, 09:10 · via API'),
            ('w1', '“Need the cart scope call.”', 'Juno (PM) · Thu 22 Oct, 17:30 · via API')]
    li = ''.join(f'<li>{status_badge(k, sm=True) if k == "ok" else f"<span class=\"badge sm is-w1\">{ic(chr(104)+\"ourglass\")}Waiting input</span>"}<q>{q[1:-1]}</q><span class="m">{m}</span></li>' for k, q, m in rows)
    return f'<div class="pop histpop" role="dialog" aria-label="Status history" data-show="history"><div class="row" style="justify-content:space-between;margin-bottom:var(--s-3)"><h2 style="margin:0">Status history</h2><button type="button" class="x-btn" aria-label="Close" data-goto="default" data-close="default">{ic("x")}</button></div><ul class="plain">{li}</ul></div>'


def filters_pop():
    return f'''<div class="pop filters" role="dialog" aria-label="Filter tickets" data-show="filter">
  <h2>Filter tickets</h2>
  <div class="field"><label class="label" for="f-a">Assignee</label><select id="f-a" class="select" autofocus><option>Anyone</option><option>Juno</option><option>Alex Kim</option><option>Sam Rivera</option></select></div>
  <div class="field"><label class="label" for="f-l">Label</label><select id="f-l" class="select"><option>Any label</option></select></div>
  <div class="field"><label class="label" for="f-p">Priority</label><select id="f-p" class="select"><option>Any priority</option></select></div>
  <div style="margin-top:var(--s-2)"><label class="check"><input type="checkbox"> Blocked only</label><label class="check"><input type="checkbox"> New replies for you</label><label class="check"><input type="checkbox"> Your input not picked up</label></div>
  <div class="pop-foot"><button type="button" class="btn">Clear filters</button><button type="button" class="btn btn-primary" data-goto="default" data-close="default">Done</button></div>
</div>'''


def head(nav_state=True):
    return f'''<a class="crumb" href="portfolio.html">← All projects</a>
  <div class="page-head" style="margin-bottom:var(--s-4)">
    <h1 class="title">Northwind Portal</h1>
    <div class="board-tools">
      <label class="search">{ic('search')}<span class="sr-only">Search tickets</span><input class="input" type="search" placeholder="Search tickets"></label>
      <button type="button" class="btn btn-icon-m fbtn" data-goto="filter" aria-label="Filter" aria-haspopup="dialog">{ic('filter')}<span class="fbtn-label" data-hide="filter-result">Filter</span><span class="fbtn-label" data-show="filter-result">Filter · 1</span><span class="count strong" data-show="filter-result">1</span></button>
      <button type="button" class="btn btn-primary btn-icon-m" aria-label="Add ticket">{ic('plus')}<span class="add-label">Add ticket</span></button>
      {filters_pop() if nav_state else ''}
    </div>
  </div>
  <div class="pstat">
    {status_badge('w1', '<1d', button=True)}
    <div><p class="ps-set">Set by <b>Juno</b> (PM) · Today, 07:40</p><p class="ps-quote">“Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.”</p></div>
    <button type="button" class="link ps-hist" data-goto="history">{ic('history').replace('<svg', '<svg width=16 height=16')}History</button>
    {status_menu() if nav_state else ''}{history_pop() if nav_state else ''}
  </div>
  <p class="board-meta"><span><b>13</b> open tickets</span><span><b>2</b> waiting on you</span><span>1 blocked</span><span>PM <b>Juno</b></span><span>Last activity <b>Today, 07:58</b></span>
    <span data-show="filter-result" class="filter-note">1 shown · <button type="button" class="link" data-goto="default">Clear</button></span></p>'''


def move_sheet():
    rows = [('backlog', 'Top of column'), ('todo', 'Current'), ('progress', 'Top of column'), ('waiting', 'Goes to the end, sorted oldest first'), ('review', 'Top of column'), ('done', 'Top of column')]
    li = ''.join(f'<li><button type="button"{" aria-current=true" if w == "Current" else ""} data-goto="undo"><i class="dot c-{k}" style="background:var(--c)"></i>{COLNAME[k]}<span class="where">{w}</span></button></li>' for k, w in rows)
    return f'''<div class="scrim center" data-show="move-sheet"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="mv-h">
  <div class="sheet-grip"></div>
  <div class="modal-head"><div><h2 id="mv-h">Move NP-124 to…</h2><p>Cart: Done closes the sheet</p></div><button type="button" class="x-btn" aria-label="Close" data-goto="default" data-close="default">{ic('x')}</button></div>
  <ul class="move-list" style="margin-top:var(--s-4)">{li}</ul><div style="height:var(--s-3)"></div>
</div></div>'''


def ask_dialog():
    return f'''<div class="scrim center" data-show="ask-error"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="ask-h">
  <div class="sheet-grip"></div>
  <div class="modal-head"><div><h2 id="ask-h">Move NP-131 to Waiting on <span data-me>Sam</span></h2><p>Late renewal request in the Home module</p></div></div>
  <div class="modal-body"><div class="field">
    <label class="label" for="ask">What’s the ask?</label><p class="hint" id="ask-h2">Shown on the card and in Your input.</p>
    <textarea id="ask" class="textarea" aria-invalid="true" aria-describedby="ask-h2 ask-e" autofocus rows="3"></textarea>
    <p class="hint end">Enter moves it · Shift+Enter for a new line · 0/280</p>
    <p class="ferr" id="ask-e" role="alert">Add the ask. Every ticket in Waiting on <span data-me>Sam</span> needs one.</p>
  </div></div>
  <div class="modal-foot start"><button type="button" class="btn btn-primary">Move</button><button type="button" class="btn" data-goto="default" data-close="default">Cancel</button></div>
</div></div>'''


def build_board():
    body = f'''{topbar('portfolio')}
<main id="main" class="page" style="max-width:none">
  {head()}
  <div data-show="default filter status-menu status-error history toast undo move-sheet">{board()}</div>
  <div data-show="filter-result">{board('filter-result')}</div>
  <div data-show="empty">{board('empty')}</div>
  <div data-show="loading">{loading_board()}</div>
</main>
{move_sheet()}
<div class="toasts" aria-live="polite">
  <div data-show="toast">{toast('<b>Juno changed the status</b> Smooth sailing → Waiting input', 'Today, 07:40 · “Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.”', 'See history')}</div>
  <div data-show="undo">{toast('Moved NP-124 to Review / QA', '', 'Undo', icon='arrow-right', cls='is-ok')}</div>
</div>'''
    script = '''window.onProtoState = function (id) {
  if (window.selectCol) window.selectCol(id === 'filter-result' ? 'progress' : id === 'move-sheet' || id === 'undo' ? 'todo' : 'waiting');
};'''
    page('board.html', 'Northwind Portal', body,
         states='default:Default|filter:Filter popover|filter-result:Filter result (1 shown)|status-menu:Status menu|status-error:Status save error|history:Status history|toast:Status-change toast|undo:Moved, with Undo|empty:Empty columns|loading:Loading|move-sheet:Move sheet (mobile)',
         script=script)


def ticket_modal():
    side = f'''<aside class="tmodal-side" aria-label="Ticket details">
  <div class="side-block"><h3>Status</h3><select class="select" aria-label="Status"><option>Waiting on <span>Sam</span></option></select><p class="hint">Drag the card, or pick a column here.</p></div>
  <div class="side-block"><h3>Assignee</h3><p class="val"><span class="avatar xs">J</span>Juno</p><p class="hint">Agent · PM</p></div>
  <div class="side-block"><h3>Priority</h3><p class="val prio">{ic('flag').replace('<svg', '<svg width=14 height=14')}P1 · High</p></div>
  <div class="side-block"><h3>Due <button type="button" class="link small">Clear</button></h3><p class="val due">{ic('cal').replace('<svg', '<svg width=14 height=14')}Fri 30 Oct</p></div>
  <div class="side-block"><h3>Labels <button type="button" class="link small">Edit</button></h3><div class="chips"><span class="chip l-violet">Onboarding</span><span class="chip l-navy">Decision</span></div></div>
  <div class="side-block"><h3>Created</h3><p class="small">Juno · Mon 26 Oct, 16:20</p><p class="hint">via API · updated <span data-hide="input-sent">Tue 27 Oct, 18:25</span><span data-show="input-sent">Today, 10:00</span></p></div>
  <div class="side-block"><h3>History</h3><ul class="plain hist-list">
    <li>Moved In progress → Waiting on <span data-me>Sam</span><span>Juno · Tue 27 Oct, 18:10</span></li>
    <li>Priority set to P1<span>Juno · Tue 27 Oct, 18:10</span></li>
    <li>Moved To do → In progress<span>Juno · Mon 26 Oct, 16:40</span></li></ul>
    <button type="button" class="link small">Show all 6</button></div>
</aside>'''
    yi = f'''<section class="yourinput" aria-labelledby="yi-h" data-hide="input-sent">
  <div class="yi-head"><span class="badge sm is-accent">Your input</span><h2 id="yi-h">Juno needs your input</h2><span class="badge sm is-w1">{pips(1)}Waiting 1 day</span></div>
  <p class="yi-q">Which default should the team invite link use at launch?</p>
  <p class="yi-ctx">Both options are a config switch, no extra build (Alex, Tue).</p>
  <fieldset style="border:0;padding:0;margin:var(--s-4) 0"><legend class="sr-only">Your answer</legend><div class="radio-row">
    <label class="radio-card"><input type="radio" name="yi" checked><b>48 h or first sign-in, whichever is first</b></label>
    <label class="radio-card"><input type="radio" name="yi"><b>Close at first sign-in only</b></label>
    <label class="radio-card"><input type="radio" name="yi"><b>Something else</b></label></div></fieldset>
  <div class="field"><label class="label" for="yi-n">Note for Juno <span class="opt">(optional)</span></label>
    <textarea id="yi-n" class="textarea" rows="2">Keep the current rule for launch. Revisit after the first 50 team invites.</textarea></div>
  <div class="row" style="margin-top:var(--s-4)"><button type="button" class="btn btn-primary" data-goto="input-sent">Send to Juno</button><p class="hint">Posts as a comment marked “<span data-me>Sam</span>’s input”. Juno moves the ticket on.</p></div>
</section>
<section class="yourinput is-sent" aria-labelledby="ys-h" data-show="input-sent">
  <div class="yi-head"><span class="badge sm is-solid-ok">Sent</span><h2 id="ys-h">Your input is with Juno</h2></div>
  <p class="yi-ctx">You answered <b>48 h or first sign-in, whichever is first</b> · Today, 10:00. It stays here until Juno picks it up.</p>
  <button type="button" class="btn" style="margin-top:var(--s-4)" data-goto="default">Change answer</button>
</section>'''
    thread = f'''<div class="thread">
  <div class="cmt"><span class="avatar xs">J</span><div class="cmt-box"><p class="cmt-head"><b>Juno</b><span class="chip l-navy">PM</span>Mon 26 Oct, 16:20</p><p>Opened from the invite spec. Today the link closes after 48 h <b>or</b> at first sign-in, whichever is first.</p></div></div>
  <p class="event">{ic('arrow-right').replace('<svg', '<svg width=14 height=14')}<span><b>Juno</b> moved this <b>To do</b> → <b>In progress</b> · Mon 26 Oct, 16:40</span></p>
  <div class="cmt"><span class="avatar xs">J</span><div class="cmt-box"><p class="cmt-head"><b>Juno</b><span class="chip l-navy">PM</span>Tue 27 Oct, 18:10</p><p><span data-me>Sam</span>, one call from you: which default do we launch with? Details in the description.</p></div></div>
  <p class="event">{ic('arrow-right').replace('<svg', '<svg width=14 height=14')}<span><b>Juno</b> moved this <b>In progress</b> → <b>Waiting on <span data-me>Sam</span></b> · Tue 27 Oct, 18:10</span></p>
  <div class="cmt"><span class="avatar xs">A</span><div class="cmt-box"><p class="cmt-head"><b>Alex Kim</b><span class="chip l-navy">Developer</span>Tue 27 Oct, 18:25</p><p>Confirmed: it is a flag only (<code>INVITE_LINK_CLOSE</code>). No extra build either way.</p></div></div>
  <div class="cmt" data-show="input-sent"><span class="avatar xs" data-me-ini>SR</span><div class="cmt-box is-input"><p class="cmt-head"><b data-me>Sam</b><span class="badge sm is-accent"><span data-me>Sam</span>’s input</span>Today, 10:00</p><p><b>48 h or first sign-in, whichever is first.</b></p><p>Keep the current rule for launch. Revisit after the first 50 team invites.</p><p class="cmt-ack">{ic('clock').replace('<svg', '<svg width=14 height=14')}Sent to Juno · not picked up yet</p></div></div>
  <div class="cmt"><span class="avatar xs" data-me-ini>SR</span><div class="composer"><label class="sr-only" for="cmt">Comment</label><textarea id="cmt" class="textarea" placeholder="Write a comment… (Markdown)" rows="2"></textarea>
    <div class="composer-foot"><label class="check"><input type="checkbox"> Mark as my input for the PM</label><button type="button" class="btn btn-primary">Comment</button></div></div></div>
</div>'''
    return f'''<div class="scrim in-flow" data-hide="ask-error"><div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="tk-title">
  <div class="sheet-grip"></div>
  <div class="tmodal-top"><span>Northwind Portal / <b>NP-115</b></span><div class="end"><button type="button" class="x-btn" aria-label="More actions">{ic('dots')}</button><a class="x-btn" href="board.html" aria-label="Close">{ic('x')}</a></div></div>
  <div class="tmodal-grid">
    <div class="tmodal-main">
      <div class="chips"><span class="chip l-violet">Onboarding</span><span class="chip l-navy">Decision</span></div>
      <h2 class="ticket-title" id="tk-title" tabindex="-1">Team invite link: close at first sign-in, or after 48 h?</h2>
      {yi}
      <h3 class="block-title">Description <button type="button" class="link">Edit</button></h3>
      <div class="prose"><p>People open the link the team owner shares. It now closes after <b>48 h or at first sign-in</b>, whichever comes first. The flag <code>INVITE_LINK_CLOSE</code> switches it to close at the first sign-in only.</p>
        <ul><li><b>A</b>: 48 h or first sign-in, whichever is first (current)</li><li><b>B</b>: at first sign-in only (late joiners can still ask for a new link)</li></ul>
        <p>Sample ticket for the mock. Not a real decision record.</p></div>
      <h3 class="block-title">Comments &amp; activity <span class="count" data-hide="input-sent">3</span><span class="count" data-show="input-sent">4</span></h3>
      {thread}
    </div>
    {side}
  </div>
</div></div>'''


def build_ticket():
    body = f'''{topbar('portfolio')}
<main id="main" class="page" style="max-width:none" inert>
  {head(nav_state=False)}
  {board()}
</main>
{ticket_modal()}
{ask_dialog()}'''
    page('ticket.html', 'NP-115 · Northwind Portal', body, states='default:Ticket, Your input open|ask-error:Move ask, empty (error)|input-sent:Input sent', body_class='has-modal')


def build():
    build_board()
    build_ticket()
