<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user backend auth (docs/MULTI-USER-PLAN.md §1 "To add", §2, §4). Additive, every column nullable/defaulted:
 *
 * - users: failed_logins, locked_until (lockout), last_login_at, disabled_at (admin), pending_email(_at) (email change),
 *   timezone (display only), delete_requested_at + deleted_at (SoftDeletes, 7-day grace).
 * - personal_access_tokens.token_suffix: last 4 characters of a new token's plaintext (not secret) for the masked list.
 * - Existing users are marked verified (email_verified_at = now where it is null): they predate sign-up (Tilen as the
 *   backfilled owner, Captain's API user and the per-agent token users) and must keep creating tokens / using MCP.
 *   Only rows present when this runs are touched; users signing up later verify by mail.
 * - Tilen's workspace (bridge.default_workspace) gets the `usage_upload` feature: read & write tokens include
 *   usage:write only there (§4).
 *
 * down() drops the added columns and removes `usage_upload` again. email_verified_at values set here are kept (a
 * verified mark is harmless and cannot be told apart from a real one afterwards).
 */
return new class extends Migration
{
    private const USER_COLUMNS = [
        'failed_logins', 'locked_until', 'last_login_at', 'disabled_at', 'pending_email', 'pending_email_at', 'timezone',
        'delete_requested_at', 'deleted_at',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'failed_logins')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('failed_logins')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->string('pending_email')->nullable();
                $table->timestamp('pending_email_at')->nullable();
                $table->string('timezone', 64)->default('Europe/Ljubljana');
                $table->timestamp('delete_requested_at')->nullable()->index();
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'token_suffix')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->char('token_suffix', 4)->nullable();
            });
        }

        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => Carbon::now()]);

        $this->setUsageFeature(true);
    }

    public function down(): void
    {
        $this->setUsageFeature(false);

        if (Schema::hasColumn('personal_access_tokens', 'token_suffix')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('token_suffix');
            });
        }

        if (Schema::hasColumn('users', 'failed_logins')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['delete_requested_at']);
            });
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(self::USER_COLUMNS);
            });
        }
    }

    private function setUsageFeature(bool $on): void
    {
        $workspace = DB::table('workspaces')->where('slug', (string) (config('bridge.default_workspace') ?? 'tilen'))->first();
        if ($workspace === null) {
            return;
        }
        $features = json_decode((string) $workspace->features, true) ?: [];
        if ($on) {
            $features['usage_upload'] = true;
        } else {
            unset($features['usage_upload']);
        }
        DB::table('workspaces')->where('id', $workspace->id)->update(['features' => json_encode($features)]);
    }
};
