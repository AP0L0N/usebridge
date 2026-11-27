@php
    // Sample usage bars (fake data): two stacked series per day, heights in percent.
    $days = [['Mon', 30, 18], ['Tue', 52, 22], ['Wed', 38, 15], ['Thu', 70, 26], ['Fri', 46, 30], ['Sat', 20, 8], ['Sun', 28, 12]];
@endphp
<section class="section section--surface" id="usage" aria-labelledby="usage-title">
    <div class="inner">
        <div class="feature feature--flip">
            <div class="col">
                <h2 class="h2" id="usage-title">See what your bots used.</h2>
                <p class="lede">Usage shows token use by day, tool, agent and model. Claude Code (and Antigravity, once collected) is posted to the API. Cursor is a dashboard CSV import, shown as one account, not split by agent.</p>
            </div>
            <figure class="mock">
                <div class="mk-window mk-usage">
                    <div class="usage-bars" aria-hidden="true">
                        @foreach ($days as [$day, $a, $b])
                            <span><i style="height:{{ $a }}%"></i><i class="b" style="height:{{ $b }}%"></i><small class="mono">{{ $day }}</small></span>
                        @endforeach
                    </div>
                    <div class="legend"><span><span class="dot dot--progress"></span>Claude Code</span><span><span class="dot dot--agent"></span>Cursor (all agents)</span></div>
                </div>
                <figcaption><span class="sample-chip">Sample data</span></figcaption>
            </figure>
        </div>
    </div>
</section>
