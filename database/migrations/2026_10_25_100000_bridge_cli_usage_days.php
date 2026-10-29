<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge Usage: measured Claude Code / Kimi Code tokens per Europe/Ljubljana day, tool and agent
 * (collector: bridge-usage/cli_usage.py). One row per (date, tool, agent). New table only, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cli_usage_days')) {
            return;
        }
        Schema::create('cli_usage_days', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('tool', 32);
            $table->string('agent_id', 64);
            $table->string('agent_name', 120);
            $table->unsignedBigInteger('input_tokens');
            $table->unsignedBigInteger('output_tokens');
            $table->unsignedBigInteger('cache_read_tokens');
            $table->unsignedBigInteger('cache_creation_tokens');
            $table->unsignedBigInteger('calls');
            $table->timestamps();
            $table->unique(['date', 'tool', 'agent_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cli_usage_days');
    }
};
