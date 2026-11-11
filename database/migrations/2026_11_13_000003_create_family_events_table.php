<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upcoming gatherings and announcements (reunions, celebrations, memorials). After an event
 * has happened it can be turned into a timeline entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained()->cascadeOnDelete();
            $table->text('type')->default('other');
            $table->text('title');
            $table->text('description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->text('location_text')->nullable();
            $table->uuid('place_id')->nullable();
            $table->boolean('notify_members')->default(true);
            // Set once the event has been copied to the timeline, so it is not copied twice.
            $table->uuid('timeline_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->unique(['family_id', 'id']);
            $table->index(['family_id', 'starts_at']);
        });

        DB::statement("ALTER TABLE family_events ADD CONSTRAINT family_events_type_check CHECK (type IN ('reunion', 'celebration', 'memorial', 'announcement', 'other'))");
        DB::statement('ALTER TABLE family_events ADD CONSTRAINT family_events_title_check CHECK (char_length(title) BETWEEN 1 AND 200)');
        DB::statement('ALTER TABLE family_events ADD CONSTRAINT family_events_dates_check CHECK (ends_at IS NULL OR ends_at >= starts_at)');
        DB::statement('ALTER TABLE family_events ADD CONSTRAINT family_events_place_id_foreign
            FOREIGN KEY (family_id, place_id) REFERENCES places (family_id, id) ON DELETE SET NULL (place_id)');
        DB::statement('ALTER TABLE family_events ADD CONSTRAINT family_events_timeline_id_foreign
            FOREIGN KEY (family_id, timeline_id) REFERENCES family_timeline (family_id, id) ON DELETE SET NULL (timeline_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('family_events');
    }
};
