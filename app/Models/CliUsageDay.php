<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Measured Claude Code / Kimi Code / OpenCode Go tokens for one (date, tool, agent, model): a row of a
 * collector file (bridge-usage/data/cli/YYYY-MM-DD.json, or the `cli_usage` key of an estimator day file).
 * `model` is the model name as the CLI logged it; '' (NO_MODEL) when the log recorded none, and for rows
 * imported before the collector reported models. `date` stays a plain Y-m-d string so lookups match on
 * SQLite and MySQL alike.
 */
class CliUsageDay extends Model
{
    use BelongsToWorkspace;

    public const TOOLS = ['claude_code', 'kimi_code', 'opencode_go'];

    public const TOOL_LABELS = ['claude_code' => 'Claude Code', 'kimi_code' => 'Kimi Code', 'opencode_go' => 'OpenCode Go'];

    /** Stored `model` when the log recorded no model (collector `null` or no `model` key). */
    public const NO_MODEL = '';

    public const NO_MODEL_LABEL = 'model not recorded';

    protected $fillable = ['date', 'tool', 'agent_id', 'agent_name', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens', 'calls'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_creation_tokens' => 'integer',
            'calls' => 'integer',
        ];
    }

    /** input + output + cache read + cache creation (cache reads are billed at a discount). */
    public function total(): int
    {
        return $this->input_tokens + $this->output_tokens + $this->cache_read_tokens + $this->cache_creation_tokens;
    }
}
