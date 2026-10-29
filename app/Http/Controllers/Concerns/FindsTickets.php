<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Models\Ticket;

trait FindsTickets
{
    /** {ticket} = key (CA-12) or id (tk-…); JSON 404 otherwise. */
    protected function ticket(string $ref, ?Project $project = null): Ticket
    {
        $ticket = Ticket::findByRef($ref);
        abort_if(! $ticket || ($project && $ticket->project_id !== $project->id), 404, "Unknown ticket '$ref'.");

        return $ticket;
    }

    /** API {id} = project id (slug accepted too). */
    protected function projectById(string $id): Project
    {
        $project = Project::query()->find($id) ?? Project::query()->where('slug', $id)->first();
        abort_if(! $project, 404, "Unknown project '$id'.");

        return $project;
    }

    protected function projectBySlug(string $slug): Project
    {
        return Project::query()->where('slug', $slug)->firstOrFail();
    }

    /** Query-string flag: 1/true/yes/on. */
    protected static function flag(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOL);
    }
}
