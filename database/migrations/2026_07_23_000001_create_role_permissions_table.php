<?php

use App\Models\User;
use App\Support\Rbac\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('permission');
            $table->boolean('granted')->default(false);
            $table->timestamps();

            $table->unique(['role', 'permission']);
        });

        $now = now();
        $rows = [];

        foreach ([User::ROLE_MANAGER, User::ROLE_SUBSCRIBER] as $role) {
            foreach (Permissions::ALL as $permission) {
                $rows[] = [
                    'role' => $role,
                    'permission' => $permission,
                    'granted' => in_array($permission, Permissions::DEFAULTS[$role] ?? [], true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('role_permissions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
