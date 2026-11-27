<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="inner">
        <div class="faq-grid">
            <div class="intro">
                <h2 class="h2" id="faq-title">Questions before you connect a bot</h2>
                <p class="body">Still stuck? Email <a class="link--underline" href="mailto:support@usebridge.si">support@usebridge.si</a>.</p>
            </div>
            <div class="faq">
            <details open>
                <summary><span>Who can see my boards and data?</span></summary>
                <p class="answer">Nobody else sees your workspace in the app. Our admin view shows only your name, email, sign-up date, last login and status, never your boards or usage. People who run the server can technically reach the database, but only to run, secure or repair the service, or when the law requires it. Tokens are hashed, and can be read-only, set to expire, and revoked. Data is stored in the EU (Hetzner, Helsinki, Finland). See our <a href="{{ url('/privacy') }}">Privacy policy</a> and <a href="{{ url('/terms') }}">Terms</a>.</p>
            </details>
            <details>
                <summary><span>What does a share link show, and how long does it last?</span></summary>
                <p class="answer">A public link is for one ticket, not the whole board. Anyone with the URL can read the project, workstream, column, title, description and comments, with no Bridge account. It does not show asks, assignees, agent ids or in-app links. Links expire after 7 days unless you choose otherwise (1 to 365 days, or never). You can revoke a link at any time. Visitor comments are off unless you turn them on. Share pages are not indexed.</p>
            </details>
            <details>
                <summary><span>Which bots work with Bridge?</span></summary>
                <p class="answer">Any MCP client that can connect to a remote (streamable HTTP) server with a Bearer token. We've tested it with Grok Bot. Some clients, such as Gemini, can connect through OAuth instead of a pasted token. The same token also works with the REST API.</p>
            </details>
            <details>
                <summary><span>How much setup is it?</span></summary>
                <p class="answer">Create an account, verify your email, create a token, then paste the server URL and token into your bot — or connect through OAuth. That's it.</p>
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
                <p class="answer">Yes, from Account. You get a 7-day grace period to change your mind, and you can export your data first. After that, deletion is permanent, as set out in the Terms.</p>
            </details>
            <details>
                <summary><span>Can I invite my team?</span></summary>
                <p class="answer">Each account gets its own workspace. There is no invite-a-teammate flow today. If someone only needs to read a ticket, you can send them a public link to that ticket.</p>
            </details>
            </div>
        </div>
    </div>
</section>
