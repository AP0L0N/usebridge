<?php

namespace App\Services\Agents;

use App\Models\AgentInstructionEdit;
use App\Models\AgentProfile;
use App\Models\UsageDay;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tickets\HumanActors;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Agent instructions (docs/agent-instructions-sync.md): the profiles the daily sync pushes, and the owner's proposed
 * descriptions. Saving in Bridge never changes the agent; the sync applies a pending edit to the real profile and
 * reports applied / rejected / conflict. Bridge's copy of the description only changes through ingest(), syncRoster()
 * (the same ingest step, docs/agents-sync.md) and applied().
 * Every write runs serialised per workspace (see serialised()); a hash the sync reports is always compared, never stored blindly.
 */
final class AgentInstructions
{
    public const EXCERPT = 160;

    public const HISTORY = 5;

    /** Between name and title in a roster full_name ("Shodan · Lead Developer"). */
    public const TITLE_SEPARATOR = ' · ';

    /** Tries of a write that lost a race on a unique key (revision, profile) before the violation is rethrown. */
    public const ATTEMPTS = 3;

    /**
     * Upsert profiles (name, title, description only), then the conflict rule for each agent's pending edit: the new
     * description equals the proposed text -> applied; its hash differs from the edit's base_hash -> conflict.
     * Never touches the roster columns (full_name, section, section_order, listed).
     *
     * @param  list<array{agent_id: string, name: string, title?: ?string, description?: ?string}>  $profiles
     * @return list<array{agent_id: string, description_hash: string, pending_edit_status: ?string}>
     */
    public function ingest(array $profiles): array
    {
        return $this->serialised(function () use ($profiles): array {
            $now = now();

            return array_map(fn (array $p): array => $this->ingestOne($p, $now), $profiles);
        });
    }

    /**
     * The roster sync (POST /api/agents/sync, docs/agents-sync.md): a full snapshot of the real agents. Each agent goes
     * through the same ingest step as POST /api/agent-profiles (description, hash, conflict rule for its pending edit)
     * and is stored with full_name, section, section_order and listed. Listed profiles missing from the snapshot become
     * unlisted (kept, with their edits). "unchanged": an already listed row with the same full_name, section,
     * section_order and description.
     *
     * @param  list<array{agent_id: string, full_name: string, section: string, section_order: int, description?: ?string}>  $agents
     * @return array{received: int, created: int, updated: int, unchanged: int, unlisted: int, sections: list<string>, agents: list<array{agent_id: string, result: string, description_hash: string, pending_edit_status: ?string}>}
     */
    public function syncRoster(array $agents): array
    {
        return $this->serialised(function () use ($agents): array {
            $now = now();
            $ids = array_column($agents, 'agent_id');
            $before = AgentProfile::query()->whereIn('agent_id', $ids)->get()->keyBy('agent_id');
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $results = [];

            foreach ($agents as $a) {
                $description = $a['description'] ?? '';
                /** @var AgentProfile|null $old */
                $old = $before->get($a['agent_id']);
                $result = match (true) {
                    $old === null => 'created',
                    $old->listed && $old->full_name === $a['full_name'] && $old->section === $a['section']
                        && $old->section_order === $a['section_order'] && (string) $old->description === $description => 'unchanged',
                    default => 'updated',
                };
                $counts[$result]++;

                [$name, $title] = self::splitFullName($a['full_name']);
                $ingested = $this->ingestOne(['agent_id' => $a['agent_id'], 'name' => $name, 'title' => $title, 'description' => $description], $now, [
                    'full_name' => $a['full_name'],
                    'section' => $a['section'],
                    'section_order' => $a['section_order'],
                    'listed' => true,
                    'roster_synced_at' => $now,
                ]);
                $results[] = ['agent_id' => $a['agent_id'], 'result' => $result, ...array_diff_key($ingested, ['agent_id' => true])];
            }

            $unlisted = AgentProfile::query()->where('listed', true)->whereNotIn('agent_id', $ids)->update(['listed' => false]);

            return [
                'received' => count($agents),
                ...$counts,
                'unlisted' => $unlisted,
                'sections' => self::orderSections(array_map(fn (array $a): array => [$a['section'], $a['section_order']], $agents)),
                'agents' => $results,
            ];
        });
    }

