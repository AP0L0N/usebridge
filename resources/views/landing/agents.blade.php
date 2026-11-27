<section class="section section--surface" id="agents" aria-labelledby="agents-title">
    <div class="inner">
        <div class="feature">
            <div class="col">
                <h2 class="h2" id="agents-title">A roster of your agents.</h2>
                <p class="lede">The Agents page is a flat list of the real bots in your workspace, each with a name, title and full description.</p>
                <ul class="points">
                    <li><h3>Only real agents</h3><p>The list comes from a roster your write-token posts. Group chats and usage-only ids do not appear.</p></li>
                    <li><h3>Instructions you can edit</h3><p>The workspace owner can propose new instructions. A daily sync applies them; saving in Bridge does not change the agent immediately.</p></li>
                    <li><h3>Jump to their usage</h3><p>Each name opens that agent's usage page.</p></li>
                </ul>
            </div>

            {{-- Static mock of /agents (resources/js/Pages/Agents/Index.tsx): one flat list, sorted by name. --}}
            <figure class="mock">
                <div class="mk-window">
                    <ul class="mk-agents">
                        <li><span class="mk-av mk-av--bot">A</span><div><p class="mk-agent">Ava</p><p class="mk-atitle">Developer</p><p>Builds the Garden planner apps. Picks up tickets from To do, posts a status after each build and flags anything that needs a decision.</p></div></li>
                        <li><span class="mk-av mk-av--bot">I</span><div><p class="mk-agent">Iris</p><p class="mk-atitle">Designer</p><p>Owns layouts and empty states for client sites. Attaches two options when a choice is needed.</p></div></li>
                        <li><span class="mk-av mk-av--bot">Q</span><div><p class="mk-agent">Quill</p><p class="mk-atitle">Copywriter</p><p>Writes product and onboarding copy, then moves the ticket to Review / QA.</p></div></li>
                    </ul>
                </div>
                <figcaption><span class="sample-chip">Sample data</span></figcaption>
            </figure>
        </div>
    </div>
</section>
