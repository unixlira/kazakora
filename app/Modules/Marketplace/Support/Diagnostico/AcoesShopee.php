<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Audit\Models\AuditLog;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Botões de ação do diagnóstico do Cauê (pedido do Lira 2026-10-14): manda a
 * correção sugerida direto para a Shopee pela API e confere o resultado
 * lendo de volta. Só três ações, todas reversíveis pelo próprio Seller
 * Center: pausar o ADS do produto, mudar a meta de ROAS e mudar o preço.
 * Nunca usa "stop"/"delete" de campanha.
 *
 * Com SHOPEE_ADS_ESCRITA desligada (Lira, 2026-10-14: "só simulando por
 * enquanto") nada vai para a Shopee: o pedido fica no AuditLog como simulado.
 */
class AcoesShopee
{
    public const ACOES = ['pausar_ads', 'meta_roas', 'preco'];

    public function __construct(private readonly ShopeeClient $shopee) {}

    /**
     * @return array{ok: bool, mensagem: string, antes: array, depois: array}
     */
    public function executar(string $acao, string $itemId, ?float $valor, ?string $modelId, ?int $usuarioId): array
    {
        $resultado = ! config('services.shopee.ads_escrita') ? $this->simular($acao, $valor) : match ($acao) {
            'pausar_ads' => $this->pausarAds($itemId),
            'meta_roas' => $this->metaRoas($itemId, $this->exigirValor($valor, 1, 100)),
            'preco' => $this->preco($itemId, $this->exigirValor($valor, 1, 99999), $modelId),
            default => throw new RuntimeException('Ação desconhecida.'),
        };

        AuditLog::create([
            'user_id' => $usuarioId,
            'action' => 'shopee.'.$acao,
            'entity' => 'shopee_item',
            'entity_id' => (int) $itemId,
            'old_values' => $resultado['antes'],
            'new_values' => $resultado['depois'] + ['ok' => $resultado['ok'], 'valor_pedido' => $valor, 'model_id' => $modelId],
            'created_at' => now(),
        ]);

        return $resultado;
    }

    /** Escrita desligada: valida o valor, não chama a Shopee e não conta como feita. */
    private function simular(string $acao, ?float $valor): array
    {
        match ($acao) {
            'pausar_ads' => null,
            'meta_roas' => $this->exigirValor($valor, 1, 100),
            'preco' => $this->exigirValor($valor, 1, 99999),
            default => throw new RuntimeException('Ação desconhecida.'),
        };

        return ['ok' => false, 'mensagem' => 'Simulação: nada foi mandado para a Shopee (escrita desligada).', 'antes' => [], 'depois' => ['simulacao' => true]];
    }

    /** Pausa todas as campanhas de produto em andamento deste item. */
    public function pausarAds(string $itemId): array
    {
        $ativas = array_filter($this->campanhas($itemId), fn ($c) => $c['ativa']);
        if (! $ativas) {
            return ['ok' => true, 'mensagem' => 'O ADS deste produto já estava pausado.', 'antes' => [], 'depois' => []];
        }
        foreach ($ativas as $c) {
            $this->editarCampanha($c, ['edit_action' => 'pause']);
        }

        $depois = $this->campanhas($itemId);
        $aindaAtivas = array_filter($depois, fn ($c) => $c['ativa']);

        return [
            'ok' => ! $aindaAtivas,
            'mensagem' => $aindaAtivas
                ? 'A Shopee aceitou o pedido, mas '.count($aindaAtivas).' campanha(s) ainda aparecem em andamento.'
                : count($ativas).' campanha(s) de ADS pausada(s) e conferida(s) na Shopee.',
            'antes' => array_values($ativas),
            'depois' => array_values($depois),
        ];
    }

    /** Muda a meta de ROAS das campanhas em andamento com lance automático. */
    public function metaRoas(string $itemId, float $meta): array
    {
        $meta = round($meta, 1);
        $alvo = array_filter($this->campanhas($itemId), fn ($c) => $c['ativa'] && $c['lance'] === 'auto');
        if (! $alvo) {
            throw new RuntimeException('Este produto não tem campanha de ADS em andamento com lance automático: não há meta de ROAS para mudar.');
        }
        foreach ($alvo as $c) {
            $this->editarCampanha($c, ['edit_action' => 'change_roas_target', 'roas_target' => $meta]);
        }

        $depois = array_filter($this->campanhas($itemId), fn ($c) => isset($alvo[$c['campaign_id']]));
        $erradas = array_filter($depois, fn ($c) => abs($c['roas_meta'] - $meta) > 0.05);

        return [
            'ok' => ! $erradas && count($depois) === count($alvo),
            'mensagem' => $erradas
                ? 'A Shopee aceitou, mas a meta lida de volta ficou em '.collect($erradas)->pluck('roas_meta')->implode(', ').'.'
                : 'Meta de ROAS '.number_format($meta, 1, ',', '.').' gravada e conferida na Shopee.',
            'antes' => array_values($alvo),
            'depois' => array_values($depois),
        ];
    }

    /**
     * Muda o preço cheio (original_price). Anúncio com variações exige o
     * model_id: as variações podem ser produtos diferentes (ex. Ring Light
     * 10" e 8" no mesmo anúncio).
     */
    public function preco(string $itemId, float $preco, ?string $modelId): array
    {
        $preco = round($preco, 2);
        $modelos = $this->modelos($itemId);
        if ($modelos && ! $modelId) {
            throw new RuntimeException('Anúncio com variações: escolha a variação.');
        }
        if ($modelos && ! isset($modelos[$modelId])) {
            throw new RuntimeException('Variação não encontrada neste anúncio.');
        }
        $antes = $modelos ? $modelos[$modelId] : $this->precoDoItem($itemId);

        $this->checar($this->shopee->post('/api/v2/product/update_price', [
            'item_id' => (int) $itemId,
            'price_list' => [['model_id' => (int) ($modelId ?: 0), 'original_price' => $preco]],
        ]));

        $depois = $modelos ? ($this->modelos($itemId)[$modelId] ?? []) : $this->precoDoItem($itemId);
        $ok = abs(($depois['original'] ?? 0) - $preco) < 0.01;
        $cliente = $depois['atual'] ?? null;
        $promo = $cliente !== null && abs($cliente - $preco) >= 0.01
            ? ' Há promoção ativa: o cliente paga R$ '.number_format($cliente, 2, ',', '.').' agora.'
            : '';

        return [
            'ok' => $ok,
            'mensagem' => $ok
                ? 'Preço R$ '.number_format($preco, 2, ',', '.').' gravado e conferido na Shopee.'.$promo
                : 'A Shopee aceitou, mas o preço lido de volta é R$ '.number_format((float) ($depois['original'] ?? 0), 2, ',', '.').'.',
            'antes' => $antes,
            'depois' => $depois,
        ];
    }

    /**
     * Variações do anúncio (vazio se não tiver).
     *
     * @return array<string, array{model_id: string, nome: string, original: float, atual: float}>
     */
    public function modelos(string $itemId): array
    {
        $res = $this->checar($this->shopee->get('/api/v2/product/get_model_list', ['item_id' => (int) $itemId]))['response'] ?? [];
        $nomes = [];
        foreach ($res['tier_variation'] ?? [] as $t => $tier) {
            foreach ($tier['option_list'] ?? [] as $o => $opcao) {
                $nomes[$t][$o] = $opcao['option'] ?? '';
            }
        }
        $modelos = [];
        foreach ($res['model'] ?? [] as $m) {
            $nome = collect($m['tier_index'] ?? [])->map(fn ($o, $t) => $nomes[$t][$o] ?? '')->filter()->implode(' / ');
            $modelos[(string) $m['model_id']] = [
                'model_id' => (string) $m['model_id'],
                'nome' => $nome ?: (string) ($m['model_sku'] ?? $m['model_id']),
                'original' => (float) ($m['price_info'][0]['original_price'] ?? 0),
                'atual' => (float) ($m['price_info'][0]['current_price'] ?? 0),
            ];
        }

        return $modelos;
    }

    /**
     * Campanhas de ADS por produto deste item.
     *
     * @return array<string, array{campaign_id: string, tipo: string, status: string, ativa: bool, lance: string, roas_meta: float}>
     */
    public function campanhas(string $itemId): array
    {
        $tipos = [];
        for ($offset = 0; $offset < 5000; $offset += 100) {
            $res = $this->checar($this->shopee->get('/api/v2/ads/get_product_level_campaign_id_list', ['offset' => $offset, 'limit' => 100]))['response'] ?? [];
            foreach ($res['campaign_list'] ?? [] as $c) {
                $tipos[(string) $c['campaign_id']] = (string) ($c['ad_type'] ?? 'manual');
            }
            if (empty($res['has_next_page'])) {
                break;
            }
        }

        $doItem = [];
        foreach (array_chunk(array_keys($tipos), 100) as $lote) {
            $info = $this->checar($this->shopee->get('/api/v2/ads/get_product_level_campaign_setting_info', [
                'campaign_id_list' => implode(',', $lote), 'info_type_list' => '1,3',
            ]))['response']['campaign_list'] ?? [];
            foreach ($info as $c) {
                if (! in_array((string) $itemId, array_map('strval', $c['common_info']['item_id_list'] ?? []), true)) {
                    continue;
                }
                $id = (string) $c['campaign_id'];
                $status = (string) ($c['common_info']['campaign_status'] ?? '');
                $doItem[$id] = [
                    'campaign_id' => $id,
                    'tipo' => $tipos[$id] ?? 'manual',
                    'status' => $status,
                    'ativa' => in_array($status, ['ongoing', 'scheduled'], true),
                    'lance' => (string) ($c['common_info']['bidding_method'] ?? ''),
                    'roas_meta' => (float) ($c['auto_bidding_info']['roas_target'] ?? 0),
                ];
            }
        }

        return $doItem;
    }

    private function editarCampanha(array $campanha, array $dados): void
    {
        $caminho = $campanha['tipo'] === 'auto' ? '/api/v2/ads/edit_auto_product_ads' : '/api/v2/ads/edit_manual_product_ads';
        $this->checar($this->shopee->post($caminho, [
            'reference_id' => 'kz-'.Str::uuid(),
            'campaign_id' => (int) $campanha['campaign_id'],
        ] + $dados));
    }

    private function precoDoItem(string $itemId): array
    {
        $item = $this->checar($this->shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => $itemId]))['response']['item_list'][0] ?? [];

        return [
            'model_id' => '0',
            'original' => (float) ($item['price_info'][0]['original_price'] ?? 0),
            'atual' => (float) ($item['price_info'][0]['current_price'] ?? 0),
        ];
    }

    private function exigirValor(?float $valor, float $min, float $max): float
    {
        if ($valor === null || $valor < $min || $valor > $max) {
            throw new RuntimeException("Valor inválido (entre {$min} e {$max}).");
        }

        return $valor;
    }

    private function checar(array $resposta): array
    {
        if (! empty($resposta['error'])) {
            throw new RuntimeException('Shopee: '.($resposta['message'] ?: $resposta['error']));
        }

        return $resposta;
    }
}
