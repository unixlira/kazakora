<?php

namespace App\Services\Shopee;

use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Oferta Relâmpago diária da Shopee (pedido 2026-10-17): monta a Relâmpago
 * de um dia com a lista do Lira (shopee_relampago_itens).
 *
 * Passos: horário do dia inteiro (get_time_slot_id) → Relâmpago desse
 * horário, criando se não existir → itens que ainda não estão nela, com
 * preço, estoque e lucro conferidos → add_shop_flash_sale_items → leitura de
 * volta (enabled_item_count). Rodar de novo no mesmo dia só completa o que
 * faltou: item que já está na Relâmpago (ligado ou desligado à mão) não é
 * mexido.
 *
 * Regras da Shopee já vistas na conta (memórias de 17/10):
 *  - preço pelo menos 1% abaixo do preço atual (código 10010);
 *  - preço não pode passar do menor preço dos últimos 7 dias (10014): a
 *    API não informa esse menor preço, então a recusa só vem na resposta;
 *  - Relâmpago em andamento não aceita item, por isso sempre o dia seguinte.
 */
class ShopeeRelampagoService
{
    private const LISTA_PROXIMAS = 1;

    private const LISTA_EM_ANDAMENTO = 2;

    /** Preço de cada item nas Relâmpagos em andamento agora (chave item:model). */
    private ?array $emAndamento = null;

    public function __construct(private readonly ShopeeClient $shopee) {}

    /**
     * @param  Collection<int, ShopeeRelampagoItem>  $itens
     * @return array{dia: string, timeslot_id: ?int, flash_sale_id: ?int, criada: bool, seco: bool, erro: ?string, ja_estavam: list<array>, adicionados: list<array>, recusados: list<array>, pulados: list<array>, ativos_na_shopee: ?int}
     */
    public function programar(Carbon $dia, Collection $itens, bool $seco = false): array
    {
        $dia = $dia->copy()->startOfDay();
        $r = [
            'dia' => $dia->toDateString(), 'timeslot_id' => null, 'flash_sale_id' => null, 'criada' => false,
            'seco' => $seco, 'erro' => null, 'ja_estavam' => [], 'adicionados' => [], 'recusados' => [],
            'pulados' => [], 'ativos_na_shopee' => null,
        ];

        try {
            // Horário já usado some do get_time_slot_id (17/10: a de 19/10,
            // criada à mão, tirou o horário da lista): procura antes a
            // Relâmpago que já existe para o dia.
            $existente = $this->relampagoDoDia($dia);
            if ($existente) {
                $r['timeslot_id'] = (int) $existente['timeslot_id'];
                $r['flash_sale_id'] = (int) $existente['flash_sale_id'];
            } else {
                $slot = $this->horarioDoDia($dia);
                if (! $slot) {
                    $r['erro'] = 'A Shopee não ofereceu horário de Relâmpago para '.$dia->format('d/m').'.';

                    return $r;
                }
                $r['timeslot_id'] = (int) $slot['timeslot_id'];
            }

            if (! $r['flash_sale_id'] && ! $seco) {
                $criada = $this->shopee->post('/api/v2/shop_flash_sale/create_shop_flash_sale', ['timeslot_id' => $r['timeslot_id']]);
                $r['flash_sale_id'] = (int) ($criada['response']['flash_sale_id'] ?? 0) ?: null;
                $r['criada'] = (bool) $r['flash_sale_id'];
                if (! $r['flash_sale_id']) {
                    $r['erro'] = 'A Shopee não devolveu o número da Relâmpago criada.';

                    return $r;
                }
            }

            $presentes = $r['flash_sale_id'] ? $this->itensDaRelampago($r['flash_sale_id']) : [];
            $faltam = $itens->filter(function (ShopeeRelampagoItem $item) use ($presentes, &$r) {
                $chave = self::chave($item->item_id, $item->model_id);
                if (isset($presentes[$chave])) {
                    $r['ja_estavam'][] = ['item_id' => $item->item_id, 'model_id' => $item->model_id, 'nome' => $item->nome,
                        'ligado' => $presentes[$chave]['ligado'], 'preco' => $presentes[$chave]['preco']];

                    return false;
                }

                return true;
            });

            $envio = $this->montarEnvio($faltam, $r);
        } catch (Throwable $e) {
            $r['erro'] = $e->getMessage();

            return $r;
        }

        if ($envio && ! $seco) {
            $this->enviar($r['flash_sale_id'], $envio, $r);
        } elseif ($envio) {
            // Simulação: mostra o que iria, sem mandar nada.
            $r['adicionados'] = array_merge(...array_map(fn ($e) => $e['linhas'], $envio));
        }

        if ($r['flash_sale_id']) {
            try {
                $fs = $this->shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale', ['flash_sale_id' => $r['flash_sale_id']]);
                $r['ativos_na_shopee'] = (int) ($fs['response']['enabled_item_count'] ?? 0);
            } catch (Throwable $e) {
                $r['erro'] ??= 'Leitura de volta da Relâmpago falhou: '.$e->getMessage();
            }
        }

        return $r;
    }

