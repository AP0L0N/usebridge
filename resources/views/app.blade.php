<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.theme-script')
        <title inertia>{{ config('app.name', 'Bridge') }}</title>
        @include('partials.link-preview', ['preview' => $preview ?? \App\Support\LinkPreview::generic(request())])
        {{-- The Span mark (public/brand, from the brand files) --}}
        <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/brand/favicon-32.png" sizes="32x32" type="image/png">
        <link rel="icon" href="/brand/favicon-16.png" sizes="16x16" type="image/png">
        <link rel="apple-touch-icon" href="/brand/favicon-180.png">
        @vite(['resources/css/bridge.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
