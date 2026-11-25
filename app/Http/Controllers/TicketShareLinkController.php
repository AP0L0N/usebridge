<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Models\Ticket;
use App\Models\TicketShareLink;
use App\Services\Tickets\Actor;
use App\Services\Tickets\ShareLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Public share links of a ticket, from the ticket drawer's sidebar (logged-in session, CSRF): list, create, edit (expiry,
 * visitor comments) and revoke. Only the workspace owner (Gate 'share-ticket'): anyone else gets a 403, and API / MCP
 * tokens never reach these web routes. Create body: {expires_in_days 1–365 (default 7) | never_expires, allow_comments}.
 */
class TicketShareLinkController extends Controller
{
    use FindsTickets;

    public function index(string $slug, string $key): JsonResponse
    {
        $ticket = $this->sharedTicket($slug, $key);

        return response()->json(['data' => ShareLinks::list($ticket)->map(fn (TicketShareLink $l) => ShareLinks::present($l))->values()->all()]);
    }

    public function store(Request $request, string $slug, string $key): JsonResponse
    {
        $ticket = $this->sharedTicket($slug, $key);
        $data = $request->validate([...self::expiryRules(), 'allow_comments' => ['sometimes', 'boolean']]);
        [$link] = ShareLinks::create($ticket, self::days($data, ShareLinks::DEFAULT_DAYS), (bool) ($data['allow_comments'] ?? false), $this->actor($request));

        return response()->json(['data' => ShareLinks::present($link)], 201);
    }

    /** {expires_in_days | never_expires} sets a new expiry from now; {allow_comments} turns visitor comments on or off. */
    public function update(Request $request, string $slug, string $key, string $id): JsonResponse
    {
        $link = $this->link($this->sharedTicket($slug, $key), $id);
        $data = $request->validate([...self::expiryRules(), 'allow_comments' => ['sometimes', 'boolean']]);
        if (array_key_exists('expires_in_days', $data) || ! empty($data['never_expires'])) {
            $link->expires_at = ShareLinks::expiry(self::days($data, ShareLinks::DEFAULT_DAYS));
        }
        if (array_key_exists('allow_comments', $data)) {
            $link->allow_comments = (bool) $data['allow_comments'];
        }
        $link->save();

        return response()->json(['data' => ShareLinks::present($link)]);
    }

    /** Revoke: the link stops working at once (and for good) and leaves the list. */
    public function destroy(string $slug, string $key, string $id): JsonResponse
    {
        $link = $this->link($this->sharedTicket($slug, $key), $id);
        $link->revoked_at ??= Carbon::now();
        $link->save();

        return response()->json(['data' => ['id' => $link->id, 'revoked' => true]]);
    }

    private function sharedTicket(string $slug, string $key): Ticket
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));
        Gate::authorize('share-ticket', $ticket);

        return $ticket;
    }

    private function link(Ticket $ticket, string $id): TicketShareLink
    {
        return TicketShareLink::query()->where('ticket_id', $ticket->id)->whereNull('revoked_at')->findOrFail($id);
    }

    /** @return array<string, list<string>> */
    private static function expiryRules(): array
    {
        return [
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.ShareLinks::MAX_DAYS, 'prohibited_if_accepted:never_expires'],
            'never_expires' => ['sometimes', 'boolean'],
        ];
    }

    /** null = never expires. */
    private static function days(array $data, int $default): ?int
    {
        if (! empty($data['never_expires'])) {
            return null;
        }

        return isset($data['expires_in_days']) ? (int) $data['expires_in_days'] : $default;
    }

    private function actor(Request $request): Actor
    {
        return Actor::forUser($request->user());
    }
}
