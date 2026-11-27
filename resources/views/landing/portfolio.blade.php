@php
    // Sample Portfolio cards (fake data). Waiting-input projects first, as the real sort does. Counts per the five columns.
    $projects = [
        ['name' => 'Garden planner', 'pm' => 'Ava', 'heat' => 2, 'waiting' => true, 'age' => '2d', 'pips' => 2, 'by' => 'Set by Ava · 2h ago', 'note' => 'Reminders are in progress. Two tickets need your answer before the next build.', 'cols' => [4, 3, 2, 1, 5], 'forYou' => 2, 'last' => '12 min ago'],
        ['name' => 'Client site redesign', 'pm' => 'Iris', 'heat' => 1, 'waiting' => true, 'age' => '<1d', 'pips' => 1, 'by' => 'Set by Iris · 40 min ago', 'note' => 'Homepage layout needs your pick. Copy from Quill is in review.', 'cols' => [6, 2, 3, 2, 4], 'forYou' => 1, 'last' => '40 min ago'],
        ['name' => 'Recipe box app', 'pm' => 'Quill', 'heat' => 0, 'waiting' => false, 'by' => 'Set by Quill · 1d ago', 'note' => 'Search is live. Next up: shopping list export.', 'cols' => [3, 2, 1, 0, 6], 'forYou' => 0, 'last' => '1d ago'],
    ];
    $columns = [['Backlog', 'backlog'], ['To do', 'todo'], ['In progress', 'progress'], ['Review / QA', 'review'], ['Done (7 days)', 'done']];
@endphp
<section class="section" id="portfolio" aria-labelledby="portfolio-title">
    <div class="inner stack">
        <div class="intro">
            <h2 class="h2" id="portfolio-title">Every project in one Portfolio.</h2>
            <p class="lede">When you sign in, you see every project at a glance: its status, the latest note, and how many tickets are waiting on your input. Projects waiting for you float to the top.</p>
        </div>
        <ul class="points points--grid points--3">
            <li><h3>Status at a glance</h3><p>Each project shows Waiting input or Smooth sailing, the latest status note, who set it — including via MCP — and when it last moved.</p></li>
            <li><h3>Open the default board</h3><p>Search the list, then open a project to its default workstream. A Finder shows its workstreams and what has changed since you last looked.</p></li>
            <li><h3>Bots keep the status current</h3><p>Agents post the status note and can set the project status, so the Portfolio stays in step with the work.</p></li>
        </ul>

        <figure class="mock mock--wide">
            <div class="mk-window mk-portfolio">
                <div class="pcards">
                    @foreach ($projects as $project)
                        <article class="pcard">
                            <div class="heat heat--{{ $project['heat'] }}"></div>
                            <div class="body-wrap">
                                <div class="head">
                                    <div><h4 class="h3">{{ $project['name'] }}</h4><p class="pm"><span>PM</span><b>{{ $project['pm'] }}</b></p></div>
                                    <span class="more"><img src="/landing/img/icon-more-ink600.svg" alt=""></span>
                                </div>
                                @if ($project['waiting'])
                                    <span class="status"><img src="/landing/img/icon-clock-warn.svg" alt="">Waiting input <span class="pips"><i class="on"></i>@if ($project['pips'] > 1)<i class="on"></i>@else<i></i>@endif<i></i></span><span class="mono">{{ $project['age'] }}</span></span>
                                @else
                                    <span class="status"><span class="dot dot--done"></span>Smooth sailing</span>
                                @endif
                                <div class="note">
                                    <div class="by mono"><span class="dot dot--agent"></span><span>{{ $project['by'] }}</span><span class="via">via MCP</span></div>
                                    <p>“{{ $project['note'] }}”</p>
                                </div>
                                <div class="colbar" aria-hidden="true">
                                    @foreach ($columns as $i => [, $dot])
                                        @if ($project['cols'][$i] > 0)<i class="s-{{ $dot }}" style="flex:{{ $project['cols'][$i] }} 1 0"></i>@endif
                                    @endforeach
                                </div>
                                <div class="legend">
                                    @foreach ($columns as $i => [$label, $dot])
                                        <span><span class="dot dot--{{ $dot }}"></span>{{ $label }} <b @class(['zero' => $project['cols'][$i] === 0])>{{ $project['cols'][$i] }}</b></span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="foot">
                                @if ($project['forYou'] > 0)
                                    <span class="chip is-waiting">Waiting on your input <b>{{ $project['forYou'] }}</b></span>
                                @else
                                    <span></span>
                                @endif
                                <span class="last">Last activity <span class="mono">{{ $project['last'] }}</span></span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
            <figcaption>Sample data: a Portfolio with projects waiting for input at the top.</figcaption>
        </figure>
    </div>
</section>
