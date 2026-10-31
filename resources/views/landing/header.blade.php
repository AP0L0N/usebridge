<header class="site-header">
    <div class="bar">
        <div class="header-start">
            <a class="brand" href="{{ route('portfolio') }}" aria-label="bridge home"><img src="/brand/lockup-dark.svg" alt="bridge"></a>
            <nav class="site-nav" aria-label="Main">
                <a href="#how">How it works</a>
                <a href="#connect">Connect your bot</a>
                <a href="#free">Free</a>
                <a href="#code">Code</a>
                <a href="#faq">FAQ</a>
            </nav>
        </div>
        <div class="header-actions">
            <a class="signin" href="{{ route('login.show') }}">Sign in</a>
            <a class="btn btn--inverted" href="{{ route('signup.show') }}">Sign up free</a>
            <button class="menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="site-menu">
                <img src="/landing/img/icon-menu-surface.svg" alt="">
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
    <div class="menu-sheet" id="site-menu" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="bar">
            <a class="brand" href="{{ route('portfolio') }}" aria-label="bridge home"><img src="/brand/lockup-dark.svg" alt="bridge"></a>
            <button class="menu-close" type="button" data-menu-close><img src="/landing/img/icon-x-surface.svg" alt=""><span class="sr-only">Close menu</span></button>
        </div>
        <nav class="menu-links" aria-label="Main (menu)">
            @foreach ([['#how', 'How it works'], ['#connect', 'Connect your bot'], ['#free', 'Free'], ['#code', 'Code'], ['#faq', 'FAQ']] as [$href, $label])
                <a href="{{ $href }}">{{ $label }} <img src="/landing/img/icon-down-ink400.svg" alt=""></a>
            @endforeach
        </nav>
        <div class="menu-actions">
            <a class="btn btn--inverted btn--lg btn--block" href="{{ route('signup.show') }}">Sign up free</a>
            <a class="signin" href="{{ route('login.show') }}">Sign in</a>
            <p class="mono">{{ __('landing.trust_line') }}</p>
        </div>
    </div>
</header>
