<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A slice of a project's board (portfolio-workstreams README §3–§5). Every project has exactly one is_default
 * ("General" at first); tickets without a workstream land there. Deleting is soft: the name stays for history and for
 * agents that still ask for it (renamed_from holds older names, workstream_events the full log).
 */
class Workstream extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    public const DEFAULT_NAME = 'General';

    public const NAME_MAX = 60;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
            'renamed_from' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
