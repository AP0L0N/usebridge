<?php

use App\Models\Workspace;
use App\Support\BoardColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Additive (docs/MULTI-USER-PLAN.md §3, CoS decision 31 Oct): workspaces.settings (JSON, nullable) for per-workspace
 * values. Backfill: every workspace that exists now keeps the waiting_tilen column name it showed so far, stored as
 * settings.waiting_label: bridge.owner_waiting_label (BRIDGE_OWNER_WAITING_LABEL), else "Waiting on " + the first name
 * of the default workspace's owner. Workspaces created later have no setting and show BoardColumns' default. Only the
 * new column is written; no other table and no other column is touched (labels are derived in code, never stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'settings')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->json('settings')->nullable()->after('features');
            });
        }

        $label = $this->legacyLabel();
        if ($label === null) {
            return;
        }

        DB::table('workspaces')->orderBy('id')->get(['id', 'settings'])->each(function (object $w) use ($label) {
            $settings = json_decode((string) $w->settings, true) ?: [];
            if (isset($settings[Workspace::SETTING_WAITING_LABEL])) {
                return;
            }
            $settings[Workspace::SETTING_WAITING_LABEL] = $label;
            DB::table('workspaces')->where('id', $w->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'settings')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->dropColumn('settings');
            });
        }
    }

    private function legacyLabel(): ?string
    {
        $label = trim((string) config('bridge.owner_waiting_label'));
        if ($label === '') {
            $slug = (string) (config('bridge.default_workspace') ?: 'main');
            $name = DB::table('workspaces')->join('users', 'users.id', '=', 'workspaces.owner_user_id')
                ->where('workspaces.slug', $slug)->value('users.name');
            $first = Str::before(trim((string) $name), ' ');
            $label = $first === '' ? '' : 'Waiting on '.$first;
        }

        return $label === '' ? null : Str::limit($label, BoardColumns::LABEL_MAX, '');
    }
};
