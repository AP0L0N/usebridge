<?php

namespace App\Support;

/**
 * The fixed Kanban column set (SPEC §1). Ticket.status is one of these keys; the order is the board order.
 * Agents read it from GET /api/board-columns, so labels are never hard-coded on their side.
 */
final class BoardColumns
{
    public const BACKLOG = 'backlog';

    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_TILEN = 'waiting_tilen';

    public const REVIEW = 'review';

    public const DONE = 'done';

    public const LABELS = [
        self::BACKLOG => 'Backlog',
        self::TODO => 'To do',
        self::IN_PROGRESS => 'In progress',
        self::WAITING_TILEN => 'Waiting on Tilen',
        self::REVIEW => 'Review / QA',
        self::DONE => 'Done',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public static function order(string $key): int
    {
        return (int) array_search($key, self::keys(), true);
    }

    /** Longest per-project column label (PUT /projects/{slug}/column-labels). */
    public const LABEL_MAX = 40;

    /**
     * Columns in board order. $labels = a project's own names (projects.column_labels, {key: label}); keys it does not
     * name, or unknown keys, fall back to / are ignored in favour of the defaults.
     *
     * @param  array<string, string>|null  $labels
     * @return list<array{key:string,label:string,order:int}>
     */
    public static function all(?array $labels = null): array
    {
        return array_map(fn (string $k) => ['key' => $k, 'label' => self::labelFor($k, $labels), 'order' => self::order($k)], self::keys());
    }

    /** @param  array<string, string>|null  $labels */
    public static function labelFor(string $key, ?array $labels): string
    {
        $own = $labels[$key] ?? null;

        return is_string($own) && trim($own) !== '' ? $own : self::label($key);
    }

    /** SQL CASE for ordering rows by column order (SQLite + MySQL). */
    public static function orderSql(string $column = 'status'): string
    {
        $cases = collect(self::keys())->map(fn (string $k, int $i) => "WHEN '$k' THEN $i")->implode(' ');

        return "CASE $column $cases ELSE 99 END";
    }
}