    /**
     * "Shodan · Lead Developer" -> ["Shodan", "Lead Developer"]; split on the first " · ". No separator: [full name, null].
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitFullName(string $fullName): array
    {
        $at = mb_strpos($fullName, self::TITLE_SEPARATOR);
        if ($at === false) {
            return [$fullName, null];
        }
        $name = mb_substr($fullName, 0, $at);
        $title = mb_substr($fullName, $at + mb_strlen(self::TITLE_SEPARATOR));

        return [$name === '' ? $fullName : $name, $title === '' ? null : $title];
    }

    /**
     * Section names ordered by their section_order (the lowest one an agent of that section carries), then by name.
     *
     * @param  list<array{0: string, 1: int}>  $pairs  [section, section_order]
     * @return list<string>
     */
    public static function orderSections(array $pairs): array
    {
        $orders = [];
        foreach ($pairs as [$section, $order]) {
            $orders[$section] = min($orders[$section] ?? $order, $order);
        }
        uksort($orders, fn (string $a, string $b): int => $orders[$a] <=> $orders[$b] ?: strcmp($a, $b));

        return array_keys($orders);
    }

    /** Save a new revision against the current profile; an older pending edit of this agent becomes superseded. */
    public function propose(User $user, string $agentId, string $description): AgentInstructionEdit
    {
        return $this->serialised(function () use ($user, $agentId, $description): AgentInstructionEdit {
            $profile = AgentProfile::query()->where('agent_id', $agentId)->first();
            $revision = (int) AgentInstructionEdit::query()->where('agent_id', $agentId)->max('revision') + 1;

            AgentInstructionEdit::query()->where('agent_id', $agentId)->where('status', AgentInstructionEdit::PENDING)
                ->get()->each(fn (AgentInstructionEdit $old) => $old->resolve(AgentInstructionEdit::SUPERSEDED, "replaced by revision $revision"));

            return AgentInstructionEdit::query()->create([
                'agent_id' => $agentId,
                'revision' => $revision,
                'base_hash' => $profile?->description_hash,
                'base_description' => $profile?->description,
                'proposed_description' => $description,
                'author_user_id' => $user->id,
                'author' => HumanActors::idFor($user),
                'status' => AgentInstructionEdit::PENDING,
            ]);
        });
    }

    /**
     * The sync applied the edit to the real profile: Bridge's profile copy takes the proposed text.
     * 422 (edit stays pending) unless $descriptionHash, the hash of the description now on the real profile, equals
     * sha256(proposed_description).
     */
    public function applied(AgentInstructionEdit $edit, string $descriptionHash): AgentInstructionEdit
    {
        return $this->transition($edit, function (AgentInstructionEdit $edit) use ($descriptionHash): void {
            $hash = $edit->proposedHash();
            if (! hash_equals($hash, $descriptionHash)) {
                throw ValidationException::withMessages(['description_hash' => 'The description_hash is not the sha256 of this edit\'s proposed_description.']);
            }
            $now = now();
            $values = ['description' => $edit->proposed_description, 'description_hash' => $hash, 'synced_at' => $now];
            $profile = AgentProfile::query()->firstOrCreate(['agent_id' => $edit->agent_id],
                [...$values, 'name' => $this->usageAgents()[$edit->agent_id] ?? $edit->agent_id]);
            $profile->fill($values)->save();
            $edit->resolve(AgentInstructionEdit::APPLIED, null, ['applied_hash' => $hash, 'applied_at' => $now]);
        });
    }

    public function rejected(AgentInstructionEdit $edit, ?string $reason): AgentInstructionEdit
    {
        return $this->transition($edit, fn (AgentInstructionEdit $e) => $e->resolve(AgentInstructionEdit::REJECTED, $reason));
    }

    /**
     * The sync found the real profile changed since the edit was made. $currentHash (sha256 of the real description) is
     * compared: equal to base_hash means unchanged (apply it), equal to the proposed hash means already applied; both
     * answer 422 and the edit stays pending. Otherwise conflict, with the reported hash kept in status_reason.
     */
    public function conflict(AgentInstructionEdit $edit, string $currentHash, ?string $reason): AgentInstructionEdit
    {
        return $this->transition($edit, function (AgentInstructionEdit $e) use ($currentHash, $reason): void {
            if ($e->base_hash !== null && hash_equals($e->base_hash, $currentHash)) {
                throw ValidationException::withMessages(['current_description_hash' => 'The profile has not changed since this edit was made (current_description_hash equals base_hash): apply the edit instead.']);
            }
            if (hash_equals($e->proposedHash(), $currentHash)) {
                throw ValidationException::withMessages(['current_description_hash' => 'The profile already has the proposed description (current_description_hash equals proposed_hash): report applied instead.']);
            }
            $e->resolve(AgentInstructionEdit::CONFLICT, self::conflictReason($reason, $currentHash));
        });
    }

