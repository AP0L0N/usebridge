<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Link previews (Open Graph + Twitter Card, resources/views/partials/link-preview.blade.php): what Slack and similar
 * apps show when a Bridge link is pasted. The meta is in the first HTML of every root view. A private URL (ticket,
 * board) never says more than the pasted URL itself does: a crawler is not logged in, so forGuest() answers without
 * looking anything up ("Bridge ticket CC-12", "Bridge board"). Only a public share link (/s/{token}) carries the
 * ticket title and a summary.
 */
final class LinkPreview
{
    public const SITE = 'Bridge';

    /** The branded 1200×630 card, served from public/ (no storage link). */
    public const IMAGE = '/og/bridge.png';

    public const SUMMARY = 160;

    /** Link expanders that fetch a pasted URL without a session. */
    private const CRAWLERS = '/Slackbot|Slack-ImgProxy|Twitterbot|facebookexternalhit|Facebot|LinkedInBot|Discordbot|TelegramBot|WhatsApp|SkypeUriPreview|Iframely|Embedly/i';

    /**
     * @param  string  $path  og:url, as a path of the public URL
     * @return array{title: string, description: string, url: string, image: string}
     */
    public static function meta(string $title, string $description, string $path = '/'): array
    {
        $root = rtrim((string) config('bridge.public_url'), '/');

        return ['title' => $title, 'description' => $description, 'url' => $root.$path, 'image' => $root.self::IMAGE];
    }

    /**
     * Every page without its own preview (the login screen a guest is sent to, the app itself).
     *
     * @return array{title: string, description: string, url: string, image: string}
     */
    public static function generic(Request $request): array
    {
        return self::meta(self::SITE, 'One board for every project your AI bots run.', $request->getPathInfo());
    }

    /**
     * The preview of a private ticket or board URL for a link expander without a session, null for every other request
     * (a person goes to the login screen as before). Built from the URL alone: the same answer whether or not the
     * ticket or board exists.
     *
     * @return array{title: string, description: string, url: string, image: string}|null
     */
    public static function forGuest(Request $request): ?array
    {
        if (! $request->isMethod('GET') || preg_match(self::CRAWLERS, (string) $request->userAgent()) !== 1) {
            return null;
        }
        if ($request->routeIs('projects.show')) {
            $ws = $request->query('ws');

            return self::meta(self::SITE.' board', 'Sign in to Bridge to open this board.', $request->getPathInfo().(is_string($ws) ? '?'.http_build_query(['ws' => $ws]) : ''));
        }
        if ($request->routeIs('projects.tickets.show', 'tickets.redirect.*')) {
            $key = strtoupper((string) ($request->route('key') ?? $request->route('ref')));
            $title = preg_match('/^'.TicketRefs::KEY.'$/', $key) === 1 ? self::SITE.' ticket '.$key : self::SITE.' ticket';

            return self::meta($title, 'Sign in to Bridge to open this ticket.', $request->getPathInfo());
        }

        return null;
    }

    /** Markdown as one line of plain text, at most SUMMARY characters. */
    public static function summary(?string $markdown): string
    {
        $html = Str::markdown((string) $markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return Str::limit(Str::squish($text), self::SUMMARY - 1, '…');
    }
}
