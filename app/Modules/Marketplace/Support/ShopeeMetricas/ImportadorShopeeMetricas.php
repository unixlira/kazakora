<?php

namespace App\Modules\Marketplace\Support\ShopeeMetricas;

use App\Modules\Marketplace\Models\ShopeeMetrica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lê os arquivos JSON das métricas da Shopee e grava em shopee_metricas
 * (pedido do Lira em 2026-10-26). Três formatos:
 *
 *  - tela: o do LEIA-ME de ~/projects/homer/dados/shopee-tela (cabeçalho +
 *    `linhas`, uma por número, porcentagem já em pontos percentuais);
 *  - funil: coleta diária das 07h10 (funil-7d.json / funil-30d.json, campos
 *    da /api/mydata/v4/product/performance, porcentagem em fração);
 *  - ads: ads-7d.json / ads-30d.json da mesma coleta (gasto, venda, pedidos,
 *    cliques e impressões de ADS por anúncio, vindos da API da Shopee Ads).
 *    O bloco `margem` desse arquivo NÃO entra: é uma conta de lucro feita
 *    fora do KazaKora, e lucro aqui é só a ContributionMargin.
 *
 * Reimportar o mesmo arquivo não duplica (chave única). Se a linha já
 * existe com coleta mais nova, a mais velha não sobrescreve.
 */
class ImportadorShopeeMetricas
{
    /**
     * Campos da coleta diária (funil) → métrica. Mesmas chaves da coleta
     * da tela dia a dia (2026-10-26-produto-desempenho-dia.json), para a
     * tela somar os dois sem tradução: add_carrinho = unidades no carrinho,
     * add_carrinho_compradores = pessoas, pedidos = pedidos feitos.
     */
    public const MAPA_FUNIL = [
        'product_card_impressions' => ['impressoes', 'Impressões do produto'],
        'unique_product_card_impressions' => ['impressoes_unicas', 'Pessoas que viram o produto'],
        'product_card_clicks' => ['cliques', 'Cliques no produto'],
        'unique_product_card_clicks' => ['cliques_unicos', 'Pessoas que clicaram'],
        'ctr' => ['ctr', 'CTR'],
        'search_clicks' => ['cliques_busca', 'Cliques pela busca'],
        'uv' => ['visitantes', 'Visitantes do produto'],
        'pv' => ['visualizacoes', 'Visualizações da página'],
        'likes' => ['curtidas', 'Curtidas'],
        'bounce_visitors' => ['visitantes_rejeicao', 'Visitantes que saíram sem interagir'],
        'bounce_rate' => ['taxa_rejeicao', 'Taxa de rejeição'],
        'add_to_cart_units' => ['add_carrinho', 'Unidades adicionadas ao carrinho'],
        'add_to_cart_buyers' => ['add_carrinho_compradores', 'Compradores que adicionaram ao carrinho'],
        'uv_to_add_to_cart_rate' => ['taxa_carrinho', 'Visitante → carrinho'],
        'placed_orders' => ['pedidos', 'Pedidos realizados'],
        'placed_units' => ['unidades', 'Unidades pedidas'],
        'placed_sales' => ['vendas', 'Vendas (pedidos realizados)'],
        'placed_buyers' => ['compradores', 'Compradores (pedidos realizados)'],
        'paid_orders' => ['pedidos_pagos', 'Pedidos pagos'],
        'paid_units' => ['unidades_pagas', 'Unidades pagas'],
        'paid_sales' => ['vendas_pagas', 'Vendas pagas'],
        'paid_buyers' => ['compradores_pagos', 'Compradores (pedidos pagos)'],
        'confirmed_orders' => ['pedidos_confirmados', 'Pedidos confirmados'],
        'confirmed_units' => ['unidades_confirmadas', 'Unidades confirmadas'],
        'confirmed_sales' => ['vendas_confirmadas', 'Vendas confirmadas'],
        'confirmed_buyers' => ['compradores_confirmados', 'Compradores (pedidos confirmados)'],
        'placed_order_conversion_rate' => ['conversao', 'Taxa de conversão (pedido realizado)'],
        'paid_order_conversion_rate' => ['conversao_paga', 'Taxa de conversão (pedido pago)'],
        'confirmed_order_conversion_rate' => ['conversao_confirmada', 'Taxa de conversão (pedido confirmado)'],
        'placed_to_paid_buyers_rate' => ['taxa_pagamento', 'Pedido feito → pago'],
        'placed_buyers_to_confirmed_buyers_rate' => ['taxa_confirmacao', 'Pedido feito → confirmado'],
        'placed_to_confirmed_buyers_rate' => ['taxa_confirmacao', 'Pedido feito → confirmado'],
        'uv_to_placed_buyers_rate' => ['visitante_comprador', 'Visitante → comprador'],
        'uv_to_paid_buyers_rate' => ['visitante_comprador_pago', 'Visitante → comprador (pago)'],
        'uv_to_confirmed_buyers_rate' => ['visitante_comprador_confirmado', 'Visitante → comprador (confirmado)'],
        'placed_sales_per_order' => ['ticket_medio', 'Ticket médio'],
        'paid_sales_per_order' => ['ticket_medio_pago', 'Ticket médio (pago)'],
        'confirmed_sales_per_order' => ['ticket_medio_confirmado', 'Ticket médio (confirmado)'],
        'paid_sales_per_buyers' => ['venda_por_comprador_pago', 'Venda por comprador (pago)'],
        'repeat_placed_order_rate' => ['taxa_recompra', 'Recompra'],
        'average_days_to_repeat_placed_order' => ['dias_recompra', 'Dias até recomprar'],
        'repeat_paid_order_rate' => ['taxa_recompra_paga', 'Recompra (pago)'],
        'average_days_to_repeat_paid_order' => ['dias_recompra_paga', 'Dias até recomprar (pago)'],
        'repeat_confirmed_order_rate' => ['taxa_recompra_confirmada', 'Recompra (confirmado)'],
        'average_days_to_repeat_confirmed_order' => ['dias_recompra_confirmada', 'Dias até recomprar (confirmado)'],
    ];

