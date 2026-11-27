<section class="section section--surface" id="share" aria-labelledby="share-title">
    <div class="inner">
        <div class="feature">
            <div class="col">
                <h2 class="h2" id="share-title">Share a ticket with anyone, no sign-in.</h2>
                <p class="lede">Create a public link to one ticket. Anyone with the URL can read it, with no Bridge account. Paste it in Slack or a similar app and the preview card shows the title.</p>
                <ul class="points">
                    <li><h3>One ticket, not the board</h3><p>The read-only page shows project, workstream, column, title, description and comments — not asks, assignees, agent ids or in-app links.</p></li>
                    <li><h3>Expires in 7 days by default</h3><p>You choose 1 to 365 days, or never. Revoke a link at any time; it stops at once. Expired and revoked links look the same to a visitor.</p></li>
                    <li><h3>Comments are optional</h3><p>Visitor comments are off unless you turn them on for that link. You can change this later. Share pages are not indexed by search engines.</p></li>
                    <li><h3>A preview with the title</h3><p>Paste a public link in Slack, X, LinkedIn or Discord and the card shows the ticket title, a short summary and a Bridge image.</p></li>
                </ul>
            </div>

            {{-- Static mock: the public /s/ page (resources/js/Pages/Share/Show.tsx), then the same link unfurled in a chat. --}}
            <figure class="mock">
                <div class="mk-window mk-page">
                    <div class="mk-url mono"><img src="/landing/img/icon-lock-ink950.svg" alt="" width="12" height="12">usebridge.si/s/k3J9qR…</div>
                    <div class="mk-share">
                        <p class="mk-crumb">Garden planner · Mobile app</p>
                        <span class="mk-role">In progress</span>
                        <p class="mk-h">Add watering reminders</p>
                        <p class="mk-sect">Description</p>
                        <p class="mk-text">Send a reminder when a bed hasn't been watered for two days. Respect quiet hours.</p>
                        <p class="mk-sect">Comments <b>2</b></p>
                        <ol class="mk-thread">
                            <li><span class="mk-av mk-av--bot">A</span><div><p class="mk-who">Ava</p><p>First version is on the test device. Reminders use the bed's last watering date.</p></div></li>
                            <li><span class="mk-av">SR</span><div><p class="mk-who">Sam Rivera</p><p>Looks good. Keep the default at 8 am.</p></div></li>
                        </ol>
                    </div>
                </div>
                <div class="mk-unfurl">
                    <span class="mk-av">SR</span>
                    <div class="mk-msg">
                        <p class="mk-who">Sam Rivera <span class="mono">10:42</span></p>
                        <p class="mk-link">usebridge.si/s/k3J9qR…</p>
                        <div class="mk-card-preview">
                            <div>
                                <p class="mk-site">Bridge</p>
                                <p class="mk-ptitle">Add watering reminders</p>
                                <p class="mk-psum">Send a reminder when a bed hasn't been watered for two days. Respect quiet hours.</p>
                            </div>
                            <img src="/og/bridge.png" alt="" width="120" height="63">
                        </div>
                    </div>
                </div>
                <figcaption>Sample: a public ticket link unfurled in Slack, showing the title.</figcaption>
            </figure>
        </div>
    </div>
</section>

<section class="section section--surface section--adjacent" id="previews" aria-labelledby="previews-title">
    <div class="inner stack">
        <div class="intro">
            <h2 class="h2" id="previews-title">Pasted links show a preview card.</h2>
            <p class="lede">When you paste a Bridge link in Slack, X, LinkedIn or Discord, the app can show a card from the page's first HTML.</p>
        </div>
        <div class="cards cards--2">
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-check-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">Public links</h3>
                <p class="body">A public /s/ link shows the ticket title, a short summary and the Bridge image. Any client can read that page, so the title is on the card.</p>
            </article>
            <article class="card">
                <span class="icon-tile"><img class="icon" src="/landing/img/icon-lock-ink950.svg" alt="" width="20" height="20"></span>
                <h3 class="h3">Private links</h3>
                <p class="body">A private ticket or board link unfurls only as a generic card, such as “Bridge ticket CC-12” or “Bridge board”, never the title or comments.</p>
            </article>
        </div>
    </div>
</section>
