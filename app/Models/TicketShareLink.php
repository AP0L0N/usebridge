<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public, read-only link to one ticket (GET /s/{token}; App\Services\Tickets\ShareLinks). Only the sha256 of the token
 * is looked up (token_hash); token_encrypted (APP_KEY) lets the workspace owner copy the URL again. Active = not revoked
 * and not expired (expires_at null = never expires).
 */
class TicketShareLink extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'allow_comments' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
