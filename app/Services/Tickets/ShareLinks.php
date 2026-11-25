<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketShareLink;
use App\Support\TicketRefs;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * Public ticket share links (GET /s/{token}). The token is 32 random bytes, base64url (43 characters); only its sha256
 * is looked up (token_hash), and an APP_KEY-encrypted copy (token_encrypted) lets the workspace owner copy the URL
 * again. Managing links is owner only (Gate 'share-ticket', web session only). The public payload is built from an
 * explicit allowlist (publicView), never by serializing a model.
 */
final class ShareLinks
{
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 365;

    public const NAME_MAX = 60;

    public const BODY_MAX = 5000;

    /** A public comment form must be open at least this long before it is sent (anti-spam), and at most a day. */
    public const MIN_SUBMIT_SECONDS = 3;

    public const MAX_FORM_AGE_SECONDS = 86400;

    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/';

    /** 32 random bytes, base64url without padding. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('bridge.public_url'), '/').'/s/'.$token;
    }

    /** $days null = never expires. @return array{0: TicketShareLink, 1: string} the link and its plain token */
    public static function create(Ticket $ticket, ?int $days, bool $allowComments, Actor $actor): array
    {
        $token = self::newToken();
        $link = new TicketShareLink;
        $link->forceFill([
            'workspace_id' => $ticket->workspace_id,
            'ticket_id' => $ticket->id,
            'token_hash' => self::hash($token),
            'token_encrypted' => $token,
            'expires_at' => self::expiry($days),
            'allow_comments' => $allowComments,
            'created_by' => $actor->id,
        ])->save();

        return [$link, $token];
    }

    public static function expiry(?int $days): ?Carbon
    {
        return $days === null ? null : Carbon::now()->addDays($days);
    }

    /** The ticket's links the owner still manages (not revoked; expired ones stay listed so they can be extended). */
    public static function list(Ticket $ticket): Collection
    {
        return TicketShareLink::query()->where('ticket_id', $ticket->id)->whereNull('revoked_at')->orderBy('id')->get();
    }

    /** One link for the owner's list. */
    public static function present(TicketShareLink $link): array
    {
        return [
            'id' => $link->id,
            'url' => self::url((string) $link->token_encrypted),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'active' => $link->isActive(),
            'allow_comments' => $link->allow_comments,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }

    /**
     * The active link for a token whose ticket may be shown (not archived, project neither deleted nor archived), with
     * the request now acting in the link's workspace; null otherwise. Unknown, expired and revoked all look the same.
     */
    public static function resolve(string $token): ?TicketShareLink
    {
        if (preg_match(self::TOKEN, $token) !== 1) {
            return null;
        }
        $link = TicketShareLink::acrossWorkspaces()->where('token_hash', self::hash($token))->active()->first();
        if ($link === null) {
            return null;
        }
        app(CurrentWorkspace::class)->set($link->workspace);
        $ticket = $link->ticket;
        if ($ticket === null || $ticket->archived_at !== null || $ticket->project === null || $ticket->project->trashed() || $ticket->project->isArchived()) {
            return null;
        }

        return $link;
    }

    /**
     * Everything a visitor sees: project name, workstream, column name, title, description and the live comments (body
     * and author name only). Ticket references become plain keys; owner input markers, asks, assignee, agents' ids and
     * sources, other tickets and app URLs are never part of it.
     *
     * @return array{project: string, workstream: ?string, column: string, title: string, description: ?string, comments: list<array{id: int, author: string, visitor: bool, body: string, created_at: ?string}>, comments_enabled: bool}
     */
    public static function publicView(TicketShareLink $link): array
    {
        $ticket = $link->ticket;
        $project = $ticket->project;
        $view = new TicketView;
        $column = collect($project->boardColumns())->firstWhere('key', $ticket->status);
        $comments = TicketComment::query()->where('ticket_id', $ticket->id)->whereNull('retracted_at')->orderBy('id')->get();

        return [
            'project' => (string) $project->name,
            'workstream' => $ticket->workstream?->name,
            'column' => (string) ($column['label'] ?? $ticket->status),
            'title' => (string) $ticket->title,
            'description' => TicketRefs::expand($ticket->description),
            'comments' => $comments->map(fn (TicketComment $c) => [
                'id' => $c->id,
                'author' => $c->isPublic() ? (string) $c->public_name : (string) ($view->name($c->author_id) ?? 'Team'),
                'visitor' => $c->isPublic(),
                'body' => (string) TicketRefs::expand($c->body),
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'comments_enabled' => $link->allow_comments,
        ];
    }

    /** Encrypted "form opened at" stamp for the minimum time-to-submit check, bound to the link. */
    public static function formStamp(TicketShareLink $link): string
    {
        return Crypt::encryptString($link->id.'|'.Carbon::now()->getTimestamp());
    }

    /** null = fine; otherwise why the form is refused ('fast' = sent too soon, 'stale' = missing, foreign or expired). */
    public static function checkFormStamp(TicketShareLink $link, mixed $stamp): ?string
    {
        try {
            [$id, $at] = explode('|', Crypt::decryptString((string) $stamp), 2) + [null, null];
        } catch (\Throwable) {
            return 'stale';
        }
        if ((int) $id !== $link->id || ! is_numeric($at)) {
            return 'stale';
        }
        $age = Carbon::now()->getTimestamp() - (int) $at;
        if ($age < self::MIN_SUBMIT_SECONDS) {
            return 'fast';
        }

        return $age > self::MAX_FORM_AGE_SECONDS ? 'stale' : null;
    }

    /** A visitor's name as stored: control characters dropped, whitespace collapsed (rendered as text, never HTML). */
    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name) ?? '') ?? '');
    }
}
