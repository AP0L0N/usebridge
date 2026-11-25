<?php

namespace App\Support;

/**
 * Comment bodies are Markdown (the web composer writes it; agents post plain text, which is Markdown too). Every write
 * path (web, REST, MCP) saves through TicketService::comment, which runs sanitize() first. Nothing becomes HTML here:
 *
 * - raw HTML never survives as markup: a `<` that would open a tag, comment or declaration is backslash-escaped, so
 *   `use <div> here` still reads `use <div> here` (no tag is allowed; the renderer shows any old raw HTML as text too);
 * - link targets (inline links, reference definitions, autolinks) keep only http, https, mailto or a relative URL;
 *   anything else (javascript:, data:, vbscript:, also entity / percent / backslash encoded) loses its target;
 * - control characters other than tab and line breaks are dropped.
 *
 * Code spans and fenced code are left exactly as typed; indented code keeps its `<`. Running it twice changes nothing.
 * The web app renders the result with MarkdownBody (resources/js/lib/markdown.ts: element allowlist, safe hrefs,
 * rel="noopener noreferrer").
 */
final class CommentMarkdown
{
    public const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    private const FENCE = '/^ {0,3}(`{3,}|~{3,})/';

    private const CODE_SPAN = '/(?<![`\\\\])(`+)(?!`)(?:(?!\n[ \t]*\n).)+?(?<!`)\1(?!`)/s';

    private const INLINE_LINK = '/\]\(\s*\K(<[^<>\n]*>|[^\s()<>]*(?:\([^\s()]*\)[^\s()<>]*)*)/';

    private const REFERENCE = '/^( {0,3}\[[^\]\n]+\]:[ \t]*\n?[ \t]*)(<[^<>\n]*>|\S+)/m';

    public static function sanitize(string $body): string
    {
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body);
        $out = '';
        foreach (self::blocks($body) as [$text, $kind]) {
            $out .= match ($kind) {
                'fence' => $text,
                'indented' => self::links($text),
                default => self::prose($text),
            };
        }

        return $out;
    }

    /** Whether a link target may stay: http(s), mailto or no scheme, judged on the raw and the decoded form. */
    public static function isSafeHref(string $url): bool
    {
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = rawurldecode(preg_replace('/\\\\(?=[[:punct:]])/', '', $decoded));
        $decoded = preg_replace('/[\x00-\x20\x7F]|\xC2[\x80-\xA0]/', '', $decoded);

        foreach ([$url, $decoded] as $candidate) {
            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $candidate, $m) && ! in_array(strtolower($m[1]), self::SAFE_SCHEMES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The body cut into fenced code, indented code (4 spaces or a tab after a blank line) and everything else.
     *
     * @return list<array{0: string, 1: 'fence'|'indented'|'text'}>
     */
    private static function blocks(string $body): array
    {
        $blocks = [];
        $push = function (string $line, string $kind) use (&$blocks): void {
            $last = array_key_last($blocks);
            if ($last !== null && $blocks[$last][1] === $kind) {
                $blocks[$last][0] .= $line;
            } else {
                $blocks[] = [$line, $kind];
            }
        };
        $fence = null;
        $blankBefore = true;
        $indented = false;
        foreach (preg_split('/(?<=\n)/', $body) as $line) {
            if ($line === '') {
                continue;
            }
            if ($fence !== null) {
                $push($line, 'fence');
                if (preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*\r?\n?$/', $line)) {
                    $fence = null;
                }

                continue;
            }
            $blank = trim($line) === '';
            if (preg_match(self::FENCE, $line, $m)) {
                $fence = $m[1];
                $push($line, 'fence');
            } else {
                $indented = preg_match('/^( {4}|\t)/', $line) && ($blankBefore || $indented) || ($indented && $blank);
                $push($line, $indented ? 'indented' : 'text');
            }
            $blankBefore = $blank;
        }

        return $blocks;
    }

    /** Text outside fenced / indented code: code spans stay as they are, the rest gets safe links and no raw HTML. */
    private static function prose(string $text): string
    {
        $out = '';
        $at = 0;
        preg_match_all(self::CODE_SPAN, $text, $spans, PREG_OFFSET_CAPTURE);
        foreach ($spans[0] as [$span, $offset]) {
            $out .= self::escapeHtml(self::links(substr($text, $at, $offset - $at))).$span;
            $at = $offset + strlen($span);
        }

        return $out.self::escapeHtml(self::links(substr($text, $at)));
    }

    /** Unsafe targets of inline links and reference definitions are emptied; the link text stays. */
    private static function links(string $text): string
    {
        $text = preg_replace_callback(self::INLINE_LINK, fn (array $m): string => self::isSafeHref(trim($m[1], '<>')) ? $m[1] : '', $text);

        return preg_replace_callback(self::REFERENCE, fn (array $m): string => $m[1].(self::isSafeHref(trim($m[2], '<>')) ? $m[2] : '<>'), $text);
    }

    /**
     * A `<` that would start raw HTML (`<tag`, `</tag`, `<!`, `<?`) gets a backslash and reads as text. Autolinks to a
     * safe target or an email address, `<…>` link targets, `a < b` and already escaped `\<` are left alone.
     */
    private static function escapeHtml(string $text): string
    {
        return preg_replace_callback('/(\\\\*)<(?=[A-Za-z\/!?])/', function (array $m) use ($text): string {
            [$match, $offset] = $m[0];
            if (strlen($m[1][0]) % 2 === 1) {
                return $match;
            }
            $rest = substr($text, $offset + strlen($match) - 1);
            $before = substr($text, 0, $offset + strlen($m[1][0]));
            $autolink = preg_match('/^<([a-z][a-z0-9+.-]{1,31}:[^\s<>]*)>/i', $rest, $url) && self::isSafeHref($url[1]);
            $email = preg_match('/^<[^\s<>@\\\\]+@[^\s<>@\\\\]+>/', $rest);
            $target = preg_match('/(?:\]\(|\]:)[ \t]*\n?[ \t]*$/', $before);

            return $autolink || $email || $target ? $match : $m[1][0].'\\<';
        }, $text, flags: PREG_OFFSET_CAPTURE);
    }
}
