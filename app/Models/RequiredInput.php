<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequiredInput extends Model
{
    use BelongsToWorkspace;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'status_update_id', 'text', 'waiting_on', 'since', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'resolved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
