<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge Polymarket desk (Frank Yang's feed, posted by Captain to POST /api/polymarket/feed).
 * polymarket_snapshots: the single current snapshot (replace semantics, newer as_of wins).
 * polymarket_series_points: one row per desk run, upserted by ts. All instants are stored as UTC.
 * New tables only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polymarket_snapshots')) {
            Schema::create('polymarket_snapshots', function (Blueprint $table) {
                $table->id();
                $table->dateTime('as_of'); // UTC
                $table->string('as_of_raw', 40); // as sent, with offset
                $table->json('payload'); // the snapshot without `history`
                $table->char('payload_sha256', 64);
                $table->dateTime('received_at'); // UTC
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('polymarket_series_points')) {
            Schema::create('polymarket_series_points', function (Blueprint $table) {
                $table->id();
                $table->dateTime('ts')->unique(); // UTC
                $table->string('ts_raw', 40); // as sent, with offset
                $table->decimal('book_value_usd', 12, 2);
                $table->decimal('cash_usd', 12, 2)->nullable();
                $table->decimal('stop_usd', 12, 2)->nullable();
                $table->string('source', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('polymarket_series_points');
        Schema::dropIfExists('polymarket_snapshots');
    }
};
