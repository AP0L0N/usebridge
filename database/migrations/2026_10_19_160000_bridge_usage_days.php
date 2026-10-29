<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bridge Usage: one estimator file per Europe/Ljubljana day. New table only, reversible. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_days')) {
            return;
        }
        Schema::create('usage_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->dateTime('generated_at');
            $table->unsignedBigInteger('est_tokens_total');
            $table->longText('payload'); // validated day JSON, as imported
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_days');
    }
};
