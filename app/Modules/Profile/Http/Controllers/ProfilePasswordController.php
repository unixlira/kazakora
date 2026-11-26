<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfilePasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $eraTemporaria = (bool) $request->user()->deve_trocar_senha;
        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            // Senha pessoal criada: some o pedido de troca da senha temporária.
            'deve_trocar_senha' => false,
        ])->save();
        \App\Modules\Clientes\Support\HistoricoDoCliente::kazakora($request->user(), 'senha_trocada', $eraTemporaria ? ['era_temporaria' => true] : []);

        return back()->with('success', 'Senha alterada com sucesso.');
    }
}
