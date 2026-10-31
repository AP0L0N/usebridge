<section class="section section-alt" id="faq" aria-labelledby="faq-title">
    <div class="wrap">
        <div class="section-head">
            <h2 class="h2" id="faq-title">Questions before you connect a bot</h2>
        </div>
        <div class="faq-list">
            <details>
                <summary>Why is it free? What's the catch?</summary>
                <div class="answer">No catch. Everything is free, and the only limit is 120 requests a minute per token. <span class="ph-chip" data-placeholder="why-free"><span class="tbd">TBD</span>I built it for my own bots and opened it up because it costs little to run.</span> More in <a href="#free">Free, and why</a>.</div>
            </details>
            <details>
                <summary>Who can see my boards and data?</summary>
                <div class="answer">Nobody else sees your workspace in the app. Our admin view shows only your account name, email, sign-up date, last login and status, never your boards or usage. The people who run the server can technically reach the database, but only do so to run, secure or repair the service, or when the law requires it. Tokens are stored hashed. You can make them read-only, set them to expire and revoke them at any time. Your data is stored in the EU, on Hetzner servers in Helsinki, Finland. See our <a href="{{ url('/privacy') }}">Privacy policy</a>.</div>
            </details>
            <details>
                <summary>Which bots work with Bridge?</summary>
                <div class="answer">Any MCP client that can connect to a remote (streamable HTTP) server with a Bearer token. We've tested it with Grok Bot.</div>
            </details>
            <details>
                <summary>How much setup is it?</summary>
                <div class="answer">Create an account, verify your email, create a token, then paste the server URL and token into your bot. That's it.</div>
            </details>
            <details>
                <summary>Does it cost my bot tokens?</summary>
                <div class="answer">Your bot's calls to Bridge use its own model tokens, like any other tool it calls. Bridge doesn't charge for them.</div>
            </details>
            <details>
                <summary>What if Bridge shuts down?</summary>
                <div class="answer">You can export all your workspace data as JSON from Account at any time, and Bridge is open source, so you can run your own copy. If Bridge ever closes, <span class="ph-chip" data-placeholder="shutdown-notice"><span class="tbd">TBD</span>you'll get at least 30 days' notice by email.</span></div>
            </details>
            <details>
                <summary>Can I delete my account?</summary>
                <div class="answer">Yes, from Account. You get a 7-day grace period to change your mind, and you can export your data first.</div>
            </details>
        </div>
        <p class="still-stuck muted">Still stuck? Email <a class="link" href="mailto:support@usebridge.si">support@usebridge.si</a>.</p>
    </div>
</section>
