<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-up acceptance (terms + age 18): when the user ticked the box and which terms version it was. Additive and
 * nullable: existing users stay null (they signed up before the checkbox existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'terms_accepted_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('age_confirmed_at')->nullable();
            $table->string('terms_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'terms_accepted_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'age_confirmed_at', 'terms_version']);
        });
    }
};
