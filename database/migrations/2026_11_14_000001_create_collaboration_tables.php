<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collaboration: invitations, change requests, the activity feed and the audit log
 * (docs/architecture/02-database.md, "Collaboration and audit").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->smallInteger('role_id');
            // SHA-256 of the token. The token itself exists only in the emailed link.
            $table->text('token_hash')->unique();
            // "This invitation is for this person in the tree" (optional).
            $table->uuid('member_id')->nullable();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('role_id')->references('id')->on('family_roles')->restrictOnDelete();
            $table->index(['family_id', 'created_at']);
        });

        DB::statement('ALTER TABLE family_invitations ALTER COLUMN email TYPE citext');

        // Nobody can be invited as Owner: ownership is only ever transferred.
        DB::statement('ALTER TABLE family_invitations ADD CONSTRAINT family_invitations_role_check CHECK (role_id <> 1)');
        DB::statement('CREATE UNIQUE INDEX family_invitations_one_open_per_email ON family_invitations (family_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        DB::statement('ALTER TABLE family_invitations ADD CONSTRAINT family_invitations_member_id_foreign
            FOREIGN KEY (family_id, member_id) REFERENCES family_members (family_id, id) ON DELETE SET NULL (member_id)');

        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->text('target_type');
            $table->uuid('target_id')->nullable();
            $table->text('action');
            $table->jsonb('payload');
            $table->integer('base_version')->nullable();
            $table->text('summary');
            // The strictest visibility among the people involved: who may see this request.
            $table->text('visibility')->default('family');
            $table->text('status')->default('pending');
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['family_id', 'status', 'created_at']);
            $table->index(['submitted_by', 'status']);
        });

        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_target_type_check CHECK (target_type IN ('member', 'relationship', 'timeline', 'photo', 'document'))");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_action_check CHECK (action IN ('create', 'update', 'delete'))");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'superseded'))");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_visibility_check CHECK (visibility IN ('family', 'restricted', 'private'))");

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('verb');
            $table->text('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->text('summary');
            $table->text('visibility')->default('family');
            $table->timestampTz('created_at');

            $table->index(['family_id', 'created_at']);
        });

        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_visibility_check CHECK (visibility IN ('family', 'restricted', 'private'))");

        // Security record. Deliberately no foreign keys: it must outlive what it describes.
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                family_id uuid NULL,
                user_id uuid NULL,
                action text NOT NULL,
                auditable_type text NULL,
                auditable_id text NULL,
                old_values jsonb NULL,
                new_values jsonb NULL,
                ip_address inet NULL,
                user_agent text NULL,
                request_id uuid NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX audit_logs_created_at_brin ON audit_logs USING brin (created_at)');
        DB::statement('CREATE INDEX audit_logs_family_created ON audit_logs (family_id, created_at)');

        // Append-only for everyone, including the application itself: history cannot be
        // rewritten. (In production the app's database role also lacks UPDATE and DELETE.)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_log_changes() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only' USING ERRCODE = 'MF010';
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_changes()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_log_changes()');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('family_invitations');
    }
};
