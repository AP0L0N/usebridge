<?php

namespace App\Services\Tickets;

use App\Support\TicketRefs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Existing text → ticket reference tokens (`php artisan bridge:ticket-refs`, docs/TICKET-REFS.md). Run by hand after a
 * backup and a dry run, never by a migration (pushing to main deploys and migrates production).
 *
 * Every column of TicketRefs::MODELS (each model's TICKET_REF_FIELDS) is walked separately, chunked by primary key, with
 * raw queries: no model events, updated_at untouched. Mentions resolve to tickets of the row's own workspace (any
 * project). Idempotent: tokens are never re-tokenized, plain text is never re-expanded. A value whose tokenized form
 * would not fit its column (varchar length, or TEXT bytes) is left as it is and counted as skipped. Revert expands every
 * token to the ticket's current key (the stored key when the ticket is gone); a key is always shorter than its token,
 * so a revert always fits.
 */
final class TicketRefsBackfill
{
    public const CHUNK = 500;

    /**
     * The table.column list the command rewrites, with each column's length (null = TEXT).
     *
     * @return array<string, int|null> "table.column" => max characters
     */
    public static function columns(): array
    {
        $out = [];
        foreach (TicketRefs::MODELS as $table => $model) {
            foreach ($model::TICKET_REF_FIELDS as $column => $maxChars) {
                $out["$table.$column"] = $maxChars;
            }
        }

        return $out;
    }

    /**
     * @param  string|null  $onlyTable  one table of TicketRefs::MODELS, null = all
     * @return array<string, array{scanned:int, changed:int, refs:int, skipped:int}> per "table.column": rows read,
     *                                                                               rows changed (or that would change),
     *                                                                               references converted, values skipped
     *                                                                               because the result would not fit
     */
    public function run(bool $revert = false, bool $dryRun = false, ?string $onlyTable = null, int $chunk = self::CHUNK): array
    {
        if ($onlyTable !== null && ! array_key_exists($onlyTable, TicketRefs::MODELS)) {
            throw new InvalidArgumentException("Unknown table '$onlyTable'. One of: ".implode(', ', array_keys(TicketRefs::MODELS)));
        }
        $counts = [];
        foreach (self::columns() as $name => $maxChars) {
            [$table, $column] = explode('.', $name, 2);
            if ($onlyTable !== null && $table !== $onlyTable) {
                continue;
            }
            $counts[$name] = ['scanned' => 0, 'changed' => 0, 'refs' => 0, 'skipped' => 0];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $hasWorkspace = Schema::hasColumn($table, 'workspace_id');
            DB::table($table)
                ->where($column, 'like', $revert ? '%[[ticket:%' : '%-%')
                ->select(['id', $column, ...($hasWorkspace ? ['workspace_id'] : [])])
                ->chunkById($chunk, function (Collection $rows) use ($table, $column, $maxChars, $revert, $dryRun, &$counts, $name) {
                    $lookup = $revert ? $this->keysById($rows, $column) : $this->idsByKeyPerWorkspace($rows, $column);
                    foreach ($rows as $row) {
                        $old = $row->{$column};
                        if (! is_string($old)) {
                            continue;
                        }
                        $counts[$name]['scanned']++;
                        $new = $revert
                            ? (string) TicketRefs::expand($old, $lookup)
                            : TicketRefs::replaceKeys($old, $lookup[(int) ($row->workspace_id ?? 0)] ?? []);
                        if ($new === $old) {
                            continue;
                        }
                        if (! TicketRefs::fits($new, $maxChars)) {
                            $counts[$name]['skipped']++;

                            continue;
                        }
                        $counts[$name]['changed']++;
                        $counts[$name]['refs'] += abs(TicketRefs::tokenCount($new) - TicketRefs::tokenCount($old));
                        if (! $dryRun) {
                            DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        }
                    }
                });
        }

        return $counts;
    }

    /**
     * The keys mentioned in a chunk, resolved per workspace (one query per workspace in the chunk).
     *
     * @return array<int, array<string, string>> workspace id (0 = none) => key => ticket id
     */
    private function idsByKeyPerWorkspace(Collection $rows, string $column): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (is_string($row->{$column})) {
                $workspace = (int) ($row->workspace_id ?? 0);
                $keys[$workspace] ??= [];
                array_push($keys[$workspace], ...TicketRefs::mentionedKeys($row->{$column}));
            }
        }
        $out = [];
        foreach ($keys as $workspace => $list) {
            if ($list !== []) {
                $out[$workspace] = DB::table('tickets')->whereIn('key', array_values(array_unique($list)))
                    ->when($workspace !== 0, fn ($q) => $q->where('workspace_id', $workspace))
                    ->pluck('id', 'key')->all();
            }
        }

        return $out;
    }

    /**
     * Revert: the tokens of a chunk, resolved in any workspace (ticket ids are global).
     *
     * @return array<string, array{key:string}>
     */
    private function keysById(Collection $rows, string $column): array
    {
        $ids = [];
        foreach ($rows as $row) {
            array_push($ids, ...TicketRefs::ids(is_string($row->{$column}) ? $row->{$column} : null));
        }
        if ($ids === []) {
            return [];
        }

        return DB::table('tickets')->whereIn('id', array_values(array_unique($ids)))->pluck('key', 'id')
            ->map(fn ($key) => ['key' => (string) $key])->all();
    }
}
