/*!
 * Bridge · hero banner animation
 * Vanilla JS, no dependencies. Hydrates every [data-bridge-hero] element whose
 * markup is already in the page (see README.md for the snippet).
 *
 * How it works
 *  - One 13 000 ms loop. A single requestAnimationFrame clock advances a time
 *    value `t`; render(t) is a pure function of `t`, so pause/play resumes on
 *    the exact frame and seek(ms) is deterministic.
 *  - Cards: FLIP-style. Each card's position in each of the 4 board states is
 *    measured once (cards are laid out in the real columns), then the cards
 *    move to an overlay and are only ever moved with transform + opacity.
 *  - Only transform and opacity are animated.
 */
(function () {
  "use strict";

  var LOOP = 13000;
  var FINAL = 11000; // static frame for prefers-reduced-motion
  var STATES = 4;

  // Beats (ms). Read top to bottom like a storyboard.
  var B = {
    postWin: [600, 2900],      // Ava active: post_status
    postPulse: [700, 1150],    // pulse travels chip → board
    oldOut: [900, 1100],       // previous status fades
    typeAt: 1150, typeStep: 45, charFade: 70,
    caretOff: 3000,
    timeSwap: [2750, 2950],    // "2h ago" → "now"
    move1Win: [3300, 4800],
    move1Pulse: [3350, 3800],
    move1: [3800, 4700],       // To do → In progress
    pickAv: [4400, 4800],      // Ava avatar lands on picked card
    move2Win: [5200, 6600],
    move2Pulse: [5250, 5700],
    move2: [5600, 6500],       // In progress → Waiting on you
    approveIn: [6300, 6600],
    glowIn: [6400, 6800],
    rings: [6500, 7350],       // two lime pulses, 850 ms each
    cursorIn: [7000, 8150],
    press: [8250, 8450],
    approveOut: [8450, 8600],
    okIn: [8500, 8750],
    move3: [8700, 9600],       // Waiting on you → Done
    glowOut: [8700, 9300],
    cursorOut: [8850, 9700],
    // calm hold 9600 → 11900
    resetOut: [11900, 12450],  // changed cards + status fade out
    resetMove: [12150, 12900], // siblings glide back
    resetIn: [12450, 13000]    // frame-0 cards fade back in
  };
  var MOVES = [
    { from: 0, to: 1, w: B.move1 },
    { from: 1, to: 2, w: B.move2 },
    { from: 2, to: 3, w: B.move3 }
  ];

  // ---------- helpers ----------
  function clamp01(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
  function prog(t, w) { return clamp01((t - w[0]) / (w[1] - w[0])); }
  function easeIO(p) { return p < .5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; }
  function easeOut(p) { return 1 - Math.pow(1 - p, 3); }
  function lerp(a, b, p) { return a + (b - a) * p; }
  // fade in over w, hold, fade out over w2 (both optional)
  function win(t, a, b, f) {
    f = f || 200;
    if (t < a || t > b) return 0;
    return Math.min(clamp01((t - a) / f), clamp01((b - t) / f));
  }
  function setO(el, v) {
    var s = v < .001 ? "0" : v > .999 ? "1" : v.toFixed(3);
    if (el._o !== s) { el.style.opacity = s; el._o = s; }
  }
  function setT(el, v) { if (el._t !== v) { el.style.transform = v; el._t = v; } }
  function qb(p0, p1, p2, q) { // quadratic bezier point
    var u = 1 - q;
    return { x: u * u * p0.x + 2 * u * q * p1.x + q * q * p2.x, y: u * u * p0.y + 2 * u * q * p1.y + q * q * p2.y };
  }
  function $(root, s) { return root.querySelector(s); }
  function $$(root, s) { return Array.prototype.slice.call(root.querySelectorAll(s)); }

  var reducedMQ = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : { matches: false };
  var instances = [];

  // ---------- one shared rAF clock ----------
  var rafId = 0, last = 0;
  function tick(now) {
    rafId = 0;
    var dt = last ? Math.min(now - last, 64) : 0; // cap so tab switches never jump
    last = now;
    var any = false;
    for (var i = 0; i < instances.length; i++) {
      var inst = instances[i];
      if (inst._running()) {
        inst._t = (inst._t + dt) % LOOP;
        inst._render(inst._t);
        any = true;
      }
    }
    if (any) rafId = requestAnimationFrame(tick); else last = 0;
  }
  function wake() { if (!rafId) { last = 0; rafId = requestAnimationFrame(tick); } }

  // ---------- instance ----------
  function Hero(el) {
    var self = this;
    this.el = el;
    this._t = 0;
    this._playing = true;   // user intent (pause()/play())
    this._visible = true;   // IntersectionObserver
    this._ready = false;

    var stage = $(el, ".bh-stage");
    var layer = $(el, ".bh-cards");
    var refs = {
      stage: stage,
      layer: layer,
      bot: $(stage, ".bh-bot"),
      botDot: $(stage, ".bh-bot .bh-dot"),
      botRing: $(stage, ".bh-bot .bh-dot__ring"),
      botGlow: $(stage, ".bh-bot__glow"),
      idle: $(stage, ".bh-bot__idle"),
      toolPost: $(stage, '.bh-tool[data-tool="post_status"]'),
      toolMove: $(stage, '.bh-tool[data-tool="move_ticket"]'),
      wireBase: $(stage, ".bh-wire__base"),
      wireHot: $(stage, ".bh-wire__hot"),
      wireSvg: $(stage, ".bh-wire"),
      wireLabel: $(stage, ".bh-wire__label"),
      pulse: $(stage, ".bh-pulse"),
      statusWho: $(stage, ".bh-status__who .bh-dot"),
      body: $(stage, ".bh-status__body"),
      old: $(stage, ".bh-status__old"),
      neu: $(stage, ".bh-status__new"),
      caret: $(stage, ".bh-caret"),
      tag: $(stage, ".bh-status__tag"),
      timeOld: $(stage, ".bh-time-old"),
      timeNew: $(stage, ".bh-time-new"),
      cursor: $(stage, ".bh-cursor"),
      cols: {},
      pills: {}
    };
    this._refs = refs;
    $$(stage, ".bh-col").forEach(function (c) { refs.cols[c.getAttribute("data-col")] = c; });
    $$(stage, ".bh-pill").forEach(function (p) { refs.pills[p.getAttribute("data-pill")] = p; });

    // Status text → one span per character (text stays real DOM text).
    var text = refs.neu.getAttribute("data-text") || refs.neu.textContent.trim();
    refs.neu.setAttribute("data-text", text);
    refs.neu.textContent = "";
    refs.chars = text.split("").map(function (ch) {
      var s = document.createElement("span");
      s.className = "bh-ch";
      s.textContent = ch;
      refs.neu.appendChild(s);
      return s;
    });

    // Cards and their column in each state.
    var order = 0;
    this._cards = $$(stage, ".bh-card").map(function (c) {
      var cols = (c.getAttribute("data-cols") || "").trim().split(/\s+/);
      while (cols.length < STATES) cols.push(cols[cols.length - 1]);
      return {
        el: c, cols: cols, i: order++,
        lift: ensure(c, "bh-card__lift"),
        glow: null, ring: null,
        swapA: $(c, ".bh-swap-a"), swapB: $(c, ".bh-swap-b"),
        approve: $(c, ".bh-approve"), ok: $(c, ".bh-ok"),
        pos: []
      };
    });
    // Roles derived from the state data.
    this._cards.forEach(function (c) {
      if (c.cols[0] !== c.cols[1]) self._pick = c;
      if (c.cols[2] !== c.cols[3]) self._appr = c;
    });
    if (this._appr) {
      this._appr.glow = ensure(this._appr.el, "bh-card__glow");
      this._appr.ring = ensure(this._appr.el, "bh-card__ring");
    }

    // Counters per column per state.
    var counts = {};
    ["todo", "progress", "waiting", "done"].forEach(function (k) {
      counts[k] = [];
      for (var s = 0; s < STATES; s++) {
        counts[k][s] = self._cards.filter(function (c) { return c.cols[s] === k; }).length;
      }
    });
    this._counts = counts;
    this._countEls = $$(stage, "[data-count]").map(function (n) {
      n.textContent = "";
      var a = document.createElement("span"), b = document.createElement("span");
      n.appendChild(a); n.appendChild(b);
      return { key: n.getAttribute("data-count"), a: a, b: b };
    });

    // API on the element
    el.bridgeHero = this;

    this._measure();
    this._ready = true;

    // Observers
    if ("IntersectionObserver" in window) {
      this._io = new IntersectionObserver(function (entries) {
        self._visible = entries[entries.length - 1].isIntersecting;
        wake();
      }, { threshold: 0.05 });
      this._io.observe(stage);
    }
    var rzTimer = 0, lastW = stage.clientWidth, lastH = stage.clientHeight;
    var onResize = function () {
      clearTimeout(rzTimer);
      rzTimer = setTimeout(function () {
        if (stage.clientWidth === lastW && stage.clientHeight === lastH) return;
        lastW = stage.clientWidth; lastH = stage.clientHeight;
        self._remeasure(true);
      }, 160);
    };
    if ("ResizeObserver" in window) {
      this._ro = new ResizeObserver(onResize);
      this._ro.observe(stage);
    } else {
      window.addEventListener("resize", onResize);
    }
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () { self._remeasure(false); });
    }

    this._render(reducedMQ.matches ? FINAL : this._t);
    wake();
  }

  function ensure(card, cls) {
    var e = card.querySelector("." + cls);
    if (!e) {
      e = document.createElement("span");
      e.className = cls;
      card.insertBefore(e, card.firstChild);
    }
    return e;
  }

  Hero.prototype._running = function () {
    return this._ready && this._playing && this._visible && !document.hidden && !reducedMQ.matches;
  };

  Hero.prototype._remeasure = function (restart) {
    this._measure();
    if (restart && this._playing && !reducedMQ.matches) this._t = 0;
    this._render(reducedMQ.matches ? FINAL : this._t);
    wake();
  };

  // Lay cards out in each state inside the real columns, record positions,
  // then park them in the overlay for transform-only animation.
  Hero.prototype._measure = function () {
    var r = this._refs, cards = this._cards, stage = r.stage;
    var sr = stage.getBoundingClientRect();
    function rel(e) {
      var b = e.getBoundingClientRect();
      return { x: b.left - sr.left, y: b.top - sr.top, w: b.width, h: b.height };
    }
    cards.forEach(function (c) {
      c.el.style.transform = ""; c.el._t = null;
      c.el.style.opacity = ""; c.el._o = null;
      c.el.style.width = ""; c.el.style.height = "";
    });
    var size = null, self = this, approveOff = null;
    for (var s = 0; s < STATES; s++) {
      Object.keys(r.cols).forEach(function (k) {
        var list = $(r.cols[k], ".bh-list");
        var inCol = cards.filter(function (c) { return c.cols[s] === k; });
        inCol.sort(function (a, b) { return arrival(b, s) - arrival(a, s) || a.i - b.i; });
        inCol.forEach(function (c) { list.appendChild(c.el); });
      });
      cards.forEach(function (c) {
        var col = r.cols[c.cols[s]];
        var hidden = !col || getComputedStyle(col).display === "none";
        if (!hidden) {
          var p = rel(c.el);
          c.pos[s] = { x: p.x, y: p.y, v: 1 };
          if (!size) size = p;
        } else {
          c.pos[s] = { pill: c.cols[s], v: 0 };
        }
      });
      if (s === 2 && self._appr && self._appr.approve) {
        var cr = rel(self._appr.el), br = rel(self._appr.approve);
        approveOff = { x: br.x - cr.x + br.w * .55, y: br.y - cr.y + br.h * .6 };
      }
    }
    // Folded columns (mobile): park the card centred on its counter pill.
    cards.forEach(function (c) {
      for (var s = 0; s < STATES; s++) {
        var p = c.pos[s];
        if (p.pill) {
          var pill = r.pills[p.pill];
          var pr = pill && getComputedStyle(pill.parentNode).display !== "none" ? rel(pill) : { x: sr.width / 2, y: sr.height, w: 0, h: 0 };
          c.pos[s] = { x: pr.x + pr.w / 2 - size.w / 2, y: pr.y + pr.h / 2 - size.h / 2, v: 0 };
        }
      }
    });
    // Approve button centre (relative to the card) — measured in state 2.
    if (approveOff) {
      var p2 = this._appr.pos[2];
      this._target = { x: p2.x + approveOff.x, y: p2.y + approveOff.y };
    }
    // Park cards in the overlay.
    cards.forEach(function (c) {
      c.el.style.width = size.w + "px";
      c.el.style.height = size.h + "px";
      r.layer.appendChild(c.el);
    });
    this._size = { w: sr.width, h: sr.height };

    // MCP wire: leaves the chip's left edge, runs down the gutter beside the
    // board and enters the status strip at Ava's dot (connected, not embedded).
    var chip = rel(r.bot), dot = rel(r.statusWho), board = rel($(stage, ".bh-board"));
    var cm = chip.y + chip.h / 2, sy = dot.y + dot.h / 2;
    var gx = Math.max(4, board.x * .45), rr = Math.min(10, (sy - cm) / 3);
    var f = function (v) { return v.toFixed(1); };
    var d = "M" + f(chip.x) + "," + f(cm) + " H" + f(gx + rr) + " Q" + f(gx) + "," + f(cm) + " " + f(gx) + "," + f(cm + rr) +
      " V" + f(sy - rr) + " Q" + f(gx) + "," + f(sy) + " " + f(gx + rr) + "," + f(sy) + " H" + f(dot.x - 3);
    r.wireSvg.setAttribute("viewBox", "0 0 " + f(sr.width) + " " + f(sr.height));
    r.wireBase.setAttribute("d", d);
    r.wireHot.setAttribute("d", d);
    this._wireLen = r.wireBase.getTotalLength ? r.wireBase.getTotalLength() : 0;
    var lw = r.wireLabel.offsetWidth, lh = r.wireLabel.offsetHeight;
    var lx = Math.max(gx + rr, (gx + chip.x) / 2 - lw / 2);
    r.wireLabel.style.transform = "translate(" + f(lx) + "px," + f(cm - lh / 2) + "px)";
    r.wireLabel._t = null;

    // Typing caret stops (end of each char, relative to the status body).
    var br0 = r.body.getBoundingClientRect();
    r.caretStops = [{ x: 0, y: 0 }].concat(r.chars.map(function (ch) {
      var cb = ch.getBoundingClientRect();
      return { x: cb.right - br0.left, y: cb.top - br0.top };
    }));
    var first = r.chars[0] && r.chars[0].getBoundingClientRect();
    if (first) r.caretStops[0] = { x: first.left - br0.left, y: first.top - br0.top };

    function arrival(c, s) { var k = s; while (k > 0 && c.cols[k - 1] === c.cols[s]) k--; return k; }
  };

  Hero.prototype._render = function (t) {
    var r = this._refs, self = this;
    var resetting = t >= B.resetOut[0];
    var afterReset = t >= B.resetIn[0];

    // ---- bot chip, tools, wire ----
    var act = Math.max(win(t, B.postWin[0], B.postWin[1], 250), win(t, B.move1Win[0], B.move1Win[1], 250), win(t, B.move2Win[0], B.move2Win[1], 250));
    setO(r.botGlow, act);
    setO(r.wireHot, act * .9);
    var ringP = -1;
    [B.postWin, B.move1Win, B.move2Win].forEach(function (w) {
      if (t >= w[0] && t < w[1]) ringP = ((t - w[0]) % 900) / 900;
    });
    if (ringP >= 0) {
      setT(r.botRing, "scale(" + (1 + ringP * 1.8).toFixed(3) + ")");
      setO(r.botRing, .55 * (1 - ringP) * act);
    } else setO(r.botRing, 0);
    var tp = win(t, B.postWin[0] + 100, B.postWin[1], 200);
    var tm = Math.max(win(t, B.move1Win[0], B.move1Win[1], 200), win(t, B.move2Win[0], B.move2Win[1], 200));
    setO(r.toolPost, tp);
    setO(r.toolMove, tm);
    setO(r.idle, 1 - Math.max(tp, tm));

    var pulse = null;
    [B.postPulse, B.move1Pulse, B.move2Pulse].forEach(function (w) { if (t >= w[0] && t <= w[1]) pulse = w; });
    if (pulse && this._wireLen) {
      var q = easeIO(prog(t, pulse));
      var pt = r.wireBase.getPointAtLength(q * this._wireLen);
      setT(r.pulse, "translate(" + pt.x.toFixed(1) + "px," + pt.y.toFixed(1) + "px)");
      setO(r.pulse, Math.sin(Math.PI * q));
    } else setO(r.pulse, 0);

    // ---- status strip ----
    var outF = 1 - prog(t, B.resetOut);           // 1 → 0 during reset fade-out
    var inF = prog(t, B.resetIn);                  // 0 → 1 during reset fade-in
    var oldO = afterReset ? inF : 1 - prog(t, B.oldOut);
    setO(r.old, oldO);
    var newO = afterReset ? 0 : outF;
    setO(r.neu, newO);
    var nChars = r.chars.length, shown = 0;
    for (var i = 0; i < nChars; i++) {
      var ci = clamp01((t - (B.typeAt + i * B.typeStep)) / B.charFade);
      if (afterReset) ci = 0;
      setO(r.chars[i], ci);
      if (t >= B.typeAt + i * B.typeStep) shown = i + 1;
    }
    var typeEnd = B.typeAt + nChars * B.typeStep;
    var caretO = 0;
    if (t >= B.typeAt - 150 && t < B.caretOff) {
      caretO = t < typeEnd ? 1 : (Math.floor((t - typeEnd) / 260) % 2 ? 0 : 1) * (1 - prog(t, [B.caretOff - 120, B.caretOff]));
    }
    setO(r.caret, caretO);
    if (r.caretStops) {
      var cs = r.caretStops[Math.min(shown, r.caretStops.length - 1)];
      setT(r.caret, "translate(" + (cs.x + 1).toFixed(1) + "px," + cs.y.toFixed(1) + "px)");
    }
    var tswap = afterReset ? 1 - inF : resetting ? 1 : prog(t, B.timeSwap);
    if (resetting && !afterReset) { setO(r.timeNew, outF); setO(r.timeOld, 0); }
    else { setO(r.timeNew, tswap); setO(r.timeOld, 1 - tswap); }
    setO(r.tag, 1);

    // ---- which board state are we in? ----
    var seg = null; // {from,to,p,w}
    for (var m = 0; m < MOVES.length; m++) {
      var mv = MOVES[m];
      if (t < mv.w[0]) { seg = { from: mv.from, to: mv.from, p: 0 }; break; }
      if (t <= mv.w[1]) { seg = { from: mv.from, to: mv.to, p: prog(t, mv.w), move: true }; break; }
    }
    if (!seg) seg = { from: 3, to: 3, p: 0 };
    if (resetting) seg = { from: 3, to: 0, p: easeIO(prog(t, B.resetMove)), reset: true };

    // ---- cards ----
    this._cards.forEach(function (c) {
      var A = c.pos[seg.from], Z = c.pos[seg.to];
      if (!A || !Z) return;
      var changed = c.cols[seg.from] !== c.cols[seg.to];
      var x, y, sc = 1, rot = 0, o, lift = 0;
      if (seg.reset && changed) {
        // cross-fade in place instead of travelling back
        var P = afterReset ? Z : A;
        x = P.x; y = P.y;
        o = afterReset ? inF * Z.v : outF * A.v;
        sc = afterReset ? lerp(.97, 1, inF) : lerp(1, .97, 1 - outF);
      } else {
        var p = seg.move ? easeIO(seg.p) : seg.p;
        x = lerp(A.x, Z.x, p); y = lerp(A.y, Z.y, p);
        var vis = lerp(A.v, Z.v, p);
        o = vis;
        sc = .45 + .55 * vis;
        if (changed && seg.move) {
          var arc = Math.sin(Math.PI * p);
          y -= arc * self._size.h * .035;
          sc *= 1 + .035 * arc;
          rot = (Z.x >= A.x ? 1 : -1) * 1.2 * arc;
          lift = arc;
        }
      }
      c.el.classList.toggle("is-moving", changed && (seg.move || seg.reset));
      setT(c.el, "translate3d(" + x.toFixed(2) + "px," + y.toFixed(2) + "px,0) scale(" + sc.toFixed(4) + ") rotate(" + rot.toFixed(3) + "deg)");
      setO(c.el, o);
      setO(c.lift, lift);
    });

    // picked-up card: empty avatar → Ava
    var pk = this._pick;
    if (pk && pk.swapA && pk.swapB) {
      var pa = afterReset ? 0 : prog(t, B.pickAv);
      setO(pk.swapA, 1 - pa); setO(pk.swapB, pa);
      setT(pk.swapB, "scale(" + lerp(.6, 1, easeOut(pa)).toFixed(3) + ")");
    }

    // approval card: Ava → Approve → Approved
    var ap = this._appr;
    if (ap) {
      var aIn = prog(t, B.approveIn), aOut = prog(t, B.approveOut), ok = prog(t, B.okIn);
      if (afterReset) { aIn = 0; aOut = 0; ok = 0; }
      if (ap.swapA) setO(ap.swapA, 1 - aIn);
      if (ap.approve) {
        setO(ap.approve, aIn * (1 - aOut));
        var pr = prog(t, B.press);
        setT(ap.approve, "scale(" + (1 - .08 * Math.sin(Math.PI * pr)).toFixed(4) + ")");
      }
      if (ap.ok) {
        setO(ap.ok, ok);
        setT(ap.ok, "translateY(" + ((1 - easeOut(ok)) * 4).toFixed(2) + "px)");
      }
      var g = afterReset ? 0 : easeOut(prog(t, B.glowIn)) * (1 - prog(t, B.glowOut));
      setO(ap.glow, g);
      var rp = -1, rw = B.rings, rl = (rw[1] - rw[0]) / 2;
      if (t >= rw[0] && t < rw[1] + rl) rp = ((t - rw[0]) % rl) / rl;
      if (rp >= 0 && t < rw[1] + rl - 1) {
        setT(ap.ring, "scale(" + (1 + .07 * easeOut(rp)).toFixed(4) + ")");
        setO(ap.ring, .9 * (1 - rp));
      } else setO(ap.ring, 0);
    }

    // ---- counters ----
    this._countEls.forEach(function (n) {
      var v = self._counts[n.key];
      if (!v) return;
      var a = v[seg.from], b = v[seg.to], p;
      if (a === b) { p = 1; }
      else p = seg.reset ? (afterReset ? 1 : 0) : clamp01((seg.p - .35) / .5);
      var ta = String(a), tb = String(b);
      if (n.a.textContent !== ta) n.a.textContent = ta;
      if (n.b.textContent !== tb) n.b.textContent = tb;
      setO(n.a, a === b ? 0 : 1 - p);
      setO(n.b, p);
      setT(n.a, "translateY(" + (-p * 60).toFixed(1) + "%)");
      setT(n.b, "translateY(" + ((1 - p) * 60).toFixed(1) + "%)");
    });

    // ---- human cursor ----
    var tg = this._target, W = this._size.w, H = this._size.h;
    if (tg) {
      var cx, cy, co = 0, csc = 1;
      var start = { x: W * 1.04, y: H * .98 };
      var end = { x: tg.x + W * .14, y: H * 1.05 };
      if (t >= B.cursorIn[0] && t < B.cursorOut[1]) {
        if (t < B.press[0]) {
          var ci2 = easeIO(prog(t, B.cursorIn));
          var cp = qb(start, { x: lerp(start.x, tg.x, .3), y: tg.y + H * .2 }, tg, ci2);
          cx = cp.x; cy = cp.y;
          co = clamp01((t - B.cursorIn[0]) / 300);
        } else if (t < B.cursorOut[0]) {
          cx = tg.x; cy = tg.y; co = 1;
          csc = 1 - .12 * Math.sin(Math.PI * prog(t, B.press));
        } else {
          var co2 = prog(t, B.cursorOut);
          var e2 = easeIO(co2);
          cx = lerp(tg.x, end.x, e2); cy = lerp(tg.y, end.y, e2);
          co = 1 - clamp01((co2 - .2) / .7);
        }
        setT(r.cursor, "translate3d(" + cx.toFixed(1) + "px," + cy.toFixed(1) + "px,0) scale(" + csc.toFixed(3) + ")");
      }
      setO(r.cursor, co);
    }
  };

  // ---------- public API ----------
  Hero.prototype.pause = function () { this._playing = false; return this; };
  Hero.prototype.play = function () {
    this._playing = true;
    if (reducedMQ.matches) this._render(FINAL);
    wake();
    return this;
  };
  // Render the exact frame at `ms` and freeze there (for screenshots/QA).
  Hero.prototype.seek = function (ms) {
    this._playing = false;
    this._t = ((Number(ms) || 0) % LOOP + LOOP) % LOOP;
    this._render(this._t);
    return this;
  };
  Hero.prototype.destroy = function () {
    this._playing = false;
    if (this._io) this._io.disconnect();
    if (this._ro) this._ro.disconnect();
    var idx = instances.indexOf(this);
    if (idx >= 0) instances.splice(idx, 1);
    delete this.el.bridgeHero;
  };

  function init(el) {
    if (!el || el.bridgeHero) return el && el.bridgeHero;
    if (!el.querySelector(".bh-stage")) return null;
    var inst = new Hero(el);
    instances.push(inst);
    applyDebugSeek(inst);
    return inst;
  }

  function applyDebugSeek(inst) {
    var m = /[?&]t=(\d+)/.exec(window.location.search);
    if (m) inst.seek(parseInt(m[1], 10));
  }

  function initAll() {
    $$(document, "[data-bridge-hero]").forEach(init);
  }

  document.addEventListener("visibilitychange", wake);
  var onMQ = function () {
    instances.forEach(function (inst) {
      if (reducedMQ.matches) inst._render(FINAL);
      else { inst._t = 0; inst._render(0); }
    });
    wake();
  };
  if (reducedMQ.addEventListener) reducedMQ.addEventListener("change", onMQ);
  else if (reducedMQ.addListener) reducedMQ.addListener(onMQ);

  window.BridgeHero = {
    init: init,
    instances: instances,
    loop: LOOP,
    seek: function (ms) { instances.forEach(function (i) { i.seek(ms); }); },
    pause: function () { instances.forEach(function (i) { i.pause(); }); },
    play: function () { instances.forEach(function (i) { i.play(); }); }
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initAll);
  else initAll();
})();
