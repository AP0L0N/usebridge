<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The current Polymarket desk snapshot (feed.json without `history`). At most one row.
 * `as_of` / `received_at` are UTC 'Y-m-d H:i:s' strings (the app timezone is Europe/Ljubljana,
 * so they are not cast: a cast would read them back as local time).
 */
class PolymarketSnapshot extends Model
{
    protected $fillable = ['as_of', 'as_of_raw', 'payload', 'payload_sha256', 'received_at'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function asOf(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $this->as_of, 0, 19), 'UTC');
    }
}
