from lib import *


def skeleton_card():
    return '''<div class="pcard" aria-hidden="true"><div class="pcard-body">
  <div class="pcard-head"><div><span class="sk t"></span><span class="sk w40" style="margin-top:8px"></span></div><span class="sk" style="width:110px;height:24px;border-radius:999px"></span></div>
  <span class="sk w60"></span><span class="sk w80"></span><span class="sk" style="height:6px"></span><span class="sk w60"></span>
</div><div class="pcard-foot"><span></span><span class="sk" style="width:160px"></span></div></div>'''


def build():
    q = 'port'
    all_cards = ''.join(pcard(p) for p in PROJECTS)
    search_cards = ''.join(pcard(p, q) for p in [PROJECTS[2], PROJECTS[3]])
    body = f'''{topbar('portfolio')}
<main id="main" class="page">
  <div class="page-head">
    <div><h1 class="title">Portfolio</h1>
      <p class="summary" data-hide="empty loading search no-results"><b>6</b> active projects · <b>3</b> waiting input · <b>4</b> tickets waiting on you</p>
      <p class="summary" data-show="search" role="status">2 results for “{q}”, incl. archived</p>
      <p class="summary" data-show="loading"><span class="sk" style="width:320px;max-width:70vw;height:14px;margin-top:6px"></span></p>
    </div>
    <div class="tools" data-hide="empty">
      <label class="search" style="width:340px;max-width:100%">{ic('search')}<span class="sr-only">Search projects and updates</span>
        <input class="input" type="search" placeholder="Search projects and updates" data-q value="">
        <button type="button" class="clear" data-show="search no-results" data-goto="default">Clear</button></label>
      <label class="switch"><input type="checkbox" role="switch" data-arch> Show archived</label>
    </div>
  </div>

  <div data-show="default archived"><div class="pgrid">{all_cards}</div></div>
  <div data-show="archived">
    <h2 class="group-title">Archived <span class="count">1</span></h2>
    <div class="pgrid">{pcard(ARCHIVED)}</div>
  </div>
  <div data-show="search"><div class="pgrid">{search_cards}</div></div>
  <div data-show="loading" aria-busy="true"><div class="pgrid">{skeleton_card() * 6}</div><p class="sr-only" role="status">Loading projects…</p></div>
  <div data-show="no-results" class="card">{empty('search', 'No projects match “kiosk”', 'Search covers names, updates and archived projects. Try a shorter word.', '<button type="button" class="btn" data-goto="default">Clear search</button>')}</div>
  <div data-show="empty" class="card">{empty('folder', 'No projects yet', 'Projects show up here after an agent posts its first update.')}</div>
</main>'''
    script = '''window.onProtoState = function (id) {
  var qi = document.querySelector('[data-q]');
  qi.value = id === 'search' ? 'port' : id === 'no-results' ? 'kiosk' : '';
  document.querySelector('[data-arch]').checked = id === 'archived';
};
document.addEventListener('change', function (e) { if (e.target.matches('[data-arch]')) document.querySelector('[data-goto="' + (e.target.checked ? 'archived' : 'default') + '"]').click(); });'''
    page('portfolio.html', 'Portfolio', body,
         states='default:Default|search:Search with highlight|archived:Show archived on|no-results:No results|empty:Empty (no projects)|loading:Loading',
         script=script)
