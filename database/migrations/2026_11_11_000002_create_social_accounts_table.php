<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links a user to an external identity (Google now, Apple later).
 * OAuth tokens are deliberately not stored: they are only needed during login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('provider');
            $table->text('provider_user_id');
            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        DB::statement("ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_provider_check CHECK (provider IN ('google', 'apple'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
