{{-- Root view of the public share link pages (/s/{token}): no app shell, never indexed, no Referer (HandleInertiaRequests::rootView). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.theme-script')
        <title inertia>{{ config('app.name', 'Bridge') }}</title>
        <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/bridge.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