    /** Campos do item da coleta que não são número de desempenho. */
    private const FORA_FUNIL = ['id', 'name', 'image', 'status', 'display_tag_label', 'models'];

    public const MAPA_ADS = [
        'gasto' => ['gasto_ads', 'Gasto com ADS', 'R$'],
        'gmv' => ['venda_ads', 'Venda pelo ADS', 'R$'],
        'pedidos' => ['pedidos_ads', 'Pedidos do ADS', ''],
        'cliques' => ['cliques_ads', 'Cliques do ADS', ''],
        'impressoes' => ['impressoes_ads', 'Impressões do ADS', ''],
        'meta' => ['meta_roas', 'Meta de ROAS configurada', ''],
        'orcamento' => ['orcamento_ads', 'Orçamento diário do ADS', 'R$'],
        'ativo' => ['ads_ativo', 'ADS ativo (1 = sim)', ''],
    ];

    /**
     * @return array{formato: string, lidas: int, gravadas: int, ignoradas: int, mais_novas_no_banco: int, periodo: ?string}
     */
    public function importar(string $caminho, bool $simular = false): array
    {
        $json = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($json)) {
            throw new InvalidArgumentException('Não é um JSON válido.');
        }
        $arquivo = basename(dirname($caminho)).'/'.basename($caminho);

        [$formato, $linhas, $ignoradas] = match (true) {
            isset($json['linhas']) && is_array($json['linhas']) => ['tela', ...$this->deTela($json, $arquivo)],
            isset($json['itens']) && is_array($json['itens']) => ['funil', ...$this->deFunil($json, $arquivo)],
            isset($json['ads']) && is_array($json['ads']) => ['ads', ...$this->deAds($json, $arquivo, $caminho)],
            default => throw new InvalidArgumentException('Formato desconhecido (esperado: linhas, itens ou ads).'),
        };

