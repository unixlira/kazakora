<?php

namespace App\Modules\Profile\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Regras do "Meu Perfil" (pedido 2026-10-14) em um lugar só: valem para a
 * KazaKora e para o Meu Perfil do FerraKora e da Beauty Kora, que chamam a
 * API da KazaKora com os mesmos campos.
 */
trait ValidaPerfil
{
    /** @return array<string, mixed> */
    protected function validarEndereco(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'zip' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'is_default' => ['boolean'],
        ], [
            'zip.regex' => 'Informe um CEP válido (00000-000).',
            'state.size' => 'A UF tem 2 letras (ex.: SP).',
        ], [
            'recipient_name' => 'nome de quem recebe',
            'phone' => 'telefone',
            'zip' => 'CEP',
            'street' => 'rua',
            'number' => 'número',
            'neighborhood' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ]);

        return [...$data, 'is_default' => (bool) ($data['is_default'] ?? false)];
    }

    /** @return array{password: string} */
    protected function validarSenha(Request $request): array
    {
        return $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
    }

    protected function validarAvatar(Request $request): void
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }
}
