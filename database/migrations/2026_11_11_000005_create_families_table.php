<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Families (tenants). `root_member_id` and `cover_photo_id` get their foreign keys when
 * `family_members` (Phase 4) and `family_photos` (Phase 7) exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('slug')->unique();
            $table->text('description')->nullable();
            $table->uuid('cover_photo_id')->nullable();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->uuid('root_member_id')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->bigInteger('graph_version')->default(0);
            $table->bigInteger('storage_bytes_used')->default(0);
            $table->bigInteger('storage_quota_bytes');
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index('owner_id');
        });

        DB::statement('ALTER TABLE families ADD CONSTRAINT families_storage_check CHECK (storage_bytes_used >= 0 AND storage_quota_bytes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
