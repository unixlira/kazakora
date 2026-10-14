<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraEndereco;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Conta, endereço e pedidos do site Beauty Kora (pedido 2026-10-12), que
 * antes ficavam no PocketBase do Horizons. O site guarda o token no
 * navegador e manda como Bearer (middleware AuthenticateBeautyKoraCliente).
 */
class BeautyKoraContaController extends Controller
{
    public function cadastro(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('beautykora_clientes', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        $cliente = BeautyKoraCliente::query()->create([...$dados, 'email' => mb_strtolower($dados['email'])]);

        return $this->comToken($cliente, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $cliente = BeautyKoraCliente::query()->where('email', mb_strtolower($dados['email']))->first();
        if (! $cliente || ! Hash::check($dados['password'], $cliente->password)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }

        return $this->comToken($cliente);
    }

    public function eu(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->paraSite()]);
    }

    public function atualizar(Request $request): JsonResponse
    {
        $dados = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $cliente = $request->user();
        $cliente->update($dados);

        return response()->json(['user' => $cliente->paraSite()]);
    }

    public function sair(Request $request): JsonResponse
    {
        $request->attributes->get('beautykora_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function endereco(Request $request): JsonResponse
    {
        $endereco = $request->user()->enderecos()->latest('id')->first();

        return response()->json(['address' => $endereco?->paraSite()]);
    }

    public function salvarEndereco(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'cep' => ['required', 'string', 'max:9'],
            'logradouro' => ['required', 'string', 'max:191'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:120'],
            'bairro' => ['required', 'string', 'max:120'],
            'cidade' => ['required', 'string', 'max:120'],
            'estado' => ['required', 'string', 'size:2'],
        ]);
        $dados['estado'] = mb_strtoupper($dados['estado']);

        $cliente = $request->user();
        $endereco = $cliente->enderecos()->latest('id')->first();
        $endereco ? $endereco->update($dados) : $endereco = $cliente->enderecos()->create($dados);

        return response()->json(['address' => $endereco->paraSite()]);
    }

    public function apagarEndereco(Request $request, int $id): JsonResponse
    {
        $request->user()->enderecos()->whereKey($id)->firstOrFail()->delete();

        return response()->json(['ok' => true]);
    }

    public function pedidos(Request $request): JsonResponse
    {
        $pedidos = $request->user()->pedidos()->with('order')->latest('created_at')->latest('id')->limit(200)->get();

        return response()->json(['orders' => $pedidos->map->paraSite()->values()]);
    }

    public function pedido(Request $request, int $id): JsonResponse
    {
        $pedido = $request->user()->pedidos()->whereKey($id)->firstOrFail();
        $endereco = $pedido->endereco_id ? BeautyKoraEndereco::query()->find($pedido->endereco_id) : null;

        return response()->json([
            'order' => $pedido->paraSite(),
            'address' => $endereco?->paraSite() ?? ($pedido->endereco ? ['id' => null, ...$pedido->endereco] : null),
        ]);
    }

    /** O checkout manda o carrinho inteiro: um registro por produto. */
    public function criarPedidos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'address_id' => ['nullable', 'integer'],
            // E-mail digitado no checkout da Hostinger: é por ele que o
            // pagamento é achado depois (beautykora:conciliar-pagamentos).
            'email' => ['nullable', 'email', 'max:191'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:64'],
            'items.*.variant_id' => ['nullable', 'string', 'max:64'],
            'items.*.product_name' => ['required', 'string', 'max:191'],
            'items.*.product_image' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.valor_pago' => ['required', 'integer', 'min:0'],
        ]);

        $cliente = $request->user();
        $endereco = isset($dados['address_id'])
            ? $cliente->enderecos()->whereKey($dados['address_id'])->first()
            : null;

        $lote = (string) Str::uuid();
        $email = mb_strtolower($dados['email'] ?? $cliente->email);

        $pedidos = DB::transaction(fn () => collect($dados['items'])->map(fn (array $item) => BeautyKoraPedido::query()->create([
            'cliente_id' => $cliente->id,
            'endereco_id' => $endereco?->id,
            'produto_id' => $item['product_id'],
            'variante_id' => $item['variant_id'] ?? null,
            'lote' => $lote,
            'email_checkout' => $email,
            'produto_nome' => $item['product_name'],
            'produto_imagem' => $item['product_image'] ?? null,
            'quantidade' => $item['quantity'],
            'valor_pago_centavos' => $item['valor_pago'],
            'status' => 'pending',
            'endereco' => $endereco?->only(BeautyKoraEndereco::CAMPOS),
        ])));

        return response()->json(['orders' => $pedidos->map->paraSite()->values()], 201);
    }

    private function comToken(BeautyKoraCliente $cliente, int $status = 200): JsonResponse
    {
        $token = $cliente->createToken('beautykora-site', ['beautykora:cliente'], now()->addDays(30));

        return response()->json(['token' => $token->plainTextToken, 'user' => $cliente->paraSite()], $status);
    }
}
