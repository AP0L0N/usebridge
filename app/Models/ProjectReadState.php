<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When a Bridge user last opened a project (and saw its status); drives the status-change toast. */
class ProjectReadState extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
