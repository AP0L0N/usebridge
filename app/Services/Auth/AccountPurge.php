<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hard purge of deleted accounts (docs/MULTI-USER-PLAN.md §2; MUST-BUILD A3). Behind bridge.account_purge_enabled
 * (default off: conflicts with the standing "nothing hard-deleted" rule until Tilen decides). A user is due when they
 * are soft-deleted and their delete request is at least GRACE_DAYS + bridge.account_purge_after_days (7 + 30) days old.
 *
 * Tables are found from the schema, not a list, so new tables are not forgotten: starting from the user row and the
 * workspaces they solely own, every row that references a purged row is purged too — through foreign keys, `<name>_id`
 * columns of an existing `<names>` table (e.g. legacy_r0_project_fields.project_id), `workspace_id` / `user_id`,
 * morph pairs (`tokenable_type` + `tokenable_id`) and `email` (password resets). Rows are deleted children first, in one
 * transaction per user; the user row is force-deleted (or anonymised if a constraint still holds it).
 *
 * Never purged (the user is skipped and reported): an is_admin user, the configured default workspace, a workspace with
 * other members. Output and log: ids and row counts only, never an email or a name.
 */
final class AccountPurge
{
    /** Never scanned: framework bookkeeping without user data references. */
    private const SKIP_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /** @var array<string, array{columns: list<string>, pk: ?string, refs: list<array{column: string, table: string, key: string}>, fks: list<string>}>|null */
    private ?array $schema = null;

    public static function enabled(): bool
    {
        return (bool) config('bridge.account_purge_enabled', false);
    }

    public static function dueBefore(): Carbon
    {
        return Carbon::now()->subDays(AccountDeletion::GRACE_DAYS + (int) config('bridge.account_purge_after_days', 30));
    }

    /**
     * Purges every due user (or only plans it when $dryRun). Returns, per user id, either
     * ['status' => 'purged'|'would purge'|'anonymised', 'counts' => [table => rows]] or ['status' => 'skipped', 'reason' => ...].
     *
     * @return array<int, array{status: string, counts?: array<string, int>, reason?: string}>
     */
    public function run(bool $dryRun = false): array
    {
        $due = User::onlyTrashed()->whereNotNull('delete_requested_at')
            ->where('delete_requested_at', '<=', self::dueBefore())->orderBy('id')->get();
        $out = [];
        foreach ($due as $user) {
            $reason = $this->refusal($user);
            if ($reason !== null) {
                $out[$user->id] = ['status' => 'skipped', 'reason' => $reason];

                continue;
            }
            $out[$user->id] = $dryRun ? ['status' => 'would purge', 'counts' => $this->counts($this->plan($user))] : $this->purge($user);
        }

        return $out;
    }

    /** Why this user must not be purged, or null. */
    public function refusal(User $user): ?string
    {
        if ($user->is_admin) {
            return 'admin';
        }
        $default = (string) config('bridge.default_workspace');
        foreach ($this->ownedWorkspaceIds($user) as $id) {
            if ((string) DB::table('workspaces')->where('id', $id)->value('slug') === $default) {
                return "default workspace #$id";
            }
            if (DB::table('workspace_user')->where('workspace_id', $id)->where('user_id', '!=', $user->id)->exists()) {
                return "workspace #$id has other members";
            }
            if (DB::table('workspaces')->where('id', $id)->whereNotNull('owner_user_id')->where('owner_user_id', '!=', $user->id)->exists()) {
                return "workspace #$id has another owner";
            }
        }

        return null;
    }

