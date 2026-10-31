<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** 2026_10_31_130000_multi_user_auth: additive columns, existing users verified, reversible (outside a transaction). */
class MultiUserAuthMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const USER_COLUMNS = [
        'failed_logins', 'locked_until', 'last_login_at', 'disabled_at', 'pending_email', 'pending_email_at', 'timezone',
        'delete_requested_at', 'deleted_at',
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_31_130000_multi_user_auth.php');
    }

    #[Test]
    public function existing_users_are_marked_verified_and_down_up_round_trips(): void
    {
        $this->migration()->down();
        foreach (self::USER_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), $column);
        }
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'token_suffix'));
        $this->assertSame(['polymarket' => true], json_decode(DB::table('workspaces')->where('slug', 'tilen')->value('features'), true));

        $verifiedAt = '2026-01-01 00:00:00';
        DB::table('users')->insert([
            ['name' => 'Captain', 'email' => 'captain@lantis.internal', 'password' => Hash::make('x'), 'email_verified_at' => null],
            ['name' => 'Old', 'email' => 'old@lantis.internal', 'password' => Hash::make('x'), 'email_verified_at' => $verifiedAt],
        ]);

        $this->migration()->up();
        $this->migration()->up(); // idempotent

        foreach (self::USER_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'token_suffix'));
        $this->assertSame(0, DB::table('users')->whereNull('email_verified_at')->count());
        $this->assertSame($verifiedAt, DB::table('users')->where('email', 'old@lantis.internal')->value('email_verified_at'));
        $captain = User::query()->where('email', 'captain@lantis.internal')->firstOrFail();
        $this->assertTrue($captain->hasVerifiedEmail());
        $this->assertSame([0, 'Europe/Ljubljana', null], [$captain->failed_logins, $captain->timezone, $captain->disabled_at]);
        $this->assertSame(['polymarket' => true, 'usage_upload' => true], json_decode(DB::table('workspaces')->where('slug', 'tilen')->value('features'), true));
        $this->assertSame(3, DB::table('users')->count(), 'nothing deleted');
    }
}
