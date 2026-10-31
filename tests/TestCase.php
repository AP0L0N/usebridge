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

    /** An API user of Tilen's workspace (like Captain's API user on live): a member, so its tokens act there. */
    protected function workspaceUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(CurrentWorkspace::class)->default()->users()->attach($user->id, ['role' => 'member']);

        return $user;
    }
}
