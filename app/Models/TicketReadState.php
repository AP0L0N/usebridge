<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/** When a Bridge user (only 'tilen' for now) last opened a ticket; drives unread_for_tilen. */
class TicketReadState extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
