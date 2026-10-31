<?php

namespace App\Services\Auth;

use App\Models\Agent;
use App\Models\CliUsageDay;
use App\Models\Label;
use App\Models\NextStep;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\UsageDay;
use App\Support\Workspaces\CurrentWorkspace;
use App\Support\Workspaces\MissingWorkspace;
use Illuminate\Support\Carbon;

/**
 * "Export my data" before an account deletion (docs/MULTI-USER-PLAN.md §2): the current workspace's rows as JSON.
 * Every query goes through the models' WorkspaceScope, so only the current workspace is ever read; no workspace, no
 * export (fails closed).
 */
final class WorkspaceExport
{
    /** key => owned model, in a readable order */
    private const SECTIONS = [
        'projects' => Project::class,
        'agents' => Agent::class,
        'labels' => Label::class,
        'tickets' => Ticket::class,
        'ticket_comments' => TicketComment::class,
        'ticket_events' => TicketEvent::class,
        'status_updates' => StatusUpdate::class,
        'project_status_events' => ProjectStatusEvent::class,
        'questions' => Question::class,
        'required_inputs' => RequiredInput::class,
        'next_steps' => NextStep::class,
        'usage_days' => UsageDay::class,
        'cli_usage_days' => CliUsageDay::class,
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function export(): array
    {
        $workspace = $this->current->get() ?? throw new MissingWorkspace('export');
        $out = [
            'exported_at' => Carbon::now()->toIso8601String(),
            'workspace' => ['slug' => $workspace->slug, 'name' => $workspace->name, 'created_at' => $workspace->created_at?->toIso8601String()],
        ];
        foreach (self::SECTIONS as $key => $model) {
            $query = $model::query();
            $out[$key] = $query->orderBy($query->getModel()->getKeyName())->get()->map->attributesToArray()->values()->all();
        }

        return $out;
    }
}
