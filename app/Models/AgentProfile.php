<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Agents\AgentInstructions;
use Illuminate\Database\Eloquent\Model;

/**
 * An agent's real profile as pushed by the daily sync (POST /api/agent-profiles): name, title and description (the
 * agent's role instructions). agent_id is the usage agent id (the key of /usage/agents/{agentId}), not agents.id.
 * The description only changes through the ingest or a confirmed "applied"; Bridge never edits it on its own.
 * The roster sync (POST /api/agents/sync, docs/agents-sync.md) adds full_name, section, section_order and listed: only
 * listed rows show on /agents.
 */
class AgentProfile extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['agent_id', 'name', 'title', 'full_name', 'section', 'section_order', 'listed', 'description',
        'description_hash', 'synced_at', 'roster_synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'roster_synced_at' => 'datetime', 'listed' => 'boolean', 'section_order' => 'integer'];
    }

    /** The roster full_name, else "name · title" (a profile only the legacy ingest sent). */
    public function displayName(): string
    {
        return $this->full_name ?? ($this->title !== null ? $this->name.AgentInstructions::TITLE_SEPARATOR.$this->title : $this->name);
    }

    /** sha256 hex of the exact UTF-8 description (no trimming); a missing description hashes as ''. */
    public static function hashOf(?string $description): string
    {
        return hash('sha256', $description ?? '');
    }
}
