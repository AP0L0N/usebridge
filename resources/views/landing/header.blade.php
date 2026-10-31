<header class="site-header">
    <div class="wrap">
        <a class="brand" href="{{ route('portfolio') }}" aria-label="Bridge home"><img src="/brand/lockup-dark.svg" alt="Bridge" width="149" height="40"></a>
        <nav class="site-nav" id="site-nav" aria-label="Main">
            <a href="#how">How it works</a>
            <a href="#connect">Connect your bot</a>
            <a href="#free">Free</a>
            <a href="#code">Code</a>
            <a href="#faq">FAQ</a>
        </nav>
        <div class="header-actions">
            <a class="signin" href="{{ route('login.show') }}">Sign in</a>
            <a class="btn btn-primary btn-sm" href="{{ route('signup.show') }}">Sign up free</a>
            <button class="menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="site-nav">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>
