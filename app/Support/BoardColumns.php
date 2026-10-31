<?php

namespace App\Support;

use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;

/**
 * The fixed Kanban column set (SPEC §1). Ticket.status is one of these keys; the order is the board order.
 * Agents read it from GET /api/board-columns, so labels are never hard-coded on their side. Labels are never stored:
 * LABELS, then the workspace's own waiting_tilen name (workspaces.settings.waiting_label), then a project's own
 * column_labels (which win).
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
        self::WAITING_TILEN => 'Waiting on you',
        self::REVIEW => 'Review / QA',
        self::DONE => 'Done',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Default labels of a workspace ($workspace, else the current one): LABELS with its own waiting_tilen name, if set.
     *
     * @return array<string, string>
     */
    public static function defaults(?Workspace $workspace = null): array
    {
        $waiting = ($workspace ?? app(CurrentWorkspace::class)->get())?->waitingLabel();

        return $waiting === null ? self::LABELS : [...self::LABELS, self::WAITING_TILEN => $waiting];
    }

    public static function label(string $key, ?Workspace $workspace = null): string
    {
        return self::defaults($workspace)[$key] ?? $key;
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
        $defaults = self::defaults($workspace);

        return array_map(fn (string $k) => ['key' => $k, 'label' => self::pick($labels[$k] ?? null, $defaults[$k]), 'order' => self::order($k)], self::keys());
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
