<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers that receive push notifications (laravel-notification-channels/webpush).
 *
 * Written for PostgreSQL instead of the package's own migration: owners are users with UUID
 * keys, and the endpoint is limited at the database as well as in PushEndpoint.
 * Rows are removed when the push service reports them expired, on logout from that device,
 * and whenever the person's sessions are revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('subscribable', 'push_subscriptions_subscribable_idx');
            $table->text('endpoint')->unique();
            $table->text('public_key')->nullable();
            $table->text('auth_token')->nullable();
            $table->text('content_encoding')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_endpoint_check CHECK (char_length(endpoint) <= 500 AND endpoint LIKE 'https://%')");
        DB::statement('ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_keys_check CHECK (char_length(public_key) <= 200 AND char_length(auth_token) <= 100)');
        DB::statement("ALTER TABLE push_subscriptions ADD CONSTRAINT push_subscriptions_encoding_check CHECK (content_encoding IS NULL OR content_encoding IN ('aesgcm', 'aes128gcm'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
