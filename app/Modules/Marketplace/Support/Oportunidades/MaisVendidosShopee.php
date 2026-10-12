<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Models\ShopeeMaisVendidosCategoria;
use App\Models\ShopeeMaisVendidosColeta;
use App\Models\ShopeeMaisVendidosProduto;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Oportunidades na Shopee (correção do Lira 2026-10-12): produtos de
 * TERCEIROS com mais vendas, não as vendas da KazaKora.
 *
 * A API de vendedor (Open Platform) só enxerga a própria loja e a busca
 * pública da Shopee bloqueia robô (erro 90309999 testado da VPS). A fonte
 * oficial com vendas de outras lojas é a API de Afiliados
 * (open-api.affiliate.shopee.com.br, query productOfferV2 ordenada por
 * vendas). Precisa de AppID e senha do programa de afiliados em
 * SHOPEE_AFILIADOS_APP_ID / SHOPEE_AFILIADOS_SECRET; sem eles a tela
 * mostra o que falta.
 *
 * Central do Vendedor (pedido do Lira 2026-10-12): enquanto a API de
 * Afiliados não sai, a fonte é a mineração de Dados › Assistente de Vendas ›
 * Produtos Mais Vendidos, gravada no banco por ImportarMaisVendidosShopee.
 * Havendo coleta, a tela lê a mais recente (por categoria, subcategoria ou
 * palavra) e não chama API nenhuma.
 */
class MaisVendidosShopee
{
    public const LIMITE = 30;

    /** productOfferV2: sortType 2 = ITEM_SOLD_DESC; listType 2 = TOP_PERFORMING. */
    private const ORDENAR_POR_VENDAS = 2;

    private const LISTA_MAIS_VENDIDOS = 2;

    private const CACHE_HORAS = 6;

    public function configurado(): bool
    {
        return filled(config('services.shopee_afiliados.app_id')) && filled(config('services.shopee_afiliados.secret'));
    }

    /** Quantos produtos mostrar na visão geral e na busca por palavra da coleta. */
    public const LIMITE_COLETA = 50;

    public function analisar(string $termo = '', bool $atualizar = false, string $categoria = ''): array
    {
        if ($coleta = ShopeeMaisVendidosColeta::ultima()) {
            return $this->daColeta($coleta, trim($termo), $categoria);
        }

        if (! $this->configurado()) {
            return ['configurado' => false, 'produtos' => [], 'atualizado_em' => null, 'erro' => null];
        }

        $termo = trim($termo);
        $chave = 'oportunidades:shopee:'.md5(mb_strtolower($termo));
        if ($atualizar) {
            Cache::forget($chave);
        }

        try {
            return Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar($termo));
        } catch (Throwable $e) {
            report($e);

            return ['configurado' => true, 'produtos' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar a Shopee agora. Tente de novo em alguns minutos.'];
        }
    }

    /**
     * Categorias de nível 2 da última coleta (com o nível 1 no nome), para o
     * filtro da tela. Vazio sem coleta.
     */
    public function categorias(): array
    {
        $coleta = ShopeeMaisVendidosColeta::ultima();
        if (! $coleta) {
            return [];
        }

        return $coleta->categorias()
            ->selectRaw('l1_nome, l2_id, l2_nome, SUM(unidades_vendidas) as unidades')
            ->groupBy('l1_nome', 'l2_id', 'l2_nome')
            ->orderBy('l1_nome')->orderBy('l2_nome')
            ->get()
            ->map(fn ($c) => ['id' => (string) $c->l2_id, 'nome' => "{$c->l1_nome} › {$c->l2_nome}", 'unidades' => (int) $c->unidades])
            ->all();
    }

