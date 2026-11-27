<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;

/**
 * Plataformas x E-commerces (pedido do Lira em 16/11/2026: "o
 * desmembramento das lojas para os marketplaces deve refletir sobre o
 * e-mail e o financeiro"). Lista ÚNICA dos canais de cada grupo: a tela
 * Financeiro › Plataformas e E-commerces e o e-mail diário de resultado
 * leem daqui.
 *
 * Os números são sempre os de ContributionMargin::porCanal(): aqui só se
 * SOMA linha de canal dentro do grupo, nunca se refaz a conta. Canal que
 * não está em nenhum grupo (origem antiga, canal novo) cai em
 * "Outros", para a soma dos grupos continuar batendo com periodo().
 */
final class GruposDeCanais
{
    public const PLATAFORMAS = 'plataformas';

    public const ECOMMERCES = 'ecommerces';

    public const OUTROS = 'outros';

    /** Canais na chave de métrica de Order::canalSql(). */
    public const GRUPOS = [
        self::PLATAFORMAS => [
            Order::ORIGIN_MERCADO_LIVRE,
            Order::ORIGIN_SHOPEE,
            Order::ORIGIN_TIKTOK_SHOP,
            Order::ORIGIN_AMAZON,
            Order::ORIGIN_SHEIN,
        ],
        self::ECOMMERCES => [
            Order::ORIGIN_STORE, // kazakora.com
            Order::SITE_FERRAKORA,
            Order::SITE_BEAUTYKORA,
        ],
    ];

    /**
     * Endereço de cada grupo no menu Painel do admin (pedido do Lira em
     * 17/11/2026: "métricas só marketplaces de um lado, meus e-commerces do
     * outro, nunca somar os dois"): /admin/painel/{slug}.
     */
    public const SLUGS = [
        'marketplaces' => self::PLATAFORMAS,
        'ecommerces' => self::ECOMMERCES,
    ];

    public const NOMES = [
        self::PLATAFORMAS => 'Marketplaces',
        self::ECOMMERCES => 'E-commerces',
        self::OUTROS => 'Outros',
    ];

    public const DESCRICOES = [
        self::PLATAFORMAS => 'Marketplaces: Mercado Livre, Shopee, TikTok Shop, Amazon e Shein',
        self::ECOMMERCES => 'Sites próprios: kazakora.com, FerraKora e Beauty Kora',
        self::OUTROS => 'Vendas fora dos dois grupos (origem antiga ou canal ainda sem grupo)',
    ];

    /**
     * Canais sem integração de ADS: o gasto aparece zerado mesmo havendo
     * anúncio, e a tela e o e-mail avisam. O ADS dos sites é o da Meta
     * (ads:sync-spend: kazakora.com e FerraKora).
     */
    public const SEM_ADS_INTEGRADO = [Order::ORIGIN_AMAZON, Order::ORIGIN_SHEIN, Order::SITE_BEAUTYKORA];

    /** Campos de porCanal() que são somados no grupo. */
    private const CAMPOS = ['pedidos', 'receita', 'custo_produtos', 'taxas', 'frete', 'insumos', 'ads', 'bonificacoes', 'comissao_zaio', 'margem', 'imposto', 'lucro'];

    /** Canais de um grupo pela chave ('plataformas') ou pelo slug ('marketplaces'). */
    public static function canais(string $grupo): array
    {
        return self::GRUPOS[self::SLUGS[$grupo] ?? $grupo] ?? [];
    }

    /** Slug de URL do grupo ('marketplaces'/'ecommerces') de um canal. */
    public static function slugDoCanal(?string $canal): string
    {
        $grupo = $canal ? self::grupoDe($canal) : self::PLATAFORMAS;

        return array_search($grupo, self::SLUGS, true) ?: 'marketplaces';
    }

    public static function grupoDe(string $canal): string
    {
        foreach (self::GRUPOS as $grupo => $canais) {
            if (in_array($canal, $canais, true)) {
                return $grupo;
            }
        }

        return self::OUTROS;
    }

    public static function nomeDoCanal(string $canal): string
    {
        return Order::MARKETPLACES[$canal] ?? Order::ROTULOS_SITE[$canal] ?? ucfirst(str_replace('_', ' ', $canal));
    }

    /**
     * Junta as linhas de ContributionMargin::porCanal() por grupo.
     * Plataformas e E-commerces sempre; Outros só quando tem algum valor.
     *
     * @param  array<string, array<string, float|int>>  $porCanal
     * @return array<string, array{chave: string, nome: string, canais: array<string, array>, total: array}>
     */
    public static function agrupar(array $porCanal): array
    {
        $grupos = [];
        foreach ([...array_keys(self::GRUPOS), self::OUTROS] as $grupo) {
            $grupos[$grupo] = ['chave' => $grupo, 'nome' => self::NOMES[$grupo], 'canais' => [], 'total' => self::metricas([])];
        }

        // Os canais do grupo aparecem sempre, na ordem da lista (zerados se não venderam).
        foreach (self::GRUPOS as $grupo => $canais) {
            foreach ($canais as $canal) {
                $grupos[$grupo]['canais'][$canal] = self::metricas($porCanal[$canal] ?? []);
            }
        }
        foreach ($porCanal as $canal => $linha) {
            if (self::grupoDe((string) $canal) === self::OUTROS) {
                $grupos[self::OUTROS]['canais'][$canal] = self::metricas($linha);
            }
        }

        foreach ($grupos as $grupo => $dados) {
            $grupos[$grupo]['total'] = self::metricas(self::somar(array_values($dados['canais'])));
        }

        if (! self::temValor($grupos[self::OUTROS]['total'])) {
            unset($grupos[self::OUTROS]);
        }

        return $grupos;
    }

    /**
     * Soma campo a campo linhas no formato de porCanal()/metricas().
     *
     * @param  list<array<string, float|int|null>>  $linhas
     * @return array<string, float|int>
     */
    public static function somar(array $linhas): array
    {
        $soma = [];
        foreach (self::CAMPOS as $campo) {
            $total = array_sum(array_map(fn ($l) => (float) ($l[$campo] ?? 0), $linhas));
            $soma[$campo] = $campo === 'pedidos' ? (int) $total : round($total, 2);
        }

        return $soma;
    }

    /**
     * Linha de porCanal() com os indicadores da tela: ADS % da receita e
     * margem líquida (lucro / receita). Sem receita, os dois ficam nulos.
     *
     * @param  array<string, float|int>  $linha
     * @return array<string, float|int|null>
     */
    public static function metricas(array $linha): array
    {
        $m = [];
        foreach (self::CAMPOS as $campo) {
            $m[$campo] = $campo === 'pedidos' ? (int) ($linha[$campo] ?? 0) : round((float) ($linha[$campo] ?? 0), 2);
        }
        $m['ads_pct'] = $m['receita'] > 0 ? round($m['ads'] / $m['receita'] * 100, 1) : null;
        $m['margem_pct'] = $m['receita'] > 0 ? round($m['lucro'] / $m['receita'] * 100, 1) : null;

        return $m;
    }

    /** @param array<string, float|int|null> $metricas */
    public static function temValor(array $metricas): bool
    {
        foreach (self::CAMPOS as $campo) {
            if (abs((float) ($metricas[$campo] ?? 0)) >= 0.005) {
                return true;
            }
        }

        return false;
    }
}
