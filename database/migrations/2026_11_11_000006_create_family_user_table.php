<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memberships: one role per user per family.
 * `linked_member_id` ("this person in the tree is me") gets its composite foreign key
 * when `family_members` exists (Phase 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('role_id');
            $table->uuid('linked_member_id')->nullable();
            $table->text('status')->default('active');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->foreign('role_id')->references('id')->on('family_roles')->restrictOnDelete();
            $table->unique(['family_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE family_user ADD CONSTRAINT family_user_status_check CHECK (status IN ('active', 'suspended'))");

        // One account per person in the tree.
        DB::statement('CREATE UNIQUE INDEX family_user_family_id_linked_member_id_unique ON family_user (family_id, linked_member_id) WHERE linked_member_id IS NOT NULL');

        // Exactly one owner per family: at most one membership row may hold the owner role (id 1).
        DB::statement('CREATE UNIQUE INDEX family_user_one_owner_per_family ON family_user (family_id) WHERE role_id = 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('family_user');
    }
};
