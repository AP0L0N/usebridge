<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequiredInput extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'status_update_id', 'text', 'waiting_on', 'since', 'done_at', 'done_by'];

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return $this->done_at === null;
    }
}
