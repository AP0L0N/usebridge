{{-- Amber "PLACEHOLDER" box. Callers wrap it in @if (config('bridge.landing_pending')) so it is absent from the HTML otherwise. --}}
<div class="pending" data-placeholder="{{ $key }}">
    <p class="mono">PLACEHOLDER · {{ $label }} · Tilen to approve, do not ship</p>
    <p>{{ $text }}</p>
</div>
