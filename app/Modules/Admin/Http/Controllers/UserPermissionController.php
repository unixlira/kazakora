<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Rbac\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserPermissionController extends Controller
{
    public function index(Request $request): Response
    {
        $grants = RolePermission::query()->get(['role', 'permission', 'granted']);

        return Inertia::render('Admin/UserPermissions/Index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'authUserId' => $request->user()->id,
            'roles' => User::ASSIGNABLE_ROLES,
            'permissions' => Permissions::CONFIGURABLE,
            'matrix' => [
                User::ROLE_MANAGER => $grants->where('role', User::ROLE_MANAGER)->pluck('granted', 'permission'),
                User::ROLE_SUBSCRIBER => $grants->where('role', User::ROLE_SUBSCRIBER)->pluck('granted', 'permission'),
            ],
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(User::ASSIGNABLE_ROLES)],
        ]);

        $user->update($validated);

        return back()->with('success', "Perfil de {$user->name} atualizado para \"{$validated['role']}\".");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Você não pode excluir a própria conta.');
        }

        $user->delete();

        return back()->with('success', "{$user->name} foi removido.");
    }

    public function updatePermissions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_MANAGER, User::ROLE_SUBSCRIBER])],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['boolean'],
        ]);

        foreach (Permissions::CONFIGURABLE as $permission) {
            RolePermission::query()->updateOrCreate(
                ['role' => $validated['role'], 'permission' => $permission],
                ['granted' => (bool) ($validated['permissions'][$permission] ?? false)],
            );
        }

        return back()->with('success', 'Permissões atualizadas com sucesso.');
    }
}
