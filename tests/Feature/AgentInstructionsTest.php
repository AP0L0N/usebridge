<?php

namespace Tests\Feature;

use App\Models\AgentInstructionEdit;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agents\AgentInstructions;
use App\Services\Usage\UsageDayImporter;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agents list, the Instructions section of /usage/agents/{id} and the owner-only sync API
 * (docs/agent-instructions-sync.md). Saving in Bridge never changes the profile; only the ingest and "applied" do.
 */
class AgentInstructionsTest extends TestCase
{
    use RefreshDatabase;

    /** In the usage fixtures. */
    private const JUNIPER = '0445b205-093c-4120-85c8-c87c882fa3d4';

    /** Profile only, never in a usage file. */
    private const NOVA = 'df74ed60-9505-4b2b-80c5-4c3dc2b2f58b';

    private const DESCRIPTION = "You are Nova, the support lead.\n\n- Answer within a day\n- Escalate billing  ";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2037-10-19 16:00:00', 'Europe/Ljubljana'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loadUsage(): void
    {
        $importer = app(UsageDayImporter::class);
        foreach (UsageImportTest::fixturePaths() as $path) {
            $importer->import((string) file_get_contents($path));
        }
    }

    private function ownerToken(array $abilities = ['boards:read', 'agents:write']): string
    {
        return $this->workspaceOwner()->createToken('captain', $abilities)->plainTextToken;
    }

    /** Bearer request as this token: Sanctum caches the last token user between requests of one test. */
    private function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function ingest(string $description, string $agentId = self::NOVA, ?string $token = null): TestResponse
    {
        return $this->api($token ?? $this->ownerToken())->postJson('/api/agent-profiles', ['profiles' => [
            ['agent_id' => $agentId, 'name' => 'Nova', 'title' => 'Support lead', 'description' => $description],
        ]]);
    }

    private function save(string $description, string $agentId = self::NOVA): TestResponse
    {
        return $this->postJson("/usage/agents/$agentId/instructions", ['description' => $description]);
    }

    /** A row another request wrote meanwhile, straight into the table (no events, no workspace scope). */
    private function insertConcurrently(string $table, array $row): void
    {
        $now = now();
        DB::table($table)->insert([...$row, 'workspace_id' => app(CurrentWorkspace::class)->default()->id, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function concurrentProfile(string $agentId, string $description): array
    {
        return ['agent_id' => $agentId, 'name' => 'Synced meanwhile', 'description' => $description,
            'description_hash' => hash('sha256', $description), 'synced_at' => now()];
    }

    /** Run $fn once, right after the first executed query whose SQL contains $needle. */
    private function afterFirstQuery(string $needle, callable $fn): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($needle, $fn, &$done): void {
            if (! $done && str_contains($query->sql, $needle)) {
                $done = true;
                $fn();
            }
        });
    }

    #[Test]
    public function the_agents_page_lists_profiles_and_usage_agents_with_links_and_the_nav_has_agents(): void
    {
        $this->loadUsage();
        $this->ingest(str_repeat('Long instructions. ', 20))->assertOk();
        $this->asOwner()->save('Proposed text')->assertCreated();

        $response = $this->get('/agents')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Agents/Index')
            ->where('agents', function ($agents) {
                $rows = collect($agents)->keyBy('id');
                $nova = $rows[self::NOVA];
                $this->assertSame('Support lead', $nova['title']);
                $this->assertSame(160, mb_strlen($nova['excerpt']));
                $this->assertStringEndsWith('…', $nova['excerpt']);
                $this->assertNotNull($nova['syncedAt']);
                $this->assertSame('pending', $nova['editStatus']);
                $this->assertFalse($nova['inUsage']);
                $this->assertNull($rows[self::JUNIPER]['syncedAt']);
                $this->assertTrue($rows[self::JUNIPER]['inUsage']);

                return true;
            }));
        $this->get('/usage/agents/'.self::NOVA)->assertOk();
        $this->get('/usage/agents/'.self::JUNIPER)->assertOk();

        $nav = (string) file_get_contents(resource_path('js/Components/Shell/AppTopBar.tsx'));
        $this->assertStringContainsString("{ href: '/agents', label: 'nav.agents' }", $nav);
        $page = (string) file_get_contents(resource_path('js/Pages/Agents/Index.tsx'));
        $this->assertStringContainsString('/usage/agents/${encodeURIComponent(a.id)}', $page);
    }

