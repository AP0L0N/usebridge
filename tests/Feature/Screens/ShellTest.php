<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Shared shell props (TopBar): the account menu user, Polymarket only with the feature, Admin only for is_admin. */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    private User $tilen;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->tilen = app(CurrentWorkspace::class)->default()->owner; // backfilled owner, is_admin, polymarket on
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27');
    }

    #[Test]
    public function a_regular_user_gets_no_polymarket_and_no_admin_nav(): void
    {
        $this->actingAs($this->sam)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user', [
                'id' => $this->sam->id, 'name' => 'Sam Rivera', 'email' => 'sam@example.test', 'initials' => 'SR',
                'is_admin' => false, 'email_verified' => false, 'actor_id' => 'user:'.$this->sam->id,
            ])
            ->where('features.polymarket', false)
            ->where('features.admin', false));

        $this->get('/polymarket')->assertNotFound();
        $this->get('/admin/users')->assertNotFound();
    }

    #[Test]
    public function the_owner_gets_polymarket_and_admin(): void
    {
        $this->actingAs($this->tilen)->get('/usage')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_admin', true)
            ->where('auth.user.initials', \App\Http\Middleware\HandleInertiaRequests::initials($this->tilen->name))
            ->where('features.polymarket', true)
            ->where('features.admin', true));
    }

    #[Test]
    public function a_leftover_gate_flag_shows_the_landing_page_and_no_app(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true])->get('/')->assertOk()->assertViewIs('landing');
        $this->withSession(['bridge_gate_unlocked' => true])->get('/polymarket')->assertRedirect('/login');
        $this->withSession(['bridge_gate_unlocked' => true])->get('/admin/users')->assertNotFound();
    }

    #[Test]
    public function shared_props_never_carry_a_password_hash_or_token(): void
    {
        $this->sam->createToken('bot', ['boards:read']);
        $body = $this->actingAs($this->sam)->get('/')->getContent();

        $this->assertStringNotContainsString($this->sam->password, $body);
        $this->assertStringNotContainsString('remember_token', $body);
        $this->assertStringNotContainsString('brg_live_', $body);
    }

    #[Test]
    public function initials_for_the_avatar(): void
    {
        $this->assertSame('SR', \App\Http\Middleware\HandleInertiaRequests::initials('Sam Rivera'));
        $this->assertSame('T', \App\Http\Middleware\HandleInertiaRequests::initials(' Tilen '));
        $this->assertSame('ŽK', \App\Http\Middleware\HandleInertiaRequests::initials('žan kos lee'));
    }
}
