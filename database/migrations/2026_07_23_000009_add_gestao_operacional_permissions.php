<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newPermissions = [
            'relatorios.view',
            'financeiro.view',
            'financeiro.create',
            'financeiro.edit',
            'financeiro.delete',
            'operacional.view',
            'operacional.create',
            'operacional.edit',
            'operacional.delete',
        ];

        $grantedByDefault = [
            User::ROLE_MANAGER => [
                'relatorios.view', 'financeiro.view', 'financeiro.create', 'financeiro.edit',
                'operacional.view', 'operacional.create', 'operacional.edit',
            ],
            User::ROLE_SUBSCRIBER => [
                'relatorios.view', 'financeiro.view', 'operacional.view',
            ],
        ];

        $now = now();
        $rows = [];

        foreach ([User::ROLE_MANAGER, User::ROLE_SUBSCRIBER] as $role) {
            foreach ($newPermissions as $permission) {
                $rows[] = [
                    'role' => $role,
                    'permission' => $permission,
                    'granted' => in_array($permission, $grantedByDefault[$role], true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('role_permissions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [
            'relatorios.view', 'financeiro.view', 'financeiro.create', 'financeiro.edit', 'financeiro.delete',
            'operacional.view', 'operacional.create', 'operacional.edit', 'operacional.delete',
        ])->delete();
    }
};
