{{-- Link preview (Open Graph + Twitter Card, App\Support\LinkPreview): server-rendered so Slack and similar apps read it. --}}
<meta property="og:site_name" content="{{ \App\Support\LinkPreview::SITE }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $preview['title'] }}">
<meta property="og:description" content="{{ $preview['description'] }}">
<meta property="og:url" content="{{ $preview['url'] }}">
<meta property="og:image" content="{{ $preview['image'] }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $preview['title'] }}">
<meta name="twitter:description" content="{{ $preview['description'] }}">
<meta name="twitter:image" content="{{ $preview['image'] }}">
