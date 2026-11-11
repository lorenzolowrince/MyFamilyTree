<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account columns from docs/architecture/02-database.md (`users`).
 *
 * `deletion_scheduled_at` is an addition: it holds the end of the 14-day grace period
 * after a user asks for their account to be deleted (docs/adr/0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
            $table->text('status')->default('active');
            $table->text('locale')->default('en');
            $table->text('timezone')->default('Asia/Kuala_Lumpur');
            $table->text('avatar_path')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('deletion_scheduled_at')->nullable();
            $table->softDeletesTz();

            // Social-only accounts have no password.
            $table->string('password')->nullable()->change();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'deactivated', 'suspended'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('en', 'ms'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_locale_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_super_admin',
                'status',
                'locale',
                'timezone',
                'avatar_path',
                'last_login_at',
                'deletion_scheduled_at',
                'deleted_at',
            ]);
        });
    }
};
