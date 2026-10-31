<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * "A collector file covered this tool on this date": one row per (workspace, date, tool), upserted by
 * CliUsageImporter on every import, also when the file had zero rows for the tool. Marker + no cli_usage_days rows =
 * zero usage; no marker = no file that day. `rows` / `total` are the last import's row count and token total.
 */
class CliUsageImport extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = ['date', 'tool', 'rows', 'total', 'imported_at'];

    protected function casts(): array
    {
        return [
            'rows' => 'integer',
            'total' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
