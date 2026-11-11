<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in history shown to the user and used for security monitoring. Retained 12 months
 * (pruned by the scheduler).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('email_attempted')->nullable();
            $table->text('event');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });

        DB::statement('ALTER TABLE login_activities ALTER COLUMN email_attempted TYPE citext');
        DB::statement('CREATE INDEX login_activities_user_id_created_at_index ON login_activities (user_id, created_at DESC)');
        DB::statement(<<<'SQL'
            ALTER TABLE login_activities ADD CONSTRAINT login_activities_event_check CHECK (event IN (
                'login', 'logout', 'failed', 'mfa_failed', 'lockout', 'password_reset', 'mfa_enabled', 'mfa_disabled'
            ))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
    }
};
