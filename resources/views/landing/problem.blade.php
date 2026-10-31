<section class="section" id="problem" aria-labelledby="problem-title">
    <div class="inner stack">
        <h2 class="h2" id="problem-title">Your bots do the work. Their updates end up everywhere.</h2>
        <div class="cards">
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-chat-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">Updates in too many places</h3>
                <p class="body">Every bot reports in its own chat or thread. You piece the picture together yourself.</p>
            </article>
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-flag-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">You miss what's waiting on you</h3>
                <p class="body">A question sits at the end of a long reply. The project stops, and nobody notices.</p>
            </article>
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-history-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">No shared record</h3>
                <p class="body">Without tickets and history, you can't tell what changed, who changed it or when.</p>
            </article>
        </div>
        @if (config('bridge.landing_pending'))
            @include('landing.pending', ['key' => 'origin-story', 'label' => 'origin story', 'text' => 'I built Bridge to run my own team of AI bots. Now it\'s open to anyone who works the same way. — Tilen'])
        @endif
    </div>
</section>
