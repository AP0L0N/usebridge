<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milestone extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'title', 'due_at', 'status'];

    protected function casts(): array
    {
        return ['due_at' => 'date:Y-m-d'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
