<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Alternate and previous names of a person (birth name, married name, religious name...). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_names', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->uuid('member_id');
            $table->text('type')->default('other');
            $table->text('name');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['family_id', 'member_id']);
        });

        DB::statement('ALTER TABLE member_names ADD CONSTRAINT member_names_member_id_foreign
            FOREIGN KEY (family_id, member_id) REFERENCES family_members (family_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE member_names ADD CONSTRAINT member_names_type_check CHECK (type IN ('birth', 'married', 'religious', 'alias', 'romanised', 'other'))");
        DB::statement('ALTER TABLE member_names ADD CONSTRAINT member_names_name_check CHECK (char_length(name) BETWEEN 1 AND 200)');
        DB::statement('CREATE INDEX member_names_name_trgm ON member_names USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('member_names');
    }
};
