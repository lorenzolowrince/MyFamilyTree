<?php

use App\Support\FuzzyDate\FuzzyDateColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical life events a family records (education, career, migration, reunions...).
 * Births, deaths and marriages are not stored here: the timeline merges them in from the
 * people and relationship tables, so a date corrected in one place is correct everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_timeline', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('category')->default('other');
            $table->text('title');
            $table->text('description')->nullable();
            FuzzyDateColumns::add($table, 'event');
            $table->uuid('place_id')->nullable();
            $table->text('visibility')->default('family');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->unique(['family_id', 'id']);
            $table->index(['family_id', 'event_date']);
        });

        FuzzyDateColumns::constrain('family_timeline', 'event');

        DB::statement("ALTER TABLE family_timeline ADD CONSTRAINT family_timeline_category_check CHECK (category IN ('education', 'career', 'migration', 'reunion', 'military', 'religious', 'residence', 'achievement', 'other'))");
        DB::statement("ALTER TABLE family_timeline ADD CONSTRAINT family_timeline_visibility_check CHECK (visibility IN ('family', 'restricted', 'private'))");
        DB::statement('ALTER TABLE family_timeline ADD CONSTRAINT family_timeline_title_check CHECK (char_length(title) BETWEEN 1 AND 200)');
        DB::statement('ALTER TABLE family_timeline ADD CONSTRAINT family_timeline_place_id_foreign
            FOREIGN KEY (family_id, place_id) REFERENCES places (family_id, id) ON DELETE SET NULL (place_id)');

        Schema::create('timeline_participants', function (Blueprint $table) {
            $table->uuid('timeline_id');
            $table->uuid('member_id');
            $table->uuid('family_id');
            $table->text('role')->nullable();

            $table->primary(['timeline_id', 'member_id']);
            $table->index(['family_id', 'member_id']);
        });

        // Both ends must belong to the same family as the link itself.
        DB::statement('ALTER TABLE timeline_participants ADD CONSTRAINT timeline_participants_timeline_id_foreign
            FOREIGN KEY (family_id, timeline_id) REFERENCES family_timeline (family_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE timeline_participants ADD CONSTRAINT timeline_participants_member_id_foreign
            FOREIGN KEY (family_id, member_id) REFERENCES family_members (family_id, id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_participants');
        Schema::dropIfExists('family_timeline');
    }
};
