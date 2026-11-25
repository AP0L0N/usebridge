<?php

namespace App\Support;

use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;

/**
 * The fixed Kanban column set (SPEC §1). Ticket.status is one of these keys; the order is the board order.
 * Agents read it from GET /api/board-columns, so labels are never hard-coded on their side. Labels are never stored:
 * LABELS, then a project's own column_labels (which win).
 *
 * WAITING_TILEN ("Waiting on <owner>") is no longer a column (migration 2026_11_25_100100 moved its tickets out; "input
 * needed from the owner" is now the waiting-on-input flag on a ticket in any column). The key only survives in older
 * ticket_events, so label() still names it: the workspace's own name (workspaces.settings.waiting_label), else
 * "Waiting on <owner's first name>" / "Waiting on you".
 */
final class BoardColumns
{
    public const BACKLOG = 'backlog';

    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const REVIEW = 'review';

    public const DONE = 'done';

    public const LABELS = [
        self::BACKLOG => 'Backlog',
        self::TODO => 'To do',
        self::IN_PROGRESS => 'In progress',
        self::REVIEW => 'Review / QA',
        self::DONE => 'Done',
    ];

    /** The removed waiting column: history only (see the class comment). */
    public const WAITING_TILEN = 'waiting_tilen';

    public const WAITING_TILEN_LABEL = 'Waiting on you';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** The label of a column key; the removed waiting_tilen key keeps its name for history lines. */
    public static function label(string $key, ?Workspace $workspace = null): string
    {
        if ($key === self::WAITING_TILEN) {
            $workspace ??= app(CurrentWorkspace::class)->get();

            return $workspace === null ? self::WAITING_TILEN_LABEL : $workspace->waitingColumnLabel();
        }

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
    public static function all(?array $labels = null, ?Workspace $workspace = null): array
    {
        return array_map(fn (string $k) => ['key' => $k, 'label' => self::labelFor($k, $labels, $workspace), 'order' => self::order($k)], self::keys());
    }

    /** @param  array<string, string>|null  $labels */
    public static function labelFor(string $key, ?array $labels, ?Workspace $workspace = null): string
    {
        return self::pick($labels[$key] ?? null, self::label($key, $workspace));
    }

    private static function pick(mixed $own, string $default): string
    {
        return is_string($own) && trim($own) !== '' ? $own : $default;
    }

    /** SQL CASE for ordering rows by column order (SQLite + MySQL). */
    public static function orderSql(string $column = 'status'): string
    {
        $cases = collect(self::keys())->map(fn (string $k, int $i) => "WHEN '$k' THEN $i")->implode(' ');

        return "CASE $column $cases ELSE 99 END";
    }
}
