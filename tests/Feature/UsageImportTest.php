<?php

namespace Tests\Feature;

use App\Models\UsageDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/usage/days';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->workspaceUser()->createToken('bridge-api')->plainTextToken;
    }

    public static function fixture(string $date = '2026-10-19'): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/usage/$date.json"));
    }

    /** @return list<string> */
    public static function fixturePaths(): array
    {
        $paths = glob(base_path('tests/fixtures/usage/2026-10-*.json'));
        sort($paths);

        return $paths;
    }

    private function send(string $body, ?string $token = null, string $type = 'application/json'): \Illuminate\Testing\TestResponse
    {
        $headers = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== '') {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.($token ?? $this->token);
        }

        return $this->call('POST', self::URL, [], [], [], $headers, $body);
    }

    /** @param callable(array): array $mutate */
    private function mutated(callable $mutate, string $date = '2026-10-19'): string
    {
        return json_encode($mutate(json_decode(self::fixture($date), true)));
    }

    #[Test]
    public function no_token_is_rejected(): void
    {
        $this->send(self::fixture(), '')->assertUnauthorized();
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function unknown_token_is_rejected(): void
    {
        $this->send(self::fixture(), '1|not-a-real-token')->assertUnauthorized();
        $this->send(self::fixture(), 'garbage')->assertUnauthorized();
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function valid_file_is_created_then_reposting_is_idempotent(): void
    {
        $this->send(self::fixture())
            ->assertCreated()
            ->assertExactJson(['data' => ['date' => '2026-10-19', 'est_tokens_total' => 176291252, 'agents' => 19, 'created' => true]]);

        $first = UsageDay::query()->sole();
        $this->assertSame(json_decode(self::fixture(), true), $first->payload);

        $this->send(self::fixture())
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertSame(1, UsageDay::count());
        $again = UsageDay::query()->sole();
        $this->assertSame($first->getRawOriginal('payload'), $again->getRawOriginal('payload'));
        $this->assertSame($first->id, $again->id);
    }

    #[Test]
    public function modified_file_for_same_date_replaces_row(): void
    {
        $this->send(self::fixture())->assertCreated();

        $body = $this->mutated(function (array $d) {
            $d['agents'][0]['est_tokens_total'] += 1000;
            $d['agents'][0]['other_est_tokens'] += 1000;
            $d['est_tokens_total'] += 1000;
            $d['generated_at'] = '2026-10-19T17:05:00+02:00';

            return $d;
        });
        $this->send($body)->assertOk()->assertJsonPath('data.est_tokens_total', 176292252);

        $row = UsageDay::query()->sole();
        $this->assertSame(176292252, $row->est_tokens_total);
        $this->assertSame('2026-10-19T17:05:00+02:00', $row->payload['generated_at']);
    }

    #[Test]
    public function all_seven_fixtures_import(): void
    {
        foreach (self::fixturePaths() as $path) {
            $this->send((string) file_get_contents($path))->assertCreated();
        }
        $this->assertSame(7, UsageDay::count());
        $this->assertSame(2625145391, (int) UsageDay::sum('est_tokens_total'));
    }

    public static function badSchemas(): array
    {
        return [
            'missing key' => [fn (array $d) => array_diff_key($d, ['method' => 1]), 'method'],
            'unknown top key' => [fn (array $d) => $d + ['extra' => 1], 'extra'],
            'unknown agent key' => [function (array $d) {
                $d['agents'][3]['cost_usd'] = 1;

                return $d;
            }, 'agents.3.cost_usd'],
            'unknown params key' => [function (array $d) {
                $d['params']['x'] = 1;

                return $d;
            }, 'params.x'],
            'unknown routine key' => [function (array $d) {
                $d['agents'][0]['by_routine'][0]['ts'] = 'x';

                return $d;
            }, 'agents.0.by_routine.0.ts'],
            'by_hour length 23' => [function (array $d) {
                array_pop($d['agents'][0]['by_hour']);

                return $d;
            }, 'agents.0.by_hour'],
            'string number' => [function (array $d) {
                $d['agents'][0]['turns'] = (string) $d['agents'][0]['turns'];

                return $d;
            }, 'agents.0.turns'],
            'float for int' => [function (array $d) {
                $d['agents'][0]['turns'] = 1.5;

                return $d;
            }, 'agents.0.turns'],
            'bool for int' => [function (array $d) {
                $d['agents'][0]['visible_chars'] = true;

                return $d;
            }, 'agents.0.visible_chars'],
            'negative' => [function (array $d) {
                $d['agents'][0]['est_io_tokens'] = -1;

                return $d;
            }, 'agents.0.est_io_tokens'],
            'over cap' => [function (array $d) {
                $d['agents'][0]['visible_chars'] = 2_000_000_000_000_000;

                return $d;
            }, 'agents.0.visible_chars'],
            'wrong timezone' => [fn (array $d) => ['timezone' => 'UTC'] + $d, 'timezone'],
            'invalid date' => [fn (array $d) => ['date' => '2026-02-30'] + $d, 'date'],
            'generated_at without offset' => [fn (array $d) => ['generated_at' => '2026-10-19T15:29:26'] + $d, 'generated_at'],
            'total mismatch' => [fn (array $d) => ['est_tokens_total' => $d['est_tokens_total'] + 1] + $d, 'est_tokens_total'],
            'duplicate agent_id' => [function (array $d) {
                $d['agents'][1]['agent_id'] = $d['agents'][0]['agent_id'];

                return $d;
            }, 'agents.1.agent_id'],
            'bad agent_id chars' => [function (array $d) {
                $d['agents'][0]['agent_id'] = 'a b';

                return $d;
            }, 'agents.0.agent_id'],
            'params as list' => [fn (array $d) => ['params' => []] + $d, 'params'],
            'routine window not pair' => [function (array $d) {
                $d['params']['routine_window_min'] = [-2];

                return $d;
            }, 'params.routine_window_min'],
        ];
    }

    #[Test]
    #[DataProvider('badSchemas')]
    public function bad_schema_is_422_and_nothing_written(callable $mutate, string $errorKey): void
    {
        $this->send($this->mutated($mutate))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$errorKey]]);
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function non_json_and_non_object_bodies_are_422(): void
    {
        $this->send('{"date": "2026-10-19",')->assertStatus(422)->assertJsonStructure(['errors' => ['body']]);
        $this->send('[1,2,3]')->assertStatus(422);
        $this->send('')->assertStatus(422);
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function wrong_content_type_is_415(): void
    {
        $this->send(self::fixture(), null, 'text/plain')->assertStatus(415);
        $this->send(self::fixture(), null, 'application/x-www-form-urlencoded')->assertStatus(415);
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function json_with_charset_is_accepted(): void
    {
        $this->send(self::fixture(), null, 'application/json; charset=utf-8')->assertCreated();
    }

    #[Test]
    public function oversize_body_is_413(): void
    {
        $body = $this->mutated(fn (array $d) => ['method' => str_repeat('x', 600_000)] + $d);
        $this->send($body)->assertStatus(413);

        // Lying Content-Length header is caught by the header check too.
        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_LENGTH' => '600000',
        ], self::fixture())->assertStatus(413);

        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function artisan_import_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $paths = self::fixturePaths();

        $this->artisan('bridge:usage-import', ['paths' => $paths, '--dry-run' => true])
            ->expectsOutputToContain('2026-10-13  valid, would be created')
            ->assertExitCode(0);
        $this->assertSame(0, UsageDay::count());

        $this->artisan('bridge:usage-import', ['paths' => $paths])
            ->expectsOutputToContain('2026-10-19  created  total 176,291,252')
            ->assertExitCode(0);
        $this->assertSame(7, UsageDay::count());
        $before = UsageDay::query()->orderBy('date')->pluck('payload', 'date')->all();

        $this->artisan('bridge:usage-import', ['paths' => $paths])
            ->expectsOutputToContain('2026-10-19  updated')
            ->assertExitCode(0);
        $this->assertSame(7, UsageDay::count());
        $this->assertSame($before, UsageDay::query()->orderBy('date')->pluck('payload', 'date')->all());
    }

    #[Test]
    public function artisan_invalid_file_exits_non_zero_and_is_not_written(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'usage');
        file_put_contents($bad, $this->mutated(fn (array $d) => ['est_tokens_total' => 1] + $d, '2026-10-18'));

        try {
            $this->artisan('bridge:usage-import', ['paths' => [base_path('tests/fixtures/usage/2026-10-19.json'), $bad]])
                ->assertExitCode(1);
        } finally {
            unlink($bad);
        }

        $this->assertSame(['2026-10-19'], UsageDay::query()->pluck('date')->all());
    }
}
