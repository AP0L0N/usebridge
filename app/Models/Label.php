<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Global ticket label (reused across projects). */
class Label extends Model
{
    use BelongsToWorkspace;

    public const COLORS = ['navy', 'green', 'amber', 'rose', 'violet', 'grey'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'color'];

    protected $attributes = ['color' => 'grey'];

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_label');
    }

    public function toView(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
