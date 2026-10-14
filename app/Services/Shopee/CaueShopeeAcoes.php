<?php

namespace App\Services\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Models\ShopeeAdsAcao;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cauê agindo nos ADS da Shopee (tarefa 569, 2026-10-14): pausar, retomar
 * e mudar a meta de ROAS de uma campanha por produto, sempre pelo clique do
 * Lira no botão Ação da Estratégia Shopee (ADS é dinheiro: nada roda sozinho).
 *
 * Depois de mandar, relê a campanha na Shopee e só marca "conferida" se o
 * valor novo voltou de lá. Com SHOPEE_ADS_ESCRITA desligada, nada vai para a
 * Shopee: a ação fica gravada como simulada, com o pedido que seria mandado.
 *
 * Endpoints (Open Platform v2, módulo Ads): campanha de seleção manual
 * (uma por produto) = edit_manual_product_ads com edit_action pause, resume
 * ou change_roas_target (meta só existe com lance automático); campanha de
 * seleção automática = edit_auto_product_ads, que só pausa e retoma.
 */
class CaueShopeeAcoes
{
    /** Meta mudada reinicia o aprendizado da Shopee: segura nova troca por 7 dias. */
    public const DIAS_TRAVA_META = 7;

    /** Folga acima do empate na meta sugerida (regra da reunião: empate + 2). */
    public const FOLGA_META = 2.0;

    private const STATUS_FORA = ['deleted', 'ended', 'closed'];

    public function __construct(private readonly ShopeeClient $shopee) {}

    public function escritaLigada(): bool
    {
        return (bool) config('services.shopee.ads_escrita');
    }

    public static function metaSugerida(?float $roasEmpate): ?float
    {
        return $roasEmpate && $roasEmpate > 0 ? round($roasEmpate + self::FOLGA_META, 1) : null;
    }

    /**
     * Campanhas de ADS por produto que anunciam o item, como estão agora na Shopee.
     *
     * @return array<int, array{campaign_id: string, nome: string, tipo: string, lance: string, status: string, meta: ?float, orcamento: float}>
     */
    public function campanhasDoItem(string $itemId): array
    {
        $ids = [];
        for ($offset = 0; $offset < 5000; $offset += 100) {
            $res = $this->shopee->get('/api/v2/ads/get_product_level_campaign_id_list', ['offset' => $offset, 'limit' => 100])['response'] ?? [];
            foreach ($res['campaign_list'] ?? [] as $c) {
                $ids[] = (string) $c['campaign_id'];
            }
            if (empty($res['has_next_page'])) {
                break;
            }
        }

        $campanhas = [];
        foreach (array_chunk($ids, 100) as $lote) {
            foreach ($this->configuracao($lote) as $c) {
                $itens = array_map('strval', $c['common_info']['item_id_list'] ?? []);
                if (in_array($itemId, $itens, true) && ! in_array($c['common_info']['campaign_status'] ?? '', self::STATUS_FORA, true)) {
                    $campanhas[] = $this->resumo($c);
                }
            }
        }

        return $campanhas;
    }

