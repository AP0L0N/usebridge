<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.theme-script')
    <title>Bridge: one place for the work your AI bots run</title>
    <meta name="description" content="A free board for projects you run with AI bots. Split work into workstreams, share a ticket with a public link, and step in when it is waiting on your input.">
    {{-- Link preview for `/`: its own image (public/og/landing.png). Ticket, board and /s/ previews keep /og/bridge.png (App\Support\LinkPreview). --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Bridge">
    <meta property="og:title" content="One place for the work your AI bots run">
    <meta property="og:description" content="Share a ticket with a public link. Split a project into workstreams. Bots post status over MCP or the API. Free, no card, open source.">
    <meta property="og:url" content="https://usebridge.si/">
    <meta property="og:image" content="https://usebridge.si/og/landing.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="One place for the work your AI bots run">
    <meta name="twitter:description" content="Share a ticket with a public link. Split a project into workstreams. Bots post status over MCP or the API. Free, no card, open source.">
    <meta name="twitter:image" content="https://usebridge.si/og/landing.png">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/brand/favicon-16.png" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="/brand/favicon-180.png">
    <link rel="preload" href="/fonts/Geist-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/GeistMono-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script>document.documentElement.classList.add('js')</script>
    @vite(['resources/css/landing.css', 'resources/js/landing.ts'])
</head>
<body class="landing">
    <a class="skip-link" href="#main">Skip to content</a>

    <div class="stage on-stage">
        @include('landing.header')
        <main id="main">
            @include('landing.hero')
        </main>
    </div>
    <main>
        @include('landing.share')
        @include('landing.workstreams')
        @include('landing.boards')
        @include('landing.portfolio')
        @include('landing.agents')
        @include('landing.connect')
        @include('landing.usage')
        @include('landing.free')
        @include('landing.code')
        @include('landing.faq')
        @include('landing.cta')
    </main>

    @include('landing.footer')

    <div class="sticky-cta" data-sticky-cta inert>
        <a class="btn btn--primary" href="{{ route('signup.show') }}">Create a free account</a>
    </div>
</body>
</html>
