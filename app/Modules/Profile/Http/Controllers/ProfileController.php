<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'profileUser' => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'cpf' => ['nullable', 'string', 'max:14', Rule::unique('users', 'cpf')->ignore($user->id)],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ]);

        // Always the currently authenticated user — never accepts an id from
        // the request, so there's no way to edit anyone else's profile.
        $user->update($validated);

        return back()->with('success', 'Perfil atualizado com sucesso.');
    }
}
