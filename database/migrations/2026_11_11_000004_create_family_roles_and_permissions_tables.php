<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed roles/permissions matrix (docs/architecture/05-roles-permissions.md).
 * Rows are written by RolesAndPermissionsSeeder; families cannot invent custom roles in v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_roles', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->text('key')->unique();
            $table->text('name');
            $table->smallInteger('rank');
        });

        Schema::create('family_permissions', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->text('key')->unique();
            $table->text('description');
        });

        Schema::create('family_role_permissions', function (Blueprint $table) {
            $table->smallInteger('role_id');
            $table->smallInteger('permission_id');

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('family_roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('family_permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_role_permissions');
        Schema::dropIfExists('family_permissions');
        Schema::dropIfExists('family_roles');
    }
};
