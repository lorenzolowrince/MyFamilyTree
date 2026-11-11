<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Places a family refers to (birthplaces, hometowns, where a marriage took place).
 * One row per place per family, so a spelling is fixed once for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('locality')->nullable();
            $table->text('district')->nullable();
            $table->text('state')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampsTz();

            // Target of composite foreign keys: a place can only be used inside its own family.
            $table->unique(['family_id', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX places_family_id_name_unique ON places (family_id, lower(name))');
        DB::statement('CREATE INDEX places_name_trgm ON places USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
