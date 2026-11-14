<?php

namespace App\Support;

use App\Models\NextStep;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\Tickets\Actor;

/**
 * Ticket references in stored text (docs/API-TICKETS.md "Ticket references").
 *
 * Storage: a mention of an existing ticket key ("BR-9") is saved as the token `[[ticket:<ticket id>|<key when saved>]]`,
 * e.g. `[[ticket:tk-01jc…|BR-9]]`. The id is what resolves (it survives a key / prefix change); the key after the bar is
 * only the fallback text when the ticket can no longer be found. Keys of tickets that do not exist stay plain text, and
 * nothing inside a URL, a Markdown link, inline code / a code block or an existing token is touched.
 *
 * Write paths: every model with StoresTicketRefs tokenizes its TICKET_REF_FIELDS when saved (web, REST, MCP, services),
 * only when the result fits the column (TICKET_REF_FIELDS lengths). Existing text: `php artisan bridge:ticket-refs`
 * (TicketRefsBackfill, docs/TICKET-REFS.md), run by hand, never by a migration.
 * Read paths: the REST API (ExpandTicketRefs middleware) and MCP tools (BridgeTool) expand tokens back to the current
 * key, so clients never see tokens; the web app gets the raw tokens and renders them as ticket chips
 * (resources/js/lib/ticketRefs.ts), resolving them in one batch (GET /ticket-refs).
 */
final class TicketRefs
{
    /** Ticket key: the project prefix (TicketPrefix::PATTERN) + "-" + number. */
    public const KEY = '[A-Z][A-Z0-9]{0,7}-\d+';

    /** A stored token: group 1 = ticket id, group 2 = the key when saved (optional). */
    public const TOKEN = '/\[\[ticket:([A-Za-z0-9_-]{1,64})(?:\|('.self::KEY.'))?\]\]/';

    /**
     * Protected spans first (kept as they are), then a bare key. Order matters: a key inside a fenced block, inline code,
     * a token, a Markdown link (text and target), an autolink or a URL is matched as part of that span and skipped.
     */
    private const SCAN = '/(?<skip>```.*?(?:```|\z)|~~~.*?(?:~~~|\z)|`[^`\n]*`|\[\[ticket:[^\]\n]*\]\]|\[[^\]\n]*\]\([^)\n]*\)|<[a-z][a-z0-9+.-]*:[^>\s]*>|\b(?i:[a-z][a-z0-9+.-]*:\/\/|www\.)[^\s<>]+)|(?<![\w\/.#@-])(?<key>'.self::KEY.')(?![\w-])/s';

    /**
     * Every stored text column that can mention tickets (workstream / project names and agent instructions are left
     * alone on purpose; ticket titles stay plain and get read-time references, tokenizeMany). Table => model; the
     * columns and their lengths are the model's TICKET_REF_FIELDS.
     *
     * @var array<string, class-string>
     */
    public const MODELS = [
        'tickets' => Ticket::class,
        'ticket_comments' => TicketComment::class,
        'projects' => Project::class,
        'project_status_events' => ProjectStatusEvent::class,
        'status_updates' => StatusUpdate::class,
        'questions' => Question::class,
        'required_inputs' => RequiredInput::class,
        'next_steps' => NextStep::class,
    ];

    /** Bytes a MySQL TEXT column holds (TICKET_REF_FIELDS null): production is MySQL (README), tests SQLite. */
    public const TEXT_BYTES = 65535;

    /**
     * Mentions of existing tickets -> tokens. $workspaceId: whose tickets count (null = the current workspace scope).
     * $maxChars: the column's length (TICKET_REF_FIELDS; null = TEXT). When the tokenized text would not fit, the text
     * is returned as typed: a token is never cut by a short column, the mention just stays a plain key.
     */
    public static function tokenize(?string $text, ?int $workspaceId = null, ?int $maxChars = null): ?string
    {
        if ($text === null || ! preg_match('/'.self::KEY.'/', $text)) {
            return $text;
        }
        $keys = self::mentionedKeys($text);
        if ($keys === []) {
            return $text;
        }
        $out = self::replaceKeys($text, self::idsByKey($keys, $workspaceId));

        return self::fits($out, $maxChars) ? $out : $text;
    }

    /** Whether $value fits a column of $maxChars characters (varchar), or a TEXT column when null. */
    public static function fits(string $value, ?int $maxChars): bool
    {
        return $maxChars === null ? strlen($value) <= self::TEXT_BYTES : mb_strlen($value) <= $maxChars;
    }

