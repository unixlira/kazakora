<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\App\Support\FormatoApp;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Support\CompletarEnderecoPeloCep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Endereços da conta no app (2026-10-18), com a regra do "Meu Perfil" do
 * site (ProfileAddressController + ValidaPerfil): o primeiro vira o
 * principal, escolher outro principal tira o anterior, e endereço já usado
 * em pedido não pode ser excluído (só editado: o pedido guarda a cópia).
 * Campos com os nomes do contrato do app; rua, bairro, cidade e UF vazios
 * são completados pelo CEP, como no checkout.
 */
class EnderecoAppController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['enderecos' => $request->user()->addresses()->get()->map(fn (Address $a) => FormatoApp::endereco($a))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $dados = $this->validar($request);

        $endereco = DB::transaction(function () use ($user, $dados) {
            $principal = $dados['is_default'] || ! $user->addresses()->exists();
            if ($principal) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create([...$dados, 'is_default' => $principal]);
        });

        return response()->json(['endereco' => FormatoApp::endereco($endereco)], 201);
    }

    public function update(Request $request, int $endereco): JsonResponse
    {
        $address = $this->daConta($request, $endereco);
        if (! $address) {
            return $this->naoEncontrado();
        }
        $dados = $this->validar($request);

        DB::transaction(function () use ($request, $address, $dados) {
            if ($dados['is_default']) {
                $request->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }

            // O principal só deixa de ser principal quando outro é escolhido.
            $address->update([...$dados, 'is_default' => $dados['is_default'] || $address->is_default]);
        });

        return response()->json(['endereco' => FormatoApp::endereco($address->refresh())]);
    }

    public function destroy(Request $request, int $endereco): Response|JsonResponse
    {
        $address = $this->daConta($request, $endereco);
        if (! $address) {
            return $this->naoEncontrado();
        }

        if ($address->orders()->exists()) {
            $mensagem = 'Este endereço já foi usado em um pedido e não pode ser excluído. Você pode editá-lo.';

            return response()->json(['message' => $mensagem, 'errors' => ['endereco' => [$mensagem]]], 422);
        }

        DB::transaction(function () use ($request, $address) {
            $address->delete();

            if ($address->is_default) {
                $request->user()->addresses()->first()?->update(['is_default' => true]);
            }
        });

        return response()->noContent();
    }

    private function daConta(Request $request, int $id): ?Address
    {
        return Address::query()->whereKey($id)->where('user_id', $request->user()->id)->first();
    }

    private function naoEncontrado(): JsonResponse
    {
        return response()->json(['message' => 'Endereço não encontrado.'], 404);
    }

    /** Regras do ValidaPerfil::validarEndereco com os nomes do app. @return array<string, mixed> colunas do Address */
    private function validar(Request $request): array
    {
        $doCep = app(CompletarEnderecoPeloCep::class)->completar([
            'zip' => $request->input('cep'),
            'street' => $request->input('rua'),
            'neighborhood' => $request->input('bairro'),
            'city' => $request->input('cidade'),
            'state' => $request->input('uf'),
        ]);
        $request->merge(array_filter([
            'rua' => $doCep['street'] ?? null,
            'bairro' => $doCep['neighborhood'] ?? null,
            'cidade' => $doCep['city'] ?? null,
            'uf' => $doCep['state'] ?? null,
        ], fn ($valor) => filled($valor)));

        $dados = $request->validate([
            'apelido' => ['nullable', 'string', 'max:60'],
            'destinatario' => ['required', 'string', 'max:255'],
            'telefone' => ['required', 'string', 'max:20'],
            'cep' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'rua' => ['required', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'uf' => ['required', 'string', 'size:2'],
            'principal' => ['nullable', 'boolean'],
        ], [
            'cep.regex' => 'Informe um CEP válido (00000-000).',
            'uf.size' => 'A UF tem 2 letras (ex.: SP).',
        ], [
            'destinatario' => 'nome de quem recebe',
            'telefone' => 'telefone',
            'cep' => 'CEP',
            'numero' => 'número',
            'uf' => 'UF',
        ]);

        return [
            'label' => $dados['apelido'] ?? null,
            'recipient_name' => trim($dados['destinatario']),
            'phone' => $dados['telefone'],
            'zip' => $dados['cep'],
            'street' => $dados['rua'],
            'number' => $dados['numero'],
            'complement' => $dados['complemento'] ?? null,
            'neighborhood' => $dados['bairro'],
            'city' => $dados['cidade'],
            'state' => $dados['uf'],
            'is_default' => (bool) ($dados['principal'] ?? false),
        ];
    }
}
