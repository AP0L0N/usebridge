{{-- Blade twin of resources/js/Components/SiteFooter.tsx: same lang keys (lang/en/legal.php 'footer'), same links, same AGPL
     source URL (the deployed sha when known) via LegalPages::footer(). No EN | SL (legal pages only). Styled by .site-footer-legal. --}}
@php($footer = \App\Support\Legal\LegalPages::footer(null))
<div class="site-footer-legal" lang="{{ $footer['locale'] }}">
    <nav aria-label="{{ $footer['navLabel'] }}">
        <ul>
            @foreach ($footer['links'] as $link)
                <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a><span class="sep" aria-hidden="true">·</span></li>
            @endforeach
            <li><a href="{{ $footer['source']['href'] }}" rel="noopener">{{ $footer['source']['label'] }}</a></li>
        </ul>
    </nav>
    <p class="copy">{!! str_replace(':link', '<a href="'.e($footer['source']['href']).'" rel="noopener">'.e($footer['getSource']).'</a>', e($footer['copyright'])) !!}</p>
</div>
