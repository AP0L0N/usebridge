<?php

namespace Tests\Feature;

use App\Models\PolymarketSeriesPoint;
use App\Models\PolymarketSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** POST /api/polymarket/feed + `bridge:polymarket-import`: guard, validation, snapshot replace rules, series upsert. */
class PolymarketFeedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/polymarket/feed';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->workspaceUser()->createToken('bridge-api')->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function fixturePath(string $name): string
    {
        return base_path("tests/fixtures/markets/$name.json");
    }

    /** @return array<string, mixed>|list<mixed> */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::fixturePath($name)), true);
    }

    /** feed.json + book_series.json as one POST body. */
    public static function body(?array $snapshot = null, ?array $series = null): string
    {
        return json_encode(['snapshot' => $snapshot ?? self::fixture('feed'), 'series' => $series ?? self::fixture('book_series')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function send(string $body, ?string $token = null, string $type = 'application/json'): TestResponse
    {
        $headers = ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== '') {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.($token ?? $this->token);
        }

        return $this->call('POST', self::URL, [], [], [], $headers, $body);
    }

    /** @return array<string, string> ts => updated_at */
    private function pointStamps(): array
    {
        return PolymarketSeriesPoint::query()->orderBy('ts')->pluck('updated_at', 'ts')->map(fn ($v) => (string) $v)->all();
    }

    #[Test]
    public function no_token_is_rejected(): void
    {
        $this->send(self::body(), '')->assertUnauthorized();
        $this->send(self::body(), '1|not-a-real-token')->assertUnauthorized();
        $this->assertSame(0, PolymarketSnapshot::count());
        $this->assertSame(0, PolymarketSeriesPoint::count());
    }

    #[Test]
    public function wrong_content_type_is_415(): void
    {
        $this->send(self::body(), null, 'text/plain')->assertStatus(415);
        $this->assertSame(0, PolymarketSnapshot::count());
    }

    #[Test]
    public function body_above_1_mb_is_413(): void
    {
        $series = array_fill(0, 12000, ['as_of' => '2037-10-03T16:05+02:00', 'book_value_usd' => 1000.5, 'cash_usd' => null, 'source' => str_repeat('x', 40)]);
        $body = self::body(null, $series);
        $this->assertGreaterThan(1048576, strlen($body));

        $this->send($body)->assertStatus(413);
        $this->assertSame(0, PolymarketSnapshot::count());
    }

    #[Test]
    public function feed_and_series_import_then_reposting_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2037-10-25 22:00:00', 'Europe/Ljubljana'));
        $this->send(self::body())
            ->assertCreated()
            ->assertExactJson(['data' => [
                'snapshot' => 'created',
                'as_of' => '2037-10-25T20:47+01:00',
                'series' => ['received' => 120, 'inserted' => 120, 'updated' => 0, 'unchanged' => 0, 'total' => 120],
            ]]);

        $snap = PolymarketSnapshot::query()->sole();
        $this->assertSame('2037-10-25 19:47:00', substr((string) $snap->as_of, 0, 19));
        $this->assertSame('2037-10-25T20:47+01:00', $snap->as_of_raw);
        $this->assertArrayNotHasKey('history', $snap->payload);
        $this->assertSame(1093.3, $snap->payload['book_value_usd']);
        $this->assertSame(64, strlen($snap->payload_sha256));

        // DST: +02:00 and +01:00 points are stored as UTC.
        $first = PolymarketSeriesPoint::query()->orderBy('ts')->first();
        $this->assertSame('2037-10-03 07:26:00', substr((string) $first->ts, 0, 19));
        $this->assertSame('2037-10-03T09:26+02:00', $first->ts_raw);
        $this->assertSame(562.59, (float) $first->stop_usd);
        $last = PolymarketSeriesPoint::query()->orderByDesc('ts')->first();
        $this->assertSame('2037-10-25 18:22:00', substr((string) $last->ts, 0, 19));
        $this->assertSame(189.75, (float) $last->cash_usd);
        $this->assertNull($last->stop_usd);
        $this->assertSame('desk-run-2037-10-25-1922.json', $last->source);
        // history[] stop_usd: set on 3–6 Oct runs only.
        $this->assertSame(19, PolymarketSeriesPoint::query()->whereNotNull('stop_usd')->count());

        $stamps = $this->pointStamps();
        $snapUpdated = (string) $snap->updated_at;

        Carbon::setTestNow(Carbon::parse('2037-10-25 22:30:00', 'Europe/Ljubljana'));
        $this->send(self::body())
            ->assertOk()
            ->assertExactJson(['data' => [
                'snapshot' => 'unchanged',
                'as_of' => '2037-10-25T20:47+01:00',
                'series' => ['received' => 120, 'inserted' => 0, 'updated' => 0, 'unchanged' => 120, 'total' => 120],
            ]]);

        $this->assertSame(1, PolymarketSnapshot::count());
        $this->assertSame(120, PolymarketSeriesPoint::count());
        $this->assertSame($stamps, $this->pointStamps());
        $again = PolymarketSnapshot::query()->sole();
        $this->assertSame($snapUpdated, (string) $again->updated_at);
        $this->assertSame((string) $snap->received_at, (string) $again->received_at);
    }

    #[Test]
    public function newer_snapshot_replaces_older_is_kept_newer(): void
    {
        $this->send(self::body())->assertCreated();

        $newer = self::fixture('feed');
        $newer['as_of'] = '2037-10-26T08:05+01:00';
        $newer['book_value_usd'] = 1104.6;
        $series = self::fixture('book_series');
        $series[] = ['as_of' => '2037-10-26T08:05+01:00', 'book_value_usd' => 1104.6, 'cash_usd' => 190.1, 'source' => 'desk-run-2037-10-26-0805.json'];
        $series[0]['cash_usd'] = 1000.5; // a corrected value on an existing point

        $this->send(self::body($newer, $series))
            ->assertOk()
            ->assertJsonPath('data.snapshot', 'replaced')
            ->assertJsonPath('data.series', ['received' => 121, 'inserted' => 1, 'updated' => 1, 'unchanged' => 119, 'total' => 121]);
        $this->assertSame(1104.6, PolymarketSnapshot::query()->sole()->payload['book_value_usd']);
        $this->assertSame(1000.5, (float) PolymarketSeriesPoint::query()->orderBy('ts')->first()->cash_usd);

        $older = self::fixture('feed');
        $older['as_of'] = '2037-10-25T18:13+01:00';
        $this->send(self::body($older, []))
            ->assertOk()
            ->assertJsonPath('data.snapshot', 'kept_newer')
            ->assertJsonPath('data.series.received', 0);
        $row = PolymarketSnapshot::query()->sole();
        $this->assertSame('2037-10-26T08:05+01:00', $row->as_of_raw);
        $this->assertSame(1104.6, $row->payload['book_value_usd']);
    }

    #[Test]
    public function history_fills_stop_usd_and_adds_points_missing_from_series(): void
    {
        $snap = self::fixture('feed');
        $snap['history'] = [
            ['ts' => '2037-10-03T16:05+02:00', 'book_value_usd' => 1000.5, 'stop_usd' => 450.25],
            ['ts' => '2037-10-03T18:05+02:00', 'book_value_usd' => 1000.5, 'stop_usd' => 410],
        ];
        $series = [['as_of' => '2037-10-03T16:05+02:00', 'book_value_usd' => 1000.5, 'cash_usd' => null, 'source' => 'a.json']];

        $this->send(self::body($snap, $series))
            ->assertCreated()
            ->assertJsonPath('data.series', ['received' => 1, 'inserted' => 2, 'updated' => 0, 'unchanged' => 0, 'total' => 2]);
        $pts = PolymarketSeriesPoint::query()->orderBy('ts')->get();
        $this->assertSame(450.25, (float) $pts[0]->stop_usd);
        $this->assertSame('a.json', $pts[0]->source);
        $this->assertSame(410.0, (float) $pts[1]->stop_usd);
        $this->assertSame(1000.5, (float) $pts[1]->book_value_usd);
        $this->assertNull($pts[1]->cash_usd);

        // A later post without history keeps the stops; a changed history stop updates just that column.
        $this->send(self::body(array_diff_key($snap, ['history' => 1]), $series))
            ->assertOk()->assertJsonPath('data.series.unchanged', 1);
        $this->assertSame(450.25, (float) PolymarketSeriesPoint::query()->orderBy('ts')->first()->stop_usd);

        $snap['history'][0]['stop_usd'] = null;
        $this->send(self::body($snap, $series))
            ->assertOk()
            ->assertJsonPath('data.series', ['received' => 1, 'inserted' => 0, 'updated' => 1, 'unchanged' => 1, 'total' => 2]);
        $this->assertNull(PolymarketSeriesPoint::query()->orderBy('ts')->first()->stop_usd);
    }

    #[Test]
    public function older_latest_json_shape_is_accepted(): void
    {
        $this->send(self::body(self::fixture('latest'), []))
            ->assertCreated()
            ->assertJsonPath('data.snapshot', 'created')
            ->assertJsonPath('data.series.total', 0);
        $this->assertSame(8, count(PolymarketSnapshot::query()->sole()->payload['positions']));
    }

    #[Test]
    public function unknown_snapshot_keys_are_stored_as_is(): void
    {
        $snap = self::fixture('feed');
        $snap['schema_version'] = 2;
        $snap['positions'][0]['new_field'] = ['x' => 1];
        $this->send(self::body($snap, []))->assertCreated();
        $payload = PolymarketSnapshot::query()->sole()->payload;
        $this->assertSame(2, $payload['schema_version']);
        $this->assertSame(['x' => 1], $payload['positions'][0]['new_field']);
    }

    public static function badPayloads(): array
    {
        $feed = fn (callable $m) => fn () => self::body($m(self::fixture('feed')));
        $series = fn (callable $m) => fn () => self::body(null, $m(self::fixture('book_series')));

        return [
            'not json' => [fn () => '{"snapshot":', 'body'],
            'array body' => [fn () => '[]', 'body'],
            'missing series' => [fn () => json_encode(['snapshot' => self::fixture('feed')]), 'series'],
            'unknown top key' => [fn () => json_encode(['snapshot' => self::fixture('feed'), 'series' => [], 'x' => 1]), 'x'],
            'snapshot not object' => [fn () => json_encode(['snapshot' => [1, 2], 'series' => []]), 'snapshot'],
            'as_of without offset' => [$feed(fn ($d) => ['as_of' => '2037-10-25T21:52'] + $d), 'snapshot.as_of'],
            'missing as_of' => [$feed(fn ($d) => array_diff_key($d, ['as_of' => 1])), 'snapshot.as_of'],
            'book value string' => [$feed(fn ($d) => ['book_value_usd' => '1093.30'] + $d), 'snapshot.book_value_usd'],
            'missing positions' => [$feed(fn ($d) => array_diff_key($d, ['positions' => 1])), 'snapshot.positions'],
            'positions object' => [$feed(fn ($d) => ['positions' => ['a' => 1]] + $d), 'snapshot.positions'],
            'position without market' => [$feed(function ($d) {
                unset($d['positions'][2]['market']);

                return $d;
            }), 'snapshot.positions.2.market'],
            'position numeric string' => [$feed(function ($d) {
                $d['positions'][0]['value_usd'] = '12.34';

                return $d;
            }), 'snapshot.positions.0.value_usd'],
            'cash string' => [$feed(fn ($d) => ['cash_usd' => 'n/a'] + $d), 'snapshot.cash_usd'],
            'gateway not object' => [$feed(fn ($d) => ['gateway' => 'ok'] + $d), 'snapshot.gateway'],
            'geoblock string' => [$feed(function ($d) {
                $d['gateway']['geoblock'] = 'no';

                return $d;
            }), 'snapshot.gateway.geoblock'],
            'fill bad ts' => [$feed(function ($d) {
                $d['fills'][1]['ts'] = 'yesterday';

                return $d;
            }), 'snapshot.fills.1.ts'],
            'sweep missing amount' => [$feed(function ($d) {
                unset($d['sweeps_detail'][0]['amount_usd']);

                return $d;
            }), 'snapshot.sweeps_detail.0.amount_usd'],
            'history stop string' => [$feed(function ($d) {
                $d['history'][3]['stop_usd'] = 'none';

                return $d;
            }), 'snapshot.history.3.stop_usd'],
            'series not array' => [fn () => json_encode(['snapshot' => self::fixture('feed'), 'series' => ['a' => 1]]), 'series'],
            'series point without offset' => [$series(function ($s) {
                $s[5]['as_of'] = '2037-10-04 10:05';

                return $s;
            }), 'series.5.as_of'],
            'series book null' => [$series(function ($s) {
                $s[7]['book_value_usd'] = null;

                return $s;
            }), 'series.7.book_value_usd'],
            'series cash string' => [$series(function ($s) {
                $s[9]['cash_usd'] = '1.00';

                return $s;
            }), 'series.9.cash_usd'],
            'series value too large' => [$series(function ($s) {
                $s[0]['book_value_usd'] = 1e12;

                return $s;
            }), 'series.0.book_value_usd'],
        ];
    }

    #[Test]
    #[DataProvider('badPayloads')]
    public function bad_payload_is_422_and_writes_nothing(callable $make, string $errorKey): void
    {
        $this->send($make())
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors([$errorKey]);
        $this->assertSame(0, PolymarketSnapshot::count());
        $this->assertSame(0, PolymarketSeriesPoint::count());
    }

    #[Test]
    public function too_many_series_points_is_422(): void
    {
        $series = array_fill(0, 20001, ['as_of' => '2037-10-03T16:05+02:00', 'book_value_usd' => 1]);
        $this->artisanImport(self::fixture('feed'), $series)->assertExitCode(1);
        $this->assertSame(0, PolymarketSeriesPoint::count());
    }

    private function artisanImport(array $snapshot, array $series, bool $dry = false): PendingCommand
    {
        $dir = sys_get_temp_dir().'/pm-import-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/feed.json", json_encode($snapshot));
        file_put_contents("$dir/series.json", json_encode($series));

        return $this->artisan('bridge:polymarket-import', ['snapshot' => "$dir/feed.json", 'series' => "$dir/series.json", '--dry-run' => $dry]);
    }

    #[Test]
    public function artisan_import_and_dry_run(): void
    {
        $args = ['snapshot' => self::fixturePath('feed'), 'series' => self::fixturePath('book_series')];

        $this->artisan('bridge:polymarket-import', $args + ['--dry-run' => true])
            ->expectsOutputToContain('dry run, nothing written · would be: snapshot created (as_of 2037-10-25T20:47+01:00) · series: 120 received, 120 inserted')
            ->assertExitCode(0);
        $this->assertSame(0, PolymarketSnapshot::count());
        $this->assertSame(0, PolymarketSeriesPoint::count());

        $this->artisan('bridge:polymarket-import', $args)
            ->expectsOutputToContain('snapshot created (as_of 2037-10-25T20:47+01:00)')
            ->assertExitCode(0);
        $this->assertSame(1, PolymarketSnapshot::count());
        $this->assertSame(120, PolymarketSeriesPoint::count());

        $this->artisan('bridge:polymarket-import', $args)
            ->expectsOutputToContain('snapshot unchanged (as_of 2037-10-25T20:47+01:00) · series: 120 received, 0 inserted, 0 updated, 120 unchanged, 120 total')
            ->assertExitCode(0);

        // Same importer as the API: a post of the same files is unchanged too.
        $this->send(self::body())->assertOk()->assertJsonPath('data.snapshot', 'unchanged');

        $this->artisan('bridge:polymarket-import', ['snapshot' => '/nonexistent.json', 'series' => self::fixturePath('book_series')])
            ->assertExitCode(1);
        $this->artisanImport(['as_of' => 'nope'], [])->expectsOutputToContain('snapshot.as_of')->assertExitCode(1);
    }

    #[Test]
    public function dry_run_on_existing_data_writes_nothing(): void
    {
        $this->send(self::body())->assertCreated();
        $stamps = $this->pointStamps();
        $newer = self::fixture('feed');
        $newer['as_of'] = '2037-10-26T08:05+01:00';
        $this->artisanImport($newer, [['as_of' => '2037-10-26T08:05+01:00', 'book_value_usd' => 1010]], true)
            ->expectsOutputToContain('would be: snapshot replaced (as_of 2037-10-26T08:05+01:00) · series: 1 received, 1 inserted')
            ->assertExitCode(0);
        $this->assertSame('2037-10-25T20:47+01:00', PolymarketSnapshot::query()->sole()->as_of_raw);
        $this->assertSame($stamps, $this->pointStamps());
    }
}
