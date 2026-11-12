<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/** Append-only workstream audit (README §5): created | renamed | deleted | merged, by a user or an agent. */
class WorkstreamEvent extends Model
{
    use BelongsToWorkspace;

    public const TYPES = ['created', 'renamed', 'deleted', 'merged'];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'ticket_count' => 'integer'];
    }
}
