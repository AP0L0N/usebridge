<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One access + refresh token pair of the MCP OAuth flow (docs/MCP-OAUTH.md), sha256 at rest. It acts as the
 * personal_access_tokens row of its grant (workspace, abilities, agent); a refresh revokes the pair and issues a new one.
 */
class McpOAuthToken extends Model
{
    protected $table = 'mcp_oauth_tokens';

    protected $fillable = [
        'client_id', 'user_id', 'personal_access_token_id', 'access_token_hash', 'access_expires_at', 'refresh_token_hash', 'refresh_expires_at',
    ];

    protected $hidden = ['access_token_hash', 'refresh_token_hash'];

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
