{{-- What a link expander without a session gets for a private ticket or board URL (EnsureUserWorkspace, LinkPreview::forGuest): the preview meta, nothing of the workspace. --}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $preview['title'] }}</title>
        <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
        @include('partials.link-preview')
    </head>
    <body>
        <h1>{{ $preview['title'] }}</h1>
        <p><a href="{{ route('login') }}">{{ $preview['description'] }}</a></p>
    </body>
</html>