    /** "<reason> (current_description_hash <hash>)", the reason shortened so it fits status_reason (255). */
    public static function conflictReason(?string $reason, string $currentHash): string
    {
        $suffix = " (current_description_hash $currentHash)";

        return mb_strimwidth($reason ?? AgentInstructionEdit::CONFLICT_REASON, 0, 255 - mb_strlen($suffix), '…').$suffix;
    }

    /**
     * Edits in one state for the sync, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function editsForApi(string $status): array
    {
        $names = AgentProfile::query()->pluck('name', 'agent_id');
        $usage = null;

        return AgentInstructionEdit::query()->where('status', $status)->orderBy('id')->get()
            ->map(function (AgentInstructionEdit $e) use ($names, &$usage): array {
                $name = $names[$e->agent_id] ?? ($usage ??= $this->usageAgents())[$e->agent_id] ?? $e->agent_id;

                return [
                    'id' => $e->id,
                    'agent_id' => $e->agent_id,
                    'agent_name' => $name,
                    'revision' => $e->revision,
                    'status' => $e->status,
                    'base_hash' => $e->base_hash,
                    'proposed_description' => $e->proposed_description,
                    'proposed_hash' => $e->proposedHash(),
                    'created_at' => $e->created_at?->toIso8601String(),
                    'author' => $e->author,
                ];
            })->all();
    }

    /**
     * The Agents page: only profiles the roster sync listed (never usage-only ids or legacy-ingest-only profiles), grouped
     * by section in section order (orderSections()), each section sorted by full name, case-insensitive, then id.
     *
     * @return list<array{name: string, agents: list<array{id: string, fullName: string, name: string, title: ?string, excerpt: ?string, syncedAt: ?string, editStatus: ?string}>}>
     */
    public function list(): array
    {
        $profiles = AgentProfile::query()->where('listed', true)->get();
        $latest = AgentInstructionEdit::query()->whereIn('agent_id', $profiles->pluck('agent_id'))->orderBy('revision')->get()->keyBy('agent_id');

        $sections = [];
        foreach ($profiles as $profile) {
            /** @var AgentInstructionEdit|null $edit */
            $edit = $latest->get($profile->agent_id);
            $excerpt = self::excerpt((string) $profile->description);
            $sections[(string) $profile->section][] = [
                'id' => $profile->agent_id,
                'fullName' => $profile->displayName(),
                'name' => $profile->name,
                'title' => $profile->title,
                'excerpt' => $excerpt === '' ? null : $excerpt,
                'syncedAt' => $profile->synced_at?->toIso8601String(),
                // Only what still needs the owner or the sync: a pending or conflicting latest revision.
                'editStatus' => in_array($edit?->status, [AgentInstructionEdit::PENDING, AgentInstructionEdit::CONFLICT], true) ? $edit->status : null,
            ];
        }

        $order = self::orderSections($profiles->map(fn (AgentProfile $p): array => [(string) $p->section, (int) $p->section_order])->all());

        return array_map(function (string $name) use ($sections): array {
            $rows = $sections[$name];
            usort($rows, fn (array $a, array $b): int => strcasecmp($a['fullName'], $b['fullName']) ?: strcmp($a['id'], $b['id']));

            return ['name' => $name, 'agents' => $rows];
        }, $order);
    }

    /** The agent has a profile or appears in a usage file of this workspace. */
    public function knows(string $agentId): bool
    {
        return AgentProfile::query()->where('agent_id', $agentId)->exists() || isset($this->usageAgents()[$agentId]);
    }

    public function profile(string $agentId): ?AgentProfile
    {
        return AgentProfile::query()->where('agent_id', $agentId)->first();
    }

    /**
     * The Instructions section of /usage/agents/{agentId}.
     *
     * @return array<string, mixed>
     */
    public function pageProps(string $agentId, bool $canEdit): array
    {
        $profile = $this->profile($agentId);
        $edits = AgentInstructionEdit::query()->with('authorUser:id,name')->where('agent_id', $agentId)
            ->orderByDesc('revision')->limit(self::HISTORY)->get();

        return [
            'canEdit' => $canEdit,
            'profile' => $profile === null ? null : [
                'name' => $profile->name,
                'title' => $profile->title,
                'fullName' => $profile->displayName(),
                'description' => (string) $profile->description,
                'hash' => $profile->description_hash,
                'syncedAt' => $profile->synced_at?->toIso8601String(),
            ],
            'latest' => $edits->isEmpty() ? null : self::presentEdit($edits->first()),
            'history' => $edits->map(fn (AgentInstructionEdit $e) => self::presentEdit($e))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentEdit(AgentInstructionEdit $e): array
    {
        return [
            'id' => $e->id,
            'revision' => $e->revision,
            'status' => $e->status,
            'statusReason' => $e->status_reason,
            'baseHash' => $e->base_hash,
            'baseDescription' => $e->base_description,
            'proposedDescription' => $e->proposed_description,
            'author' => $e->authorUser?->name ?? $e->author,
            'createdAt' => $e->created_at?->toIso8601String(),
            'appliedAt' => $e->applied_at?->toIso8601String(),
            'resolvedAt' => $e->resolved_at?->toIso8601String(),
        ];
    }

    public static function excerpt(string $description): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $description));

