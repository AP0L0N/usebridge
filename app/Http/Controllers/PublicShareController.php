<?php

namespace App\Http\Controllers;

use App\Services\Tickets\ShareLinks;
use App\Services\Tickets\TicketService;
use App\Support\Legal\LegalPages;
use App\Support\LinkPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public, read-only ticket page behind a share link (GET /s/{token}, no login) and its visitor comments
 * (POST /s/{token}/comments, CSRF). An unknown, expired or revoked token, or a ticket that may no longer be shown, all
 * answer the same 404 page, so a visitor can never tell whether a ticket exists. The page gets only
 * ShareLinks::publicView (an allowlist) and the AGPL source line, rendered without the app shell (root view "share").
 */
class PublicShareController extends Controller
{
    public function show(string $token): InertiaResponse|Response
    {
        $link = ShareLinks::resolve($token);
        if ($link === null) {
            return self::unavailable();
        }

        $share = ShareLinks::publicView($link);

        return Inertia::render('Share/Show', [
            'share' => $share,
            // The comment form's "opened at" stamp (minimum time to submit); null while visitor comments are off.
            'stamp' => $link->allow_comments ? ShareLinks::formStamp($link) : null,
            'limits' => ['name' => ShareLinks::NAME_MAX, 'body' => ShareLinks::BODY_MAX],
            // The page's only footer content: the © line and the AGPL source link (no app navigation).
            'legal' => LegalPages::sourceFooter(),
            // Link preview of a valid public link: the ticket title and a plain-text summary of its description.
        ])->withViewData('preview', LinkPreview::meta($share['title'], LinkPreview::summary($share['description']) ?: 'A ticket shared from Bridge.', '/s/'.$token));
    }

    /**
     * {name*, body*, stamp* (from the page), website (honeypot: must stay empty)}. Comments off: 403. A filled honeypot
     * looks like success but stores nothing; a form sent within MIN_SUBMIT_SECONDS of opening is refused.
     */
    public function comment(Request $request, TicketService $tickets, string $token): RedirectResponse|JsonResponse|Response
    {
        $link = ShareLinks::resolve($token);
        if ($link === null) {
            return self::unavailable();
        }
        abort_unless($link->allow_comments, 403, 'Comments are turned off for this link.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.ShareLinks::NAME_MAX],
            'body' => ['required', 'string', 'max:'.ShareLinks::BODY_MAX],
            'stamp' => ['required', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:200'],
        ]);
        if (($data['website'] ?? null) === null) {
            $problem = ShareLinks::checkFormStamp($link, $data['stamp']);
            if ($problem !== null) {
                throw ValidationException::withMessages(['body' => $problem === 'fast'
                    ? 'That was quick. Wait a few seconds and send it again.'
                    : 'This form has expired. Reload the page and try again.']);
            }
            $name = ShareLinks::cleanName($data['name']);
            $body = TicketService::clean($data['body']);
            if ($name === '' || $body === null) {
                throw ValidationException::withMessages($name === '' ? ['name' => 'Enter your name.'] : ['body' => 'Write a comment.']);
            }
            $tickets->publicComment($link->ticket, $link, $name, $body);
        }

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['data' => ['posted' => true]], 201)
            : redirect()->to('/s/'.$token, 303);
    }

    /** The one answer for every token that does not open a ticket (unknown, expired, revoked, ticket gone). */
    public static function unavailable(): Response
    {
        return response()->view('share.unavailable', ['legal' => LegalPages::sourceFooter()], 404);
    }
}
