<?php

namespace Tests\Feature;

use App\Models\AgentInstructionEdit;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Usage\UsageDayImporter;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The roster sync POST /api/agents/sync and the grouped /agents list (docs/agents-sync.md). The fixture is synthetic:
 * four sections, an empty and a null description, a name without a title, and agents out of order.
 */
class AgentsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ZED = 'a1000000-0000-4000-8000-000000000001';

    private const MIRA = 'a1000000-0000-4000-8000-000000000002';

    private const BEA = 'a1000000-0000-4000-8000-000000000003';

    private const ADA = 'a1000000-0000-4000-8000-000000000004';

    private const OTTO = 'a1000000-0000-4000-8000-000000000005';

    private const PIM = 'a1000000-0000-4000-8000-000000000006';

    /** In the usage fixtures, never in the roster. */
    private const JUNIPER = '0445b205-093c-4120-85c8-c87c882fa3d4';

    /** A room that only ever came through the legacy POST /api/agent-profiles. */
    private const ROOM = 'f0000000-0000-4000-8000-00000000000f';

    private const SECTIONS = ['Project managers', 'Development', 'Trading', 'Unassigned'];

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

    /** @return array{generated_at: string, source: string, sections: list<string>, agents: list<array<string, mixed>>} */
    private static function roster(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/agents/agents-sync.json')), true);
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

    private function sync(?array $body = null, ?string $token = null): TestResponse
    {
        return $this->api($token ?? $this->ownerToken())->postJson('/api/agents/sync', $body ?? self::roster());
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private static function withAgent(string $agentId, callable $change): array
    {
        $body = self::roster();
        $body['agents'] = array_map(fn (array $a) => $a['agent_id'] === $agentId ? $change($a) : $a, $body['agents']);

        return $body;
    }

    private function loadUsage(): void
    {
        $importer = app(UsageDayImporter::class);
        foreach (UsageImportTest::fixturePaths() as $path) {
            $importer->import((string) file_get_contents($path));
        }
    }

    #[Test]
    public function the_sync_creates_then_reposting_changes_nothing_then_counts_updates(): void
    {
        $this->sync()->assertOk()
            ->assertJsonPath('data.received', 6)
            ->assertJsonPath('data.created', 6)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unchanged', 0)
            ->assertJsonPath('data.unlisted', 0)
            ->assertJsonPath('data.sections', self::SECTIONS)
            ->assertJsonPath('data.agents.0.agent_id', self::ZED)
            ->assertJsonPath('data.agents.0.result', 'created')
            ->assertJsonPath('data.agents.0.description_hash', hash('sha256', "You are Zed, the lead developer.\n- Review every pull request  "))
            ->assertJsonPath('data.agents.0.pending_edit_status', null);

        $zed = AgentProfile::query()->where('agent_id', self::ZED)->first();
        $this->assertSame(['Zed', 'Lead Developer', 'Zed · Lead Developer', 'Development', 1, true],
            [$zed->name, $zed->title, $zed->full_name, $zed->section, $zed->section_order, $zed->listed]);
        $this->assertSame("You are Zed, the lead developer.\n- Review every pull request  ", $zed->description);
        $this->assertArrayNotHasKey('model', $zed->getAttributes());
        $otto = AgentProfile::query()->where('agent_id', self::OTTO)->first();
        $this->assertSame(['Otto Lark', null, 'Otto Lark'], [$otto->name, $otto->title, $otto->full_name]);
        $this->assertSame('', AgentProfile::query()->where('agent_id', self::PIM)->value('description'));
        $this->assertSame(hash('sha256', ''), AgentProfile::query()->where('agent_id', self::PIM)->value('description_hash'));

        $this->sync()->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.unchanged', 6)
            ->assertJsonPath('data.unlisted', 0);

        $body = self::withAgent(self::ZED, fn (array $a) => [...$a, 'description' => 'New text']);
        $body['agents'] = array_map(fn (array $a) => $a['agent_id'] === self::OTTO ? [...$a, 'section_order' => 5] : $a, $body['agents']);
        $this->sync($body)->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unchanged', 4)
            ->assertJsonPath('data.agents.0.result', 'updated');
        $this->assertSame(6, AgentProfile::query()->count());
    }

    #[Test]
    public function a_legacy_profile_with_the_same_id_is_updated_and_listed(): void
    {
        $this->api($this->ownerToken())->postJson('/api/agent-profiles', ['profiles' => [
            ['agent_id' => self::OTTO, 'name' => 'Otto', 'title' => 'Old', 'description' => 'You watch the markets.'],
        ]])->assertOk();
        $this->assertFalse(AgentProfile::query()->where('agent_id', self::OTTO)->value('listed'));

        $this->sync()->assertOk()->assertJsonPath('data.created', 5)->assertJsonPath('data.updated', 1);
        $otto = AgentProfile::query()->where('agent_id', self::OTTO)->first();
        $this->assertTrue($otto->listed);
        $this->assertSame(['Otto Lark', null], [$otto->name, $otto->title]);
    }

    #[Test]
    public function the_sync_needs_an_owner_token_with_agents_write(): void
    {
        $this->postJson('/api/agents/sync', self::roster())->assertUnauthorized();

        $member = $this->workspaceUser()->createToken('m', ['boards:read', 'agents:write'])->plainTextToken;
        $this->sync(null, $member)->assertForbidden();
        $this->sync(null, $this->ownerToken(['boards:read']))->assertForbidden();
        $this->sync(null, $this->ownerToken(['boards:write']))->assertForbidden();

        $this->assertSame(0, AgentProfile::query()->count());
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        $agent = fn (int $i, callable $change) => function (array $body) use ($i, $change): array {
            $body['agents'][$i] = $change($body['agents'][$i]);

            return $body;
        };

        return [
            'missing full_name' => [$agent(0, fn (array $a) => array_diff_key($a, ['full_name' => 1])), 'agents.0.full_name'],
            'bad agent_id' => [$agent(1, fn (array $a) => [...$a, 'agent_id' => 'bad id!']), 'agents.1.agent_id'],
            'agent_id too long' => [$agent(1, fn (array $a) => [...$a, 'agent_id' => str_repeat('a', 65)]), 'agents.1.agent_id'],
            'duplicate agent_id' => [$agent(1, fn (array $a) => [...$a, 'agent_id' => self::ZED]), 'agents.1.agent_id'],
            'section_order above 99' => [$agent(2, fn (array $a) => [...$a, 'section_order' => 100]), 'agents.2.section_order'],
            'negative section_order' => [$agent(2, fn (array $a) => [...$a, 'section_order' => -1]), 'agents.2.section_order'],
            'section_order as a string' => [$agent(2, fn (array $a) => [...$a, 'section_order' => '1']), 'agents.2.section_order'],
            'missing section' => [$agent(3, fn (array $a) => array_diff_key($a, ['section' => 1])), 'agents.3.section'],
            'description too long' => [$agent(4, fn (array $a) => [...$a, 'description' => str_repeat('a', 20001)]), 'agents.4.description'],
            'no agents' => [fn (array $body) => [...$body, 'agents' => []], 'agents'],
            'sections not strings' => [fn (array $body) => [...$body, 'sections' => [['x']]], 'sections.0'],
        ];
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function an_invalid_body_is_422_and_stores_nothing(callable $break, string $field): void
    {
        $token = $this->ownerToken();
        $this->sync(['agents' => [self::roster()['agents'][5]]], $token)->assertOk();
        $before = AgentProfile::query()->orderBy('id')->get()->toArray();

        $this->sync($break(self::roster()), $token)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($before, AgentProfile::query()->orderBy('id')->get()->toArray());
    }

    #[Test]
    public function the_agents_grid_has_section_counts_and_initials(): void
    {
        $this->loadUsage();
        $this->sync(null, $this->ownerToken())->assertOk();

        $this->asOwner()->get('/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Agents/Index')
            ->where('sections', function ($sections) {
                $sections = collect($sections);
                $this->assertSame([2, 2, 1, 1], $sections->pluck('count')->all());
                $rows = $sections->flatMap(fn ($s) => $s['agents'])->keyBy('id');
                $this->assertSame('ZE', $rows[self::ZED]['initials']);
                $this->assertSame('OL', $rows[self::OTTO]['initials']);

                return true;
            }));
        // The tile's target is the unchanged detail page.
        $this->get('/usage/agents/'.self::OTTO)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Usage/Agent'));
    }

    #[Test]
    public function the_agents_grid_is_empty_before_the_first_sync(): void
    {
        $this->asOwner()->get('/agents')->assertOk()->assertInertia(fn (Assert $page) => $page->where('sections', []));
    }

    #[Test]
    public function the_agents_page_lists_only_roster_agents_grouped_and_sorted_by_full_name(): void
    {
        $this->loadUsage();
        $token = $this->ownerToken();
        $this->api($token)->postJson('/api/agent-profiles', ['profiles' => [
            ['agent_id' => self::ROOM, 'name' => 'Lobby', 'title' => null, 'description' => 'A group chat.'],
        ]])->assertOk();
        $this->sync(null, $token)->assertOk();

        $this->asOwner()->get('/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Agents/Index')
            ->missing('agents')
            ->where('sections', function ($sections) {
                $sections = collect($sections);
                $this->assertSame(self::SECTIONS, $sections->pluck('name')->all());
                $this->assertSame([[self::ADA, self::BEA], [self::MIRA, self::ZED], [self::OTTO], [self::PIM]],
                    $sections->map(fn ($s) => collect($s['agents'])->pluck('id')->all())->all());

                $rows = $sections->flatMap(fn ($s) => $s['agents'])->keyBy('id');
                $this->assertSame('Zed · Lead Developer', $rows[self::ZED]['fullName']);
                $this->assertSame('Otto Lark', $rows[self::OTTO]['fullName']);
                $this->assertSame('You are Zed, the lead developer. - Review every pull request', $rows[self::ZED]['excerpt']);
                $this->assertNull($rows[self::MIRA]['excerpt']);
                $this->assertNull($rows[self::PIM]['excerpt']);
                $this->assertArrayNotHasKey(self::JUNIPER, $rows->all());
                $this->assertArrayNotHasKey(self::ROOM, $rows->all());

                return true;
            }));

        // Not listed, but their pages still exist.
        $this->get('/usage/agents/'.self::JUNIPER)->assertOk();
        $this->get('/usage/agents/'.self::ROOM)->assertOk();
    }

    #[Test]
    public function the_detail_page_has_the_full_name_and_the_whole_description(): void
    {
        $this->sync()->assertOk();

        $this->asOwner()->get('/usage/agents/'.self::ZED)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Agent')
            ->where('instructions.profile.fullName', 'Zed · Lead Developer')
            ->where('instructions.profile.title', 'Lead Developer')
            ->where('instructions.profile.description', "You are Zed, the lead developer.\n- Review every pull request  "));
        $this->get('/usage/agents/'.self::OTTO)->assertInertia(fn (Assert $page) => $page
            ->where('instructions.profile.fullName', 'Otto Lark')
            ->where('instructions.profile.title', null));
        $this->get('/usage/agents/'.self::MIRA)->assertInertia(fn (Assert $page) => $page->where('instructions.profile.description', ''));

        $page = (string) file_get_contents(resource_path('js/Pages/Usage/Agent.tsx'));
        $this->assertStringContainsString('<h1>{fullName}</h1>', $page);
    }

    #[Test]
    public function a_pending_edit_is_never_overwritten_and_follows_the_ingest_rules(): void
    {
        $token = $this->ownerToken();
        $this->sync(null, $token)->assertOk();
        $this->asOwner()->postJson('/usage/agents/'.self::ZED.'/instructions', ['description' => "Proposed\n"])->assertCreated();

        $this->sync(null, $token)->assertOk()->assertJsonPath('data.agents.0.pending_edit_status', 'pending');
        $edit = AgentInstructionEdit::query()->first();
        $this->assertSame(['pending', "Proposed\n"], [$edit->status, $edit->proposed_description]);

        $this->sync(self::withAgent(self::ZED, fn (array $a) => [...$a, 'description' => "Proposed\n"]), $token)->assertOk()
            ->assertJsonPath('data.agents.0.pending_edit_status', 'applied');
        $edit->refresh();
        $this->assertSame('applied', $edit->status);
        $this->assertSame(hash('sha256', "Proposed\n"), $edit->applied_hash);

        $this->asOwner()->postJson('/usage/agents/'.self::ZED.'/instructions', ['description' => 'Second'])->assertCreated();
        $this->sync(self::withAgent(self::ZED, fn (array $a) => [...$a, 'description' => 'Changed on the host']), $token)->assertOk()
            ->assertJsonPath('data.agents.0.pending_edit_status', 'conflict');
        $second = AgentInstructionEdit::query()->where('revision', 2)->first();
        $this->assertSame(['conflict', AgentInstructionEdit::CONFLICT_REASON, 'Second'], [$second->status, $second->status_reason, $second->proposed_description]);
        $this->assertSame('Changed on the host', AgentProfile::query()->where('agent_id', self::ZED)->value('description'));
    }

    #[Test]
    public function the_sync_is_a_snapshot_absent_agents_are_unlisted_not_deleted(): void
    {
        $token = $this->ownerToken();
        $this->sync(null, $token)->assertOk();
        $this->asOwner()->postJson('/usage/agents/'.self::PIM.'/instructions', ['description' => 'Kept'])->assertCreated();

        $subset = self::roster();
        $subset['agents'] = array_slice($subset['agents'], 0, 4);
        $this->sync($subset, $token)->assertOk()
            ->assertJsonPath('data.unchanged', 4)
            ->assertJsonPath('data.unlisted', 2)
            ->assertJsonPath('data.sections', ['Project managers', 'Development']);
        $this->sync($subset, $token)->assertOk()->assertJsonPath('data.unlisted', 0)->assertJsonPath('data.updated', 0);

        $this->assertSame(6, AgentProfile::query()->count());
        $this->assertFalse(AgentProfile::query()->where('agent_id', self::PIM)->value('listed'));
        $this->assertSame('Unassigned', AgentProfile::query()->where('agent_id', self::PIM)->value('section'));
        $this->assertSame('pending', AgentInstructionEdit::query()->where('agent_id', self::PIM)->value('status'));
        $this->asOwner()->get('/agents')->assertInertia(fn (Assert $page) => $page->where('sections', fn ($s) => collect($s)->pluck('name')->all() === ['Project managers', 'Development']));
        $this->get('/usage/agents/'.self::PIM)->assertOk();

        $this->sync(null, $token)->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.unchanged', 4);
    }

    #[Test]
    public function the_legacy_profile_ingest_keeps_the_roster_columns(): void
    {
        $token = $this->ownerToken();
        $this->sync(null, $token)->assertOk();

        $this->api($token)->postJson('/api/agent-profiles', ['profiles' => [
            ['agent_id' => self::ZED, 'name' => 'Zed', 'title' => 'Lead Developer', 'description' => 'Legacy text'],
        ]])->assertOk();

        $zed = AgentProfile::query()->where('agent_id', self::ZED)->first();
        $this->assertSame(['Legacy text', true, 'Development', 1, 'Zed · Lead Developer'],
            [$zed->description, $zed->listed, $zed->section, $zed->section_order, $zed->full_name]);
    }

    #[Test]
    public function the_sync_and_the_list_are_per_workspace(): void
    {
        $this->sync()->assertOk();

        $stranger = User::factory()->create();
        $other = Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => $stranger->id]);
        $theirs = ['agents' => [['agent_id' => self::ZED, 'full_name' => 'Their Zed', 'section' => 'Ops', 'section_order' => 0, 'description' => 'Theirs']]];
        $this->sync($theirs, $stranger->createToken('other', ['*'])->plainTextToken)->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.unlisted', 0);

        $ours = AgentProfile::query()->withoutGlobalScopes()->where('workspace_id', app(CurrentWorkspace::class)->default()->id);
        $mine = (clone $ours)->where('agent_id', self::ZED)->first();
        $this->assertSame(['Zed · Lead Developer', true], [$mine->full_name, $mine->listed]);
        $this->assertSame(6, (clone $ours)->where('listed', true)->count());
        $this->assertSame('Their Zed', AgentProfile::query()->withoutGlobalScopes()->where('workspace_id', $other->id)->value('full_name'));

        $this->actingAs($stranger, 'web')->get('/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sections', fn ($s) => collect($s)->pluck('name')->all() === ['Ops']
                && collect($s[0]['agents'])->pluck('fullName')->all() === ['Their Zed']));
    }
}
