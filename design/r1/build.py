import html, json, re
STR = {}
import re as _re
FINAL={m.group(1):m.group(2) for m in _re.finditer(r'^\| `([^`]+)` \| (.*?) \|$',open('STRINGS-FINAL.md').read(),_re.M)}
KEEP={'status.smooth':'Smooth sailing','status.waiting':'Waiting input','dash.summary':'{w} waiting input · {o} smooth sailing'}
FINAL.update(KEEP); Q=''
def S(key, text, tag='span', cls=''):
    text=FINAL.get(key,text).replace('{q}',Q); STR.setdefault(key, FINAL.get(key,text))
    c = f' class="{cls}"' if cls else ''
    return f'<{tag}{c} data-stand-in-key="{key}">{html.escape(text)}</{tag}>'

CSS = open('bridge.css').read()
ICON = {
 'ok': '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4.8 8.3l2.2 2.2 4.2-4.6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>',
 'wait': '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 1.8h8M4 14.2h8M5 2v2.6L8 8l3-3.4V2M5 14v-2.6L8 8l3 3.4V14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
 'arch': '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="1.8" y="2.5" width="12.4" height="3.4" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3 6v6.5a1 1 0 001 1h8a1 1 0 001-1V6M6.3 8.6h3.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
 'search': '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M13 13l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
 'reopen': '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13 8a5 5 0 11-1.5-3.6M13 2.5v3h-3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
}
def badge(st, days=0):
    if st=='ok': return f'<span class="badge b-ok">{ICON["ok"]}{S("status.smooth","Smooth sailing")}</span>'
    if st=='arch': return f'<span class="badge b-arch">{ICON["arch"]}{S("status.archived","Archived")}</span>'
    lvl = 1 if days<=1 else 2 if days==2 else 3
    pips = ''.join(f'<i class="{"on" if i<lvl else ""}"></i>' for i in range(3))
    age = S("status.waiting.age", f"{days}d") if False else f'<span data-stand-in-key="status.waiting.age">{days}d</span>'
    STR.setdefault("status.waiting.age","{n}d")
    return f'<span class="badge b-w{lvl}" title="Waiting input, {days} day{'' if days==1 else 's'}">{ICON["wait"]}{S("status.waiting","Waiting input")}<span class="pips" aria-hidden="true">{pips}</span>{age}</span>'

def header(active, search_val='', show_arch=False, dash=True):
    return f'''<header class="top"><div class="wrap topin">
<a class="brand" href="#"><span class="mark" aria-hidden="true"></span>{S("app.name","Bridge")}</a>
<span class="sample">{S("app.sample","Sample data")}</span>
<span class="who">Tilen</span></div></header>'''

PROJ = [
 # name, status, days, latest, updated, flags
 ("Northwind","w",4,"Round 6 mocks delivered — waiting on Dana for product photos and the store count.","4 days ago",""),
 ("Blue Harbor","w",2,"Hotfix passed QA on preview — waiting on Watts to publish to live.","2 days ago",""),
 ("Acme Studio","w",1,"Onboarding copy draft ready — waiting on Marie for the final pass.","Yesterday",""),
 ("Cobalt Works","ok",0,"Weekly digest shipped; nothing blocking.","Today, 09:12","reopened"),
 ("Sundial","ok",0,"Inbox triage running smoothly; next review on Friday.","Today, 07:40",""),
 ("Bridge","ok",0,"Redesign r1 in progress — two screens only.","Today, 18:05",""),
]
ARCH = [
 ("Fernleaf","arch",0,"Launch page live. Project closed.","12 Sep 2026",""),
 ("Blue Harbor Phase 0","arch",0,"Theme audit handed over to Phase 1.","28 Aug 2026",""),
]
def row(p, hl=None):
    n,st,d,line,upd,fl = p
    name = html.escape(n); 
    if hl: name = re.sub(f'({re.escape(hl)})', r'<mark>\1</mark>', name, flags=re.I); line_h = re.sub(f'({re.escape(hl)})', r'<mark>\1</mark>', html.escape(line), flags=re.I)
    else: line_h = html.escape(line)
    reo = f'<span class="reopen" title="Reopened by a new update">{ICON["reopen"]}{S("dash.reopened","Reopened by new update")}</span>' if fl=='reopened' else ''
    cls = {'w':'s-w'+str(1 if d<=1 else 2 if d==2 else 3),'ok':'s-ok','arch':'s-arch'}[st]
    return f'''<a class="prow {cls}" href="project-northwind.html"><span class="spine"></span>
<div class="pmain"><div class="pname"><span>{name}</span>{reo}</div><p class="pline">{line_h}</p></div>
<div class="pbadge">{badge(st,d)}</div>
<div class="pupd"><span class="lbl">{S("dash.updated","Updated")}</span> <time>{upd}</time></div></a>'''

