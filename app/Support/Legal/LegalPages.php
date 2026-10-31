<?php

namespace App\Support\Legal;

use Illuminate\Support\Str;

/**
 * Legal pages (/terms, /privacy, /imprint and the SL /sl/pogoji, /sl/zasebnost, /sl/impresum) and the site footer.
 *
 * Content: <config bridge.legal_dir>/<file> when that directory is set and the file exists (the final texts, kept outside
 * the public repo), else the placeholder in resources/legal. Only the fixed files below are ever read. Markdown is
 * rendered on the server with raw HTML stripped (html_input 'strip': drafting notes in HTML comments and any <script>
 * never reach the page) and unsafe links (javascript:, data:, …) dropped.
 */
final class LegalPages
{
    /** id => [path, locale, the same page in the other language, file in legal_dir, placeholder under resources/legal]. */
    public const PAGES = [
        'terms' => ['path' => '/terms', 'locale' => 'en', 'other' => 'pogoji', 'file' => 'terms.en.md', 'placeholder' => 'en/terms.md'],
        'privacy' => ['path' => '/privacy', 'locale' => 'en', 'other' => 'zasebnost', 'file' => 'privacy.en.md', 'placeholder' => 'en/privacy.md'],
        'imprint' => ['path' => '/imprint', 'locale' => 'en', 'other' => 'impresum', 'file' => 'imprint.en.md', 'placeholder' => 'en/imprint.md'],
        'pogoji' => ['path' => '/sl/pogoji', 'locale' => 'sl', 'other' => 'terms', 'file' => 'pogoji.sl.md', 'placeholder' => 'sl/pogoji.md'],
        'zasebnost' => ['path' => '/sl/zasebnost', 'locale' => 'sl', 'other' => 'privacy', 'file' => 'zasebnost.sl.md', 'placeholder' => 'sl/zasebnost.md'],
        'impresum' => ['path' => '/sl/impresum', 'locale' => 'sl', 'other' => 'imprint', 'file' => 'impresum.sl.md', 'placeholder' => 'sl/impresum.md'],
    ];

    /** Footer link targets per locale: imprint, terms, privacy. */
    private const LINKS = [
        'en' => ['imprint' => 'imprint', 'terms' => 'terms', 'privacy' => 'privacy'],
        'sl' => ['imprint' => 'impresum', 'terms' => 'pogoji', 'privacy' => 'zasebnost'],
    ];

    /** Which page title key (legal.pages.*) a page id uses. */
    private const TITLES = ['terms' => 'terms', 'privacy' => 'privacy', 'imprint' => 'imprint', 'pogoji' => 'terms', 'zasebnost' => 'privacy', 'impresum' => 'imprint'];

    public static function locale(string $id): string
    {
        return self::PAGES[$id]['locale'];
    }

    /** Props for the Legal/Show page. */
    public static function page(string $id): array
    {
        $page = self::PAGES[$id];

        return [
            'id' => $id,
            'locale' => $page['locale'],
            'title' => __('legal.pages.'.self::TITLES[$id], [], $page['locale']),
            'prevails' => __('legal.prevails', [], $page['locale']),
            'html' => self::html($id),
        ];
    }

    public static function html(string $id): string
    {
        return (string) Str::markdown(self::markdown($id), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    private static function markdown(string $id): string
    {
        $page = self::PAGES[$id];
        $dir = config('bridge.legal_dir');
        if (filled($dir)) {
            $file = rtrim((string) $dir, '/').'/'.$page['file'];
            if (is_file($file) && is_readable($file)) {
                return (string) file_get_contents($file);
            }
        }

        return (string) file_get_contents(resource_path('legal/'.$page['placeholder']));
    }

    /**
     * Shared footer props (every Inertia page). $id: the legal page being shown, or null; only legal pages get the
     * EN | SL switch, and the SL ones the SL footer. Copy from lang/{en,sl}/legal.php; :link is filled by the page.
     */
    public static function footer(?string $id): array
    {
        $locale = $id !== null ? self::locale($id) : 'en';
        $t = fn (string $key, array $replace = []) => __('legal.footer.'.$key, $replace, $locale);
        $links = [];
        foreach (self::LINKS[$locale] as $key => $target) {
            $links[] = ['label' => $t($key), 'href' => self::PAGES[$target]['path']];
        }

        return [
            'locale' => $locale,
            'navLabel' => $t('nav_label'),
            'links' => $links,
            'source' => ['label' => $t('source'), 'href' => self::sourceUrl()],
            'copyright' => $t('copyright', ['entity' => (string) config('bridge.legal_entity')]),
            'getSource' => $t('get_source'),
            'switch' => $id === null ? null : [
                'label' => $t('switch_label'),
                'en' => self::PAGES[$locale === 'en' ? $id : self::PAGES[$id]['other']]['path'],
                'sl' => self::PAGES[$locale === 'sl' ? $id : self::PAGES[$id]['other']]['path'],
            ],
        ];
    }

    /** AGPL §13: the source of the running version; /tree/<sha> when the deployed commit is known. */
    public static function sourceUrl(): string
    {
        $url = rtrim((string) config('bridge.source_url'), '/');
        $sha = self::deployedSha();

        return $sha === null ? $url : $url.'/tree/'.$sha;
    }

    /** config bridge.source_sha, else the file .bridge-deploy-sha (written by the deploy); a 7–40 hex sha or null. */
    public static function deployedSha(): ?string
    {
        $sha = trim((string) config('bridge.source_sha'));
        if ($sha === '') {
            $file = base_path('.bridge-deploy-sha');
            $sha = is_file($file) && is_readable($file) ? trim((string) file_get_contents($file, false, null, 0, 128)) : '';
        }

        return preg_match('/^[0-9a-f]{7,40}$/i', $sha) === 1 ? strtolower($sha) : null;
    }
}
