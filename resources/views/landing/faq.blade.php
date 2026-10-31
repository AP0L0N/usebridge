<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="inner">
        <div class="faq-grid">
            <div class="intro">
                <h2 class="h2" id="faq-title">Questions before you connect a bot</h2>
                <p class="body">Still stuck? Email <a class="link--underline" href="mailto:support@usebridge.si">support@usebridge.si</a>.</p>
            </div>
            <div class="faq">
            @if (config('bridge.landing_drafts'))
            {{-- Draft (Tilen to approve): the whole first FAQ item renders only with bridge.landing_drafts on. --}}
            <details open>
                <summary><span>Why is it free? What's the catch?</span></summary>
                <p class="answer">No catch. Everything is free, and the only limit is 120 requests a minute per token. <span class="pending-inline" data-placeholder="why-free-faq">[PLACEHOLDER: I built it for my own bots and opened it up because it costs little to run.]</span> More in <a href="#free">Free, and why</a>.</p>
            </details>
            @endif
            <details open>
                <summary><span>Who can see my boards and data?</span></summary>
                <p class="answer">Nobody else sees your workspace in the app. Our admin view shows only your account name, email, sign-up date, last login and status, never your boards or usage. The people who run the server can technically reach the database, but only do so to run, secure or repair the service, or when the law requires it. Tokens are stored hashed. You can make them read-only, set them to expire and revoke them at any time. Your data is stored in the EU, on Hetzner servers in Helsinki, Finland. See our <a href="{{ url('/privacy') }}">Privacy policy</a>.</p>
            </details>
            <details>
                <summary><span>Which bots work with Bridge?</span></summary>
                <p class="answer">Any MCP client that can connect to a remote (streamable HTTP) server with a Bearer token. We've tested it with Grok Bot.</p>
            </details>
            <details>
                <summary><span>How much setup is it?</span></summary>
                <p class="answer">Create an account, verify your email, create a token, then paste the server URL and token into your bot. That's it.</p>
            </details>
            <details>
                <summary><span>Does it cost my bot tokens?</span></summary>
                <p class="answer">Your bot's calls to Bridge use its own model tokens, like any other tool it calls. Bridge doesn't charge for them.</p>
            </details>
            <details>
                <summary><span>What if Bridge shuts down?</span></summary>
                <p class="answer">You can export all your workspace data as JSON from Account at any time, and Bridge is open source, so you can run your own copy. If Bridge ever closes, you'll get at least 30 days' notice by email.</p>
            </details>
            <details>
                <summary><span>Can I delete my account?</span></summary>
                <p class="answer">Yes, from Account. You get a 7-day grace period to change your mind, and you can export your data first.</p>
            </details>
            </div>
        </div>
    </div>
</section>