    /** Lucro estimado por unidade: preço − taxa da Shopee pela faixa − Simples − custo. */
    public static function lucroUnidade(float $preco, ?float $custo): ?float
    {
        if ($custo === null) {
            return null;
        }

        $faixa = collect(config('shopee_relampago.taxas'))->first(fn ($f) => $f['ate'] === null || $preco <= $f['ate']);
        $taxa = $preco * $faixa['pct'] / 100 + $faixa['fixo'];
        $imposto = $preco * (float) config('loja.simples.aliquota', 4.5) / 100;

        return round($preco - $taxa - $imposto - $custo, 2);
    }

    public static function lucroMinimo(float $preco): float
    {
        $faixa = collect(config('shopee_relampago.lucro_minimo'))->first(fn ($f) => $f['ate'] === null || $preco <= $f['ate']);

        return (float) $faixa['valor'];
    }

    /**
     * Preço da Relâmpago de um item. $precoAtual é o preço do anúncio hoje;
     * $precoEmAndamento é o preço do item numa Relâmpago que está no ar
     * agora (aí o preço do anúncio lido pode ser o da própria Relâmpago e não
     * serve de base: repete o preço que está no ar).
     *
     * @return array{preco: ?float, ajustado: bool, lucro: ?float, motivo: ?string}
     */
    public static function precificar(ShopeeRelampagoItem $item, ?float $precoAtual, ?float $precoEmAndamento): array
    {
        $valor = (float) $item->valor;
        $base = $precoEmAndamento === null ? $precoAtual : null;

        $preco = match ($item->modo) {
            ShopeeRelampagoItem::MODO_DESCONTO_REAIS => $precoEmAndamento ?? ($base !== null ? $base - $valor : null),
            ShopeeRelampagoItem::MODO_DESCONTO_PCT => $precoEmAndamento ?? ($base !== null ? self::centavos($base * (1 - $valor / 100)) : null),
            default => $valor,
        };
        if ($preco === null) {
            return ['preco' => null, 'ajustado' => false, 'lucro' => null, 'motivo' => 'sem preço atual do anúncio'];
        }
        $preco = round($preco, 2);

        // Desconto necessário: pelo menos 1% abaixo do preço atual.
        $ajustado = false;
        if ($base !== null) {
            $teto = self::centavos($base * (1 - (float) config('shopee_relampago.desconto_minimo_pct', 1) / 100));
            if ($preco > $teto) {
                $preco = $teto;
                $ajustado = true;
            }
        }

        if ($preco <= 0) {
            return ['preco' => null, 'ajustado' => $ajustado, 'lucro' => null, 'motivo' => 'preço calculado zerado'];
        }

        $custo = $item->custoUnidade();
        $lucro = self::lucroUnidade($preco, $custo);
        $minimo = $item->lucro_minimo !== null ? (float) $item->lucro_minimo : self::lucroMinimo($preco);

        if ($lucro !== null && $lucro < 0) {
            return ['preco' => $preco, 'ajustado' => $ajustado, 'lucro' => $lucro, 'motivo' => 'prejuízo de R$ '.self::reais(-$lucro).' por unidade'];
        }

        // Preço fixo do Lira vale como está; preço que o sistema calculou ou
        // baixou precisa de custo conhecido e do lucro mínimo.
        if ($item->modo !== ShopeeRelampagoItem::MODO_PRECO || $ajustado) {
            if ($lucro === null) {
                return ['preco' => $preco, 'ajustado' => $ajustado, 'lucro' => null, 'motivo' => 'sem custo no cadastro para conferir o lucro'];
            }
            if ($lucro < $minimo) {
                return ['preco' => $preco, 'ajustado' => $ajustado, 'lucro' => $lucro,
                    'motivo' => 'lucro R$ '.self::reais($lucro).' abaixo do mínimo de R$ '.self::reais($minimo)];
            }
        }

        return ['preco' => $preco, 'ajustado' => $ajustado, 'lucro' => $lucro, 'motivo' => null];
    }

