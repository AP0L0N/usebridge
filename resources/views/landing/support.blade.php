<section class="section section--surface section--center" id="support" aria-labelledby="support-title">
    <div class="inner stack support">
        <h2 class="h2" id="support-title">If Bridge saves you time, buy me a coffee.</h2>
        <p class="lede">Coffees help pay for hosting and the time that goes into Bridge. There are no tiers and no perks. Bridge stays free either way.</p>
        @if (config('bridge.landing_pending'))
            <span class="btn btn--tertiary" role="link" aria-disabled="true" data-placeholder="coffee-link"><img class="icon" src="/landing/img/icon-coffee-ink950.svg" alt="" width="18" height="18">Buy me a coffee</span>
            @include('landing.pending', ['key' => 'coffee-link', 'label' => 'coffee link', 'text' => 'Buy me a coffee link and name, e.g. https://buymeacoffee.com/usebridge. Tilen picks the platform and the name.'])
        @endif
    </div>
</section>