    /** Workspaces the user owns (owner_user_id or an owner membership). */
    public function ownedWorkspaceIds(User $user): array
    {
        return DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')
            ->merge(DB::table('workspace_user')->where('user_id', $user->id)->where('role', 'owner')->pluck('workspace_id'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return array{counts: array<string, int>, status: string} */
    private function purge(User $user): array
    {
        $result = DB::transaction(function () use ($user) {
            $plan = $this->plan($user);
            $counts = [];
            $status = 'purged';
            foreach ($this->deleteOrder(array_keys($plan)) as $table) {
                if ($table === 'users') {
                    try {
                        // savepoint: a failing delete must not abort the outer transaction
                        $counts['users'] = DB::transaction(fn () => $this->query('users', $plan['users'])->delete());
                    } catch (QueryException) {
                        DB::table('users')->where('id', $user->id)->update([
                            'email' => hash('sha256', Str::lower($user->email)).'@purged.invalid',
                            'name' => 'Deleted user',
                            'password' => '',
                            'remember_token' => null,
                        ]);
                        $status = 'anonymised';
                    }

                    continue;
                }
                $counts[$table] = $this->query($table, $plan[$table])->delete();
            }

            return ['status' => $status, 'counts' => array_filter($counts)];
        });
        ksort($result['counts']);
        Log::info(sprintf('bridge:accounts-purge user #%d %s: %s', $user->id, $result['status'],
            implode(', ', array_map(fn ($t, $n) => "$t=$n", array_keys($result['counts']), $result['counts'])) ?: 'no rows'));

        return $result;
    }

    /**
     * Which rows go: table => list of conditions [column => values] (OR-ed), found by walking references from the user
     * and their owned workspaces until nothing new turns up.
     *
     * @return array<string, list<array{0: string, 1: list<int|string>}|array{0: string, 1: string, 2: list<int|string>}>>
     */
    public function plan(User $user): array
    {
        $schema = $this->schema();
        $morphTypes = array_values(array_unique([User::class, $user->getMorphClass()]));
        /** @var array<string, array<string, int|string>> $purged table => ids already known to go (keyed by string) */
        $purged = ['users' => [(string) $user->id => $user->id], 'workspaces' => []];
        foreach ($this->ownedWorkspaceIds($user) as $id) {
            $purged['workspaces'][(string) $id] = $id;
        }

        $conditions = [];
        do {
            $grew = false;
            foreach ($schema as $table => $info) {
                $conds = [];
                foreach ($info['refs'] as $ref) {
                    $ids = array_values($purged[$ref['table']] ?? []);
                    if ($ids !== [] && $ref['table'] !== $table && $ref['key'] === $schema[$ref['table']]['pk']) {
                        $conds[] = [$ref['column'], $ids];
                    }
                }
                foreach ($this->morphColumns($info['columns']) as $name) {
                    $conds[] = [$name.'_id', [$user->id], [$name.'_type', $morphTypes]];
                }
                if ($table !== 'users' && in_array('email', $info['columns'], true)) {
                    $conds[] = ['email', [$user->email]];
                }
                if ($table === 'users') {
                    $conds = [['id', [$user->id]]];
                }
                if ($table === 'workspaces' && $purged['workspaces'] !== []) {
                    $conds = [['id', array_values($purged['workspaces'])]];
                }
                if ($conds === []) {
                    continue;
                }
                $conditions[$table] = $conds;
                if ($info['pk'] !== null && $table !== 'users' && $table !== 'workspaces') {
                    foreach ($this->query($table, $conds)->pluck($info['pk']) as $id) {
                        if (! isset($purged[$table][(string) $id])) {
                            $purged[$table][(string) $id] = $id;
                            $grew = true;
                        }
                    }
                }
            }
        } while ($grew);

        return $conditions;
    }

    /** @param  array<string, list<array>>  $plan  @return array<string, int> */
    private function counts(array $plan): array
    {
        $counts = [];
        foreach ($plan as $table => $conds) {
            $counts[$table] = $this->query($table, $conds)->count();
        }
        ksort($counts);

        return array_filter($counts);
    }

    private function query(string $table, array $conds)
    {
        return DB::table($table)->where(function ($q) use ($conds) {
            foreach ($conds as $c) {
                $q->orWhere(function ($w) use ($c) {
                    $w->whereIn($c[0], $c[1]);
                    if (isset($c[2])) {
                        $w->whereIn($c[2][0], $c[2][1]);
                    }
                });
            }
        });
    }

    /** Children before parents (foreign keys), so nothing is left pointing at a deleted row. */
    private function deleteOrder(array $tables): array
    {
        $schema = $this->schema();
        $pending = array_fill_keys($tables, true);
        $order = [];
        while ($pending !== []) {
            $progress = false;
            foreach (array_keys($pending) as $table) {
                // ready when no other pending table still references it
                $blocked = false;
                foreach (array_keys($pending) as $other) {
                    if ($other !== $table && in_array($table, $schema[$other]['fks'], true)) {
                        $blocked = true;
                        break;
                    }
                }
                if (! $blocked) {
                    $order[] = $table;
                    unset($pending[$table]);
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new RuntimeException('Foreign key cycle between: '.implode(', ', array_keys($pending)));
            }
        }

        return $order;
    }

    /** `<name>_type` + `<name>_id` pairs (e.g. tokenable). */
    private function morphColumns(array $columns): array
    {
        $names = [];
        foreach ($columns as $c) {
            if (str_ends_with($c, '_type') && in_array(substr($c, 0, -5).'_id', $columns, true)) {
                $names[] = substr($c, 0, -5);
            }
        }

        return $names;
    }

    /** Tables, their primary key and their references (foreign keys + `<name>_id` naming convention). */
    private function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $tables = array_values(array_diff(Schema::getTableListing(schemaQualified: false), self::SKIP_TABLES));
        $schema = [];
        foreach ($tables as $table) {
            $columns = array_column(Schema::getColumns($table), 'name');
            $refs = [];
            $fks = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) === 1 && in_array($fk['foreign_table'], $tables, true)) {
                    $refs[$fk['columns'][0]] = ['column' => $fk['columns'][0], 'table' => $fk['foreign_table'], 'key' => $fk['foreign_columns'][0]];
                }
                $fks[] = $fk['foreign_table'];
            }
            foreach ($columns as $column) {
                if (isset($refs[$column]) || ! str_ends_with($column, '_id')) {
                    continue;
                }
                $target = Str::plural(substr($column, 0, -3));
                if (in_array($target, $tables, true)) {
                    $refs[$column] = ['column' => $column, 'table' => $target, 'key' => 'id'];
                }
            }
            $pk = null;
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['primary'] && count($index['columns']) === 1) {
                    $pk = $index['columns'][0];
                }
            }
            if ($pk === null && in_array('id', $columns, true)) {
                $pk = 'id';
            }
            $schema[$table] = ['columns' => $columns, 'pk' => $pk, 'refs' => array_values($refs), 'fks' => array_values(array_unique($fks))];
        }

        return $this->schema = $schema;
    }
}
