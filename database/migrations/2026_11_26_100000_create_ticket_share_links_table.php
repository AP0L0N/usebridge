<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public ticket share links (GET /s/{token}). Additive: one new table, no existing table or row is touched.
 * - token_hash: sha256 of the link token (lookup by hash only); token_encrypted: the same token encrypted with APP_KEY,
 *   so the workspace owner can copy the URL again later. The plain token is never stored.
 * - expires_at null = never expires; revoked_at set = revoked. allow_comments: visitors may post public comments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_share_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('ticket_id');
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('allow_comments')->default(false);
            $table->string('created_by', 120);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->index(['ticket_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_share_links');
    }
};
