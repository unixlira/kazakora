<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

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

    public function analisar(string $termo = '', bool $atualizar = false): array
    {
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
