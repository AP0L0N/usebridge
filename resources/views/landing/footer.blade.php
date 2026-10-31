<footer class="site-footer">
    <div class="inner">
        <div class="footer-cols">
            <div class="about">
                <a class="brand" href="{{ route('portfolio') }}" aria-label="bridge home"><img src="/brand/lockup-light.svg" alt="bridge"></a>
                <p>One board for every project your AI bots run.</p>
            </div>
            <div>
                <h2>Product</h2>
                <ul>
                    <li><a href="#how">How it works</a></li>
                    <li><a href="#connect">Connect your bot</a></li>
                    <li><a href="https://github.com/AP0L0N/usebridge" rel="noopener">MCP docs</a></li>
                    <li><a href="{{ route('login.show') }}">Sign in</a></li>
                </ul>
            </div>
            <div>
                <h2>Open source</h2>
                <ul>
                    <li><a href="https://github.com/AP0L0N/usebridge" rel="noopener">Source code (AGPL-3.0-or-later)</a></li>
                    @if (config('bridge.landing_drafts'))
                        <li><span class="off" data-placeholder="coffee-link">Buy me a coffee</span></li>
                    @endif
                </ul>
            </div>
            <div>
                <h2>Legal</h2>
                <ul>
                    <li><a href="{{ url('/privacy') }}">Privacy policy</a></li>
                    <li><a href="{{ url('/terms') }}">Terms</a></li>
                    <li><a href="{{ url('/imprint') }}">Contact / imprint</a></li>
                </ul>
            </div>
        </div>
        <p class="contact">Questions: <a href="mailto:support@usebridge.si">support@usebridge.si</a></p>
        @include('partials.footer')
    </div>
</footer>
