<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth 2.1 for the MCP server (docs/MCP-OAUTH.md). Additive: three new tables, no existing table or row is touched.
 * - mcp_oauth_clients: registered MCP clients (Gemini, ...). secret_hash null = public client (PKCE only).
 * - mcp_oauth_codes: one-time authorization codes (sha256 at rest), bound to client, user, workspace and redirect URI.
 * - mcp_oauth_tokens: access + refresh token pairs (sha256 at rest). Each pair acts as one personal_access_tokens row
 *   (the grant, listed under Account → API tokens); deleting that row revokes the grant. No foreign keys on purpose:
 *   the token row is deleted by the existing revoke paths and the pairs simply stop resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // client_id
            $table->string('name', 100);
            $table->string('secret_hash', 64)->nullable();
            $table->json('redirect_uris');
            $table->boolean('is_dynamic')->default(false); // registered through POST /oauth/register
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 64)->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('workspace_id');
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('personal_access_token_id')->nullable(); // the grant this code was exchanged for
            $table->timestamps();
        });

        Schema::create('mcp_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64)->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('personal_access_token_id')->index();
            $table->string('access_token_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->string('refresh_token_hash', 64)->unique();
            $table->timestamp('refresh_expires_at');
            $table->timestamp('revoked_at')->nullable(); // rotated by a refresh, or revoked
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
