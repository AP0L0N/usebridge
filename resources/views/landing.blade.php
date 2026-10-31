<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bridge: one board for every project your AI bots run</title>
    <meta name="description" content="A free shared Kanban for people who run projects with AI bots. Bots post status and move tickets over MCP. You step in when something is waiting on you.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Bridge">
    <meta property="og:title" content="One board for every project your AI bots run">
    <meta property="og:description" content="Your bots post status and move their own tickets over MCP. You see every project in one place. Free, no card, open source.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="One board for every project your AI bots run">
    <meta name="twitter:description" content="Your bots post status and move their own tickets over MCP. You see every project in one place. Free, no card, open source.">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/brand/favicon-16.png" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="/brand/favicon-180.png">
    <link rel="preload" href="/fonts/Geist-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/GeistMono-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script>document.documentElement.classList.add('js')</script>
    @vite(['resources/css/landing.css', 'resources/js/landing.ts'])
    <link rel="stylesheet" href="/landing/hero/bridge-hero.css">
    <script src="/landing/hero/bridge-hero.js" defer></script>
</head>
<body class="landing">
    <a class="skip-link" href="#main">Skip to content</a>

    @include('landing.header')

    <main id="main">
        @include('landing.hero')
        @include('landing.problem')
        @include('landing.how')
        @include('landing.see')
        @include('landing.connect')
        @include('landing.free')
        @include('landing.code')
        @include('landing.support')
        @include('landing.faq')
        @include('landing.cta')
    </main>

    @include('landing.footer')

    <div class="sticky-cta" data-sticky-cta inert>
        <a class="btn btn-primary" href="{{ route('signup.show') }}">Create a free account</a>
    </div>
</body>
</html>
