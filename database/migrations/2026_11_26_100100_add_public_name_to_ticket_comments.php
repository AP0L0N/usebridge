<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: ticket_comments.public_name (nullable) = the name a visitor typed on a public share link. Such comments have
 * author_type 'public' and author_id 'public:<ticket_share_links.id>'; they are never owner input. Existing rows stay
 * null; down() drops only this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ticket_comments', 'public_name')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->string('public_name', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_comments', 'public_name')) {
            Schema::table('ticket_comments', function (Blueprint $table) {
                $table->dropColumn('public_name');
            });
        }
    }
};
