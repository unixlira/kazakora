<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ShopeeAdsAcao;
use App\Services\Shopee\CaueShopeeAcoes;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Estratégia Shopee, botão Ação (tarefa 569, 2026-10-14). O modal da linha
 * pede aqui as campanhas do produto como estão na Shopee e manda cada passo
 * marcado pelo Lira. O clique em Aplicar é o OK dele: nada roda sozinho.
 * Ver é financeiro.view; aplicar e desfazer gastam/poupam dinheiro de ADS e
 * exigem financeiro.create (mesma régua das campanhas da Meta).
 */
class ShopeeAdsAcaoController extends Controller
{
    public function preparar(Request $request, string $item, CaueShopeeAcoes $caue): JsonResponse
    {
        $empate = $request->filled('empate') ? (float) $request->query('empate') : null;

        try {
            $campanhas = $caue->campanhasDoItem($item);
        } catch (ShopeeException $e) {
            return response()->json(['message' => 'A Shopee não respondeu: '.$e->getMessage()], 502);
        }

        foreach ($campanhas as &$c) {
            $c['pode_meta'] = $c['tipo'] !== 'auto' && $c['lance'] === 'auto';
            $c['meta_travada_ate'] = $caue->trocaDeMetaRecente($c['campaign_id'])?->created_at
                ?->addDays(CaueShopeeAcoes::DIAS_TRAVA_META)->timezone('America/Sao_Paulo')->format('d/m H:i');
            unset($c['itens']);
        }

        return response()->json([
            'escrita_ligada' => $caue->escritaLigada(),
            'meta_sugerida' => CaueShopeeAcoes::metaSugerida($empate),
            'campanhas' => $campanhas,
            'historico' => $this->historico($item),
        ]);
    }

    public function aplicar(Request $request, CaueShopeeAcoes $caue): JsonResponse
    {
        $dados = $request->validate([
            'item_id' => ['required', 'string', 'max:32'],
            'campaign_id' => ['required', 'string', 'max:32'],
            'acao' => ['required', Rule::in(ShopeeAdsAcao::ACOES)],
            'meta' => ['nullable', 'numeric', 'min:0.1', 'max:100', 'required_if:acao,'.ShopeeAdsAcao::ACAO_META],
            'roas_empate' => ['nullable', 'numeric', 'min:0'],
            'forcar' => ['boolean'],
            'nome_produto' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $acao = $caue->aplicar(
                $dados['item_id'],
                $dados['campaign_id'],
                $dados['acao'],
                $request->user(),
                isset($dados['meta']) ? (float) $dados['meta'] : null,
                isset($dados['roas_empate']) ? (float) $dados['roas_empate'] : null,
                (bool) ($dados['forcar'] ?? false),
                $dados['nome_produto'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ShopeeException $e) {
            return response()->json(['message' => 'A Shopee não respondeu: '.$e->getMessage()], 502);
        }

        return response()->json(['acao' => $this->linha($acao), 'historico' => $this->historico($dados['item_id'])]);
    }

    public function conferir(ShopeeAdsAcao $acao, CaueShopeeAcoes $caue): JsonResponse
    {
        if (! in_array($acao->status, [ShopeeAdsAcao::STATUS_NAO_CONFERIDA, ShopeeAdsAcao::STATUS_CONFERIDA], true)) {
            return response()->json(['message' => 'Só ação mandada à Shopee pode ser conferida.'], 422);
        }

        $caue->conferir($acao);

        return response()->json(['acao' => $this->linha($acao), 'historico' => $this->historico($acao->item_id)]);
    }

    public function desfazer(Request $request, ShopeeAdsAcao $acao, CaueShopeeAcoes $caue): JsonResponse
    {
        try {
            $nova = $caue->desfazer($acao, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ShopeeException $e) {
            return response()->json(['message' => 'A Shopee não respondeu: '.$e->getMessage()], 502);
        }

        return response()->json(['acao' => $this->linha($nova), 'historico' => $this->historico($acao->item_id)]);
    }

    private function historico(string $item): array
    {
        return ShopeeAdsAcao::query()->with('user:id,name')->where('item_id', $item)
            ->latest('id')->limit(20)->get()->map(fn (ShopeeAdsAcao $a) => $this->linha($a))->all();
    }

    private function linha(ShopeeAdsAcao $a): array
    {
        return [
            'id' => $a->id,
            'campaign_id' => $a->campaign_id,
            'acao' => $a->acao,
            'descricao' => $a->descricao(),
            'status' => $a->status,
            'erro' => $a->erro,
            'quem' => $a->user?->name,
            'quando' => $a->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'desfeita' => $a->desfeita_em !== null,
            'desfaz' => $a->desfaz_id !== null,
            'pode_desfazer' => $a->podeDesfazer(),
        ];
    }
}
