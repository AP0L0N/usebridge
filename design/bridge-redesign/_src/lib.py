"""Shared helpers for the static prototype pages. Run `python3 _src/build.py` from design/bridge-redesign/.
The generated *.html files are the deliverable; this only keeps repeated markup (cards, board, icons) in one place."""
import os

OUT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

ICONS = {
    'search': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m13 13 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'hourglass': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 2.5h10M5 17.5h10M6 2.5c0 4 4 5 4 7.5s-4 3.5-4 7.5M14 2.5c0 4-4 5-4 7.5s4 3.5 4 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'check-circle': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m6.8 10.2 2.2 2.2 4.2-4.6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'archive': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v3.5H3zM4.5 7.5V16h11V7.5M8 10.5h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'chev': '<svg class="chev" viewBox="0 0 12 12" aria-hidden="true"><path d="m3 4.5 3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'history': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 10a6.5 6.5 0 1 0 2-4.7M3.5 3.5v3h3M10 6.5V10l2.5 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'filter': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M6 10h8M8.5 15h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'plus': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
    'flag': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 17.5V3h9l-1.5 3.5L14 10H5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'cal': '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4.5" width="14" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 8.5h14M7 2.5v4M13 2.5v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
    'cmt': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 4.5h13v9h-7l-3.5 3v-3H3.5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    'block': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m5 15 10-10" stroke="currentColor" stroke-width="1.8"/></svg>',
    'reply': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M8 5 3.5 9.5 8 14M4 9.5h7.5a5 5 0 0 1 5 5V16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'x': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'dots': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="4.5" cy="10" r="1.5" fill="currentColor"/><circle cx="10" cy="10" r="1.5" fill="currentColor"/><circle cx="15.5" cy="10" r="1.5" fill="currentColor"/></svg>',
    'arrow-right': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h11M11 5.5 15.5 10 11 14.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'info': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 9v5M10 6.2v.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'warn': '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3 18 17H2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M10 8.5v3.5M10 14.6v.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'clock': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 6v4l2.5 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'chart': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'folder': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l2 2.2h8.8A1.5 1.5 0 0 1 21 8.7V18a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'key': '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m11 12 8-8M16 7l2.5 2.5M14 9l2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'mail': '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m4 7 8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'inbox': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2.5-8h13L21 13v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM3 13h5l1.5 3h5L16 13h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
    'users': '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2.5 19.5c.8-3.4 3.4-5 6.5-5s5.7 1.6 6.5 5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.8.7 3 2.3 3.5 4.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    'external': '<svg viewBox="0 0 16 16" aria-hidden="true" width="12" height="12"><path d="M9 3h4v4M13 3 7.5 8.5M12 9.5V13H3V4h3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
}


def ic(name):
    return ICONS[name]


def page(fname, title, body, states=None, role=None, body_class='', script=''):
    st = f' data-states="{states}"' if states else ''
    rl = f' data-role="{role}"' if role else ''
    bc = f' class="{body_class}"' if body_class else ''
    js = f'<script>\n{script}\n</script>\n' if script else ''
    html = f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} · Bridge</title>