    /**
     * Pausa, retoma ou muda a meta de uma campanha do item, relê e grava.
     *
     * @throws \InvalidArgumentException quando a ação não cabe no estado atual da campanha
     */
    public function aplicar(
        string $itemId,
        string $campaignId,
        string $acao,
        ?User $user,
        ?float $meta = null,
        ?float $roasEmpate = null,
        bool $forcar = false,
        ?string $nomeProduto = null,
        ?ShopeeAdsAcao $desfazendo = null,
    ): ShopeeAdsAcao {
        $campanha = $this->campanha($campaignId);
        if (! $campanha || ! in_array($itemId, $campanha['itens'], true)) {
            throw new \InvalidArgumentException('Essa campanha não anuncia este produto na Shopee.');
        }

        $this->validar($campanha, $acao, $meta, $roasEmpate, $forcar);

        $editAction = match ($acao) {
            ShopeeAdsAcao::ACAO_PAUSAR => 'pause',
            ShopeeAdsAcao::ACAO_RETOMAR => 'resume',
            ShopeeAdsAcao::ACAO_META => 'change_roas_target',
        };
        $endpoint = $campanha['tipo'] === 'auto' ? '/api/v2/ads/edit_auto_product_ads' : '/api/v2/ads/edit_manual_product_ads';
        $pedido = ['reference_id' => (string) Str::uuid(), 'campaign_id' => (int) $campaignId, 'edit_action' => $editAction];
        if ($acao === ShopeeAdsAcao::ACAO_META) {
            $pedido['roas_target'] = round((float) $meta, 1);
        }

        $antes = ['status' => $campanha['status'], 'meta' => $campanha['meta'], 'tipo' => $campanha['tipo'], 'lance' => $campanha['lance']];
        $depois = match ($acao) {
            ShopeeAdsAcao::ACAO_PAUSAR => ['status' => 'paused'],
            ShopeeAdsAcao::ACAO_RETOMAR => ['status' => 'ongoing'],
            ShopeeAdsAcao::ACAO_META => ['meta' => round((float) $meta, 1), 'roas_empate' => $roasEmpate],
        };

        $registro = new ShopeeAdsAcao([
            'item_id' => $itemId,
            'campaign_id' => $campaignId,
            'nome_produto' => $nomeProduto ?: $campanha['nome'],
            'acao' => $acao,
            'antes' => $antes,
            'depois' => $depois,
            'user_id' => $user?->id,
            'desfaz_id' => $desfazendo?->id,
            'simulacao' => ! $this->escritaLigada(),
        ]);

        if ($registro->simulacao) {
            $registro->fill(['status' => ShopeeAdsAcao::STATUS_SIMULADA, 'resposta' => ['endpoint' => $endpoint, 'pedido' => $pedido]])->save();

            return $registro;
        }

        try {
            $resposta = $this->shopee->post($endpoint, $pedido);
        } catch (ShopeeException $e) {
            $registro->fill(['status' => ShopeeAdsAcao::STATUS_ERRO, 'erro' => $e->getMessage(), 'resposta' => ['endpoint' => $endpoint, 'pedido' => $pedido]])->save();

            return $registro;
        }

        $registro->fill(['resposta' => ['endpoint' => $endpoint, 'pedido' => $pedido, 'shopee' => $resposta]]);
        $this->conferir($registro);

        return $registro;
    }

    /** Relê a campanha e marca conferida só se o valor novo voltou da Shopee. */
    public function conferir(ShopeeAdsAcao $registro): ShopeeAdsAcao
    {
        try {
            $agora = $this->campanha($registro->campaign_id);
        } catch (Throwable $e) {
            report($e);
            $agora = null;
        }

        $ok = $agora && match ($registro->acao) {
            ShopeeAdsAcao::ACAO_PAUSAR => $agora['status'] === 'paused',
            ShopeeAdsAcao::ACAO_RETOMAR => in_array($agora['status'], ['ongoing', 'scheduled'], true),
            ShopeeAdsAcao::ACAO_META => $agora['meta'] !== null && abs($agora['meta'] - (float) $registro->depois['meta']) < 0.05,
            default => false,
        };

        $registro->fill([
            'status' => $ok ? ShopeeAdsAcao::STATUS_CONFERIDA : ShopeeAdsAcao::STATUS_NAO_CONFERIDA,
            'conferida_em' => $ok ? now() : null,
            'erro' => $ok ? null : ($agora ? "A Shopee ainda mostra status {$agora['status']} e meta ".($agora['meta'] ?? 'nenhuma').'.' : 'Não consegui reler a campanha na Shopee.'),
        ])->save();

        return $registro;
    }

    /** Manda de volta o "antes" de uma ação conferida (prazo de 7 dias). */
    public function desfazer(ShopeeAdsAcao $original, ?User $user): ShopeeAdsAcao
    {
        if (! $original->podeDesfazer()) {
            throw new \InvalidArgumentException('Essa ação não pode mais ser desfeita.');
        }

        [$acao, $meta] = match ($original->acao) {
            ShopeeAdsAcao::ACAO_PAUSAR => [ShopeeAdsAcao::ACAO_RETOMAR, null],
            ShopeeAdsAcao::ACAO_RETOMAR => [ShopeeAdsAcao::ACAO_PAUSAR, null],
            ShopeeAdsAcao::ACAO_META => [ShopeeAdsAcao::ACAO_META, (float) ($original->antes['meta'] ?? 0)],
        };
        if ($acao === ShopeeAdsAcao::ACAO_META && $meta <= 0) {
            throw new \InvalidArgumentException('A campanha não tinha meta de ROAS antes; não há valor para voltar.');
        }

        $nova = $this->aplicar($original->item_id, $original->campaign_id, $acao, $user, $meta, null, true, $original->nome_produto, $original);
        if (in_array($nova->status, [ShopeeAdsAcao::STATUS_CONFERIDA, ShopeeAdsAcao::STATUS_NAO_CONFERIDA], true)) {
            $original->forceFill(['desfeita_em' => now()])->save();
        }

        return $nova;
    }

