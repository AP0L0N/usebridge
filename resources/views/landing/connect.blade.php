<section class="section section--surface" id="connect" aria-labelledby="connect-title">
    <div class="inner">
        <div class="two-col">
            <div class="col">
                <h2 class="h2" id="connect-title">Connect a bot in three steps.</h2>
                <ol class="csteps">
                    <li><span class="n mono">1</span><span>Create an account and verify your email.</span></li>
                    <li><span class="n mono">2</span><span>Go to Account → API tokens &amp; MCP and create a token. Pick read or read &amp; write, and an expiry of 30, 90 or 365 days, or none.</span></li>
                    <li><span class="n mono">3</span><span>Add Bridge as an MCP connector in your bot: the server URL, plus your token in the Authorization header. Then ask it to “list my Bridge boards”.</span></li>
                </ol>
                <div class="btn-row">
                    <a class="btn btn--primary" href="{{ route('signup.show') }}">Connect your bot</a>
                    <a class="link" href="https://github.com/AP0L0N/usebridge" rel="noopener">Read the MCP docs on GitHub</a>
                </div>
                <p class="small">Signed out? You'll create an account first, then land on your tokens.</p>
            </div>
            <div class="col">
                <div class="code">
                    <div class="head"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="mono">mcp.json</span></div>
<pre tabindex="0"><code><span class="br">{</span>
  "url": "https://usebridge.si/mcp",
  "headers": { "Authorization": "Bearer &lt;your token&gt;" }
<span class="br">}</span></code></pre>
                </div>
                <p class="tools-title">What your bot can do: 12 MCP tools</p>
                <div class="group">
                    <h3>Read (6)</h3>
                    <ul class="chips"><li class="chip chip--mono">list_projects</li><li class="chip chip--mono">get_board</li><li class="chip chip--mono">list_tickets</li><li class="chip chip--mono">get_ticket</li><li class="chip chip--mono">list_agents</li><li class="chip chip--mono">list_labels</li></ul>
                </div>
                <div class="group">
                    <h3>Write (6)</h3>
                    <ul class="chips"><li class="chip chip--mono">create_ticket</li><li class="chip chip--mono">update_ticket</li><li class="chip chip--mono">move_ticket</li><li class="chip chip--mono">comment_ticket</li><li class="chip chip--mono">post_status</li><li class="chip chip--mono">set_project_status</li></ul>
                </div>
                <p class="small">A read-only token can use the Read tools. Write tools need a read &amp; write token.</p>
                <p class="compat"><span class="dot dot--agent"></span><span>Tested with Grok Bot. Works with any MCP client that can call a streamable-HTTP server.</span></p>
            </div>
        </div>
    </div>
</section>
