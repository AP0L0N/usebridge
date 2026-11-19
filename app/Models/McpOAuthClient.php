<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An OAuth client of the MCP server (docs/MCP-OAUTH.md): id = client_id. secret_hash (sha256) is set for confidential
 * clients (bridge:mcp-oauth-client) and null for public ones (dynamic registration, PKCE only). Not workspace data:
 * a client only acts for the users who approved it.
 */
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'secret_hash', 'redirect_uris', 'is_dynamic'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'is_dynamic' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function isConfidential(): bool
    {
        return $this->secret_hash !== null;
    }

    /** Exact string match (OAuth 2.1): no prefix, wildcard or normalized comparison. */
    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris ?? [], true);
    }
}
