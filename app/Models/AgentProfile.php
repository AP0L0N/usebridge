<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * An agent's real profile as pushed by the daily sync (POST /api/agent-profiles): name, title and description (the
 * agent's role instructions). agent_id is the usage agent id (the key of /usage/agents/{agentId}), not agents.id.
 * The description only changes through the ingest or a confirmed "applied"; Bridge never edits it on its own.
 */
class AgentProfile extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['agent_id', 'name', 'title', 'description', 'description_hash', 'synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    /** sha256 hex of the exact UTF-8 description (no trimming); a missing description hashes as ''. */
    public static function hashOf(?string $description): string
    {
        return hash('sha256', $description ?? '');
    }
}
