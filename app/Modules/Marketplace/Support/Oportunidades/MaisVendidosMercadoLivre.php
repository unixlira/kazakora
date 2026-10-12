<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Oportunidades no Mercado Livre (correção do Lira 2026-10-12): produtos de
 * TERCEIROS que mais vendem e buscas em alta, não as vendas da KazaKora.
 *
 * Fontes oficiais da API do ML:
 * - /highlights/MLB/category/{id}: ranking "Mais vendidos" da categoria.
 *   Só as entradas do tipo PRODUCT (produto de catálogo) têm detalhe
 *   liberado; anúncio avulso (ITEM/USER_PRODUCT) de outro vendedor volta 403
 *   desde 2025, por isso entra só na contagem de "ocultos".
 * - /products/{id}/items: vendedores do produto (concorrência e menor preço).
 * - /trends/MLB/{id}: buscas que mais crescem na categoria.
 *
 * Resultado em cache (6 h) porque são ~40 chamadas por categoria.
 */
class MaisVendidosMercadoLivre
{
    public const SITE = 'MLB';

    /** Quantos produtos do ranking detalhar. */
    public const LIMITE = 20;

    private const CACHE_HORAS = 6;

    public function __construct(private readonly MercadoLivreClient $ml) {}

    /** @return list<array{id: string, nome: string}> Categorias de primeiro nível. */
    public function categorias(): array
    {
        try {
            return Cache::remember('oportunidades:ml:categorias', now()->addDay(), fn () => array_values(array_map(
                fn (array $c) => ['id' => (string) $c['id'], 'nome' => (string) $c['name']],
                $this->ml->get('sites/'.self::SITE.'/categories'),
            )));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function analisar(string $categoria, bool $atualizar = false): array
    {
        $chave = "oportunidades:ml:{$categoria}";
        if ($atualizar) {
            Cache::forget($chave);
        }

        try {
            return Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar($categoria));
        } catch (Throwable $e) {
            report($e);

            return ['produtos' => [], 'tendencias' => [], 'ocultos' => 0, 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Mercado Livre agora. Tente de novo em alguns minutos.'];
        }
    }

    private function coletar(string $categoria): array
    {
        $nosso = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->value('seller_id');
        $ranking = $this->ml->get('highlights/'.self::SITE."/category/{$categoria}")['content'] ?? [];

        $produtos = [];
        $ocultos = 0;
        foreach ($ranking as $entrada) {
            if (($entrada['type'] ?? null) !== 'PRODUCT') {
                $ocultos++;

                continue;
            }
            if (count($produtos) >= self::LIMITE) {
                continue;
            }
            $linha = $this->produto((string) $entrada['id'], (int) ($entrada['position'] ?? 0), $nosso);
            if ($linha) {
                $produtos[] = $linha;
            }
        }

        try {
            $tendencias = array_values(array_map(
                fn (array $t) => ['termo' => (string) $t['keyword'], 'link' => (string) ($t['url'] ?? '')],
                array_slice($this->ml->get('trends/'.self::SITE."/{$categoria}"), 0, 20),
            ));
        } catch (Throwable $e) {
            report($e);
            $tendencias = [];
        }

        return [
            'produtos' => $produtos,
            'tendencias' => $tendencias,
            'ocultos' => $ocultos,
            'atualizado_em' => now()->toIso8601String(),
            'erro' => null,
        ];
    }

    private function produto(string $id, int $posicao, string $nosso): ?array
    {
        try {
            $produto = $this->ml->get("products/{$id}");
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $precos = [];
        $vendedores = null;
        $voceVende = false;
        try {
            $ofertas = $this->ml->get("products/{$id}/items", ['limit' => 50]);
            $vendedores = (int) ($ofertas['paging']['total'] ?? 0);
            foreach ($ofertas['results'] ?? [] as $oferta) {
                if (isset($oferta['price'])) {
                    $precos[] = (float) $oferta['price'];
                }
                if ($nosso !== '' && (string) ($oferta['seller_id'] ?? '') === $nosso) {
                    $voceVende = true;
                }
            }
        } catch (Throwable $e) {
            // Produto sem vendedor ativo também volta erro: segue sem preço.
        }

        return [
            'id' => $id,
            'posicao' => $posicao,
            'nome' => (string) ($produto['name'] ?? $id),
            'imagem' => $produto['pictures'][0]['url'] ?? null,
            'link' => ($produto['permalink'] ?? '') ?: "https://www.mercadolivre.com.br/p/{$id}",
            'preco_min' => $precos ? min($precos) : null,
            'preco_max' => $precos ? max($precos) : null,
            'vendedores' => $vendedores,
            'voce_vende' => $voceVende,
        ];
    }
}