def summary(rows):
    w = sum(1 for r in rows if r[1]=='w'); o=sum(1 for r in rows if r[1]=='ok')
    STR.setdefault("dash.summary","{w} waiting on input · {o} smooth sailing")
    return f'<p class="summary" data-stand-in-key="dash.summary"><b>{w}</b> waiting input · <b>{o}</b> smooth sailing</p>'

def dash(name, query='', arch=False, rows=None, empty=None, title='Portfolio'):
    global Q; Q=query
    tog = 'on' if arch else ''
    body = ''
    if empty=='noresults':
        body = f'''<div class="empty"><div class="eico">{ICON["search"]}</div>{S("dash.noresults.title","No projects match “"+query+"”",'h2')}{S("dash.noresults.body","Search covers names, updates and archived projects. Try a shorter word.","p")}<button class="btn">{S("dash.noresults.clear","Clear search")}</button></div>'''
    elif empty=='portfolio':
        body = f'''<div class="empty"><div class="eico big">{ICON["ok"]}</div>{S("dash.empty.title","No projects yet","h2")}{S("dash.empty.body","Projects appear here as soon as an agent posts their first status update.","p")}</div>'''
    else:
        live=[r for r in rows if r[1]!='arch']; ar=[r for r in rows if r[1]=='arch']
        body = '<div class="plist">'+''.join(row(r,query or None) for r in live)+'</div>'
        if ar:
            body += f'<h3 class="sect">{S("dash.archived.heading","Archived")} <span class="cnt">{len(ar)}</span></h3><div class="plist arch">'+''.join(row(r,query or None) for r in ar)+'</div>'
    sm = summary(rows) if rows and not query else (f'<p class="summary" data-stand-in-key="dash.results">{len(rows)} results for “{html.escape(query)}”, including archived</p>' if rows else '<p class="summary">&nbsp;</p>')
    if query and rows: STR.setdefault("dash.results","{n} results for “{q}”, incl. archived")
    val = f'value="{html.escape(query)}"' if query else ''
    clear = f'<button class="clr" aria-label="Clear">×</button>' if query else ''
    ph = 'placeholder="Search projects and updates"'; STR.setdefault("dash.search.placeholder","Search projects and updates")
    return page(name, f'''{header('dash')}<main class="wrap">
<div class="dhead"><div>{S("dash.title","Portfolio","h1")}{sm}</div>
<div class="tools"><label class="search">{ICON["search"]}<input {ph} {val} data-stand-in-key="dash.search.placeholder">{clear}</label>
<label class="toggle {tog}"><span class="sw"><span></span></span>{S("dash.showArchived","Show archived")}</label></div></div>
{body}</main>''')