    /**
     * Read-time references for texts that are stored plain (ticket titles: README §11.2 "Kanban card titles"): mentions
     * of existing tickets as tokens, all texts resolved in one query. Never stored; titles keep the key as typed.
     *
     * @param  array<array-key, string>  $texts
     * @return array<array-key, string> same keys; only texts that gained a token
     */
    public static function tokenizeMany(array $texts, ?int $workspaceId = null): array
    {
        $mentioned = [];
        foreach ($texts as $i => $text) {
            if (preg_match('/'.self::KEY.'/', $text) && ($keys = self::mentionedKeys($text)) !== []) {
                $mentioned[$i] = $keys;
            }
        }
        if ($mentioned === []) {
            return [];
        }
        $ids = self::idsByKey(array_values(array_unique(array_merge(...array_values($mentioned)))), $workspaceId);
        $out = [];
        foreach (array_keys($mentioned) as $i) {
            $tokenized = self::replaceKeys($texts[$i], $ids);
            if ($tokenized !== $texts[$i]) {
                $out[$i] = $tokenized;
            }
        }

        return $out;
    }

    /** Tokens -> the current key (falls back to the key in the token, else nothing). @param array<string, array{key:string}>|null $resolved */
    public static function expand(?string $text, ?array $resolved = null): ?string
    {
        if ($text === null || ! str_contains($text, '[[ticket:')) {
            return $text;
        }
        $resolved ??= self::resolve(self::ids($text));

        return preg_replace_callback(self::TOKEN, fn (array $m) => $resolved[$m[1]]['key'] ?? ($m[2] ?? ''), $text);
    }

    /** @return list<string> ticket ids referenced in $text */
    public static function ids(?string $text): array
    {
        if ($text === null || ! preg_match_all(self::TOKEN, $text, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /** Number of tokens in $text (repeats counted). */
    public static function tokenCount(string $text): int
    {
        return (int) preg_match_all(self::TOKEN, $text);
    }

    /**
     * Tickets by id, in the current workspace scope (any project, archived tickets included): one query. What a chip and
     * its popover show (README §11.2): key, title, status (column key + the project's label), assignee.
     *
     * @param  list<string>  $ids
     * @return array<string, array{key:string,title:string,project_slug:?string,archived:bool,status:string,status_label:string,assignee_id:?string,assignee_name:?string}>
     */
    public static function resolve(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }
        $names = Actor::names();

        return Ticket::query()->whereIn('tickets.id', $ids)
            ->leftJoin('projects', 'projects.id', '=', 'tickets.project_id')
            ->get(['tickets.id', 'tickets.key', 'tickets.title', 'tickets.status', 'tickets.assignee_id', 'tickets.archived_at', 'projects.slug as project_slug', 'projects.column_labels'])
            ->mapWithKeys(fn (Ticket $t) => [$t->id => [
                'key' => (string) $t->key,
                'title' => (string) $t->title,
                'project_slug' => $t->getAttribute('project_slug'),
                'archived' => $t->archived_at !== null,
                'status' => (string) $t->status,
                'status_label' => self::columnLabel((string) $t->status, $t->getAttribute('column_labels')),
                'assignee_id' => $t->assignee_id,
                'assignee_name' => Actor::label($t->assignee_id, $names),
            ]])->all();
    }

    /** The project's own label of a column (projects.column_labels, raw JSON from the join), else the default. */
    private static function columnLabel(string $status, mixed $labels): string
    {
        $own = is_string($labels) ? json_decode($labels, true) : $labels;

        return BoardColumns::labelFor($status, is_array($own) ? $own : null);
    }

    /** @return list<string> keys mentioned outside the protected spans */
    public static function mentionedKeys(string $text): array
    {
        preg_match_all(self::SCAN, $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_values(array_unique(array_filter(array_map(fn (array $x) => $x['key'] ?? null, $m))));
    }

    /** @param array<string, string> $idsByKey key => ticket id */
    public static function replaceKeys(string $text, array $idsByKey): string
    {
        if ($idsByKey === []) {
            return $text;
        }

        return preg_replace_callback(self::SCAN, function (array $m) use ($idsByKey) {
            $key = $m['key'] ?? null;

            return $key !== null && isset($idsByKey[$key]) ? '[[ticket:'.$idsByKey[$key].'|'.$key.']]' : $m[0];
        }, $text, -1, $count, PREG_UNMATCHED_AS_NULL);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> key => ticket id
     */
    private static function idsByKey(array $keys, ?int $workspaceId): array
    {
        $q = $workspaceId !== null ? Ticket::acrossWorkspaces()->where('workspace_id', $workspaceId) : Ticket::query();

        return $q->whereIn('key', $keys)->pluck('id', 'key')->all();
    }
}