<link rel="stylesheet" href="tokens.css">
<link rel="stylesheet" href="components.css">
<script src="proto.js"></script>
</head>
<body{st}{rl}{bc}>
<a class="sr-only" href="#main">Skip to content</a>
{body}
{js}</body>
</html>
'''
    with open(os.path.join(OUT, fname), 'w') as f:
        f.write(html)


def topbar(active='', nav=True):
    n = '' if nav else ' data-nav="false"'
    return f'<header class="topbar" data-active="{active}"{n}></header>'


# ---------------------------------------------------------------- status

HEAT = {'w1': 1, 'w2': 2, 'w3': 3}


def pips(level):
    return '<span class="pips" aria-hidden="true">' + ''.join('<i></i>' if i < level else '<i class="off"></i>' for i in range(3)) + '</span>'


def status_badge(kind, age='', button=False, sm=False):
    """kind: w1|w2|w3|ok|arch|unset"""
    cls = 'badge is-' + kind + (' sm' if sm else '')
    if kind in HEAT:
        days = {'<1d': 'less than 1 day', '1d': '1 day'}.get(age, age.replace('d', ' days'))
        inner = ic('hourglass') + 'Waiting input ' + pips(HEAT[kind]) + f'<span class="age">{age}</span>'
        aria = f' aria-label="Waiting input, {days}"'
    elif kind == 'ok':
        inner, aria = ic('check-circle') + 'Smooth sailing', ''
    elif kind == 'arch':
        inner, aria = ic('archive') + 'Archived', ''
    else:
        inner, aria = 'Status not set', ''
    if button:
        return f'<button type="button" class="{cls}" aria-haspopup="dialog" data-goto="status-menu"{aria}>{inner}{ic("chev")}</button>'
    return f'<span class="{cls}"{aria}>{inner}</span>'


def heat_chip(kind, age):
    return f'<span class="badge sm is-{kind}" aria-label="Waiting {age}">{pips(HEAT[kind])}{age}</span>'


COLS = [('backlog', 'Backlog'), ('todo', 'To do'), ('progress', 'In progress'), ('waiting', 'Waiting on <span data-me>Sam</span>'), ('review', 'Review / QA'), ('done', 'Done')]


def dist(counts):
    """counts: dict col->n (done = Done (7 days))."""
    total = sum(counts.values()) or 1
    bar = ''.join(f'<i class="c-{k}" style="width:{v / total * 100:.1f}%"></i>' for k, v in counts.items() if v)
    names = dict(COLS)
    names['done'] = 'Done (7 days)'
    leg = ''.join(f'<span><i class="dot c-{k}" style="background:var(--c)"></i><span>{names[k]} <b>{v}</b></span></span>' for k, v in counts.items() if v)
    return f'<div class="dist" aria-hidden="true">{bar}</div><p class="dist-legend">{leg}</p>'


def pcard(p, q=None):
    """Portfolio card. p: dict(name, slug, pm, kind, age, set_by, set_at, quote, counts, foryou, last, reopened)."""
    name = p['name']
    quote = p.get('quote')
    if q:
        import re
        rx = re.compile('(' + re.escape(q) + ')', re.I)
        name = rx.sub(r'<mark>\1</mark>', name)
        if quote:
            quote = rx.sub(r'<mark>\1</mark>', quote)
    kind = p['kind']
    if kind == 'unset':
        setline = '<p class="pcard-set">The PM hasn’t set a status yet</p>'
    else:
        setline = f'<p class="pcard-set">Set by <b>{p["set_by"]}</b> · {p["set_at"]}</p><p class="pcard-quote">“{quote}”</p>'
    foryou = ''
    if p.get('foryou'):
        foryou = f'<span class="badge sm is-accent">{ic("hourglass")}{p["foryou"]} for you</span>'
    reo = '<span class="chip l-navy reopened" title="Reopened by a new update">Reopened</span>' if p.get('reopened') else ''
    return f'''<article class="pcard is-{kind if kind != 'unset' else 'unset'}">
  <div class="pcard-body">
    <div class="pcard-head"><div><h3 class="pcard-name"><a href="board.html">{name}</a></h3><p class="pcard-pm">PM {p["pm"]}</p></div>{reo}{status_badge(kind, p.get("age", ""), sm=False)}</div>
    {setline}
    {dist(p["counts"]) if p.get("counts") else '<div class="dist" aria-hidden="true"></div>'}
  </div>
  <div class="pcard-foot">{foryou or '<span></span>'}<span>Last activity <b>{p["last"]}</b></span></div>
</article>'''


PROJECTS = [
    dict(name='Harbor Mobile App', pm='Vega', kind='w3', age='7d', set_by='Vega', set_at='Wed 21 Oct, 10:05', quote='Release checklist waits on your pricing call.',
         counts={'backlog': 1, 'todo': 1, 'waiting': 1}, foryou=1, last='Wed 21 Oct, 10:05'),
    dict(name='Atlas Checkout', pm='Orion', kind='w2', age='3d', set_by='Orion', set_at='Sun 25 Oct, 18:02', quote='Translation batch 4 needs your OK on the reviewed strings.',
         counts={'backlog': 4, 'todo': 2, 'progress': 2, 'waiting': 1, 'review': 1, 'done': 9}, foryou=1, last='Yesterday, 16:30'),
    dict(name='Northwind Portal', pm='Juno', kind='w1', age='<1d', set_by='Juno', set_at='Today, 07:40', quote='Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.',
         counts={'backlog': 3, 'todo': 3, 'progress': 3, 'waiting': 2, 'review': 2, 'done': 3}, foryou=2, last='Today, 07:58'),
    dict(name='Lumen Docs', pm='Iris', kind='ok', set_by='Iris', set_at='Today, 06:50', quote='Docs site port at page 16 of 22.',
         counts={'backlog': 2, 'todo': 4, 'progress': 3, 'done': 6}, last='Today, 06:50'),
    dict(name='Fieldnote CRM', pm='Nova', kind='ok', set_by='Nova', set_at='Yesterday, 21:15', quote='Contacts page live; mobile list next. Nothing needed from you.',
         counts={'backlog': 5, 'todo': 3, 'progress': 2, 'review': 1, 'done': 12}, last='Yesterday, 21:15'),
    dict(name='Orchard Analytics', pm='Rhea', kind='unset', counts={'todo': 2, 'progress': 1}, last='Mon 26 Oct, 11:00'),
]
ARCHIVED = dict(name='Pinecrest Rebrand', pm='Orion', kind='arch', set_by='Jordan Lee', set_at='Thu 22 Oct, 09:30', quote='Shelved for now. Mocks archived.', counts=None, last='Thu 22 Oct, 09:30')


def empty(icon, title, body, actions='', cls=''):
    return f'<div class="empty {cls}"><div class="empty-icon">{ic(icon)}</div><h2>{title}</h2><p>{body}</p>{f"<div class=row>{actions}</div>" if actions else ""}</div>'


def toast(body, sub='', action='', icon='history', cls='', close=True):
    a = f'<button type="button" class="t-act">{action}</button>' if action else ''
    s = f'<p>{sub}</p>' if sub else ''
    x = f'<button type="button" class="t-x" aria-label="Dismiss">×</button>' if close else ''
    return f'<div class="toast {cls}">{ic(icon)}<div class="t-body"><p>{body}</p>{s}</div>{a}{x}</div>'