    /**
     * Mais vendidos de uma coleta. $categoria = "L2" ou "L2.L3" (ids da
     * Shopee). Sem categoria: os produtos com mais unidades na Shopee toda
     * e as subcategorias que mais vendem.
     */
    private function daColeta(ShopeeMaisVendidosColeta $coleta, string $termo, string $categoria): array
    {
        [$l2, $l3] = array_pad(array_map('intval', explode('.', $categoria, 2)), 2, 0);
        $l2 = preg_match('/^\d{1,12}(\.\d{1,12})?$/', $categoria) ? $l2 : 0;
        $l3 = $l2 ? $l3 : 0;

        $nomes = $coleta->categorias()->get(['l2_id', 'l2_nome', 'l3_id', 'l3_nome'])
            ->mapWithKeys(fn ($c) => [$c->l3_id => "{$c->l2_nome} › {$c->l3_nome}"]);

        $query = $coleta->produtos();
        if ($l2) {
            $query->where('l2_id', $l2);
        }
        if ($termo !== '') {
            $query->where('nome', 'like', '%'.addcslashes($termo, '%_\\').'%');
        }

        if ($l2 && $termo === '') {
            // Ranking da Shopee como ela mostra: da subcategoria ou do nível 2 inteiro.
            $linhas = $query->when($l3, fn ($q) => $q->where('l3_id', $l3), fn ($q) => $q->whereNull('l3_id'))
                ->orderBy('posicao')->get();
        } else {
            // Um produto aparece no ranking do nível 2 e no da subcategoria: fica uma vez.
            $linhas = $query->orderByDesc('unidades_vendidas')->limit(self::LIMITE_COLETA * 4)->get()
                ->unique('item_id')->take(self::LIMITE_COLETA)->values();
        }

        $produtos = $linhas->values()->map(fn (ShopeeMaisVendidosProduto $p, int $i) => [
            'id' => (string) $p->item_id,
            'posicao' => $i + 1,
            'nome' => $p->nome,
            'imagem' => $p->imagem ? 'https://down-br.img.susercontent.com/file/'.$p->imagem : null,
            'link' => "https://shopee.com.br/product/{$p->shop_id}/{$p->item_id}",
            'preco_min' => $p->preco_min ?? $p->preco,
            'preco_max' => $p->preco_max,
            'vendas' => $p->unidades_vendidas,
            'nota' => null,
            'loja' => '',
            'categoria' => $p->l3_id ? ($nomes[$p->l3_id] ?? null) : null,
            // A Shopee já tira os produtos da própria loja deste ranking.
            'voce_vende' => false,
        ])->all();

        $subcategorias = $coleta->categorias()
            ->when($l2, fn ($q) => $q->where('l2_id', $l2)->orderBy('posicao'), fn ($q) => $q->orderByDesc('unidades_vendidas')->limit(30))
            ->get()
            ->map(fn (ShopeeMaisVendidosCategoria $c) => [
                'id' => "{$c->l2_id}.{$c->l3_id}",
                'nome' => $l2 ? $c->l3_nome : "{$c->l2_nome} › {$c->l3_nome}",
                'unidades' => $c->unidades_vendidas,
            ])->all();

        return [
            'configurado' => true,
            'fonte' => 'central_vendedor',
            'produtos' => $produtos,
            'subcategorias' => $subcategorias,
            'periodo' => ['inicio' => $coleta->periodo_inicio?->toDateString(), 'fim' => $coleta->periodo_fim?->toDateString()],
            'atualizado_em' => $coleta->coletado_em->toIso8601String(),
            'erro' => null,
        ];
    }

    private function coletar(string $termo): array
    {
        $argumentos = $termo !== ''
            ? sprintf('keyword: %s, sortType: %d', json_encode($termo, JSON_UNESCAPED_UNICODE), self::ORDENAR_POR_VENDAS)
            : sprintf('listType: %d, sortType: %d', self::LISTA_MAIS_VENDIDOS, self::ORDENAR_POR_VENDAS);
        $consulta = sprintf(
            '{productOfferV2(%s, page: 1, limit: %d) { nodes { itemId shopId productName imageUrl priceMin priceMax sales ratingStar shopName productLink } } }',
            $argumentos,
            self::LIMITE,
        );

        $corpo = json_encode(['query' => $consulta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resposta = Http::withBody($corpo, 'application/json')
            ->withHeaders(['Authorization' => $this->assinatura($corpo)])
            ->timeout(20)
            ->post(config('services.shopee_afiliados.url'))
            ->throw()
            ->json();

        if (! empty($resposta['errors'])) {
            throw new RuntimeException('Shopee Afiliados: '.json_encode($resposta['errors'], JSON_UNESCAPED_UNICODE));
        }

        $nossaLoja = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->value('seller_id');
        $produtos = [];
        foreach ($resposta['data']['productOfferV2']['nodes'] ?? [] as $no) {
            $produtos[] = [
                'id' => (string) $no['itemId'],
                'posicao' => count($produtos) + 1,
                'nome' => (string) ($no['productName'] ?? ''),
                'imagem' => $no['imageUrl'] ?? null,
                'link' => (string) ($no['productLink'] ?? ''),
                'preco_min' => isset($no['priceMin']) ? (float) $no['priceMin'] : null,
                'preco_max' => isset($no['priceMax']) ? (float) $no['priceMax'] : null,
                'vendas' => isset($no['sales']) ? (int) $no['sales'] : null,
                'nota' => isset($no['ratingStar']) ? (float) $no['ratingStar'] : null,
                'loja' => (string) ($no['shopName'] ?? ''),
                'voce_vende' => $nossaLoja !== '' && (string) ($no['shopId'] ?? '') === $nossaLoja,
            ];
        }

        return ['configurado' => true, 'produtos' => $produtos, 'atualizado_em' => now()->toIso8601String(), 'erro' => null];
    }

    /** Authorization da API de Afiliados: SHA256(AppId + Timestamp + corpo + Secret). */
    private function assinatura(string $corpo): string
    {
        $appId = (string) config('services.shopee_afiliados.app_id');
        $agora = time();
        $assinatura = hash('sha256', $appId.$agora.$corpo.config('services.shopee_afiliados.secret'));

        return "SHA256 Credential={$appId}, Timestamp={$agora}, Signature={$assinatura}";
    }
}
