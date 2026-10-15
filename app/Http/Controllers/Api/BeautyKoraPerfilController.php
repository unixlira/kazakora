<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraEndereco;
use App\Modules\Profile\Concerns\ValidaPerfil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Meu Perfil do site Beauty Kora (pedido 2026-10-14): o mesmo da KazaKora
 * (foto, dados pessoais, endereços e senha), pela API. A conta é a própria
 * da Beauty Kora (beautykora_clientes); a resposta usa os mesmos nomes de
 * campo do Meu Perfil da KazaKora e do FerraKora.
 */
class BeautyKoraPerfilController extends Controller
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
            'email' => ['required', 'email', 'max:191', Rule::unique('beautykora_clientes', 'email')->ignore($cliente->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'cpf' => ['nullable', 'string', 'max:14'],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ], [
            'email.unique' => 'Já existe uma conta com este e-mail.',
        ]);

        $cliente->update([...$dados, 'email' => mb_strtolower(trim($dados['email']))]);

        return $this->perfil($cliente->fresh(), ['user' => $cliente->fresh()->paraSite()]);
    }

    public function avatar(Request $request): JsonResponse
    {
        $this->validarAvatar($request);
        $cliente = $request->user();

        if ($cliente->avatar_path) {
            Storage::disk('public')->delete($cliente->avatar_path);
        }

        $cliente->update(['avatar_path' => $request->file('avatar')->store('beautykora/avatars', 'public')]);

        return $this->perfil($cliente, ['user' => $cliente->paraSite()]);
    }

    public function removerAvatar(Request $request): JsonResponse
    {
        $cliente = $request->user();

        if ($cliente->avatar_path) {
            Storage::disk('public')->delete($cliente->avatar_path);
            $cliente->update(['avatar_path' => null]);
        }

        return $this->perfil($cliente, ['user' => $cliente->paraSite()]);
    }

    public function senha(Request $request): JsonResponse
    {
        $dados = $this->validarSenha($request);

        // Cast "hashed" do modelo faz o hash.
        $request->user()->update(['password' => $dados['password']]);

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function criarEndereco(Request $request): JsonResponse
    {
        $cliente = $request->user();
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($cliente, $dados) {
            $principal = $dados['is_default'] || ! $cliente->enderecos()->exists();

            if ($principal) {
                $cliente->enderecos()->update(['principal' => false]);
            }

            $cliente->enderecos()->create([...BeautyKoraEndereco::doPerfil($dados), 'principal' => $principal]);
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)], 201);
    }

    public function atualizarEndereco(Request $request, int $id): JsonResponse
    {
        $cliente = $request->user();
        $endereco = $cliente->enderecos()->whereKey($id)->firstOrFail();
        $dados = $this->validarEndereco($request);

        DB::transaction(function () use ($cliente, $endereco, $dados) {
            if ($dados['is_default']) {
                $cliente->enderecos()->whereKeyNot($endereco->id)->update(['principal' => false]);
            }

            // O principal só deixa de ser principal quando outro é escolhido.
            $endereco->update([...BeautyKoraEndereco::doPerfil($dados), 'principal' => $dados['is_default'] || $endereco->principal]);
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)]);
    }

    public function apagarEndereco(Request $request, int $id): JsonResponse
    {
        $cliente = $request->user();
        $endereco = $cliente->enderecos()->whereKey($id)->firstOrFail();

        if ($endereco->pedidos()->exists()) {
            return response()->json(['message' => 'Este endereço já foi usado em um pedido e não pode ser excluído. Você pode editá-lo.'], 422);
        }

        DB::transaction(function () use ($cliente, $endereco) {
            $endereco->delete();

            if ($endereco->principal) {
                $cliente->enderecos()->latest('id')->first()?->update(['principal' => true]);
            }
        });

        return response()->json(['enderecos' => $this->enderecos($cliente)]);
    }

    /** @param array<string, mixed> $extra */
    private function perfil(BeautyKoraCliente $cliente, array $extra = []): JsonResponse
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
    private function enderecos(BeautyKoraCliente $cliente): array
    {
        return $cliente->enderecos()
            ->withExists(['pedidos as tem_pedido'])
            ->orderByDesc('principal')
            ->latest('id')
            ->get()
            ->map->paraPerfil()
            ->all();
    }
}
