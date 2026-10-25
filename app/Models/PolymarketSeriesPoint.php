<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One desk run on the book value chart. `ts` is a UTC 'Y-m-d H:i:s' string (unique, not cast, see
 * PolymarketSnapshot); money columns are decimal(12,2) and come back as strings.
 */
class PolymarketSeriesPoint extends Model
{
    protected $fillable = ['ts', 'ts_raw', 'book_value_usd', 'cash_usd', 'stop_usd', 'source'];

    public function instant(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $this->ts, 0, 19), 'UTC');
    }
}
