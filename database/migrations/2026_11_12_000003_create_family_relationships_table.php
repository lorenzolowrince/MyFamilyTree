<?php

use App\Support\FuzzyDate\FuzzyDateColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relationship edges between people (docs/architecture/03-relationship-modelling.md).
 *
 * Only two kinds are stored: parent_child (a = parent, b = child) and partnership (a < b).
 * The constraints and the trigger below make impossible structures impossible to save, even
 * if application code has a bug. The application runs the same checks first so people get a
 * friendly message; SQLSTATEs MF001..MF003 are the safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('type');
            $table->uuid('person_a_id');
            $table->uuid('person_b_id');
            $table->text('subtype');
            $table->text('status')->nullable();
            $table->text('certainty')->default('confirmed');
            FuzzyDateColumns::add($table, 'start');
            FuzzyDateColumns::add($table, 'end');
            $table->uuid('place_id')->nullable();
            $table->smallInteger('sequence')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        FuzzyDateColumns::constrain('family_relationships', 'start');
        FuzzyDateColumns::constrain('family_relationships', 'end');

        foreach (['person_a_id', 'person_b_id'] as $column) {
            DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_{$column}_foreign
                FOREIGN KEY (family_id, {$column}) REFERENCES family_members (family_id, id) ON DELETE CASCADE");
        }

        DB::statement('ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_place_id_foreign
            FOREIGN KEY (family_id, place_id) REFERENCES places (family_id, id) ON DELETE SET NULL (place_id)');

        DB::statement('ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_not_self_check CHECK (person_a_id <> person_b_id)');
        DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_type_check CHECK (type IN ('parent_child', 'partnership'))");
        DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_certainty_check CHECK (certainty IN ('confirmed', 'probable', 'possible', 'disputed'))");
        DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_subtype_check CHECK (
            (type = 'parent_child' AND subtype IN ('biological', 'adoptive', 'step', 'foster', 'guardian', 'unknown'))
            OR (type = 'partnership' AND subtype IN ('marriage', 'customary_marriage', 'civil_union', 'partner', 'engaged'))
        )");
        DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_status_check CHECK (
            (type = 'parent_child' AND status IS NULL)
            OR (type = 'partnership' AND status IN ('current', 'divorced', 'separated', 'widowed', 'annulled'))
        )");
        // Canonical order, so A-B and B-A cannot both be recorded as partners.
        DB::statement("ALTER TABLE family_relationships ADD CONSTRAINT family_relationships_partner_order_check CHECK (type <> 'partnership' OR person_a_id < person_b_id)");

        DB::statement("CREATE UNIQUE INDEX family_relationships_one_parent_link ON family_relationships (person_a_id, person_b_id) WHERE type = 'parent_child' AND deleted_at IS NULL");
        DB::statement('CREATE INDEX family_relationships_person_a ON family_relationships (family_id, person_a_id, type) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX family_relationships_person_b ON family_relationships (family_id, person_b_id, type) WHERE deleted_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_family_relationship_rules() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.deleted_at IS NOT NULL THEN
                    RETURN NULL;
                END IF;

                IF NEW.type = 'parent_child' THEN
                    -- Nobody can be their own ancestor: the child must not be among the parent's ancestors.
                    IF EXISTS (
                        WITH RECURSIVE ancestors(id) AS (
                            SELECT r.person_a_id FROM family_relationships r
                            WHERE r.family_id = NEW.family_id AND r.type = 'parent_child'
                              AND r.deleted_at IS NULL AND r.person_b_id = NEW.person_a_id
                            UNION
                            SELECT r.person_a_id FROM family_relationships r
                            JOIN ancestors a ON r.person_b_id = a.id
                            WHERE r.family_id = NEW.family_id AND r.type = 'parent_child' AND r.deleted_at IS NULL
                        )
                        SELECT 1 FROM ancestors WHERE id = NEW.person_b_id
                    ) THEN
                        RAISE EXCEPTION 'A person cannot be their own ancestor.' USING ERRCODE = 'MF001';
                    END IF;

                    IF (
                        SELECT count(*) FROM family_relationships r
                        WHERE r.family_id = NEW.family_id AND r.type = 'parent_child' AND r.subtype = 'biological'
                          AND r.deleted_at IS NULL AND r.person_b_id = NEW.person_b_id
                    ) > 2 THEN
                        RAISE EXCEPTION 'A person cannot have more than two biological parents.' USING ERRCODE = 'MF002';
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM family_relationships r
                        WHERE r.family_id = NEW.family_id AND r.type = 'partnership' AND r.deleted_at IS NULL
                          AND r.person_a_id = LEAST(NEW.person_a_id, NEW.person_b_id)
                          AND r.person_b_id = GREATEST(NEW.person_a_id, NEW.person_b_id)
                    ) THEN
                        RAISE EXCEPTION 'A person cannot be both parent and partner of the same person.' USING ERRCODE = 'MF003';
                    END IF;
                ELSE
                    IF EXISTS (
                        SELECT 1 FROM family_relationships r
                        WHERE r.family_id = NEW.family_id AND r.type = 'parent_child' AND r.deleted_at IS NULL
                          AND ((r.person_a_id = NEW.person_a_id AND r.person_b_id = NEW.person_b_id)
                            OR (r.person_a_id = NEW.person_b_id AND r.person_b_id = NEW.person_a_id))
                    ) THEN
                        RAISE EXCEPTION 'A person cannot be both parent and partner of the same person.' USING ERRCODE = 'MF003';
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER enforce_parent_child_rules
            AFTER INSERT OR UPDATE ON family_relationships
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION enforce_family_relationship_rules()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('family_relationships');

        DB::statement('DROP FUNCTION IF EXISTS enforce_family_relationship_rules()');
    }
};
