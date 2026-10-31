<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\AdminUsers;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Admin · Users screen: owner (is_admin) only, 404 for everyone else; each row has exactly AdminUsers::KEYS. */
class AdminScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $tilen;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->tilen = app(CurrentWorkspace::class)->default()->owner;
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27')->fresh();
    }

    #[Test]
    public function the_owner_gets_the_users_page_with_only_the_allowed_keys(): void
    {
        $this->sam->createToken('bot', ['boards:read']);

        $res = $this->actingAs($this->tilen)->get('/admin/users')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Users')
            ->where('meId', $this->tilen->id)
            ->has('users', 2));

        $this->assertSame(['users', 'meId'], array_values(array_diff(array_keys($res->inertiaProps()), ['errors', 'auth', 'features', 'footer', 'flash'])));
        foreach ($res->inertiaProps('users') as $row) {
            $this->assertSame(AdminUsers::KEYS, array_keys($row));
        }
        $sam = collect($res->inertiaProps('users'))->firstWhere('id', $this->sam->id);
        $this->assertSame(['name' => 'Sam Rivera', 'email' => 'sam@example.test', 'status' => 'active'], array_intersect_key($sam, array_flip(['name', 'email', 'status'])));
        // nothing about another workspace: no counts, no tokens, no projects
        $this->assertStringNotContainsString('projects', strtolower(json_encode($res->inertiaProps('users'))));
        $this->assertStringNotContainsString('token', strtolower(json_encode($res->inertiaProps('users'))));

        // JSON clients keep the JSON list
        $this->getJson('/admin/users')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function everyone_else_gets_404(): void
    {
        $this->get('/admin/users')->assertNotFound();
        $this->withSession(['bridge_gate_unlocked' => true])->get('/admin/users')->assertNotFound();
        $this->actingAs($this->sam)->get('/admin/users')->assertNotFound();
        $this->actingAs($this->sam)->getJson('/admin/users')->assertNotFound();
    }

    #[Test]
    public function disable_and_enable_from_the_screen_update_the_row_status(): void
    {
        $this->actingAs($this->tilen)->postJson("/admin/users/{$this->sam->id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->get('/admin/users')->assertInertia(fn (Assert $p) => $p->where('users.1.status', 'disabled'));
        $this->postJson("/admin/users/{$this->sam->id}/enable")->assertOk()->assertJsonPath('data.status', 'active');
    }
}