        return mb_strlen($flat) > self::EXCERPT ? rtrim(mb_substr($flat, 0, self::EXCERPT - 1)).'…' : $flat;
    }

    /**
     * Agent ids seen in this workspace's usage files => latest name.
     *
     * @return array<string, string>
     */
    public function usageAgents(): array
    {
        $seen = [];
        foreach (UsageDay::query()->orderBy('date')->get(['payload']) as $day) {
            foreach (is_array($day->payload) ? ($day->payload['agents'] ?? []) : [] as $a) {
                if (is_array($a) && is_string($a['agent_id'] ?? null) && $a['agent_id'] !== '') {
                    $seen[$a['agent_id']] = is_string($a['name'] ?? null) && $a['name'] !== '' ? $a['name'] : $a['agent_id'];
                }
            }
        }

        return $seen;
    }

    /**
     * One profile of ingest() / syncRoster(): upsert (plus the roster columns in $roster), then the conflict rule.
     *
     * @param  array{agent_id: string, name: string, title?: ?string, description?: ?string}  $p
     * @param  array<string, mixed>  $roster
     * @return array{agent_id: string, description_hash: string, pending_edit_status: ?string}
     */
    private function ingestOne(array $p, CarbonInterface $now, array $roster = []): array
    {
        $description = $p['description'] ?? '';
        $hash = AgentProfile::hashOf($description);
        AgentProfile::query()->updateOrCreate(['agent_id' => $p['agent_id']], [
            'name' => $p['name'],
            'title' => filled($p['title'] ?? null) ? $p['title'] : null,
            'description' => $description,
            'description_hash' => $hash,
            'synced_at' => $now,
            ...$roster,
        ]);

        $edit = $this->pendingFor($p['agent_id']);
        if ($edit !== null) {
            if ($edit->proposed_description === $description) {
                $edit->resolve(AgentInstructionEdit::APPLIED, null, ['applied_hash' => $hash, 'applied_at' => $now]);
            } elseif ($edit->base_hash !== $hash) {
                $edit->resolve(AgentInstructionEdit::CONFLICT, AgentInstructionEdit::CONFLICT_REASON);
            }
        }

        return ['agent_id' => $p['agent_id'], 'description_hash' => $hash, 'pending_edit_status' => $edit?->status];
    }

    private function pendingFor(string $agentId): ?AgentInstructionEdit
    {
        return AgentInstructionEdit::query()->where('agent_id', $agentId)->where('status', AgentInstructionEdit::PENDING)
            ->orderByDesc('revision')->lockForUpdate()->first();
    }

    /** Run $change on a still-pending edit (row locked); 409 from any other state. */
    private function transition(AgentInstructionEdit $edit, callable $change): AgentInstructionEdit
    {
        return $this->serialised(function () use ($edit, $change): AgentInstructionEdit {
            $locked = AgentInstructionEdit::query()->whereKey($edit->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new ConflictHttpException("This edit is {$locked->status}, not pending.");
            }
            $change($locked);

            return $locked;
        });
    }

    /**
     * Run $write in a transaction that first takes the current workspace row's write lock, so writes of one workspace
     * (ingest, saves, transitions) run one after another: a first save has no profile row to lock, and two of them would
     * otherwise read the same max(revision). The lock is a no-op UPDATE, not SELECT … FOR UPDATE, because SQLite ignores
     * FOR UPDATE but serialises writers. A unique violation that still slips through (a writer outside this lock) rolls
     * the attempt back and retries it, up to ATTEMPTS times; DB::transaction() does not retry those itself.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function serialised(callable $write): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($write): mixed {
                    $workspaceId = app(CurrentWorkspace::class)->id();
                    if ($workspaceId !== null) {
                        Workspace::query()->whereKey($workspaceId)->toBase()->update(['id' => DB::raw('id')]);
                    }

                    return $write();
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
