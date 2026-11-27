{{--
    Static board mock (sample data only): the five fixed columns, never a sixth. "Waiting on your input" is a flag on a
    ticket (`flag` => true), shown as a strip on the card, as in TicketCard.tsx.
    $columns: list<array{label: string, dot: string, tickets: list<array{key: string, title: string, who?: string, labels?: list<string>, pri?: string, due?: string, comments?: int, flag?: bool, ask?: string}>}>
--}}
<div class="mk-board" @isset($labelledby) tabindex="0" role="group" aria-labelledby="{{ $labelledby }}" @endisset>
    @foreach ($columns as $column)
        <div class="mk-col">
            <h4><span class="dot dot--{{ $column['dot'] }}"></span>{{ $column['label'] }} <b>{{ count($column['tickets']) }}</b></h4>
            @foreach ($column['tickets'] as $ticket)
                <div class="mk-card">
                    @if (! empty($ticket['labels']))
                        <div class="mk-labels">@foreach ($ticket['labels'] as $label)<span>{{ $label }}</span>@endforeach</div>
                    @endif
                    <p class="mk-title">{{ $ticket['title'] }}</p>
                    @if (! empty($ticket['flag']))
                        <p class="mk-flag is-waiting">Waiting on your input</p>
                    @endif
                    @if (! empty($ticket['ask']))
                        <p class="mk-ask">{{ $ticket['ask'] }}</p>
                    @endif
                    <div class="mk-meta">
                        <span class="mono">{{ $ticket['key'] }}</span>
                        @isset($ticket['pri'])<span class="mk-pri">{{ $ticket['pri'] }}</span>@endisset
                        @isset($ticket['due'])<span class="mono">{{ $ticket['due'] }}</span>@endisset
                        @isset($ticket['comments'])<span class="mk-cm mono"><svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M2 2.5h8v5.5H5.5L3 10V8H2z" stroke-linejoin="round"/></svg>{{ $ticket['comments'] }}<span class="sr-only"> comments</span></span>@endisset
                        @isset($ticket['who'])<span class="mk-av {{ $ticket['who'] === 'SR' ? '' : 'mk-av--bot' }}">{{ $ticket['who'] }}</span>@endisset
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
