{{-- The one page for every share link token that does not open a ticket (unknown, expired, revoked): status 404. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        @include('partials.theme-script')
        <title>Link unavailable · {{ config('app.name', 'Bridge') }}</title>
        <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/bridge.css'])
    </head>
    <body>
        <main class="pubshare-gone">
            <h1>This link has expired or is unavailable</h1>
            <p>Ask the person who shared it for a new link.</p>
        </main>
    </body>
</html>