    /**
     * Última troca de meta feita por aqui na campanha nos últimos 7 dias, se houver.
     */
    public function trocaDeMetaRecente(string $campaignId): ?ShopeeAdsAcao
    {
        return ShopeeAdsAcao::query()
            ->where('campaign_id', $campaignId)
            ->where('acao', ShopeeAdsAcao::ACAO_META)
            ->whereIn('status', [ShopeeAdsAcao::STATUS_CONFERIDA, ShopeeAdsAcao::STATUS_NAO_CONFERIDA])
            ->where('created_at', '>', now()->subDays(self::DIAS_TRAVA_META))
            ->latest('id')
            ->first();
    }

    private function validar(array $campanha, string $acao, ?float $meta, ?float $roasEmpate, bool $forcar): void
    {
        match ($acao) {
            ShopeeAdsAcao::ACAO_PAUSAR => $campanha['status'] === 'ongoing'
                ?: throw new \InvalidArgumentException("Só dá para pausar campanha ativa (hoje está {$campanha['status']})."),
            ShopeeAdsAcao::ACAO_RETOMAR => $campanha['status'] === 'paused'
                ?: throw new \InvalidArgumentException("Só dá para retomar campanha pausada (hoje está {$campanha['status']})."),
            ShopeeAdsAcao::ACAO_META => $this->validarMeta($campanha, $meta, $roasEmpate, $forcar),
            default => throw new \InvalidArgumentException('Ação desconhecida.'),
        };
    }

    private function validarMeta(array $campanha, ?float $meta, ?float $roasEmpate, bool $forcar): bool
    {
        if ($campanha['tipo'] === 'auto' || $campanha['lance'] !== 'auto') {
            throw new \InvalidArgumentException('Essa campanha não usa lance automático com meta de ROAS; mude na Central de Marketing.');
        }
        if (! $meta || $meta <= 0 || $meta > 100) {
            throw new \InvalidArgumentException('Meta de ROAS inválida.');
        }
        if ($roasEmpate && $meta < $roasEmpate) {
            throw new \InvalidArgumentException(sprintf('Meta %.1f abaixo do empate %.1f: a campanha daria prejuízo.', $meta, $roasEmpate));
        }
        if (! $forcar && $this->trocaDeMetaRecente($campanha['campaign_id'])) {
            throw new \InvalidArgumentException('A meta desta campanha mudou há menos de 7 dias (a Shopee ainda está aprendendo). Marque "forçar" para mudar mesmo assim.');
        }

        return true;
    }

    /** @return array{campaign_id: string, nome: string, tipo: string, lance: string, status: string, meta: ?float, orcamento: float, itens: array<int, string>}|null */
    private function campanha(string $campaignId): ?array
    {
        $c = $this->configuracao([$campaignId])[0] ?? null;

        return $c ? $this->resumo($c) : null;
    }

    private function configuracao(array $ids): array
    {
        return $this->shopee->get('/api/v2/ads/get_product_level_campaign_setting_info', [
            'campaign_id_list' => implode(',', $ids),
            'info_type_list' => '1,3',
        ])['response']['campaign_list'] ?? [];
    }

    private function resumo(array $c): array
    {
        $meta = (float) ($c['auto_bidding_info']['roas_target'] ?? 0);

        return [
            'campaign_id' => (string) $c['campaign_id'],
            'nome' => (string) ($c['common_info']['ad_name'] ?? ''),
            'tipo' => (string) ($c['common_info']['ad_type'] ?? 'manual'),
            'lance' => (string) ($c['common_info']['bidding_method'] ?? ''),
            'status' => (string) ($c['common_info']['campaign_status'] ?? ''),
            'meta' => $meta > 0 ? $meta : null,
            'orcamento' => (float) ($c['common_info']['campaign_budget'] ?? 0),
            'itens' => array_map('strval', $c['common_info']['item_id_list'] ?? []),
        ];
    }
}
