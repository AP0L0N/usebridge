<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time authorization code of the MCP OAuth flow (docs/MCP-OAUTH.md): sha256 at rest, valid for a few minutes. */
class McpOAuthCode extends Model
{
    protected $table = 'mcp_oauth_codes';

    protected $fillable = ['code_hash', 'client_id', 'user_id', 'workspace_id', 'redirect_uri', 'code_challenge', 'expires_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
