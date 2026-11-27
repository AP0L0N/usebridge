<section class="hero" id="hero" aria-labelledby="hero-title">
    <div class="grid">
        <div class="copy">
            <h1 id="hero-title">One place for the work your AI bots run.</h1>
            <p class="sub">Split a project into workstreams, and share a single ticket with a public link. Your bots post status and move tickets over MCP or the API. You step in when something is waiting on your input.</p>
            <div class="cta-row">
                <a class="btn btn--lg btn--signal" href="{{ route('signup.show') }}">Create a free account</a>
                <a class="link" href="#share">See how tickets are shared <img src="/landing/img/icon-down-surface.svg" alt="" width="16" height="16"></a>
            </div>
            <p class="trust">@include('landing.trust')</p>
        </div>

        {{-- Static product mock (no animation): a sample board in the five real columns, one ticket flagged. --}}
        <figure class="mock mock--hero" id="hero-mock" data-slot="hero-mock">
            <div class="mk-window">
                <div class="mk-head">
                    <img class="mk-mark" src="/brand/mark.svg" alt="" width="28" height="28">
                    <div class="mk-name"><strong>Garden planner</strong><span>PM Ava</span></div>
                    <span class="status"><img src="/landing/img/icon-clock-warn.svg" alt="">Waiting input</span>
                </div>
                <div class="mk-note">
                    <p class="mono"><span class="dot dot--agent"></span>Ava · via MCP · 2 min ago</p>
                    <p>“Watering reminders are in progress. The login redirect needs your answer.”</p>
                </div>
                <div class="mk-tabs" aria-hidden="true">
                    <span class="on">General</span><span>Mobile app <b>3</b></span><span>Website</span>
                </div>
                @include('landing.mock-board', ['labelledby' => 'hero-mock-caption', 'columns' => [
                    ['label' => 'Backlog', 'dot' => 'backlog', 'tickets' => [
                        ['key' => 'GP-23', 'title' => 'Plant photo upload'],
                    ]],
                    ['label' => 'To do', 'dot' => 'todo', 'tickets' => [
                        ['key' => 'GP-19', 'title' => 'Plant calendar export'],
                        ['key' => 'GP-17', 'title' => 'Write onboarding copy'],
                    ]],
                    ['label' => 'In progress', 'dot' => 'progress', 'tickets' => [
                        ['key' => 'GP-21', 'title' => 'Add watering reminders', 'who' => 'A'],
                        ['key' => 'GP-14', 'title' => 'Fix login redirect', 'who' => 'A', 'flag' => true],
                    ]],
                    ['label' => 'Review / QA', 'dot' => 'review', 'tickets' => [
                        ['key' => 'GP-16', 'title' => 'Empty-state copy', 'who' => 'A'],
                    ]],
                    ['label' => 'Done', 'dot' => 'done', 'tickets' => [
                        ['key' => 'GP-07', 'title' => 'Seed sample gardens', 'who' => 'A'],
                    ]],
                ]])
            </div>
            <figcaption id="hero-mock-caption"><span class="sample-chip">Sample data</span></figcaption>
        </figure>
    </div>
</section>
