<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/** When a Bridge user (their actor id: 'user:<id>', or the legacy 'tilen') last opened a ticket; drives unread_for_tilen. */
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
