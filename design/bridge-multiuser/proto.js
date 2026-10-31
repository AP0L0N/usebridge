/* Bridge multi-user prototypes — shared behaviour (vanilla, no build).
 *
 * States
 *   <body data-states="id:Label|id:Label">   first id is the default
 *   ?state=id in the URL selects one; the .proto switcher flips them in place.
 *   [data-show="a b"]  visible only in those states
 *   [data-hide="a b"]  hidden in those states
 *   [data-goto="id"]   click switches to that state (prototype click-through)
 *   window.onProtoState = fn(id) for page-specific tweaks (optional)
 *
 * Helpers
 *   [data-copy="#id"]  copies the target's text, says "Copied" (aria-live)
 *   [data-pw="#id"]    show/hide password toggle
 *   [data-strength="#meterId"] on a password input: live demo of the meter
 *   .mdl               a visible modal makes the rest of the page inert and takes focus;
 *                      Esc / its [data-goto] close button returns to the base state
 *
 * In React these become component state; nothing here is product logic.
 */
(function () {
  'use strict';
  var body = document.body;
  var states = (body.dataset.states || '').split('|').filter(Boolean).map(function (s) {
    var i = s.indexOf(':');
    return { id: s.slice(0, i), label: s.slice(i + 1) };
  });
  if (!states.length) return;
  var ids = states.map(function (s) { return s.id; });

  // one polite live region for copy confirmations
  var live = document.getElementById('live');
  if (!live) {
    live = document.createElement('div');
    live.id = 'live'; live.className = 'sr-only';
    live.setAttribute('aria-live', 'polite');
    body.appendChild(live);
  }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }

  /* ---------- switcher ---------- */
  var proto = document.createElement('aside');
  proto.className = 'proto';
  proto.setAttribute('aria-label', 'Prototype states (design aid)');
  var list = states.map(function (s) {
    return '<li><button type="button" data-goto="' + s.id + '">' + s.label + ' <code>' + s.id + '</code></button></li>';
  }).join('');
  proto.innerHTML =
    '<div class="pp" id="proto-panel" hidden><p>Prototype states, not part of the product. Also via <code>?state=</code>.</p><ul>' + list +
    '</ul><a class="hub" href="index.html">All screens</a></div>' +
    '<button type="button" aria-expanded="false" aria-controls="proto-panel">States: <b class="cur"></b></button>';
  body.appendChild(proto);
  var toggle = proto.querySelector(':scope > button');
  var panel = proto.querySelector('.pp');
  toggle.addEventListener('click', function () {
    var open = panel.hidden;
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
  });

  /* ---------- apply a state ---------- */
  var current = null;
  function apply(id, push) {
    if (ids.indexOf(id) < 0) id = ids[0];
    current = id;
    body.dataset.state = id;
    document.querySelectorAll('[data-show]').forEach(function (el) {
      el.hidden = el.dataset.show.split(/\s+/).indexOf(id) < 0;
    });
    document.querySelectorAll('[data-hide]').forEach(function (el) {
      el.hidden = el.dataset.hide.split(/\s+/).indexOf(id) >= 0;
    });
    proto.querySelectorAll('[data-goto]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.goto === id));
    });
    var st = states[ids.indexOf(id)];
    proto.querySelector('.cur').textContent = st.label;
    if (push) {
      var u = new URL(location.href);
      u.searchParams.set('state', id);
      try { history.replaceState(null, '', u); } catch (e) { /* file:// in some browsers */ }
    }
    // modal: make the page behind inert and move focus in
    var modal = Array.prototype.find.call(document.querySelectorAll('.mdl'), function (m) { return !m.hidden; });
    document.querySelectorAll('body > header, body > main, body > footer').forEach(function (el) {
      if (modal) el.setAttribute('inert', ''); else el.removeAttribute('inert');
    });
    if (modal && push) {
      var f = modal.querySelector('[autofocus]') || modal.querySelector('input, select, textarea, .mdlf button');
      if (f) f.focus();
    }
    // error summaries take focus when they appear after a user action
    if (push) {
      var sum = Array.prototype.find.call(document.querySelectorAll('.errsum'), function (e) { return e.offsetParent; });
      if (sum && !modal) sum.focus();
    }
    if (typeof window.onProtoState === 'function') window.onProtoState(id);
  }

  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-goto]');
    if (g) {
      e.preventDefault();
      apply(g.dataset.goto, true);
      if (proto.contains(g)) { /* keep panel open */ } else window.scrollTo(0, g.closest('.mdl') ? window.scrollY : 0);
      return;
    }
    var c = e.target.closest('[data-copy]');
    if (c) {
      var src = document.querySelector(c.dataset.copy);
      var text = src ? src.textContent.trim() : '';
      copy(text).then(function () { flash(c, 'Copied'); say('Copied to clipboard'); },
                      function () { flash(c, 'Select & copy'); say('Copy failed, select the text and copy it'); });
      return;
    }
    var p = e.target.closest('[data-pw]');
    if (p) {
      var inp = document.querySelector(p.dataset.pw);
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      p.textContent = show ? 'Hide' : 'Show';
      p.setAttribute('aria-pressed', String(show));
      return;
    }
    // close open menus when clicking elsewhere
    document.querySelectorAll('details.acct[open], details.navm[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('details.acct[open], details.navm[open]');
    if (open) { open.removeAttribute('open'); open.querySelector('summary').focus(); return; }
    var modal = Array.prototype.find.call(document.querySelectorAll('.mdl'), function (m) { return !m.hidden; });
    if (modal && modal.dataset.close) apply(modal.dataset.close, true);
  });

  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (ok, bad) {
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      body.appendChild(t); t.select();
      var done = false;
      try { done = document.execCommand('copy'); } catch (err) { done = false; }
      t.remove();
      done ? ok() : bad();
    });
  }
  function flash(btn, label) {
    var lab = btn.querySelector('.l') || btn;
    var old = lab.dataset.old || lab.textContent;
    lab.dataset.old = old;
    lab.textContent = label;
    clearTimeout(btn._t);
    btn._t = setTimeout(function () { lab.textContent = old; }, 2000);
  }

  /* ---------- password strength demo ---------- */
  var WORDS = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
  var TIPS = ['use at least 10 characters', 'add a number or symbol', 'add a few more characters', 'longer is even better', 'nice'];
  document.querySelectorAll('[data-strength]').forEach(function (inp) {
    var m = document.querySelector(inp.dataset.strength);
    if (!m) return;
    inp.addEventListener('input', function () {
      var v = inp.value, s = 0;
      if (v.length >= 10) s = 1;
      if (s && /\d|[^A-Za-z0-9]/.test(v)) s = 2;
      if (s >= 2 && v.length >= 14) s = 3;
      if (s >= 3 && /[A-Z]/.test(v) && /[^A-Za-z0-9]/.test(v)) s = 4;
      m.dataset.score = String(s);
      m.querySelector('.w').textContent = WORDS[s];
      m.querySelector('.mtip').textContent = TIPS[s];
    });
  });

  var q = new URLSearchParams(location.search).get('state');
  apply(q || ids[0], false);
})();
