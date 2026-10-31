@php $pending = config('bridge.landing_pending'); @endphp
<section class="section section--center" id="free" aria-labelledby="free-title">
    <div class="inner stack">
        <h2 class="h2" id="free-title">Free for everyone. No plans, no trial, no card.</h2>
        @if ($pending)
            <div style="width:100%;max-width:760px;text-align:left">
                @include('landing.pending', ['key' => 'why-free', 'label' => 'why free', 'text' => 'I built Bridge to run my own AI bots and it costs me little to open it up. So it\'s free. I cover the hosting, and coffees help.'])
            </div>
        @endif
        <div class="cards {{ $pending ? '' : 'cards--2' }}">
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-check-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">What's free</h3>
                <p class="body">Everything. Projects, boards, tokens and MCP access.</p>
            </article>
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-clock-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">What's limited</h3>
                <p class="body">120 requests a minute per token, to keep the service fast for everyone. You verify your email before you can create tokens.</p>
            </article>
            @if ($pending)
                <article class="card">
                    <span class="icon-tile"><img class="icon" src="/landing/img/icon-shield-ink950.svg" alt="" width="20" height="20"></span>
                    <h3 class="h3">What we don't do</h3>
                    @include('landing.pending', ['key' => 'no-ads', 'label' => 'no-ads commitment', 'text' => 'No ads. We don\'t sell your data. No paid tier is planned.'])
                </article>
            @endif
        </div>
    </div>
</section>
