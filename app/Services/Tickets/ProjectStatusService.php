<?php

namespace App\Services\Tickets;

use App\Models\Project;
use App\Models\ProjectStatusEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PM-set project status (SPEC §3). Every write — also a note-only update — appends a project_status_events row.
 * `archived` (and reopening an archived project) is Captain (agent-william) via API or Tilen in the UI only;
 * the project's PM may set smooth|waiting. archived_at follows the status so the old archive logic keeps working.
 */
final class ProjectStatusService
{
    /** PATCH /api/projects/{id}/status and MCP set_project_status. */
    public static function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Project::STATUSES)],
            'note' => ['nullable', 'string', 'max:280'],
        ];
    }

    public function canSet(Project $project, string $to, Actor $actor): ?string
    {
        if ($actor->isTilen() || $actor->isCaptain()) {
            return null;
        }
        if ($to === 'archived' || $project->isArchived()) {
            return 'Only Captain (agent-william) or Tilen can archive or reopen a project.';
        }
        if ($actor->id !== $project->owner_agent_id) {
            return "Only the project's PM ({$project->owner_agent_id}) or Captain can set its status.";
        }

        return null;
    }

    public function set(Project $project, string $to, ?string $note, Actor $actor): ProjectStatusEvent
    {
        return DB::transaction(function () use ($project, $to, $note, $actor) {
            $now = Carbon::now();
            $from = $project->status;
            $event = ProjectStatusEvent::create([
                'id' => 'pse-'.Str::lower((string) Str::ulid()),
                'project_id' => $project->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'set_by' => $actor->id,
                'set_by_type' => $actor->type === 'agent' ? 'agent' : 'human',
                'source' => $actor->source,
                'created_at' => $now,
            ]);
            $project->forceFill([
                'status' => $to,
                'status_note' => $note,
                'status_set_by' => $actor->id,
                'status_set_at' => $now,
                'last_activity_at' => $now,
            ]);
            if ($to === 'archived') {
                $project->archived_at ??= $now;
                $project->reopened_at = null;
            } elseif ($project->archived_at !== null) {
                $project->archived_at = null;
                $project->reopened_at = $now;
            }
            $project->save();

            return $event;
        });
    }

    /**
     * Archive = status 'archived' + archived_at, logged. Idempotent (an already archived project logs nothing).
     * Never deletes anything.
     */
    public function archive(Project $project, Actor $actor, ?string $note = null): ?ProjectStatusEvent
    {
        return $project->isArchived() && $project->status === 'archived' ? null : $this->set($project, 'archived', $note, $actor);
    }

    /**
     * Unarchive = reopen: back to the status the project had before it was archived (smooth when unknown), logged,
     * archived_at cleared (no "Reopened" chip). Idempotent.
     */
    public function unarchive(Project $project, Actor $actor, ?string $note = null): ?ProjectStatusEvent
    {
        if (! $project->isArchived() && $project->status !== 'archived') {
            return null;
        }

        $event = $this->set($project, $this->statusBeforeArchive($project), $note, $actor);
        // A manual unarchive is not "Reopened" (that chip is for an update arriving on an archived project).
        $project->forceFill(['reopened_at' => null])->save();

        return $event;
    }

    /** The last non-archived status the project had (from the log), 'smooth' when there is none. */
    public function statusBeforeArchive(Project $project): string
    {
        $from = ProjectStatusEvent::query()->where('project_id', $project->id)->where('to_status', 'archived')
            ->orderByDesc('created_at')->orderByDesc('id')->value('from_status');

        return in_array($from, ['smooth', 'waiting'], true) ? $from : 'smooth';
    }

    public function current(Project $project, TicketView $view): array
    {
        return [
            'status' => $project->status,
            'status_label' => Project::statusLabel($project->status),
            'note' => $project->status_note,
            'set_by' => $project->status_set_by,
            'set_by_name' => $view->name($project->status_set_by),
            'set_at' => $project->status_set_at?->toIso8601String(),
        ];
    }

    public function event(ProjectStatusEvent $e, TicketView $view): array
    {
        return [
            'id' => $e->id,
            'project_id' => $e->project_id,
            'from_status' => $e->from_status,
            'to_status' => $e->to_status,
            'to_status_label' => Project::statusLabel($e->to_status),
            'note' => $e->note,
            'set_by' => $e->set_by,
            'set_by_name' => $view->name($e->set_by),
            'set_by_type' => $e->set_by_type,
            'source' => $e->source,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** Newest first. */
    public function history(Project $project, TicketView $view, int $limit = 100): array
    {
        return ProjectStatusEvent::query()->where('project_id', $project->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($e) => $this->event($e, $view))->all();
    }
}
