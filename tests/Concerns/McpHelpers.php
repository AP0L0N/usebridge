<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\ApiTokens;
use Illuminate\Testing\TestResponse;
use stdClass;

/** JSON-RPC over POST /mcp, as a streamable-HTTP client (Cursor, Claude) sends it after initialize. */
trait McpHelpers
{
    private int $rpcId = 0;

    protected function mcp(?string $token, string $method, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer $token";
        }

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => ++$this->rpcId, 'method' => $method, 'params' => $params ?: new stdClass], $headers);
    }

    /** @return array{error: bool, text: string, json: mixed} */
    protected function callTool(string $token, string $name, array $args = []): array
    {
        $result = $this->mcp($token, 'tools/call', ['name' => $name, 'arguments' => $args ?: new stdClass])->assertOk()->json('result');
        $text = (string) ($result['content'][0]['text'] ?? '');

        return ['error' => (bool) ($result['isError'] ?? false), 'text' => $text, 'json' => json_decode($text, true)];
    }

    /** A successful tool call's JSON payload. */
    protected function tool(string $token, string $name, array $args = []): array
    {
        $r = $this->callTool($token, $name, $args);
        $this->assertFalse($r['error'], "$name failed: {$r['text']}");

        return $r['json'];
    }

    /** A failed tool call's message. */
    protected function toolError(string $token, string $name, array $args = []): string
    {
        $r = $this->callTool($token, $name, $args);
        $this->assertTrue($r['error'], "$name should have failed: {$r['text']}");

        return $r['text'];
    }

    /** A token as the Tokens screen issues it: scope read|write, bound to $workspace. */
    protected function scopedToken(User $user, Workspace $workspace, string $scope, string $name = 'grok'): string
    {
        return app(ApiTokens::class)->create($user, $workspace, $name, $scope, null)['plain_text'];
    }

    protected function bearer(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => "Bearer $token"]);
    }
}
