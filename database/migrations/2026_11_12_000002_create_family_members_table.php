<?php

use App\Support\FuzzyDate\FuzzyDateColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people in a family tree: one row per person (docs/architecture/02-database.md).
 * Also adds the foreign keys that Phase 3 left open because this table did not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `unaccent()` is only STABLE, so it cannot be used in a generated column directly.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        Schema::create('family_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('full_name');
            $table->text('given_names')->nullable();
            $table->text('surname')->nullable();
            $table->text('maiden_name')->nullable();
            $table->text('nickname')->nullable();
            $table->text('gender')->nullable();
            FuzzyDateColumns::add($table, 'birth');
            $table->uuid('birth_place_id')->nullable();
            $table->text('living_status')->default('unknown');
            FuzzyDateColumns::add($table, 'death');
            $table->uuid('death_place_id')->nullable();
            $table->uuid('hometown_place_id')->nullable();
            $table->text('occupation')->nullable();
            $table->text('education')->nullable();
            $table->text('nationality')->nullable();
            $table->text('biography')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('profile_photo_id')->nullable();
            $table->text('visibility')->default('family');
            $table->boolean('is_placeholder')->default(false);
            $table->smallInteger('generation')->nullable();
            $table->integer('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            // Target of composite foreign keys: nothing can point at a person in another family.
            $table->unique(['family_id', 'id']);
            $table->index(['family_id', 'birth_date']);
            $table->index(['family_id', 'death_date']);
            $table->index(['family_id', 'generation']);
        });

        FuzzyDateColumns::constrain('family_members', 'birth');
        FuzzyDateColumns::constrain('family_members', 'death');

        DB::statement("ALTER TABLE family_members ADD CONSTRAINT family_members_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female', 'other', 'unknown'))");
        DB::statement("ALTER TABLE family_members ADD CONSTRAINT family_members_living_status_check CHECK (living_status IN ('living', 'deceased', 'unknown'))");
        DB::statement("ALTER TABLE family_members ADD CONSTRAINT family_members_visibility_check CHECK (visibility IN ('family', 'restricted', 'private'))");
        DB::statement('ALTER TABLE family_members ADD CONSTRAINT family_members_full_name_check CHECK (char_length(full_name) BETWEEN 1 AND 200)');
        DB::statement("ALTER TABLE family_members ADD CONSTRAINT family_members_death_needs_deceased_check CHECK (death_date IS NULL OR living_status = 'deceased')");
        DB::statement('ALTER TABLE family_members ADD CONSTRAINT family_members_version_check CHECK (version >= 1)');

        // Birthday reminders: only when the full day is known.
        DB::statement(<<<'SQL'
            ALTER TABLE family_members
                ADD COLUMN birth_month smallint GENERATED ALWAYS AS (
                    CASE WHEN birth_date_precision = 'day' THEN EXTRACT(MONTH FROM birth_date)::smallint END
                ) STORED,
                ADD COLUMN birth_day smallint GENERATED ALWAYS AS (
                    CASE WHEN birth_date_precision = 'day' THEN EXTRACT(DAY FROM birth_date)::smallint END
                ) STORED,
                ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                    to_tsvector('simple'::regconfig, immutable_unaccent(
                        full_name || ' ' || coalesce(nickname, '') || ' ' || coalesce(maiden_name, '') || ' ' || coalesce(surname, '')
                    ))
                ) STORED
        SQL);

        DB::statement('CREATE INDEX family_members_search_vector ON family_members USING gin (search_vector)');
        DB::statement('CREATE INDEX family_members_full_name_trgm ON family_members USING gin (full_name gin_trgm_ops)');
        DB::statement("CREATE INDEX family_members_birthdays ON family_members (family_id, birth_month, birth_day) WHERE living_status <> 'deceased' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX family_members_family_live ON family_members (family_id) WHERE deleted_at IS NULL');

        // Places are only ever cleared on the person, never the person removed with the place.
        foreach (['birth_place_id', 'death_place_id', 'hometown_place_id'] as $column) {
            DB::statement("ALTER TABLE family_members ADD CONSTRAINT family_members_{$column}_foreign
                FOREIGN KEY (family_id, {$column}) REFERENCES places (family_id, id) ON DELETE SET NULL ({$column})");
        }

        // Left open in Phase 3.
        DB::statement('ALTER TABLE families ADD CONSTRAINT families_root_member_id_foreign
            FOREIGN KEY (id, root_member_id) REFERENCES family_members (family_id, id) ON DELETE SET NULL (root_member_id)');
        DB::statement('ALTER TABLE family_user ADD CONSTRAINT family_user_linked_member_id_foreign
            FOREIGN KEY (family_id, linked_member_id) REFERENCES family_members (family_id, id) ON DELETE SET NULL (linked_member_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE family_user DROP CONSTRAINT IF EXISTS family_user_linked_member_id_foreign');
        DB::statement('ALTER TABLE families DROP CONSTRAINT IF EXISTS families_root_member_id_foreign');

        // The people these pointed at are about to go; a dangling id would block re-migrating.
        DB::table('family_user')->whereNotNull('linked_member_id')->update(['linked_member_id' => null]);
        DB::table('families')->whereNotNull('root_member_id')->update(['root_member_id' => null]);

        Schema::dropIfExists('family_members');

        DB::statement('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
    }
};
