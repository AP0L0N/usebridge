<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An OAuth error of the MCP authorization server (docs/MCP-OAUTH.md). Token and registration endpoints answer it as
 * JSON {error, error_description} (RFC 6749 §5.2). On the authorization endpoint, redirectTo is the client's redirect
 * URI carrying the error; null there means the client or redirect URI itself is not trusted, so the user sees a page.
 */
final class McpOAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly ?string $redirectTo = null,
    ) {
        parent::__construct($description);
    }

    public function render(): JsonResponse
    {
        $response = response()->json(['error' => $this->error, 'error_description' => $this->getMessage()], $this->status)
            ->header('Cache-Control', 'no-store');

        return $this->status === 401 ? $response->header('WWW-Authenticate', 'Basic realm="oauth"') : $response;
    }
}
