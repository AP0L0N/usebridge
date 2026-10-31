{{--
    The site footer for Blade pages: the same markup and lang keys (lang/{en,sl}/legal.php) as SiteFooter.tsx, both from
    LegalPages::footer(). @include('partials.footer') on any page; pass ['legalPage' => 'terms'] (a LegalPages id) on a
    legal page for its language and the EN | SL switch. Also carries the landing's .site-footer-legal / .sep / .copy
    classes (styled in landing.css), so the landing footer keeps its look. The source link is the deployed commit when known (AGPL §13).
--}}
@php
    $footer = \App\Support\Legal\LegalPages::footer($legalPage ?? null);
    $source = '<a href="'.e($footer['source']['href']).'" rel="noopener">'.e($footer['getSource']).'</a>';
@endphp
<footer class="sfoot site-footer-legal" lang="{{ $footer['locale'] }}">
    <div class="sfoot-in">
        <nav aria-label="{{ $footer['navLabel'] }}">
            <ul class="sfoot-links">
                @foreach ($footer['links'] as $link)
                    <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a><span class="sfoot-sep sep" aria-hidden="true"> · </span></li>
                @endforeach
                <li><a href="{{ $footer['source']['href'] }}" rel="noopener">{{ $footer['source']['label'] }}</a>@if ($footer['switch'])<span class="sfoot-sep sep" aria-hidden="true"> · </span>@endif</li>
                @if ($footer['switch'])
                    <li><span class="sfoot-lang" role="group" aria-label="{{ $footer['switch']['label'] }}"><a href="{{ $footer['switch']['en'] }}" hreflang="en" lang="en"@if ($footer['locale'] === 'en') aria-current="page"@endif>EN</a><span aria-hidden="true"> | </span><a href="{{ $footer['switch']['sl'] }}" hreflang="sl" lang="sl"@if ($footer['locale'] === 'sl') aria-current="page"@endif>SL</a></span></li>
                @endif
            </ul>
        </nav>
        <p class="sfoot-copy copy">{!! str_replace(':link', $source, e($footer['copyright'])) !!}</p>
    </div>
</footer>