    /** Horário de dia inteiro da Shopee para o dia (começa à meia-noite). */
    private function horarioDoDia(Carbon $dia): ?array
    {
        $resp = $this->shopee->get('/api/v2/shop_flash_sale/get_time_slot_id', [
            'start_time' => $dia->timestamp,
            'end_time' => $dia->copy()->endOfDay()->timestamp,
        ]);

        return collect($resp['response'] ?? [])
            ->filter(fn ($s) => (int) $s['start_time'] >= $dia->timestamp && (int) $s['start_time'] <= $dia->copy()->endOfDay()->timestamp)
            ->sortBy([fn ($a, $b) => (int) $a['start_time'] <=> (int) $b['start_time'], fn ($a, $b) => ((int) $b['end_time'] - (int) $b['start_time']) <=> ((int) $a['end_time'] - (int) $a['start_time'])])
            ->first();
    }

    /** Relâmpago programada que começa no dia (a de dia inteiro primeiro). */
    private function relampagoDoDia(Carbon $dia): ?array
    {
        $fim = $dia->copy()->endOfDay()->timestamp;

        return collect($this->listar(self::LISTA_PROXIMAS))
            ->filter(fn ($fs) => (int) $fs['start_time'] >= $dia->timestamp && (int) $fs['start_time'] <= $fim)
            ->sortBy([fn ($a, $b) => (int) $a['start_time'] <=> (int) $b['start_time'], fn ($a, $b) => ((int) $b['end_time'] - (int) $b['start_time']) <=> ((int) $a['end_time'] - (int) $a['start_time'])])
            ->first();
    }

    /** @return list<array> */
    private function listar(int $tipo): array
    {
        $todas = [];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $resp = $this->shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale_list', ['type' => $tipo, 'offset' => $offset, 'limit' => 100]);
            $pagina = $resp['response']['flash_sale_list'] ?? [];
            array_push($todas, ...$pagina);
            if (count($pagina) < 100) {
                break;
            }
        }

