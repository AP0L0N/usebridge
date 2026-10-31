<div class="bh" data-bridge-hero>
  <p class="bh-sr">Animated example board for the sample project Garden planner. A bot called Ava, connected over MCP, posts the status “Deployed staging build, tests green”, moves “Add watering reminders” from To do to In progress, and moves “Fix login redirect” to Waiting on you. Sam Rivera approves it and it moves to Done. The loop then repeats.</p>

  <div class="bh-stage" aria-hidden="true">
    <svg class="bh-wire"><path class="bh-wire__base" d=""/><path class="bh-wire__hot" d=""/></svg>
    <span class="bh-wire__label">MCP</span>
    <span class="bh-pulse"></span>

    <div class="bh-bot">
      <span class="bh-bot__glow"></span>
      <span class="bh-dot"><span class="bh-dot__ring"></span></span>
      <span class="bh-bot__name">Ava · via MCP</span>
      <span class="bh-bot__tools">
        <span class="bh-bot__idle">connected</span>
        <span class="bh-tool" data-tool="post_status">post_status</span>
        <span class="bh-tool" data-tool="move_ticket">move_ticket</span>
      </span>
    </div>

    <span class="bh-sample">Sample data</span>

    <div class="bh-board">
      <header class="bh-head">
        <!-- Logo mark: inlined from assets/mark.svg -->
        <svg class="bh-head__mark" viewBox="0 0 96 96"><rect width="96" height="96" rx="22" fill="#0B0C0F"/><path d="M24 59a24 24 0 0 1 48 0" fill="none" stroke="#FFF" stroke-width="11" stroke-linecap="round"/><circle cx="24" cy="59" r="11" fill="#FFF"/><circle cx="72" cy="59" r="11" fill="#C8F135"/></svg>
        <div class="bh-head__title">
          <strong>Garden planner</strong>
          <span>Owner: Sam Rivera</span>
        </div>
        <div class="bh-head__right">
          <span class="bh-proj">On track</span>
          <span class="bh-av bh-av--sam">SR</span>
        </div>
      </header>

      <div class="bh-status">
        <span class="bh-status__who"><span class="bh-dot"></span>Ava</span>
        <span class="bh-status__body">
          <span class="bh-status__old">Started work on the login redirect fix</span>
          <span class="bh-status__new">Deployed staging build, tests green</span>
          <span class="bh-caret"></span>
        </span>
        <code class="bh-tag bh-status__tag">post_status</code>
        <span class="bh-status__time"><span class="bh-time-old">2h ago</span><span class="bh-time-new">now</span></span>
      </div>

      <!-- Shown on mobile only, where To do and Done fold into counters -->
      <div class="bh-pills">
        <span class="bh-pill" data-pill="todo">To do <b class="bh-n" data-count="todo"></b></span>
        <span class="bh-pill bh-pill--done" data-pill="done">Done <b class="bh-n" data-count="done"></b></span>
      </div>

      <div class="bh-cols">
        <!-- data-cols = column in each of the 4 beats: start · after pickup · after hand-off · after approval -->
        <section class="bh-col" data-col="todo">
          <h3 class="bh-col__head">To do <b class="bh-n" data-count="todo"></b></h3>
          <div class="bh-list">
            <article class="bh-card" data-cols="todo progress progress progress">
              <p class="bh-card__title">Add watering reminders</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-21</span>
                <span class="bh-card__side"><span class="bh-av bh-av--empty bh-swap-a"></span><span class="bh-av bh-av--ava bh-swap-b">A</span></span></div>
            </article>
            <article class="bh-card" data-cols="todo todo todo todo">
              <p class="bh-card__title">Plant calendar export</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-19</span>
                <span class="bh-card__side"><span class="bh-av bh-av--empty"></span></span></div>
            </article>
            <article class="bh-card" data-cols="todo todo todo todo">
              <p class="bh-card__title">Write onboarding copy</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-17</span>
                <span class="bh-card__side"><span class="bh-av bh-av--sam">SR</span></span></div>
            </article>
          </div>
        </section>

        <section class="bh-col" data-col="progress">
          <h3 class="bh-col__head">In progress <b class="bh-n" data-count="progress"></b></h3>
          <div class="bh-list">
            <article class="bh-card" data-cols="progress progress waiting done">
              <p class="bh-card__title">Fix login redirect</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-14</span>
                <span class="bh-card__side">
                  <span class="bh-av bh-av--ava bh-swap-a">A</span>
                  <span class="bh-approve">Approve</span>
                  <span class="bh-ok"><svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="m6 10.2 2.6 2.6L14 7.4" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Approved</span>
                </span></div>
            </article>
            <article class="bh-card" data-cols="progress progress progress progress">
              <p class="bh-card__title">Sync plots across devices</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-12</span>
                <span class="bh-card__side"><span class="bh-av bh-av--ava">A</span></span></div>
            </article>
          </div>
        </section>

        <section class="bh-col" data-col="waiting">
          <h3 class="bh-col__head">Waiting on you <b class="bh-n" data-count="waiting"></b></h3>
          <div class="bh-list">
            <article class="bh-card" data-cols="waiting waiting waiting waiting">
              <p class="bh-card__title">Confirm frost-date source</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-11</span>
                <span class="bh-card__side"><span class="bh-flag">Question</span></span></div>
            </article>
            <article class="bh-card" data-cols="waiting waiting waiting waiting">
              <p class="bh-card__title">Pick reminder sound</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-09</span>
                <span class="bh-card__side"><span class="bh-flag">Choice</span></span></div>
            </article>
          </div>
        </section>

        <section class="bh-col" data-col="done">
          <h3 class="bh-col__head">Done <b class="bh-n" data-count="done"></b></h3>
          <div class="bh-list">
            <article class="bh-card" data-cols="done done done done">
              <p class="bh-card__title">Set up staging server</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-07</span>
                <span class="bh-card__side"><span class="bh-av bh-av--ava">A</span></span></div>
            </article>
            <article class="bh-card" data-cols="done done done done">
              <p class="bh-card__title">Seed sample gardens</p>
              <div class="bh-card__meta"><span class="bh-card__id">GP-05</span>
                <span class="bh-card__side"><span class="bh-av bh-av--ava">A</span></span></div>
            </article>
          </div>
        </section>
      </div>
    </div>

    <div class="bh-cards"></div>

    <div class="bh-cursor">
      <svg viewBox="0 0 24 24"><path d="M5 3.2v15.6l4.3-4.1 2.7 6.1 2.9-1.3-2.7-6h6z" fill="#fff" stroke="#0B0C0F" stroke-width="1.4" stroke-linejoin="round"/></svg>
      <span class="bh-cursor__name">Sam Rivera</span>
    </div>
  </div>
</div>
