<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Measured Claude Code / Kimi Code tokens for one (date, tool, agent): a row of a collector file
 * (bridge-usage/data/cli/YYYY-MM-DD.json, or the `cli_usage` key of an estimator day file).
 * `date` stays a plain Y-m-d string so lookups match on SQLite and MySQL alike.
 */
class CliUsageDay extends Model
{
    public const TOOLS = ['claude_code', 'kimi_code'];

    public const TOOL_LABELS = ['claude_code' => 'Claude Code', 'kimi_code' => 'Kimi Code'];

    protected $fillable = ['date', 'tool', 'agent_id', 'agent_name', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens', 'calls'];

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
