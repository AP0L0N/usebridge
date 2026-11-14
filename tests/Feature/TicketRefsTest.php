<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\StatusUpdate;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Support\TicketRefs;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Ticket references in text (App\Support\TicketRefs): mentions of existing ticket keys are stored as
 * [[ticket:<id>|<key>]] on every write path (web, REST, MCP), the REST API and MCP read them back as the current plain
 * key, the web app resolves them in one batch, and the backwards migration converts / restores existing text.
 */
class TicketRefsTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private Ticket $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $this->target = Ticket::query()->where('key', $this->api()->newTicket(['title' => 'Pick the palette'])['key'])->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(?Ticket $t = null): string
    {
        $t ??= $this->target;

        return "[[ticket:{$t->id}|{$t->key}]]";
    }

    private function mcpToken(): string
    {
        return $this->scopedToken($this->workspaceOwner(), app(CurrentWorkspace::class)->default(), 'write', self::JANICE);
    }

    // ---------------------------------------------------------------- conversion rules

    #[Test]
    public function only_existing_keys_outside_urls_code_and_links_become_tokens(): void
    {
        $key = $this->target->key;
        $text = implode("\n", [
            "See $key, ($key) and $key.",
            'Missing: ZZ-404.',
            "URL: https://example.com/tickets/$key and www.example.com/$key",
            "Link: [the $key ticket](https://example.com) and <https://x.test/$key>",
            "Inline `$key` code",
            '```',
            "$key in a block",
            '```',
            "Not a key: x$key, {$key}0, ".strtolower($key).", {$key}-fix",
            'Already: '.$this->token(),
        ]);

        $out = TicketRefs::tokenize($text);

        $this->assertSame(implode("\n", [
            "See {$this->token()}, ({$this->token()}) and {$this->token()}.",
            'Missing: ZZ-404.',
            "URL: https://example.com/tickets/$key and www.example.com/$key",
            "Link: [the $key ticket](https://example.com) and <https://x.test/$key>",
            "Inline `$key` code",
            '```',
            "$key in a block",
            '```',
            "Not a key: x$key, {$key}0, ".strtolower($key).", {$key}-fix",
            'Already: '.$this->token(),
        ]), $out);
        $this->assertSame($out, TicketRefs::tokenize($out), 'idempotent');
        $this->assertSame(str_replace($this->token(), $key, $out), TicketRefs::expand($out));
    }

    #[Test]
    public function a_key_of_another_workspace_stays_plain(): void
    {
        $this->assertSame("See {$this->target->key}", TicketRefs::tokenize("See {$this->target->key}", 999));
    }

    // ---------------------------------------------------------------- write paths

    #[Test]
    public function web_writes_store_tokens(): void
    {
        $key = $this->target->key;
        $other = $this->api()->newTicket(['title' => 'Other']);

        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$other['key']}/comments", ['body' => "Blocked on $key, not ZZ-9"])->assertCreated();
        $this->patchJson("/projects/blue-harbor/tickets/{$other['key']}", ['description' => "Needs $key first", 'blocked_reason' => "Waits for $key"])->assertOk();
        $this->patchJson('/projects/blue-harbor/status', ['status' => 'waiting', 'note' => "Stuck on $key"])->assertOk();

        $this->assertSame("Blocked on {$this->token()}, not ZZ-9", TicketComment::query()->latest('id')->value('body'));
        $row = Ticket::query()->where('key', $other['key'])->firstOrFail();
        $this->assertSame(["Needs {$this->token()} first", "Waits for {$this->token()}"], [$row->description, $row->blocked_reason]);
        $this->assertSame("Stuck on {$this->token()}", Project::query()->findOrFail(self::BH)->status_note);
    }

    #[Test]
    public function api_writes_store_tokens_and_api_reads_return_plain_keys(): void
    {
        $key = $this->target->key;
        $t = $this->api()->newTicket(['title' => 'Ship it', 'description' => "After $key", 'status' => 'waiting_tilen', 'ask' => "Ok to close $key?", 'ask_context' => "See $key"]);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => "Done with $key", 'actor' => self::JANICE])->assertCreated();
        $this->postJson('/api/status-updates', ['project_id' => self::BH, 'agent_id' => self::JANICE, 'body' => "Today: $key"])->assertSuccessful();

        $row = Ticket::query()->where('key', $t['key'])->firstOrFail();
        $this->assertSame(["After {$this->token()}", "Ok to close {$this->token()}?", "See {$this->token()}"], [$row->description, $row->ask, $row->ask_context]);
        $this->assertSame("Done with {$this->token()}", TicketComment::query()->latest('id')->value('body'));
        $this->assertSame("Today: {$this->token()}", StatusUpdate::query()->latest('created_at')->value('body'));

        $detail = $this->getJson("/api/tickets/{$t['key']}")->assertOk();
        $this->assertStringNotContainsString('[[ticket:', $detail->getContent());
        $this->assertSame(["After $key", "Ok to close $key?"], [$detail->json('data.description'), $detail->json('data.ask')]);
        $this->assertSame("Done with $key", collect($detail->json('data.comments'))->last()['body']);
        $this->assertStringNotContainsString('[[ticket:', $this->getJson('/api/status-updates?project_id='.self::BH)->getContent());
        $this->assertStringNotContainsString('[[ticket:', $this->getJson('/api/projects/'.self::BH.'/board')->getContent());
    }

    #[Test]
    public function the_same_text_sent_again_is_not_a_new_edit(): void
    {
        $key = $this->target->key;
        $t = $this->api()->newTicket(['title' => 'Ship it']);
        foreach ([1, 2] as $_) {
            $this->patchJson("/api/tickets/{$t['key']}", ['description' => "After $key", 'blocked_reason' => "Needs $key", 'actor' => self::JANICE])->assertOk();
        }
        $types = TicketEvent::query()->where('ticket_id', Ticket::query()->where('key', $t['key'])->value('id'))->pluck('type')->all();

        $this->assertSame(1, count(array_keys($types, 'edited')));
        $this->assertSame(1, count(array_keys($types, 'blocked')));
    }

    #[Test]
    public function mcp_writes_store_tokens_and_mcp_reads_return_plain_keys(): void
    {
        $key = $this->target->key;
        $token = $this->mcpToken();
        $this->tool($token, 'comment_ticket', ['key' => $key, 'body' => "Related: $key and ZZ-1"]);

        $this->assertSame("Related: {$this->token()} and ZZ-1", TicketComment::query()->latest('id')->value('body'));
        $read = $this->callTool($token, 'get_ticket', ['key' => $key]);
        $this->assertStringNotContainsString('[[ticket:', $read['text']);
        $this->assertSame("Related: $key and ZZ-1", collect($read['json']['data']['comments'])->last()['body']);
    }

    #[Test]
    public function a_reference_follows_the_ticket_when_its_key_changes_and_falls_back_when_it_is_gone(): void
    {
        $this->api()->postJson("/api/tickets/{$this->target->key}/comments", ['body' => "Self: {$this->target->key}", 'actor' => self::JANICE])->assertCreated();
        Ticket::query()->whereKey($this->target->id)->toBase()->update(['key' => 'BH-900']);

        $this->assertSame('Self: BH-900', collect($this->getJson('/api/tickets/BH-900')->json('data.comments'))->last()['body']);
        $this->assertSame('Gone: BH-1', TicketRefs::expand('Gone: [[ticket:tk-missing|BH-1]]'));
        $this->assertSame('Gone: ', TicketRefs::expand('Gone: [[ticket:tk-missing]]'));
    }

    // ---------------------------------------------------------------- web rendering

    #[Test]
    public function the_web_app_resolves_references_in_one_batch_and_loads_the_modal_by_id(): void
    {
        $this->asOwner()->getJson("/ticket-refs?ids={$this->target->id},tk-missing")->assertOk()
            ->assertExactJson(['data' => [$this->target->id => ['key' => $this->target->key, 'title' => 'Pick the palette', 'project_slug' => 'blue-harbor', 'archived' => false]]]);

        $this->getJson("/ticket-refs/{$this->target->id}")->assertOk()
            ->assertJsonPath('data.ticket.key', $this->target->key)
            ->assertJsonPath('data.project.slug', 'blue-harbor')
            ->assertJsonStructure(['data' => ['ticket' => ['comments', 'events'], 'columns' => [['key', 'label']], 'people', 'labels']]);
        $this->getJson('/ticket-refs/tk-missing')->assertNotFound();
    }

    #[Test]
    public function the_web_app_gets_the_raw_tokens(): void
    {
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$this->target->key}/comments", ['body' => "Self: {$this->target->key}"])->assertCreated()
            ->assertJsonPath('data.body', "Self: {$this->token()}");
    }

    // ---------------------------------------------------------------- backwards migration

    #[Test]
    public function the_migration_converts_existing_text_idempotently_and_down_restores_plain_keys(): void
    {
        $key = $this->target->key;
        $plain = "Old note about $key and ZZ-77, see https://x.test/$key";
        $comment = TicketComment::query()->create(['ticket_id' => $this->target->id, 'author_id' => self::JANICE, 'author_type' => 'agent', 'body' => 'x']);
        DB::table('ticket_comments')->where('id', $comment->id)->update(['body' => $plain]);
        DB::table('tickets')->where('id', $this->target->id)->update(['description' => "Dup of $key", 'updated_at' => '2026-10-01 00:00:00']);
        DB::table('projects')->where('id', self::BH)->update(['status_note' => "Waiting on $key"]);
        $migration = require database_path('migrations/2026_11_14_100000_ticket_refs_tokens.php');
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = [$m->message, $m->context];
        });

        $migration->up();
        $tokenized = str_replace("about $key", "about {$this->token()}", $plain);
        $this->assertSame($tokenized, DB::table('ticket_comments')->where('id', $comment->id)->value('body'));
        $this->assertSame("Dup of {$this->token()}", DB::table('tickets')->where('id', $this->target->id)->value('description'));
        $this->assertSame('2026-10-01 00:00:00', DB::table('tickets')->where('id', $this->target->id)->value('updated_at'), 'timestamps untouched');
        $this->assertSame("Waiting on {$this->token()}", DB::table('projects')->where('id', self::BH)->value('status_note'));
        $this->assertSame(['Ticket references tokenized', 1, 1, 1], [$logged[0][0], $logged[0][1]['ticket_comments'], $logged[0][1]['tickets'], $logged[0][1]['projects']]);

        $migration->up();
        $this->assertSame($tokenized, DB::table('ticket_comments')->where('id', $comment->id)->value('body'));
        $this->assertSame(0, array_sum($logged[1][1]), 'a second run converts nothing');

        $migration->down();
        $this->assertSame($plain, DB::table('ticket_comments')->where('id', $comment->id)->value('body'));
        $this->assertSame("Dup of $key", DB::table('tickets')->where('id', $this->target->id)->value('description'));
        $this->assertSame("Waiting on $key", DB::table('projects')->where('id', self::BH)->value('status_note'));
        $this->assertSame(3, array_sum($logged[2][1]));
        $migration->down();
        $this->assertSame($plain, DB::table('ticket_comments')->where('id', $comment->id)->value('body'));
    }

    #[Test]
    public function every_model_listed_for_the_migration_tokenizes_on_save(): void
    {
        foreach (TicketRefs::MODELS as $table => $model) {
            $this->assertContains('App\Models\Concerns\StoresTicketRefs', class_uses_recursive($model), $model);
            $this->assertNotEmpty($model::TICKET_REF_FIELDS, $model);
        }
    }
}
