<?php

use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveTokenWorkspace;
use App\Http\Middleware\WorkspaceBoundary;
use App\Mcp\Servers\BridgeServer;
use Laravel\Mcp\Facades\Mcp;

// MCP (docs/MULTI-USER-PLAN.md §6), loaded by laravel/mcp: POST /mcp, streamable HTTP (GET/DELETE 405). Same token
// checks as /api: per-user Sanctum Bearer token, the token's workspace (disabled / deletion-pending users 403), plus a
// verified email (403) and 120 requests/min per token (429). Abilities are checked per tool (BridgeTool).
Mcp::web('/mcp', BridgeServer::class)
    ->middleware([WorkspaceBoundary::class, 'auth:sanctum', ResolveTokenWorkspace::class, RequireVerifiedEmail::class, 'throttle:mcp'])
    ->name('mcp');
