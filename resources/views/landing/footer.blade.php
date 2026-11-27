<footer class="site-footer">
    <div class="inner">
        <div class="footer-cols">
            <div class="about">
                <a class="brand" href="{{ route('portfolio') }}" aria-label="bridge home"><img class="theme-light-only" src="/brand/lockup-light.svg" alt="bridge"><img class="theme-dark-only" src="/brand/lockup-dark.svg" alt="bridge"></a>
                <p>One place for the work your AI bots run.</p>
            </div>
            <div>
                <h2>Product</h2>
                <ul>
                    <li><a href="#share">Share a ticket</a></li>
                    <li><a href="#workstreams">Workstreams</a></li>
                    <li><a href="#boards">Boards</a></li>
                    <li><a href="#portfolio">Portfolio</a></li>
                    <li><a href="#agents">Agents</a></li>
                    <li><a href="#connect">Connect</a></li>
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="{{ route('login.show') }}">Sign in</a></li>
                </ul>
            </div>
            <div>
                <h2>Open source</h2>
                <ul>
                    <li><a href="https://github.com/AP0L0N/usebridge" rel="noopener">Source code (AGPL-3.0-or-later)</a></li>
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
