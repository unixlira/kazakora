<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraEndereco;
use App\Modules\Profile\Concerns\ValidaPerfil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Meu Perfil do site FerraKora (pedido 2026-10-14): o mesmo da KazaKora
 * (foto, dados pessoais, endereços e senha), pela API. Desde 2026-10-15 a
 * conta é a do FerraKora (ferrakora_clientes, ferrakora_enderecos): o que
 * muda aqui não mexe na KazaKora.
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
        $cliente = $request->user();

        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('ferrakora_clientes', 'email')->ignore($cliente->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'cpf' => ['nullable', 'string', 'max:14', function ($attribute, $value, $fail) use ($cliente): void {
                if (FerraKoraCliente::cpfEmUso(preg_replace('/\D/', '', (string) $value), $cliente->id)) {
                    $fail('Este CPF já está em uso em outra conta.');
                }
            }],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ], [
            'email.unique' => 'Já existe uma conta com este e-mail.',
        ]);

        $cliente->update([
            ...$dados,
            'email' => mb_strtolower(trim($dados['email'])),
            'cpf' => preg_replace('/\D/', '', (string) ($dados['cpf'] ?? '')) ?: null,
        ]);

        return $this->perfil($cliente->fresh(), ['user' => $cliente->fresh()->paraSite()]);
    }

    public function avatar(Request $request): JsonResponse
    {
        $this->validarAvatar($request);
        $cliente = $request->user();

        if ($cliente->avatar_path) {
            Storage::disk('public')->delete($cliente->avatar_path);
        }

        $cliente->update(['avatar_path' => $request->file('avatar')->store('ferrakora/avatars', 'public')]);

        return $this->perfil($cliente);
    }

    public function removerAvatar(Request $request): JsonResponse
    {
        $cliente = $request->user();

        if ($cliente->avatar_path) {
            Storage::disk('public')->delete($cliente->avatar_path);
            $cliente->update(['avatar_path' => null]);
        }

        return $this->perfil($cliente);
    }

    public function senha(Request $request): JsonResponse
    {
        $dados = $this->validarSenha($request);

        // Cast "hashed" do modelo faz o hash.
        $request->user()->forceFill(['password' => $dados['password'], 'senha_definida' => true])->save();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function criarEndereco(Request $request): JsonResponse
    {
        $cliente = $request->user();
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($cliente, $dados) {
            $principal = $dados['is_default'] || ! $cliente->enderecos()->exists();

            if ($principal) {
                $cliente->enderecos()->update(['is_default' => false]);
            }

            $cliente->enderecos()->create([...$dados, 'state' => mb_strtoupper($dados['state']), 'is_default' => $principal]);
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)], 201);
    }

    public function atualizarEndereco(Request $request, int $id): JsonResponse
    {
        $cliente = $request->user();
        $endereco = $this->doCliente($cliente, $id);
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($cliente, $endereco, $dados) {
            if ($dados['is_default']) {
                $cliente->enderecos()->whereKeyNot($endereco->id)->update(['is_default' => false]);
            }

            // O principal só deixa de ser principal quando outro é escolhido.
            $endereco->update([...$dados, 'state' => mb_strtoupper($dados['state']), 'is_default' => $dados['is_default'] || $endereco->is_default]);
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)]);
    }

    public function apagarEndereco(Request $request, int $id): JsonResponse
    {
        $cliente = $request->user();
        $endereco = $this->doCliente($cliente, $id);

        if ($endereco->pedidos()->exists()) {
            return response()->json(['message' => 'Este endereço já foi usado em um pedido e não pode ser excluído. Você pode editá-lo.'], 422);
        }

        DB::transaction(function () use ($cliente, $endereco) {
            $endereco->delete();

            if ($endereco->is_default) {
                $cliente->enderecos()->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)]);
    }

    private function doCliente(FerraKoraCliente $cliente, int $id): FerraKoraEndereco
    {
        return $cliente->enderecos()->whereKey($id)->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function perfil(FerraKoraCliente $cliente, array $extra = []): JsonResponse
    {
        return response()->json([
            'perfil' => [
                'name' => $cliente->name,
                'email' => $cliente->email,
                'phone' => $cliente->phone,
                'cpf' => $cliente->cpf,
                'birth_date' => $cliente->birth_date?->format('Y-m-d'),
                'avatar_url' => $cliente->avatarUrl(),
                'initials' => $cliente->iniciais(),
            ],
            'selo' => $cliente->seloCliente(),
            'enderecos' => $this->enderecos($cliente),
            ...$extra,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function enderecos(FerraKoraCliente $cliente): array
    {
        return $cliente->enderecos()
            ->withExists(['pedidos as tem_pedido'])
            ->orderByDesc('is_default')
            ->latest('id')
            ->get()
            ->map->paraPerfil()
            ->all();
    }
}
