<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEDCOM imports (uploaded, checked, previewed, then applied) and generated report files
 * (docs/architecture/02-database.md, "`gedcom_imports`, `report_exports`").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gedcom_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('storage_key');
            $table->text('original_filename')->nullable();
            $table->text('status')->default('uploaded');
            // What the file contains: counts, warnings, the people, likely duplicates.
            $table->jsonb('summary')->default('{}');
            // The reviewer's decision for each person: create, skip, or the existing person to merge into.
            $table->jsonb('mapping')->default('{}');
            // What the import did.
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['family_id', 'created_at']);
        });

        DB::statement("ALTER TABLE gedcom_imports ADD CONSTRAINT gedcom_imports_status_check CHECK (status IN ('uploaded', 'parsing', 'preview_ready', 'importing', 'completed', 'failed', 'cancelled'))");

        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('type');
            $table->text('title');
            $table->jsonb('parameters')->default('{}');
            $table->text('status')->default('pending');
            $table->text('storage_key')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            // The file is deleted after this moment.
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['family_id', 'created_at']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'processing', 'ready', 'failed'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_type_check CHECK (type IN ('tree', 'ancestors', 'descendants', 'individual', 'directory', 'timeline'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('gedcom_imports');
    }
};