    #[Test]
    public function the_agents_page_needs_a_login(): void
    {
        $this->get('/agents')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_detail_page_shows_the_ingested_description_and_404s_for_unknown_agents(): void
    {
        $this->ingest(self::DESCRIPTION)->assertOk();

        $this->asOwner()->get('/usage/agents/'.self::NOVA)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Agent')
            ->where('agent.inUsage', false)
            ->where('zero', true)
            ->where('instructions.canEdit', true)
            ->where('instructions.profile.title', 'Support lead')
            ->where('instructions.profile.description', self::DESCRIPTION)
            ->where('instructions.profile.hash', hash('sha256', self::DESCRIPTION))
            ->where('instructions.latest', null));

        $this->get('/usage/agents/no-such-agent')->assertNotFound();
        $this->save('x', 'no-such-agent')->assertNotFound();
    }

    #[Test]
    public function owner_saves_revision_one_then_revision_two_supersedes_it_and_the_profile_is_untouched(): void
    {
        $this->ingest(self::DESCRIPTION);
        $base = hash('sha256', self::DESCRIPTION);

        $this->asOwner()->save("First edit\n")->assertCreated()
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.status', 'pending');
        $this->save("Second edit\n")->assertCreated()->assertJsonPath('data.revision', 2);

        $edits = AgentInstructionEdit::query()->orderBy('revision')->get();
        $this->assertSame(['superseded', 'pending'], $edits->pluck('status')->all());
        $this->assertSame([$base, $base], $edits->pluck('base_hash')->all());
        $this->assertSame("Second edit\n", $edits[1]->proposed_description);
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->value('description'));

