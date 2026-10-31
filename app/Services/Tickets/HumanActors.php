<?php

namespace App\Services\Tickets;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Support\Facades\Auth;

/**
 * The human (web UI) side of a workspace. A signed-in user writes as 'user:<users.id>'. Exception (pre-multi-user
 * data): the OWNER of the legacy default workspace (bridge.default_workspace) keeps the old id 'tilen', so existing
 * rows, read states and unread logic stay his. 'tilen' is labelled with the owner's first name (fallback "Owner").
 * "Owner input" (waiting_tilen, tilen_input_at) is input from any human of the workspace; the column is named after
 * the workspace owner.
 */
final class HumanActors
{
    public const USER_PREFIX = 'user:';

    /** $workspace, else the current one, else the default (console). */
    public static function workspace(?Workspace $workspace = null): ?Workspace
    {
        $current = app(CurrentWorkspace::class);

        return $workspace ?? $current->get() ?? $current->default();
    }

    /** The workspace holding all pre-multi-user data (its owner keeps the legacy id 'tilen'). */
    public static function isLegacyWorkspace(?Workspace $workspace): bool
    {
        return $workspace !== null && $workspace->slug === (string) (config('bridge.default_workspace') ?: 'main');
    }

    /** Actor id of $user writing in $workspace (default: the current one). */
    public static function idFor(User $user, ?Workspace $workspace = null): string
    {
        $workspace = self::workspace($workspace);

        return self::isLegacyWorkspace($workspace) && $workspace->isOwnedBy($user) ? Actor::TILEN : self::USER_PREFIX.$user->id;
    }

    /** Actor id of the workspace owner (whom the waiting_tilen column waits on); 'tilen' without a workspace. */
    public static function ownerId(?Workspace $workspace = null): string
    {
        $workspace = self::workspace($workspace);
        if ($workspace === null || self::isLegacyWorkspace($workspace) || $workspace->owner_user_id === null) {
            return Actor::TILEN;
        }

        return self::USER_PREFIX.$workspace->owner_user_id;
    }

    /**
     * The human reading: the signed-in web user when they belong to the workspace, else (API / MCP / console) the
     * workspace owner. Drives read states and unread_for_me.
     */
    public static function viewerId(?Workspace $workspace = null): string
    {
        $workspace = self::workspace($workspace);
        $user = Auth::guard('web')->user();
        if ($user instanceof User && $workspace !== null && $workspace->hasMember($user)) {
            return self::idFor($user, $workspace);
        }

        return self::ownerId($workspace);
    }

    /** 'owner' or the legacy 'tilen' (any case, as assignee / filter shorthand) -> the owner's actor id; else null. */
    public static function ownerAlias(?string $text): ?string
    {
        $text = trim((string) $text);

        return strcasecmp($text, 'owner') === 0 || strcasecmp($text, Actor::TILEN) === 0 ? self::ownerId() : null;
    }

    /** A 'user:<id>' of someone in the workspace (owner or member). */
    public static function isMemberId(string $id, ?Workspace $workspace = null): bool
    {
        return self::isUserId($id) && collect(self::users($workspace))->contains('id', (int) substr($id, strlen(self::USER_PREFIX)));
    }

    public static function isUserId(?string $id): bool
    {
        return $id !== null && preg_match('/^user:\d+$/', $id) === 1;
    }

    /** 'tilen' or 'user:<id>' (agents, 'migration' and free-text assignees are not). */
    public static function isHumanId(?string $id): bool
    {
        return $id === Actor::TILEN || self::isUserId($id);
    }

    /** "Sam Rivera" -> "Sam". */
    public static function firstName(?string $name): ?string
    {
        $first = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY)[0] ?? null;

        return $first === null || $first === '' ? null : $first;
    }

    /** The workspace owner's first name; "Owner" when there is none. */
    public static function ownerFirstName(?Workspace $workspace = null): string
    {
        return self::firstName(self::workspace($workspace)?->owner?->name) ?? 'Owner';
    }

    /** Default name of the waiting_tilen column: "Waiting on <owner's first name>", else "Waiting on you". */
    public static function defaultWaitingLabel(?Workspace $workspace): string
    {
        $first = self::firstName($workspace?->owner?->name);

        return $first === null ? 'Waiting on you' : 'Waiting on '.$first;
    }

    /** @return list<User> the owner and the members of the workspace */
    public static function users(?Workspace $workspace = null): array
    {
        $workspace = self::workspace($workspace);
        if ($workspace === null) {
            return [];
        }
        $users = $workspace->users()->orderBy('users.name')->get();
        if ($workspace->owner && ! $users->contains('id', $workspace->owner->id)) {
            $users->prepend($workspace->owner);
        }

        return $users->values()->all();
    }

    /**
     * @return array<string, string> human actor id => label: 'user:<id>' => name; in the legacy default workspace also
     *                               'tilen' => the owner's first name (elsewhere Actor::label still resolves it)
     */
    public static function names(?Workspace $workspace = null): array
    {
        $workspace = self::workspace($workspace);
        $names = self::isLegacyWorkspace($workspace) ? [Actor::TILEN => self::ownerFirstName($workspace)] : [];
        foreach (self::users($workspace) as $user) {
            $names[self::USER_PREFIX.$user->id] = (string) $user->name;
        }

        return $names;
    }

    /** Label of a 'user:<id>' not in names() (e.g. a former member). */
    public static function userLabel(string $id): string
    {
        $user = User::withTrashed()->find((int) substr($id, strlen(self::USER_PREFIX)));

        return $user !== null && $user->deleted_at === null ? (string) $user->name : 'Former member';
    }
}
