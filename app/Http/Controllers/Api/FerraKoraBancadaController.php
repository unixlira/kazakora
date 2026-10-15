<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Minha Bancada do site FerraKora (pedido do Lira 2026-10-15): os produtos
 * que o cliente logado guardou. Fala nos mesmos ids do catálogo ("kz-<id>")
 * e responde sempre a lista inteira, do mais recente ao mais antigo. Quem
 * não entrou guarda no navegador; ao entrar, o site manda essa lista no
 * POST e ela se junta à da conta.
 */
class FerraKoraBancadaController extends Controller
{
    /** Teto por conta: lista de favoritos, não estoque. */
    public const LIMITE = 200;

    public function index(Request $request): JsonResponse
    {
        return $this->lista($request->user());
    }

    /** Guarda um ou vários: {"produtos": ["kz-12", "kz-30"]}. Repetido não duplica. */
    public function guardar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'produtos' => ['required', 'array', 'min:1', 'max:'.self::LIMITE],
            'produtos.*' => ['required', 'string', 'regex:/^'.CatalogoOutroSite::PREFIXO_PRODUTO.'\d{1,10}$/'],
        ]);

        /** @var FerraKoraCliente $cliente */
        $cliente = $request->user();
        $ids = collect($dados['produtos'])->map(fn (string $id) => $this->numero($id))->unique()->values();

        // Só o que está à venda no site; produto que saiu do catálogo é ignorado.
        $publicados = FerraKoraProduto::query()->where('ativo', true)->whereIn('product_id', $ids)->pluck('product_id');
        $novos = $publicados->diff(DB::table('ferrakora_bancada')->where('cliente_id', $cliente->id)->pluck('product_id'));
        $vagas = self::LIMITE - DB::table('ferrakora_bancada')->where('cliente_id', $cliente->id)->count();

        if ($novos->count() > $vagas) {
            return response()->json(['message' => 'Sua bancada chegou ao limite de '.self::LIMITE.' ferramentas. Tire alguma para guardar outra.'], 422);
        }

        // Ordem do pedido = mais recente primeiro: o primeiro da lista ganha o horário mais novo.
        $agora = now();
        $linhas = $ids->filter(fn (int $id) => $novos->contains($id))->values()->map(fn (int $id, int $posicao) => [
            'cliente_id' => $cliente->id,
            'product_id' => $id,
            'created_at' => $agora->copy()->subSeconds($posicao),
            'updated_at' => $agora,
        ]);
        DB::table('ferrakora_bancada')->insertOrIgnore($linhas->all());

        return $this->lista($cliente);
    }

    public function tirar(Request $request, string $produto): JsonResponse
    {
        DB::table('ferrakora_bancada')
            ->where('cliente_id', $request->user()->id)
            ->where('product_id', $this->numero($produto))
            ->delete();

        return $this->lista($request->user());
    }

    private function numero(string $id): int
    {
        return (int) substr($id, strlen(CatalogoOutroSite::PREFIXO_PRODUTO));
    }

    private function lista(FerraKoraCliente $cliente): JsonResponse
    {
        $produtos = DB::table('ferrakora_bancada')
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('product_id')
            ->map(fn (int $id) => CatalogoOutroSite::PREFIXO_PRODUTO.$id)
            ->values();

        return response()->json(['produtos' => $produtos]);
    }
}
