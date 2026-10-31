<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Multi-user backfill (docs/MULTI-USER-PLAN.md §3): Tilen's workspace owns everything that exists.
 *
 * - Owner user: BRIDGE_OWNER_EMAIL (config bridge.owner_email) only, created with an unusable random password when
 *   missing; is_admin = true. Never picked automatically: while the workspace does not exist yet, an unset or invalid
 *   owner email throws and `migrate` stops before changing anything (a token owner or the first user, e.g. Captain's
 *   API user, must not become owner + admin). A database migrated earlier is fixed with `bridge:set-owner`.
 * - Workspace slug bridge.default_workspace ('tilen'), name 'LANTIS', features {"polymarket": true}; memberships:
 *   owner -> owner, every other token-owning user -> member.
 * - workspace_id := that workspace on every row of every owned table and every token where it is still null. Tokens
 *   keep their user, hash, name and abilities, so BRIDGE_API_TOKEN and per-agent tokens keep working.
 * Idempotent (re-running only fills what is still null). Runs on an empty database too (fresh install; tests set
 * BRIDGE_OWNER_EMAIL in phpunit.xml).
 * down(): nulls the workspace_id values pointing at this workspace, removes its memberships and the workspace, and
 * clears is_admin of its owner. Users (including one created here) are kept.
 */
return new class extends Migration
{
    private const OWNED = [
        'projects', 'agents', 'labels', 'usage_days', 'cli_usage_days', 'polymarket_snapshots', 'polymarket_series_points',
        'tickets', 'ticket_comments', 'ticket_events', 'ticket_read_states', 'project_read_states', 'project_status_events',
        'project_pm_events', 'status_updates', 'required_inputs', 'next_steps', 'questions',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $now = Carbon::now();
            $slug = $this->slug();
            $workspace = DB::table('workspaces')->where('slug', $slug)->first();
            $ownerId = $workspace->owner_user_id ?? $this->ownerId($now);

            DB::table('users')->where('id', $ownerId)->update(['is_admin' => true]);

            if ($workspace === null) {
                $workspaceId = DB::table('workspaces')->insertGetId([
                    'slug' => $slug,
                    'name' => (string) (config('bridge.default_workspace_name') ?? 'LANTIS'),
                    'owner_user_id' => $ownerId,
                    'features' => json_encode(['polymarket' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $workspaceId = $workspace->id;
            }

            $members = [$ownerId => 'owner'];
            foreach ($this->tokenUserIds() as $userId) {
                $members[$userId] ??= 'member';
            }
            foreach ($members as $userId => $role) {
                if (! DB::table('workspace_user')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])->exists()) {
                    DB::table('workspace_user')->insert([
                        'workspace_id' => $workspaceId, 'user_id' => $userId, 'role' => $role, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            foreach ([...self::OWNED, 'personal_access_tokens'] as $table) {
                DB::table($table)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
            }
        });
    }

    public function down(): void
    {
        $workspace = DB::table('workspaces')->where('slug', $this->slug())->first();
        if ($workspace === null) {
            return;
        }
        DB::transaction(function () use ($workspace) {
            foreach ([...self::OWNED, 'personal_access_tokens'] as $table) {
                DB::table($table)->where('workspace_id', $workspace->id)->update(['workspace_id' => null]);
            }
            DB::table('workspace_user')->where('workspace_id', $workspace->id)->delete();
            DB::table('workspaces')->where('id', $workspace->id)->delete();
            DB::table('users')->where('id', $workspace->owner_user_id)->update(['is_admin' => false]);
        });
    }

    private function slug(): string
    {
        return (string) (config('bridge.default_workspace') ?? 'tilen');
    }

    private function ownerId(Carbon $now): int
    {
        $email = config('bridge.owner_email');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Set BRIDGE_OWNER_EMAIL (Tilen\'s login email) and run `php artisan config:clear` before '
                .'migrating: the multi-user backfill never picks an owner by itself. See docs/MULTI-USER-PLAN.md §11.');
        }

        return (int) (DB::table('users')->where('email', $email)->value('id') ?? $this->createUser($email, $now));
    }

    /** @return list<int> users owning tokens (they become members), by id */
    private function tokenUserIds(): array
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->distinct()->orderBy('tokenable_id')
            ->pluck('tokenable_id')
            ->filter(fn ($id) => DB::table('users')->where('id', $id)->exists())
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    private function createUser(string $email, Carbon $now): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Tilen',
            'email' => $email,
            // Unusable: nobody knows it; Tilen sets his own via password reset.
            'password' => Hash::make(Str::random(64)),
            'is_admin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
