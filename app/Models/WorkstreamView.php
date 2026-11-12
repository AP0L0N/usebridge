<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When a user last opened a workstream's board: its badge counts the changes after this (README §2). */
class WorkstreamView extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_viewed_at' => 'datetime'];
    }
}
