/* Bridge redesign — prototype behaviour (vanilla, no build, works over file://).
 *
 * Load in <head> without defer: the theme is applied before first paint.
 *
 * URL params
 *   ?theme=light|dark   overrides the saved theme (for screenshots); the toggle saves to localStorage
 *   ?role=user|owner    drives the nav and the signed-in sample user (default: <body data-role>, else user)
 *   ?state=id           picks a prototype state (see <body data-states="id:Label|…">)
 *   ?shot=1             hides the prototype states switcher
 *
 * Markup hooks
 *   <header class="topbar" data-active="portfolio|usage|polymarket|admin|account" [data-nav="false"]></header>
 *   [data-show="a b"] / [data-hide="a b"]   visible only in / hidden in these states
 *   [data-goto="id"]                        click switches state (prototype click-through)
 *   [data-me] → first name of the signed-in sample user, [data-me-full] → full name, [data-me-email]
 *   [data-owner-only]                       removed unless role=owner
 *   [data-menu] button + next .menu sibling  menu toggle (Esc / outside click closes)
 *   [data-copy="#id"], [data-pw="#id"]      copy text, show/hide password
 *   .col-tabs [role=tab][data-col=key]      mobile column tabs → .col[data-col=key].is-active
 *   window.onProtoState = fn(id)            optional per-page hook
 * Nothing here is product logic; in React each of these is component state.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  var qs = new URLSearchParams(location.search);
  var KEY = 'bridge-theme';

  /* ---------- theme (runs immediately) ---------- */
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) { /* file:// privacy modes */ }
  var theme = qs.get('theme') || saved || 'light';
  root.dataset.theme = theme === 'dark' ? 'dark' : 'light';
  if (qs.get('shot')) root.classList.add('shot');

  function setTheme(t) {
    root.dataset.theme = t;
    try { localStorage.setItem(KEY, t); } catch (e) { /* ignore */ }
    document.querySelectorAll('.theme-btn').forEach(function (b) {
      b.setAttribute('aria-pressed', String(t === 'dark'));
      b.setAttribute('aria-label', t === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    });
  }

  var USERS = {
    user: { first: 'Sam', full: 'Sam Rivera', email: 'sam@example.com', ini: 'SR' },
    owner: { first: 'Mia', full: 'Mia Novak', email: 'mia@example.com', ini: 'MN' }
  };

  var ICON = {
    moon: '<svg class="i-moon" viewBox="0 0 20 20" aria-hidden="true"><path d="M16.5 12.6A7 7 0 0 1 7.4 3.5a7 7 0 1 0 9.1 9.1Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    sun: '<svg class="i-sun" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="3.6" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M10 1.8v2.1M10 16.1v2.1M1.8 10h2.1M16.1 10h2.1M4.2 4.2l1.5 1.5M14.3 14.3l1.5 1.5M4.2 15.8l1.5-1.5M14.3 5.7l1.5-1.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'
  };

  function shell(header, role) {
    var u = USERS[role];
    var owner = role === 'owner';
    var active = header.dataset.active || '';
    var withRole = function (href) { return qs.get('role') ? href + '?role=' + role : href; };
    var items = [['portfolio', 'portfolio.html', 'Portfolio'], ['usage', 'usage.html', 'Usage']];
    if (owner) items.push(['polymarket', 'polymarket.html', 'Polymarket'], ['admin', 'shell.html?state=admin&role=owner', 'Admin']);
    var links = items.map(function (n) {
      return '<a href="' + (n[0] === 'admin' ? n[1] : withRole(n[1])) + '"' + (n[0] === active ? ' aria-current="page"' : '') + '>' + n[2] + '</a>';
    }).join('');
    var cur = items.filter(function (n) { return n[0] === active; })[0];
    var nav = header.dataset.nav !== 'false';
    var themeBtn = '<button type="button" class="top-btn theme-btn" aria-pressed="false" aria-label="Switch to dark theme" title="Theme">' + ICON.moon + ICON.sun + '</button>';
    var acct = nav
      ? '<div class="acct"><button type="button" class="top-btn" data-menu aria-expanded="false" aria-haspopup="true" aria-label="Account menu, ' + u.full + '">' +
        '<span class="avatar">' + u.ini + '</span><span class="acct-name">' + u.full + '</span>' + (owner ? '<span class="role-chip acct-name">Owner</span>' : '') + '<span class="caret" aria-hidden="true">▾</span></button>' +
        '<div class="menu" hidden><div class="menu-head"><b>' + u.full + (owner ? ' <span class="role-chip">Owner</span>' : '') + '</b><span>' + u.email + '</span></div>' +
        '<a href="' + withRole('account.html') + '"' + (active === 'account' ? ' aria-current="page"' : '') + '>Account</a>' +
        '<a href="' + withRole('tokens.html') + '"' + (active === 'tokens' ? ' aria-current="page"' : '') + '>API tokens &amp; MCP</a><hr>' +
        '<a href="login.html">Log out</a></div></div>'
      : '';
    var navm = nav
      ? '<div class="navm"><button type="button" class="top-btn" data-menu aria-expanded="false" aria-label="Menu, current page: ' + (cur ? cur[2] : 'Menu') + '">' + (cur ? cur[2] : 'Menu') + ' <span class="caret" aria-hidden="true">▾</span></button>' +
        '<div class="menu" hidden><nav aria-label="Main (menu)">' + links + '</nav></div></div>'
      : '';
    header.innerHTML = '<div class="topbar-in"><a class="brand" href="' + withRole(nav ? 'portfolio.html' : 'login.html') + '"><span class="brand-mark" aria-hidden="true"></span><span>Bridge</span></a>' +
      '<span class="sample-chip">Sample data</span>' +
      (nav ? '<nav class="nav" aria-label="Main">' + links + '</nav>' : '') +
      '<div class="topbar-end">' + navm + themeBtn + acct + '</div></div>';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    function applyRole(role) {
      if (!USERS[role]) role = 'user';
      body.dataset.role = role;
      var u = USERS[role];
      document.querySelectorAll('header.topbar').forEach(function (h) { shell(h, role); });
      document.querySelectorAll('[data-me]').forEach(function (el) { el.textContent = u.first; });
      document.querySelectorAll('[data-me-full]').forEach(function (el) { el.textContent = u.full; });
      document.querySelectorAll('[data-me-email]').forEach(function (el) { el.textContent = u.email; });
      document.querySelectorAll('[data-me-ini]').forEach(function (el) { el.textContent = u.ini; });
      document.querySelectorAll('[data-owner-only]').forEach(function (el) { el.hidden = role !== 'owner'; });
      document.querySelectorAll('[data-user-only]').forEach(function (el) { el.hidden = role === 'owner'; });
      setTheme(root.dataset.theme);
    }
    window.protoRole = applyRole;
    window.protoOpenMenu = function (sel) { var b = document.querySelector(sel + ' [data-menu]'); if (b) { b.setAttribute('aria-expanded', 'true'); b.nextElementSibling.hidden = false; } };
    applyRole(qs.get('role') || body.dataset.role || 'user');
    setTheme(root.dataset.theme);

    /* ---------- live region ---------- */
    var live = document.createElement('div');
    live.className = 'sr-only'; live.setAttribute('aria-live', 'polite');
    body.appendChild(live);
    function say(m) { live.textContent = ''; setTimeout(function () { live.textContent = m; }, 30); }

    /* ---------- menus ---------- */
    function closeMenus(except) {
      document.querySelectorAll('[data-menu][aria-expanded="true"]').forEach(function (b) {
        if (b === except) return;
        b.setAttribute('aria-expanded', 'false');
        var m = b.nextElementSibling; if (m) m.hidden = true;
      });
    }
    document.addEventListener('click', function (e) {
      var t = e.target.closest('.theme-btn');
      if (t) { setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark'); return; }
      var mb = e.target.closest('[data-menu]');
      if (mb) {
        var open = mb.getAttribute('aria-expanded') !== 'true';
        closeMenus(mb);
        mb.setAttribute('aria-expanded', String(open));
        mb.nextElementSibling.hidden = !open;
        return;
      }
      if (!e.target.closest('.menu')) closeMenus();

      var tab = e.target.closest('.col-tabs [role="tab"]');
      if (tab) selectCol(tab.dataset.col);

      var cp = e.target.closest('[data-copy]');
      if (cp) {
        var src = document.querySelector(cp.dataset.copy);
        var txt = src ? src.textContent.trim() : '';
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).catch(function () {});
        var old = cp.textContent; cp.textContent = 'Copied'; say('Copied');
        setTimeout(function () { cp.textContent = old; }, 1600);
      }
      var pw = e.target.closest('[data-pw]');
      if (pw) {
        var inp = document.querySelector(pw.dataset.pw);
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        pw.textContent = show ? 'Hide' : 'Show';
        pw.setAttribute('aria-pressed', String(show));
      }
      var tg = e.target.closest('[data-toggle]');
      if (tg) {
        var el = document.querySelector(tg.dataset.toggle);
        if (el) { el.hidden = !el.hidden; tg.setAttribute('aria-expanded', String(!el.hidden)); }
      }
    });

    /* ---------- mobile column tabs ---------- */
    function selectCol(key) {
      if (!key) return;
      document.querySelectorAll('.col-tabs [role="tab"]').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.col === key)); });
      document.querySelectorAll('.board .col').forEach(function (c) { c.classList.toggle('is-active', c.dataset.col === key); });
      var tabs = Array.prototype.slice.call(document.querySelectorAll('.col-tabs [role="tab"]'));
      document.querySelectorAll('.col-dots i').forEach(function (d, i) { d.classList.toggle('on', tabs[i] && tabs[i].dataset.col === key); });
    }
    window.selectCol = selectCol;
    var firstSel = document.querySelector('.col-tabs [aria-selected="true"]');
    if (firstSel) selectCol(firstSel.dataset.col);

    /* ---------- states ---------- */
    var states = (body.dataset.states || '').split('|').filter(Boolean).map(function (s) {
      var i = s.indexOf(':'); return { id: s.slice(0, i), label: s.slice(i + 1) };
    });
    if (states.length) {
      var ids = states.map(function (s) { return s.id; });
      var proto = document.createElement('aside');
      proto.className = 'proto';
      proto.setAttribute('aria-label', 'Prototype states (design aid)');
      proto.innerHTML = '<div class="pp" hidden><p>Prototype states, not part of the product. Also via <code>?state=</code>.</p><ul>' +
        states.map(function (s) { return '<li><button type="button" data-goto="' + s.id + '"><span>' + s.label + '</span><code>' + s.id + '</code></button></li>'; }).join('') +
        '</ul><a class="hub" href="index.html">All screens</a></div><button type="button" aria-expanded="false">States: <b class="cur"></b></button>';
      body.appendChild(proto);
      var pbtn = proto.querySelector(':scope > button'), panel = proto.querySelector('.pp');
      pbtn.addEventListener('click', function () { panel.hidden = !panel.hidden; pbtn.setAttribute('aria-expanded', String(!panel.hidden)); });

      var apply = function (id, push) {
        if (ids.indexOf(id) < 0) id = ids[0];
        body.dataset.state = id;
        document.querySelectorAll('[data-show]').forEach(function (el) { el.hidden = el.dataset.show.split(/\s+/).indexOf(id) < 0; });
        document.querySelectorAll('[data-hide]').forEach(function (el) { el.hidden = el.dataset.hide.split(/\s+/).indexOf(id) >= 0; });
        proto.querySelectorAll('[data-goto]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.goto === id)); });
        proto.querySelector('.cur').textContent = states[ids.indexOf(id)].label;
        if (push) { var url = new URL(location.href); url.searchParams.set('state', id); try { history.replaceState(null, '', url); } catch (e) { /* file:// */ } }
        if (typeof window.onProtoState === 'function') window.onProtoState(id);
        var auto = document.querySelector('.scrim:not([hidden]) [autofocus], .pop:not([hidden]) [autofocus]');
        if (auto && push) auto.focus();
      };
      document.addEventListener('click', function (e) {
        var g = e.target.closest('[data-goto]');
        if (g) { e.preventDefault(); apply(g.dataset.goto, true); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeMenus();
        var open = document.querySelector('.scrim:not([hidden]) [data-close], .pop:not([hidden]) [data-close]');
        if (open) apply(open.dataset.close, true);
      });
      apply(qs.get('state') || ids[0], false);
    } else {
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(); });
    }
  });
})();
