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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket references in stored text (docs/API-TICKETS.md "Ticket references").
 *
 * Storage: a mention of an existing ticket key ("BR-9") is saved as the token `[[ticket:<ticket id>|<key when saved>]]`,
 * e.g. `[[ticket:tk-01jc…|BR-9]]`. The id is what resolves (it survives a key / prefix change); the key after the bar is
 * only the fallback text when the ticket can no longer be found. Keys of tickets that do not exist stay plain text, and
 * nothing inside a URL, a Markdown link, inline code / a code block or an existing token is touched.
 *
 * Write paths: every model with StoresTicketRefs tokenizes its TICKET_REF_FIELDS when saved (web, REST, MCP, services).
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
     * Every stored text column that can mention tickets (ticket titles, workstream / project names and agent
     * instructions are left alone on purpose). Table => model; the columns are the model's TICKET_REF_FIELDS.
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

    /** Mentions of existing tickets -> tokens. $workspaceId: whose tickets count (null = the current workspace scope). */
    public static function tokenize(?string $text, ?int $workspaceId = null): ?string
    {
        if ($text === null || ! preg_match('/'.self::KEY.'/', $text)) {
            return $text;
        }
        $keys = self::mentionedKeys($text);
        if ($keys === []) {
            return $text;
        }

        return self::replaceKeys($text, self::idsByKey($keys, $workspaceId));
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

    /**
     * Tickets by id, in the current workspace scope (archived tickets included): one query.
     *
     * @param  list<string>  $ids
     * @return array<string, array{key:string,title:string,project_slug:?string,archived:bool}>
     */
    public static function resolve(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }

        return Ticket::query()->whereIn('tickets.id', $ids)
            ->leftJoin('projects', 'projects.id', '=', 'tickets.project_id')
            ->get(['tickets.id', 'tickets.key', 'tickets.title', 'tickets.archived_at', 'projects.slug as project_slug'])
            ->mapWithKeys(fn (Ticket $t) => [$t->id => [
                'key' => (string) $t->key,
                'title' => (string) $t->title,
                'project_slug' => $t->getAttribute('project_slug'),
                'archived' => $t->archived_at !== null,
            ]])->all();
    }

    /** @return list<string> keys mentioned outside the protected spans */
    public static function mentionedKeys(string $text): array
    {
        preg_match_all(self::SCAN, $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_values(array_unique(array_filter(array_map(fn (array $x) => $x['key'] ?? null, $m))));
    }

    /**
     * Backfill (the 2026_11_14 migration): tokenize ($expand false) or expand ($expand true) every column of MODELS,
     * chunked by primary key, raw queries (no model events, timestamps untouched). Idempotent: tokens are never
     * re-tokenized, plain text is never re-expanded. Tickets resolve within the row's own workspace.
     *
     * @return array<string, int> references converted per table
     */
    public static function backfill(bool $expand = false, int $chunk = 500): array
    {
        $counts = [];
        foreach (self::MODELS as $table => $model) {
            $columns = array_values(array_filter($model::TICKET_REF_FIELDS, fn (string $c) => Schema::hasColumn($table, $c)));
            if ($columns === [] || ! Schema::hasTable($table)) {
                continue;
            }
            $hasWorkspace = Schema::hasColumn($table, 'workspace_id');
            $counts[$table] = 0;
            DB::table($table)
                ->where(function ($q) use ($columns, $expand) {
                    foreach ($columns as $c) {
                        $expand ? $q->orWhere($c, 'like', '%[[ticket:%') : $q->orWhere($c, 'like', '%-%');
                    }
                })
                ->select(['id', ...$columns, ...($hasWorkspace ? ['workspace_id'] : [])])
                ->chunkById($chunk, function ($rows) use ($table, $columns, $expand, &$counts) {
                    $lookup = $expand ? self::keysById($rows, $columns) : self::idsByKeyPerWorkspace($rows, $columns);
                    foreach ($rows as $row) {
                        $changes = [];
                        foreach ($columns as $c) {
                            $old = $row->{$c};
                            if (! is_string($old)) {
                                continue;
                            }
                            $new = $expand
                                ? self::expand($old, $lookup)
                                : self::replaceKeys($old, $lookup[(int) ($row->workspace_id ?? 0)] ?? []);
                            if ($new !== $old) {
                                $changes[$c] = $new;
                                $counts[$table] += abs(count(self::tokens($new)) - count(self::tokens($old)));
                            }
                        }
                        if ($changes !== []) {
                            DB::table($table)->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }

        return $counts;
    }

    /** @param array<string, string> $idsByKey */
    private static function replaceKeys(string $text, array $idsByKey): string
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

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $m);

        return $m[0];
    }

    /**
     * Backfill up(): the keys mentioned in a chunk, resolved per workspace (one query per workspace in the chunk).
     *
     * @param  iterable<object>  $rows
     * @param  list<string>  $columns
     * @return array<int, array<string, string>> workspace id (0 = none) => key => ticket id
     */
    private static function idsByKeyPerWorkspace(iterable $rows, array $columns): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach ($columns as $c) {
                if (is_string($row->{$c})) {
                    $workspace = (int) ($row->workspace_id ?? 0);
                    $keys[$workspace] ??= [];
                    array_push($keys[$workspace], ...self::mentionedKeys($row->{$c}));
                }
            }
        }
        $out = [];
        foreach ($keys as $workspace => $list) {
            if ($list !== []) {
                $out[$workspace] = DB::table('tickets')->whereIn('key', array_unique($list))
                    ->when($workspace !== 0, fn ($q) => $q->where('workspace_id', $workspace))
                    ->pluck('id', 'key')->all();
            }
        }

        return $out;
    }

    /**
     * Backfill down(): tokens of a chunk resolve in any workspace (ids are global).
     *
     * @param  iterable<object>  $rows
     * @param  list<string>  $columns
     * @return array<string, array{key:string}>
     */
    private static function keysById(iterable $rows, array $columns): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($columns as $c) {
                array_push($ids, ...self::ids(is_string($row->{$c}) ? $row->{$c} : null));
            }
        }
        if ($ids === []) {
            return [];
        }

        return DB::table('tickets')->whereIn('id', array_unique($ids))->pluck('key', 'id')
            ->map(fn ($key) => ['key' => (string) $key])->all();
    }
}
