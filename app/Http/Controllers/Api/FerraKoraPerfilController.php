<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\Address;
use App\Modules\Profile\Concerns\ValidaPerfil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Meu Perfil do site FerraKora (pedido 2026-10-14): o mesmo da KazaKora
 * (foto, dados pessoais, endereços e senha), pela API. A conta é a da
 * KazaKora (users), então o que muda aqui muda lá também.
 */
class FerraKoraPerfilController extends Controller
{
    use ValidaPerfil;

    public function show(Request $request): JsonResponse
    {
        return $this->perfil($request->user());
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'cpf' => ['nullable', 'string', 'max:14', function ($attribute, $value, $fail) use ($user): void {
                if (User::cpfEmUso($value, $user->id)) {
                    $fail('Este CPF já está em uso em outra conta.');
                }
            }],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ]);

        $user->update($dados);

        return $this->perfil($user->fresh(), ['user' => FerraKoraContaController::paraSite($user)]);
    }

    public function avatar(Request $request): JsonResponse
    {
        $this->validarAvatar($request);
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update(['avatar_path' => $request->file('avatar')->store('avatars', 'public')]);

        return $this->perfil($user);
    }

    public function removerAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return $this->perfil($user);
    }

    public function senha(Request $request): JsonResponse
    {
        $dados = $this->validarSenha($request);

        $request->user()->forceFill([
            'password' => Hash::make($dados['password']),
            'deve_trocar_senha' => false,
        ])->save();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function criarEndereco(Request $request): JsonResponse
    {
        $user = $request->user();
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($user, $dados) {
            $principal = $dados['is_default'] || ! $user->addresses()->exists();

            if ($principal) {
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create([...$dados, 'is_default' => $principal]);
        });

        return response()->json(['enderecos' => $this->enderecos($user)], 201);
    }

    public function atualizarEndereco(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $endereco = $this->doCliente($user, $id);
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($user, $endereco, $dados) {
            if ($dados['is_default']) {
                $user->addresses()->whereKeyNot($endereco->id)->update(['is_default' => false]);
            }

            // O principal só deixa de ser principal quando outro é escolhido.
            $endereco->update([...$dados, 'is_default' => $dados['is_default'] || $endereco->is_default]);
        });

        return response()->json(['enderecos' => $this->enderecos($user)]);
    }

    public function apagarEndereco(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $endereco = $this->doCliente($user, $id);

        if ($endereco->orders()->exists()) {
            return response()->json(['message' => 'Este endereço já foi usado em um pedido e não pode ser excluído. Você pode editá-lo.'], 422);
        }

        DB::transaction(function () use ($user, $endereco) {
            $endereco->delete();

            if ($endereco->is_default) {
                $user->addresses()->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['enderecos' => $this->enderecos($user)]);
    }

    private function doCliente(User $user, int $id): Address
    {
        return $user->addresses()->whereKey($id)->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function perfil(User $user, array $extra = []): JsonResponse
    {
        return response()->json([
            'perfil' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'cpf' => $user->cpf,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'avatar_url' => $user->avatar_url,
                'initials' => $user->initials,
            ],
            // Conta da loja que não é cliente (equipe) aparece como Super Cliente no site.
            'selo' => $user->seloCliente() ?? 'super_cliente',
            'enderecos' => $this->enderecos($user),
            ...$extra,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function enderecos(User $user): array
    {
        return $user->addresses()->withExists(['orders as tem_pedido'])->get()
            ->map(fn (Address $endereco) => [
                ...$endereco->only(['id', 'label', 'recipient_name', 'phone', 'zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'is_default']),
                'tem_pedido' => (bool) $endereco->tem_pedido,
            ])->all();
    }
}
