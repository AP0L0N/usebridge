<?php

namespace Tests;

use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * Logged in (web) as the owner of the default workspace (BRIDGE_OWNER_EMAIL in phpunit.xml, created by the backfill
     * migration): the normal way the owner reaches his board, usage and Polymarket.
     */
    protected function asOwner(): static
    {
        return $this->actingAs($this->workspaceOwner(), 'web');
    }

    protected function workspaceOwner(): User
    {
        return app(CurrentWorkspace::class)->default()->owner;
    }

    /** An API user of Tilen's workspace (like Captain's API user on live): a member, so its tokens act there. */
    protected function workspaceUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(CurrentWorkspace::class)->default()->users()->attach($user->id, ['role' => 'member']);

        return $user;
    }
}
