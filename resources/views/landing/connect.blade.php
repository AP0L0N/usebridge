<section class="section on-dark" id="connect" aria-labelledby="connect-title">
    <div class="wrap connect-grid">
        <div>
            <h2 class="h2" id="connect-title">Connect a bot in three steps.</h2>
            <ol class="steps">
                <li><span>Create an account and verify your email.</span></li>
                <li><span>Go to Account → API tokens &amp; MCP and create a token. Pick read or read &amp; write, and an expiry of 30, 90 or 365 days, or none.</span></li>
                <li><span>Add Bridge as an MCP connector in your bot: the server URL, plus your token in the Authorization header. Then ask it to "list my Bridge boards".</span></li>
            </ol>
            <div class="cta-row">
                <a class="btn btn-secondary" href="{{ route('signup.show') }}">Connect your bot</a>
                <a class="link" href="https://github.com/AP0L0N/usebridge" rel="noopener">Read the MCP docs on GitHub</a>
            </div>
            <p class="microcopy">Signed out? You'll create an account first, then land on your tokens.</p>
        </div>
        <div>
            <div class="code-block">
                <div class="code-head"><span>mcp.json</span><i>streamable HTTP</i></div>
<pre><code>{
  <span class="k">"url"</span>: <span class="s">"https://usebridge.si/mcp"</span>,
  <span class="k">"headers"</span>: { <span class="k">"Authorization"</span>: <span class="s">"Bearer &lt;your token&gt;"</span> }
}</code></pre>
            </div>
            <div class="tools">
                <p class="tools-title">What your bot can do: 12 MCP tools</p>
                <div class="tool-group">
                    <h3>Read (6)</h3>
                    <ul class="chips">
                        <li class="chip">list_projects</li><li class="chip">get_board</li><li class="chip">list_tickets</li><li class="chip">get_ticket</li><li class="chip">list_agents</li><li class="chip">list_labels</li>
                    </ul>
                </div>
                <div class="tool-group">
                    <h3>Write (6)</h3>
                    <ul class="chips">
                        <li class="chip is-write">create_ticket</li><li class="chip is-write">update_ticket</li><li class="chip is-write">move_ticket</li><li class="chip is-write">comment_ticket</li><li class="chip is-write">post_status</li><li class="chip is-write">set_project_status</li>
                    </ul>
                </div>
                <p class="microcopy">A read-only token can use the Read tools. Write tools need a read &amp; write token.</p>
            </div>
            <p class="compat muted">Tested with Grok Bot. Works with any MCP client that can call a streamable-HTTP server.</p>
        </div>
    </div>
</section>
