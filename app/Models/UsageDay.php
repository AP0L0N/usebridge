<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * One estimator file (data/YYYY-MM-DD.json). All figures are estimates; see Services\Usage.
 * `date` stays a plain Y-m-d string so lookups match on SQLite and MySQL alike.
 */
class UsageDay extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['date', 'generated_at', 'est_tokens_total', 'payload'];

    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'est_tokens_total' => 'integer',
            'payload' => 'array',
        ];
    }
}
