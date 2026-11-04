<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive (r4 QA MED-1): ticket_comments.superseded_at + superseded_by_comment_id (both nullable) = an owner input
 * comment replaced by a newer owner input on the same ticket before the PM picked it up ("Change answer"). The row and
 * its body stay; it no longer counts as the pending input. Existing rows stay null; down() drops only these two.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ticket_comments', 'superseded_at')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedBigInteger('superseded_by_comment_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_comments', 'superseded_at')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->dropColumn(['superseded_at', 'superseded_by_comment_id']);
            });
        }
    }
};