        $maisNovas = 0;
        $gravadas = 0;
        foreach (array_chunk($linhas, 500) as $lote) {
            $existentes = DB::table('shopee_metricas')->whereIn('chave', array_column($lote, 'chave'))->pluck('coletado_em', 'chave');
            $lote = array_values(array_filter($lote, function ($l) use ($existentes, &$maisNovas) {
                $atual = $existentes[$l['chave']] ?? null;
                if ($atual !== null && $l['coletado_em'] !== null && Carbon::parse($atual)->gt(Carbon::parse($l['coletado_em']))) {
                    $maisNovas++;

                    return false;
                }

                return true;
            }));
            if (! $simular && $lote !== []) {
                DB::table('shopee_metricas')->upsert($lote, ['chave'], array_values(array_diff(array_keys($lote[0]), ['chave', 'created_at'])));
            }
            $gravadas += count($lote);
        }

        $primeira = $linhas[0] ?? null;

        return [
            'formato' => $formato,
            'lidas' => count($linhas) + $ignoradas,
            'gravadas' => $gravadas,
            'ignoradas' => $ignoradas,
            'mais_novas_no_banco' => $maisNovas,
            'periodo' => $primeira ? ($primeira['periodo_inicio'] ?? '?').' a '.($primeira['periodo_fim'] ?? '?') : null,
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function deTela(array $json, string $arquivo): array
    {
        $cabecalho = $this->cabecalho($json['coletado_em'] ?? null, $json['fonte'] ?? 'tela', $json['tela'] ?? null, $json['url'] ?? null,
            $json['periodo_inicio'] ?? null, $json['periodo_fim'] ?? null, $arquivo);
        $linhas = [];
        $ignoradas = 0;
        foreach ($json['linhas'] as $l) {
            $escopo = (string) ($l['escopo'] ?? '');
            $metrica = trim((string) ($l['metrica'] ?? ''));
            $valor = $l['valor'] ?? null;
            if (! in_array($escopo, ShopeeMetrica::ESCOPOS, true) || $metrica === '' || ! is_numeric($valor)) {
                $ignoradas++;

                continue;
            }
            $linhas[] = $this->linha($cabecalho, $escopo, $l['item_id'] ?? null, $l['model_id'] ?? null, $l['nome'] ?? null,
                $this->data($l['data'] ?? null), isset($l['hora']) && is_numeric($l['hora']) ? (int) $l['hora'] : null,
                $metrica, $l['rotulo'] ?? null, (float) $valor, $l['unidade'] ?? null, $l['texto_original'] ?? null);
        }

        return [$linhas, $ignoradas];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function deFunil(array $json, string $arquivo): array
    {
        $cabecalho = $this->cabecalho($json['coletado_em'] ?? null, 'tela', 'Informações Gerenciais > Produto > Desempenho do produto',
            'https://seller.shopee.com.br/datacenter/product/performance', $json['inicio'] ?? null, $json['fim'] ?? null, $arquivo);
        $linhas = [];
        $ignoradas = 0;
        foreach ($json['itens'] as $item) {
            $itemId = isset($item['id']) ? (string) $item['id'] : null;
            if (! $itemId) {
                $ignoradas++;

                continue;
            }
            $nome = $item['name'] ?? null;
            array_push($linhas, ...$this->camposFunil($cabecalho, $item, $itemId, null, $nome));
            foreach ($item['models'] ?? [] as $modelo) {
                if (! isset($modelo['id'])) {
                    continue;
                }
                $nomeModelo = trim(($nome ?? '').' · '.($modelo['name'] ?? ''), ' ·');
                array_push($linhas, ...$this->camposFunil($cabecalho, $modelo, $itemId, (string) $modelo['id'], $nomeModelo));
            }
        }

        return [$linhas, $ignoradas];
    }

    private function camposFunil(array $cabecalho, array $campos, string $itemId, ?string $modelId, ?string $nome): array
    {
        $linhas = [];
        foreach ($campos as $campo => $valor) {
            if (in_array($campo, self::FORA_FUNIL, true) || ! is_numeric($valor) || is_bool($valor)) {
                continue;
            }
            [$metrica, $rotulo] = self::MAPA_FUNIL[$campo] ?? [$campo, $campo];
            $fracao = $campo === 'ctr' || str_ends_with($campo, '_rate');
            $unidade = $fracao ? '%' : (str_contains($campo, 'sales') ? 'R$' : (str_starts_with($campo, 'average_days') ? 'dias' : ''));
            $numero = $fracao ? round((float) $valor * 100, 4) : (float) $valor;
            $linhas[] = $this->linha($cabecalho, 'produto', $itemId, $modelId, $nome, null, null, $metrica, $rotulo, $numero, $unidade, (string) $valor);
        }

        return $linhas;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function deAds(array $json, string $arquivo, string $caminho): array
    {
        // O arquivo de ADS não traz a hora da coleta: vale a do arquivo.
        $coletado = $json['coletado_em'] ?? Carbon::createFromTimestamp((int) filemtime($caminho))->toIso8601String();
        $cabecalho = $this->cabecalho($coletado, 'api', 'Shopee Ads (API) por anúncio, coleta diária', null, $json['inicio'] ?? null, $json['fim'] ?? null, $arquivo);
        $linhas = [];
        $ignoradas = 0;
        foreach ($json['ads'] as $itemId => $ad) {
            if (! is_array($ad) || ! is_numeric($itemId)) {
                $ignoradas++;

                continue;
            }
            foreach (self::MAPA_ADS as $campo => [$metrica, $rotulo, $unidade]) {
                $valor = $ad[$campo] ?? null;
                if (is_bool($valor)) {
                    $valor = $valor ? 1 : 0;
                }
                if (! is_numeric($valor)) {
                    continue;
                }
                $linhas[] = $this->linha($cabecalho, 'anuncio_ads', (string) $itemId, null, null, null, null, $metrica, $rotulo, (float) $valor, $unidade, (string) $valor);
            }
        }

        return [$linhas, $ignoradas];
    }

    private function cabecalho(?string $coletado, ?string $fonte, ?string $tela, ?string $url, ?string $inicio, ?string $fim, string $arquivo): array
    {
        return [
            'coletado_em' => $coletado ? Carbon::parse($coletado)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            'fonte' => mb_substr((string) ($fonte ?: 'tela'), 0, 20),
            'tela' => $tela ? mb_substr($tela, 0, 190) : null,
            'url' => $url ? mb_substr($url, 0, 500) : null,
            'periodo_inicio' => $this->data($inicio),
            'periodo_fim' => $this->data($fim),
            'arquivo' => mb_substr($arquivo, 0, 190),
        ];
    }

    private function linha(array $c, string $escopo, $itemId, $modelId, $nome, ?string $data, ?int $hora, string $metrica, $rotulo, float $valor, $unidade, $texto): array
    {
        $itemId = $itemId !== null && $itemId !== '' ? (string) $itemId : null;
        $modelId = $modelId !== null && $modelId !== '' ? (string) $modelId : null;
        $agora = now()->format('Y-m-d H:i:s');

        return [
            'chave' => ShopeeMetrica::chave($escopo, $itemId, $modelId, $c['periodo_inicio'], $c['periodo_fim'], $data, $hora, $metrica),
            'coletado_em' => $c['coletado_em'],
            'fonte' => $c['fonte'],
            'tela' => $c['tela'],
            'url' => $c['url'],
            'periodo_inicio' => $c['periodo_inicio'],
            'periodo_fim' => $c['periodo_fim'],
            'escopo' => $escopo,
            'item_id' => $itemId,
            'model_id' => $modelId,
            'nome' => $nome !== null ? mb_substr((string) $nome, 0, 255) : null,
            'data' => $data,
            'hora' => $hora,
            'metrica' => mb_substr($metrica, 0, 60),
            'rotulo' => $rotulo !== null ? mb_substr((string) $rotulo, 0, 120) : null,
            'valor' => round($valor, 4),
            'unidade' => $unidade !== null && $unidade !== '' ? mb_substr((string) $unidade, 0, 10) : null,
            'texto_original' => $texto !== null ? mb_substr((string) $texto, 0, 60) : null,
            'arquivo' => $c['arquivo'],
            'created_at' => $agora,
            'updated_at' => $agora,
        ];
    }

    private function data($valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $valor)) {
            return null;
        }

        return substr($valor, 0, 10);
    }
}
