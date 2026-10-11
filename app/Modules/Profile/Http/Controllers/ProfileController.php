<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProfileController extends Controller
{
    public function edit(Request $request, ?User $user = null): Response
    {
        $target = $this->resolveTarget($request, $user);

        return Inertia::render('Profile/Edit', [
            'profileUser' => $target,
            'isOwnProfile' => $target->is($request->user()),
            'canManageUsers' => $request->user()->isAdmin(),
            'roles' => $request->user()->isAdmin() ? User::ASSIGNABLE_ROLES : [],
        ]);
    }

    public function update(Request $request, ?User $user = null): RedirectResponse
    {
        $target = $this->resolveTarget($request, $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'cpf' => ['nullable', 'string', 'max:14', Rule::unique('users', 'cpf')->ignore($target->id)],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ]);

        $target->update($validated);

        return back()->with('success', 'Perfil atualizado com sucesso.');
    }

    /**
     * A user always manages their own profile; an admin may additionally
     * open and edit anyone else's — everyone else gets a 403.
     */
    private function resolveTarget(Request $request, ?User $user): User
    {
        $target = $user ?? $request->user();

        if (! $target->is($request->user()) && ! $request->user()->isAdmin()) {
            throw new HttpException(403, 'Você não tem permissão para acessar o perfil de outro usuário.');
        }

        return $target;
    }
}
