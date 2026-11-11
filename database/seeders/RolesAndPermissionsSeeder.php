<?php

namespace Database\Seeders;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Writes the roles/permissions matrix from the enums into the database.
 *
 * Safe to run in production and on every deployment: it only inserts, updates and removes
 * rows so the tables match the enums exactly.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('family_roles')->upsert(
                array_map(fn (FamilyRole $role): array => [
                    'id' => $role->id(),
                    'key' => $role->value,
                    'name' => $role->label(),
                    'rank' => $role->rank(),
                ], FamilyRole::cases()),
                ['id'],
                ['key', 'name', 'rank'],
            );

            DB::table('family_permissions')->upsert(
                array_map(fn (FamilyPermission $permission): array => [
                    'id' => $permission->id(),
                    'key' => $permission->value,
                    'description' => $permission->description(),
                ], FamilyPermission::cases()),
                ['id'],
                ['key', 'description'],
            );

            $grants = [];

            foreach (FamilyPermission::cases() as $permission) {
                foreach ($permission->roles() as $role) {
                    $grants[] = ['role_id' => $role->id(), 'permission_id' => $permission->id()];
                }
            }

            DB::table('family_role_permissions')->delete();
            DB::table('family_role_permissions')->insert($grants);

            DB::table('family_permissions')
                ->whereNotIn('id', array_map(fn (FamilyPermission $permission): int => $permission->id(), FamilyPermission::cases()))
                ->delete();
        });

        app(FamilyAccess::class)->forgetMatrix();
    }
}
