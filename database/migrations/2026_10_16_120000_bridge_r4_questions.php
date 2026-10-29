<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bridge r4: Questions for Tilen (type yesno|text, state open|answered|closed). Additive, reversible. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('questions')) {
            return;
        }
        Schema::create('questions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('text', 1000);
            $table->string('context', 500)->nullable();
            $table->string('type', 10); // yesno|text
            $table->string('asked_by', 120);
            $table->string('state', 10)->default('open'); // open|answered|closed
            $table->string('answer_choice', 10)->nullable(); // yes|no|skip|other (yesno only)
            $table->text('answer_text')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->index(['project_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