        return $todas;
    }

    /** @return array<string, array{ligado: bool, preco: ?float}> */
    private function itensDaRelampago(int $flashSaleId): array
    {
        $itens = [];
        for ($offset = 0; $offset < 1000; $offset += 50) {
            $resp = $this->shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale_items', ['flash_sale_id' => $flashSaleId, 'offset' => $offset, 'limit' => 50]);
            $pagina = $resp['response']['item_info'] ?? [];
            foreach ($pagina as $i) {
                $itens[self::chave((int) $i['item_id'], null)] = ['ligado' => (int) ($i['status'] ?? 0) === 1,
                    'preco' => isset($i['input_promotion_price']) ? (float) $i['input_promotion_price'] : null];
            }
            foreach ($resp['response']['models'] ?? [] as $m) {
                $itens[self::chave((int) $m['item_id'], (int) $m['model_id'])] = ['ligado' => (int) ($m['status'] ?? 0) === 1,
                    'preco' => isset($m['input_promotion_price']) ? (float) $m['input_promotion_price'] : null];
            }
            if (count($pagina) < 50) {
                break;
            }
        }

        return $itens;
    }

    /** @return array<string, float> */
    private function precosEmAndamento(): array
    {
        if ($this->emAndamento !== null) {
            return $this->emAndamento;
        }

        $precos = [];
        foreach ($this->listar(self::LISTA_EM_ANDAMENTO) as $fs) {
            foreach ($this->itensDaRelampago((int) $fs['flash_sale_id']) as $chave => $i) {
                if ($i['ligado'] && $i['preco'] !== null) {
                    $precos[$chave] = $i['preco'];
                }
            }
        }

        return $this->emAndamento = $precos;
    }

    /**
     * Confere anúncio, preço, estoque e lucro e agrupa por anúncio (a
     * Shopee recebe as variações de um anúncio juntas).
     *
     * @param  Collection<int, ShopeeRelampagoItem>  $itens
     * @return list<array{payload: array, linhas: list<array>}>
     */
    private function montarEnvio(Collection $itens, array &$r): array
    {
        if ($itens->isEmpty()) {
            return [];
        }

        $anuncios = $this->anuncios($itens);
        $emAndamento = $this->precosEmAndamento();
        $grupos = [];

        foreach ($itens as $item) {
            $linha = ['item_id' => $item->item_id, 'model_id' => $item->model_id, 'nome' => $item->nome];
            $anuncio = $anuncios[self::chave($item->item_id, $item->model_id)] ?? null;

            if (! $anuncio) {
                $r['pulados'][] = $linha + ['motivo' => 'anúncio ou variação não encontrado na Shopee'];

                continue;
            }
            if ($anuncio['status'] !== 'NORMAL') {
                $r['pulados'][] = $linha + ['motivo' => 'anúncio '.$anuncio['status'].' na Shopee'];

                continue;
            }

            $desejado = $item->estoque !== null
                ? (int) $item->estoque
                : (int) floor($anuncio['total'] * (float) config('shopee_relampago.estoque_pct', 50) / 100);
            $estoque = min($desejado, (int) $anuncio['estoque']);
            if ($estoque < 1) {
                $r['pulados'][] = $linha + ['motivo' => $anuncio['total'] > 0
                    ? "estoque todo reservado (total {$anuncio['total']}, livre {$anuncio['estoque']}): outra Relâmpago ou desconto prendeu as unidades"
                    : 'sem estoque na Shopee'];

                continue;
            }

            $p = self::precificar($item, $anuncio['preco'], $emAndamento[self::chave($item->item_id, $item->model_id)] ?? null);
            $linha += ['preco_atual' => $anuncio['preco'], 'preco' => $p['preco'], 'ajustado' => $p['ajustado'], 'lucro' => $p['lucro'],
                'estoque' => $estoque, 'limite' => (int) $item->limite_por_comprador];
            if ($p['motivo']) {
                $r['pulados'][] = $linha + ['motivo' => $p['motivo']];

                continue;
            }

            $g = &$grupos[$item->item_id];
            $g['linhas'][] = $linha;
            if ($item->model_id) {
                $g['payload']['item_id'] = $item->item_id;
                $g['payload']['purchase_limit'] = (int) $item->limite_por_comprador;
                $g['payload']['models'][] = ['model_id' => $item->model_id, 'input_promo_price' => $p['preco'], 'stock' => $estoque];
            } else {
                $g['payload'] = ['item_id' => $item->item_id, 'purchase_limit' => (int) $item->limite_por_comprador,
                    'item_input_promo_price' => $p['preco'], 'item_stock' => $estoque];
            }
            unset($g);
        }

        return array_values($grupos);
    }

    /**
     * Preço atual, estoque livre e situação de cada anúncio/variação.
     *
     * @param  Collection<int, ShopeeRelampagoItem>  $itens
     * @return array<string, array{status: string, preco: ?float, estoque: int, total: int}>
     */
    private function anuncios(Collection $itens): array
    {
        $dados = [];
        $ids = $itens->pluck('item_id')->unique()->values();

        foreach ($ids->chunk(50) as $lote) {
            $resp = $this->shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => $lote->implode(',')]);
            foreach ($resp['response']['item_list'] ?? [] as $a) {
                $dados[self::chave((int) $a['item_id'], null)] = [
                    'status' => (string) ($a['item_status'] ?? ''),
                    'preco' => isset($a['price_info'][0]['current_price']) ? (float) $a['price_info'][0]['current_price'] : null,
                    'estoque' => (int) ($a['stock_info_v2']['summary_info']['total_available_stock'] ?? 0),
                    'total' => (int) ($a['stock_info_v2']['summary_info']['total_available_stock'] ?? 0)
                        + (int) ($a['stock_info_v2']['summary_info']['total_reserved_stock'] ?? 0),
                ];
            }
        }

        foreach ($itens->whereNotNull('model_id')->pluck('item_id')->unique() as $itemId) {
            $status = $dados[self::chave($itemId, null)]['status'] ?? '';
            $resp = $this->shopee->get('/api/v2/product/get_model_list', ['item_id' => $itemId]);
            foreach ($resp['response']['model'] ?? [] as $m) {
                $dados[self::chave($itemId, (int) $m['model_id'])] = [
                    'status' => $status,
                    'preco' => isset($m['price_info'][0]['current_price']) ? (float) $m['price_info'][0]['current_price'] : null,
                    'estoque' => (int) ($m['stock_info_v2']['summary_info']['total_available_stock'] ?? 0),
                    'total' => (int) ($m['stock_info_v2']['summary_info']['total_available_stock'] ?? 0)
                        + (int) ($m['stock_info_v2']['summary_info']['total_reserved_stock'] ?? 0),
                ];
            }
        }

        return $dados;
    }

    /** @param list<array{payload: array, linhas: list<array>}> $envio */
    private function enviar(int $flashSaleId, array $envio, array &$r): void
    {
        $linhas = array_merge(...array_map(fn ($e) => $e['linhas'], $envio));

        try {
            $resp = $this->shopee->post('/api/v2/shop_flash_sale/add_shop_flash_sale_items', [
                'flash_sale_id' => $flashSaleId,
                'items' => array_map(fn ($e) => $e['payload'], $envio),
            ]);
        } catch (Throwable $e) {
            foreach ($linhas as $linha) {
                $r['recusados'][] = $linha + ['motivo' => $e->getMessage()];
            }

            return;
        }

        // Falha sem model_id vale para o anúncio inteiro (todas as variações).
        $falhas = [];
        foreach ($resp['response']['failed_items'] ?? [] as $f) {
            $condicoes = collect($f['unqualified_conditions'] ?? [])->pluck('unqualified_msg')->filter()->implode('; ');
            $motivo = trim(($f['err_msg'] ?? '').($condicoes ? ' ('.$condicoes.')' : '')) ?: 'recusado pela Shopee';
            $falhas[self::chave((int) $f['item_id'], ! empty($f['model_id']) ? (int) $f['model_id'] : null)] = $motivo;
        }

        foreach ($linhas as $linha) {
            $motivo = $falhas[self::chave($linha['item_id'], $linha['model_id'])] ?? $falhas[self::chave($linha['item_id'], null)] ?? null;
            if ($motivo) {
                $r['recusados'][] = $linha + ['motivo' => $motivo];
            } else {
                $r['adicionados'][] = $linha;
            }
        }
    }

    private static function chave(int $itemId, ?int $modelId): string
    {
        return $itemId.':'.($modelId ?: 0);
    }

    private static function centavos(float $valor): float
    {
        return floor(round($valor * 100, 4)) / 100;
    }

    private static function reais(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