        $this->save(self::DESCRIPTION)->assertUnprocessable();
        $this->get('/usage/agents/'.self::NOVA)->assertInertia(fn (Assert $page) => $page
            ->where('instructions.latest.revision', 2)
            ->where('instructions.latest.status', 'pending')
            ->has('instructions.history', 2));
    }

    #[Test]
    public function only_the_workspace_owner_can_save(): void
    {
        $this->ingest(self::DESCRIPTION);

        $this->flushHeaders()->save('Guest edit')->assertRedirect(route('login'));

        $member = $this->workspaceUser();
        $this->actingAs($member, 'web')->get('/usage/agents/'.self::NOVA)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('instructions.canEdit', false));
        $this->save('Member edit')->assertForbidden();

        $stranger = User::factory()->create();
        Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => $stranger->id]);
        $this->actingAs($stranger, 'web')->get('/usage/agents/'.self::NOVA)->assertNotFound();
        $this->save('Stranger edit')->assertNotFound();

        $this->assertSame(0, AgentInstructionEdit::query()->count());
    }

    #[Test]
    public function the_api_needs_an_owner_token_of_this_workspace(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save('Proposed');
        $id = AgentInstructionEdit::query()->value('id');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson('/api/agent-instruction-edits')->assertUnauthorized();
        $this->postJson('/api/agent-profiles', ['profiles' => []])->assertUnauthorized();

        $member = $this->workspaceUser()->createToken('m', ['boards:read', 'agents:write'])->plainTextToken;
        $this->api($member)->getJson('/api/agent-instruction-edits')->assertForbidden();
        $this->ingest('Hijack', self::NOVA, $member)->assertForbidden();
        $this->api($member)->postJson("/api/agent-instruction-edits/$id/rejected")->assertForbidden();

        $reader = $this->ownerToken(['boards:read']);
        $this->api($reader)->getJson('/api/agent-instruction-edits')->assertOk();
        $this->ingest('Read only', self::NOVA, $reader)->assertForbidden();

        $stranger = User::factory()->create();
        Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => $stranger->id]);
        $other = $stranger->createToken('other', ['*'])->plainTextToken;
        $this->api($other)->getJson('/api/agent-instruction-edits')->assertOk()->assertJsonCount(0, 'data');
        $this->api($other)->postJson("/api/agent-instruction-edits/$id/rejected")->assertNotFound();

        $this->assertSame('pending', AgentInstructionEdit::query()->value('status'));
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->where('agent_id', self::NOVA)->value('description'));
    }

    #[Test]
    public function ingest_upserts_hashes_the_exact_text_and_ignores_extra_fields(): void
    {
        $token = $this->ownerToken();
        $this->api($token)->postJson('/api/agent-profiles', ['profiles' => [[
            'agent_id' => self::NOVA, 'name' => 'Nova', 'description' => self::DESCRIPTION,
            'system_prompt' => 'secret', 'api_key' => 'nope',
        ]]])->assertOk()
            ->assertJsonPath('data.0.agent_id', self::NOVA)
            ->assertJsonPath('data.0.description_hash', hash('sha256', self::DESCRIPTION))
            ->assertJsonPath('data.0.pending_edit_status', null);

        $this->ingest('Updated', self::NOVA, $token)->assertOk();
        $this->assertSame(1, AgentProfile::query()->count());
        $profile = AgentProfile::query()->first();
        $this->assertSame('Updated', $profile->description);
        $this->assertSame(hash('sha256', 'Updated'), $profile->description_hash);
        $this->assertSame('Support lead', $profile->title);
        $this->assertArrayNotHasKey('system_prompt', $profile->getAttributes());

        $this->ingest(str_repeat('a', 20001), self::NOVA, $token)->assertUnprocessable()->assertJsonValidationErrors('profiles.0.description');
        $this->ingest('x', 'bad id!', $token)->assertUnprocessable();
    }

    #[Test]
    public function ingest_marks_a_pending_edit_conflict_when_the_profile_changed_after_its_base(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save('Proposed');

        $this->ingest(self::DESCRIPTION)->assertJsonPath('data.0.pending_edit_status', 'pending');
        $this->ingest('Changed by hand on the host')->assertJsonPath('data.0.pending_edit_status', 'conflict');

        $edit = AgentInstructionEdit::query()->first();
        $this->assertSame('conflict', $edit->status);
        $this->assertSame(AgentInstructionEdit::CONFLICT_REASON, $edit->status_reason);
        $this->asOwner()->get('/usage/agents/'.self::NOVA)->assertInertia(fn (Assert $page) => $page
            ->where('instructions.latest.status', 'conflict')
            ->where('instructions.latest.proposedDescription', 'Proposed')
            ->where('instructions.profile.description', 'Changed by hand on the host'));
    }

    #[Test]
    public function ingest_that_matches_the_proposed_text_marks_the_edit_applied(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save("Proposed\n");

        $this->ingest("Proposed\n")->assertJsonPath('data.0.pending_edit_status', 'applied');

        $edit = AgentInstructionEdit::query()->first();
        $this->assertSame('applied', $edit->status);
        $this->assertSame(hash('sha256', "Proposed\n"), $edit->applied_hash);
        $this->assertNotNull($edit->applied_at);
    }

    #[Test]
    public function mark_applied_checks_the_hash_updates_the_profile_and_refuses_non_pending(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save('Proposed');
        $id = AgentInstructionEdit::query()->value('id');
        $token = $this->ownerToken();

        $this->api($token)->postJson("/api/agent-instruction-edits/$id/applied", ['description_hash' => hash('sha256', 'Other')])
            ->assertUnprocessable()->assertJsonValidationErrors('description_hash');
        $this->api($token)->postJson("/api/agent-instruction-edits/$id/applied", ['description_hash' => hash('sha256', 'Proposed')])
            ->assertOk()->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.applied_hash', hash('sha256', 'Proposed'))
            ->assertJsonPath('data.profile_description_hash', hash('sha256', 'Proposed'));

        $this->assertSame('Proposed', AgentProfile::query()->value('description'));
        $this->api($token)->postJson("/api/agent-instruction-edits/$id/applied", ['description_hash' => hash('sha256', 'Proposed')])->assertStatus(409);
        $this->api($token)->postJson("/api/agent-instruction-edits/$id/rejected")->assertStatus(409);
        $this->api($token)->postJson("/api/agent-instruction-edits/$id/conflict", ['current_description_hash' => hash('sha256', 'x')])->assertStatus(409);
    }

    #[Test]
    public function rejected_and_conflict_reports_close_a_pending_edit(): void
    {
        $this->ingest(self::DESCRIPTION);
        $token = $this->ownerToken();
        $this->asOwner()->save('One');
        $one = AgentInstructionEdit::query()->value('id');

        $this->api($token)->postJson("/api/agent-instruction-edits/$one/rejected", ['reason' => 'Too vague'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'Too vague');

        $this->asOwner()->save('Two');
        $two = AgentInstructionEdit::query()->where('revision', 2)->value('id');
        $this->api($token)->postJson("/api/agent-instruction-edits/$two/conflict")->assertUnprocessable();
        $this->api($token)->postJson("/api/agent-instruction-edits/$two/conflict", ['current_description_hash' => hash('sha256', 'host')])
            ->assertOk()->assertJsonPath('data.status', 'conflict')
            ->assertJsonPath('data.status_reason', AgentInstructionEdit::CONFLICT_REASON.' (current_description_hash '.hash('sha256', 'host').')');
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->value('description'));
    }

    #[Test]
    public function the_pending_list_has_the_sync_shape(): void
    {
        $this->loadUsage();
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save("Proposed\n");
        $this->asOwner()->save('Juniper text', self::JUNIPER)->assertCreated();

        $data = $this->api($this->ownerToken())->getJson('/api/agent-instruction-edits')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'agent_id', 'agent_name', 'revision', 'base_hash', 'proposed_description', 'proposed_hash', 'created_at', 'author']]])
            ->json('data');

        $this->assertSame(self::NOVA, $data[0]['agent_id']);
        $this->assertSame('Nova', $data[0]['agent_name']);
        $this->assertSame(1, $data[0]['revision']);
        $this->assertSame(hash('sha256', self::DESCRIPTION), $data[0]['base_hash']);
        $this->assertSame(hash('sha256', "Proposed\n"), $data[0]['proposed_hash']);
        $this->assertSame(self::JUNIPER, $data[1]['agent_id']);
        $this->assertNull($data[1]['base_hash']);
        $this->assertNotSame(self::JUNIPER, $data[1]['agent_name']);

        $this->api($this->ownerToken())->getJson('/api/agent-instruction-edits?status=applied')->assertOk()->assertJsonCount(0, 'data');
        $this->api($this->ownerToken())->getJson('/api/agent-instruction-edits?status=bogus')->assertUnprocessable();
    }

    #[Test]
    public function a_conflict_report_is_compared_with_the_base_and_proposed_hash(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save('Proposed');
        $id = AgentInstructionEdit::query()->value('id');
        $token = $this->ownerToken();
        $report = fn (string $hash, ?string $reason = null) => $this->api($token)
            ->postJson("/api/agent-instruction-edits/$id/conflict", ['current_description_hash' => $hash, 'reason' => $reason]);

        $report(hash('sha256', self::DESCRIPTION))->assertUnprocessable()->assertJsonValidationErrors('current_description_hash');
        $report(hash('sha256', 'Proposed'))->assertUnprocessable()->assertJsonValidationErrors('current_description_hash');
        $this->assertSame('pending', AgentInstructionEdit::query()->value('status'));

        $host = hash('sha256', 'Edited on the host');
        $report($host, 'Edited by hand')->assertOk()
            ->assertJsonPath('data.status', 'conflict')
            ->assertJsonPath('data.status_reason', "Edited by hand (current_description_hash $host)")
            ->assertJsonPath('data.current_description_hash', $host);
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->value('description'));
        $this->assertLessThanOrEqual(255, mb_strlen(AgentInstructions::conflictReason(str_repeat('r', 255), $host)));
    }

    #[Test]
    public function applied_with_a_wrong_hash_stays_pending_in_the_api_and_in_the_service(): void
    {
        $this->ingest(self::DESCRIPTION);
        $this->asOwner()->save('Proposed');
        $edit = AgentInstructionEdit::query()->first();

        $this->api($this->ownerToken())->postJson("/api/agent-instruction-edits/{$edit->id}/applied", ['description_hash' => hash('sha256', self::DESCRIPTION)])
            ->assertUnprocessable()->assertJsonValidationErrors('description_hash');

        try {
            app(AgentInstructions::class)->applied($edit, hash('sha256', 'Other'));
            $this->fail('applied() accepted a hash that is not sha256(proposed_description).');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description_hash', $e->errors());
        }
        $this->assertSame('pending', $edit->fresh()->status);
        $this->assertNull($edit->fresh()->applied_hash);
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->value('description'));
    }

    #[Test]
    public function a_first_save_that_loses_the_revision_race_retries_instead_of_failing(): void
    {
        $this->loadUsage();
        $concurrent = ['agent_id' => self::JUNIPER, 'revision' => 1, 'base_hash' => null, 'proposed_description' => 'Saved meanwhile',
            'author' => 'tilen', 'status' => AgentInstructionEdit::PENDING];
        // Another first save commits right after this one read max(revision); the insert of revision 1 then violates
        // the unique key, the attempt rolls back (taking the simulated row with it) and the retry sees that row again.
        $phase = 'armed';
        DB::listen(function (QueryExecuted $query) use (&$phase, $concurrent): void {
            if ($phase === 'armed' && str_contains($query->sql, 'max("revision")')) {
                $phase = 'raced';
                $this->insertConcurrently('agent_instruction_edits', $concurrent);
            } elseif ($phase === 'raced' && str_contains($query->sql, 'update "workspaces"')) {
                $phase = 'retrying';
                $this->insertConcurrently('agent_instruction_edits', $concurrent);
            }
        });

        $this->asOwner()->save('Mine', self::JUNIPER)->assertCreated()->assertJsonPath('data.revision', 2);

        $this->assertSame('retrying', $phase);
        $edits = AgentInstructionEdit::query()->where('agent_id', self::JUNIPER)->orderBy('revision')->get();
        $this->assertSame([1, 2], $edits->pluck('revision')->all());
        $this->assertSame(['superseded', 'pending'], $edits->pluck('status')->all());
        $this->assertSame('Mine', $edits[1]->proposed_description);
    }

    #[Test]
    public function ingest_and_applied_take_over_a_profile_row_created_in_between(): void
    {
        $raced = false;
        $this->afterFirstQuery('from "agent_profiles"', function () use (&$raced): void {
            $raced = true;
            $this->insertConcurrently('agent_profiles', $this->concurrentProfile(self::NOVA, 'Older'));
        });
        $this->ingest(self::DESCRIPTION)->assertOk();
        $this->assertTrue($raced);
        $this->assertSame(1, AgentProfile::query()->where('agent_id', self::NOVA)->count());
        $this->assertSame(self::DESCRIPTION, AgentProfile::query()->where('agent_id', self::NOVA)->value('description'));

        $this->loadUsage();
        $this->asOwner()->save('Juniper text', self::JUNIPER)->assertCreated();
        $id = AgentInstructionEdit::query()->where('agent_id', self::JUNIPER)->value('id');
        $token = $this->ownerToken();
        $this->afterFirstQuery('from "agent_profiles"', fn () => $this->insertConcurrently('agent_profiles', $this->concurrentProfile(self::JUNIPER, 'Older')));

        $this->api($token)->postJson("/api/agent-instruction-edits/$id/applied", ['description_hash' => hash('sha256', 'Juniper text')])
            ->assertOk()->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.profile_description_hash', hash('sha256', 'Juniper text'));
        $profiles = AgentProfile::query()->where('agent_id', self::JUNIPER)->get();
        $this->assertCount(1, $profiles);
        $this->assertSame('Juniper text', $profiles[0]->description);
        $this->assertSame('Synced meanwhile', $profiles[0]->name);
    }
}