def page(name, inner):
    inner=re.sub(r' data-stand-in-key="[^"]*"','',inner)
    open(name,'w').write(f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bridge — {name}</title><style>{CSS}</style></head><body>{inner}</body></html>''')

ALL = PROJ+ARCH
dash('dashboard-default.html', rows=PROJ)
q='blue harbor'; dash('dashboard-search.html', query=q, rows=[r for r in ALL if q in r[0].lower()+r[3].lower()])
dash('dashboard-archived.html', arch=True, rows=ALL)
dash('dashboard-no-results.html', query='lisbon', empty='noresults')
dash('dashboard-empty.html', empty='portfolio', rows=[])

def md(s):
    import markdown
    return markdown.markdown(s)
UPD = [
 ("Enrique Cortez","Today, 18:40","**Round 6 update shipped** to the client preview.\n\n- Region label now *Central* everywhere\n- Store recount: **31** (was 35)\n- Changed files listed in the preview notes"),
 ("Dr. Marie Delacroix","Today, 16:05","Round 6 strings delivered:\n\n1. Five collection names with sublines\n2. »Show more« plural forms\n3. Trip-row **Book now** label"),
 ("Enrique Cortez","Thu 8 Oct, 11:20","**Round 5 live** on the preview link.\n\n- Search window and favourites added\n- Help page for partners drafted\n\n**Waiting on:** Dana — 10 open questions"),
 ("Tommy Suarez","Wed 7 Oct, 17:45","Round 4 final copy merged; footer phone number still a placeholder.\n\n**Waiting on:** Dana — footer phone number."),
 ("Enrique Cortez","Mon 5 Oct, 09:30","Kick-off for round 4. Nothing blocking."),
]
OPEN = [("Product photos (north, south, east and west collections)","Dana","Thu 8 Oct",4),
        ("Central-region count: 18 by city or 23 by region field?","Dana","Sat 10 Oct",2),
        ("Footer phone number","Dana","Sun 11 Oct",1)]
DONE = [("Final round 6 strings","Marie","Today"),("Region label decision","Enrique","Sat 10 Oct"),("Trip descriptions","Dana","Fri 9 Oct")]

def proj(name, pname, st, d, opens, dones, upds):
    lv = lambda d: 1 if d<=1 else 2 if d==2 else 3
    oi = ''.join(f'''<li class="inp open l{lv(dd)}"><span class="chk" aria-hidden="true"></span><div><p class="it">{html.escape(t)}</p><p class="meta"><span data-stand-in-key="inputs.waitingOn">Waiting on</span> <b>{w}</b> · <span data-stand-in-key="inputs.since">since</span> {s} {badge('w',dd)}</p></div></li>''' for t,w,s,dd in opens)
    STR.setdefault("inputs.waitingOn","Waiting on"); STR.setdefault("inputs.since","since")
    di = ''.join(f'''<li class="inp done"><span class="chk on" aria-hidden="true">✓</span><div><p class="it"><s>{html.escape(t)}</s></p><p class="meta"><span data-stand-in-key="inputs.doneBy">Provided by</span> {w} · {s}</p></div></li>''' for t,w,s in dones)
    STR.setdefault("inputs.doneBy","Provided by")
    if not opens:
        oi = f'<li class="allclear">{ICON["ok"]}<div>{S("inputs.none.title","Nothing waiting on anyone","b")}{S("inputs.none.body","New inputs from status updates show up here.","p")}</div></li>'
    ul = ''.join(f'''<article class="upd"><div class="dot"></div><header><b>{a}</b><time>{t}</time></header><div class="md">{md(b)}</div></article>''' for a,t,b in upds)
    page(name, f'''{header('proj')}<main class="wrap">
<a class="back" href="dashboard-default.html">{S("proj.back","Portfolio")}</a>
<div class="phead"><h1>{pname}</h1>{badge(st,d)}</div>
<div class="pgrid"><section class="feed">{S("proj.updates.heading","Status updates","h2")}<p class="sub" data-stand-in-key="proj.updates.order">Newest first</p>{ul}</section>
<aside class="inputs">{S("proj.inputs.heading","Required inputs","h2")}<p class="sub"><b>{len(opens)}</b> <span data-stand-in-key="proj.inputs.openCount">open</span> · {len(dones)} <span data-stand-in-key="proj.inputs.doneCount">done</span></p>
<ul>{oi}</ul><ul class="donel">{di}</ul></aside></div></main>''')
STR.setdefault("proj.updates.order","Newest first"); STR.setdefault("proj.inputs.openCount","open"); STR.setdefault("proj.inputs.doneCount","done")
proj('project-northwind.html','Northwind','w',4,OPEN,DONE,UPD)
proj('project-no-open-inputs.html','Cobalt Works','ok',0,[],
 [("Digest recipient list","Tilen","Fri 9 Oct"),("Tone sample for weekly digest","Marie","Tue 6 Oct")],
 [("William Diego","Today, 09:12","**Reopened.** New weekly digest shipped.\n\n- Sent to the agreed list\n- No open questions"),
  ("William Diego","Fri 9 Oct, 14:00","Recipient list confirmed by **Tilen**. Archiving until next change.")])
json.dump(STR, open('strings.json','w'), ensure_ascii=False, indent=1)
