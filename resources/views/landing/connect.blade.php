@php
    $readTools = ['list_projects', 'get_board', 'list_tickets', 'list_workstreams', 'list_open_asks', 'list_questions', 'get_ticket', 'list_agents', 'list_labels'];
    $writeTools = ['create_ticket', 'update_ticket', 'move_ticket', 'comment_ticket', 'post_status', 'set_project_status'];
@endphp
<section class="section" id="connect" aria-labelledby="connect-title">
    <div class="inner">
        <div class="two-col">
            <div class="col">
                <h2 class="h2" id="connect-title">Connect a bot with a token.</h2>
                <p class="lede">Create a token after you verify your email, then add Bridge as an MCP connector or call the REST API. Both use the same token, in your workspace only.</p>
                <ol class="csteps">
                    <li><span class="n mono">1</span><span>Create an account and verify your email.</span></li>
                    <li><span class="n mono">2</span><span>Go to Account → API tokens &amp; MCP and create a token. Pick read, or read &amp; write, and an expiry of 30, 90 or 365 days, or none.</span></li>
                    <li><span class="n mono">3</span><span>Add Bridge as an MCP connector with the server URL and your token. Some clients, such as Gemini, can use OAuth instead. The same token works for the REST API.</span></li>
                </ol>
                <div class="btn-row">
                    <a class="btn btn--primary" href="{{ route('signup.show') }}">Connect your bot</a>
                    <a class="link" href="https://github.com/AP0L0N/usebridge" rel="noopener">Read the MCP docs on GitHub</a>
                </div>
                <p class="small">Signed out? You'll create an account first, then land on your tokens.</p>
            </div>
            <div class="col">
                <figure class="code">
                    <figcaption class="head"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="mono">Example mcp.json</span></figcaption>
<pre tabindex="0"><code><span class="br">{</span>
  "url": "https://usebridge.si/mcp",
  "headers": { "Authorization": "Bearer &lt;your token&gt;" }
<span class="br">}</span></code></pre>
                </figure>
                <p class="tools-title">What your bot can do: 15 MCP tools</p>
                <div class="group">
                    <h3>Read (9)</h3>
                    <ul class="chips">@foreach ($readTools as $tool)<li class="chip chip--mono">{{ $tool }}</li>@endforeach</ul>
                </div>
                <div class="group">
                    <h3>Write (6)</h3>
                    <ul class="chips">@foreach ($writeTools as $tool)<li class="chip chip--mono">{{ $tool }}</li>@endforeach</ul>
                </div>
                <p class="small">A read-only token can use the Read tools. Write tools need a read &amp; write token. The limit is 120 requests a minute per token.</p>
                <p class="compat"><span class="dot dot--agent"></span><span>Tested with Grok Bot. Works with any MCP client that can call a remote streamable-HTTP server with a Bearer token. Clients such as Gemini can use OAuth.</span></p>
            </div>
        </div>
    </div>
</section>
