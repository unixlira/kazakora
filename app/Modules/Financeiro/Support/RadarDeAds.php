<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alerta de ADS dentro do admin (pedido do Lira 2026-10-19: "a notificação
 * do alerta de 6% do ADS da Shopee e Meta Ads ali no painel admin"). Mesma
 * regra do vigia do Homer (ferramentas/ads-vigia/vigia.py): ADS de um canal
 * acima de 6% da receita do canal (ContributionMargin::porCanal), no mês até
 * hoje OU nos últimos 7 dias; canal com ADS e sem venda também acende.
 *
 * Meta Ads: o gasto não fica no banco nem na conta oficial do lucro. Vem da
 * Marketing API (MetaAdsService::gastoNoPeriodo, 30 min de cache) e é
 * comparado com a receita do site (canal loja). Sem resposta da Meta, a
 * linha diz "gasto não disponível" e o motivo.
 *
 * Mostrado no Fluxo de Caixa, no Resultado e margem e no sino (comando
 * financeiro:vigiar-ads). Não muda nenhum número do lucro.
 */
class RadarDeAds
{
    public const CACHE_MINUTOS = 10;

    public const META_CACHE_MINUTOS = 30;

    /** Canais da conta oficial vigiados, na ordem da tela. */
    public const CANAIS = [
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
    ];

    public const META = 'meta_ads';

    /** Tela de anúncios de cada canal (rotas conferidas em rotas-kazakora.json). */
    public const LINKS = [
        Order::ORIGIN_SHOPEE => ['href' => '/admin/publicidade/shopee', 'rotulo' => 'Abrir Shopee Ads'],
        Order::ORIGIN_MERCADO_LIVRE => ['href' => '/admin/publicidade', 'rotulo' => 'Ver anúncios'],
        Order::ORIGIN_TIKTOK_SHOP => ['href' => '/admin/arquivos-tiktok', 'rotulo' => 'Arquivos do TikTok'],
        Order::ORIGIN_AMAZON => ['href' => '/admin/publicidade', 'rotulo' => 'Ver anúncios'],
        self::META => ['href' => '/admin/publicidade/meta-ads', 'rotulo' => 'Abrir Meta Ads'],
    ];

    public function __construct(
        private readonly ContributionMargin $margem,
        private readonly MetaAdsService $meta,
    ) {}

    public static function limite(): float
    {
        return (float) config('loja.ads_alerta_percentual', 6);
    }

    /**
     * Com cache curto (a tela inicial do admin chama em toda visita).
     *
     * @return array<string, mixed>
     */
    public function resumo(): array
    {
        $hoje = Carbon::today();

        return Cache::remember('radar-ads:'.$hoje->toDateString(), now()->addMinutes(self::CACHE_MINUTOS), fn () => $this->calcular($hoje));
    }

    /**
     * Mês até hoje e últimos 7 dias (hoje incluso), em America/Sao_Paulo.
     *
     * @return array{limite: float, mes: array{de: string, ate: string}, semana: array{de: string, ate: string},
     *   canais: list<array<string, mixed>>, acima: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function calcular(?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $fim = $hoje->copy()->addDay();
        $inicioMes = $hoje->copy()->startOfMonth();
        $inicioSemana = $hoje->copy()->subDays(6);
        $limite = self::limite();

        $mes = $this->margem->porCanal($inicioMes, $fim);
        $semana = $this->margem->porCanal($inicioSemana, $fim);

        $canais = [];
        foreach (self::CANAIS as $canal => $nome) {
            $canais[] = $this->linha($canal, $nome,
                (float) ($mes[$canal]['receita'] ?? 0), (float) ($mes[$canal]['ads'] ?? 0),
                (float) ($semana[$canal]['receita'] ?? 0), (float) ($semana[$canal]['ads'] ?? 0), $limite);
        }

        $meta = $this->meta($inicioMes, $inicioSemana, $hoje);
        if ($meta['disponivel']) {
            $canais[] = $this->linha(self::META, 'Meta Ads (site)',
                (float) ($mes[Order::ORIGIN_STORE]['receita'] ?? 0), $meta['mes']['gasto'],
                (float) ($semana[Order::ORIGIN_STORE]['receita'] ?? 0), $meta['semana']['gasto'], $limite);
        }

        return [
            'limite' => $limite,
            'mes' => ['de' => $inicioMes->toDateString(), 'ate' => $hoje->toDateString()],
            'semana' => ['de' => $inicioSemana->toDateString(), 'ate' => $hoje->toDateString()],
            'canais' => $canais,
            'acima' => array_values(array_filter($canais, fn ($c) => $c['acima'])),
            'meta' => $meta,
        ];
    }

    /** @return array<string, mixed> */
    private function linha(string $canal, string $nome, float $receitaMes, float $adsMes, float $receitaSemana, float $adsSemana, float $limite): array
    {
        $periodo = function (float $receita, float $ads) use ($limite) {
            $pct = $receita > 0 ? round($ads / $receita * 100, 2) : null;

            return [
                'receita' => round($receita, 2),
                'ads' => round($ads, 2),
                'pct' => $pct,
                // Mesma regra do vigia: acima do limite, ou ADS sem nenhuma venda.
                'acima' => $ads > 0 && ($pct === null || $pct > $limite),
            ];
        };
        $m = $periodo($receitaMes, $adsMes);
        $s = $periodo($receitaSemana, $adsSemana);

        return [
            'canal' => $canal,
            'nome' => $nome,
            'mes' => $m,
            'semana' => $s,
            'acima' => $m['acima'] || $s['acima'],
            'link' => self::LINKS[$canal] ?? ['href' => '/admin/publicidade', 'rotulo' => 'Ver anúncios'],
        ];
    }

    /**
     * Gasto da Meta no mês e nos 7 dias. Falha fica 5 min guardada para a
     * tela inicial não esperar a Meta em toda visita.
     *
     * @return array{disponivel: bool, motivo: ?string, mes: array{gasto: float, vendas: float}, semana: array{gasto: float, vendas: float}}
     */
    private function meta(Carbon $inicioMes, Carbon $inicioSemana, Carbon $hoje): array
    {
        $vazio = ['gasto' => 0.0, 'vendas' => 0.0];
        if (! $this->meta->configurado()) {
            return ['disponivel' => false, 'motivo' => 'Meta Ads não configurado neste servidor.', 'mes' => $vazio, 'semana' => $vazio];
        }

        $chave = 'radar-ads:meta:'.$hoje->toDateString();
        $erro = Cache::get($chave.':erro');
        if ($erro !== null) {
            return ['disponivel' => false, 'motivo' => $erro, 'mes' => $vazio, 'semana' => $vazio];
        }

        try {
            return Cache::remember($chave, now()->addMinutes(self::META_CACHE_MINUTOS), fn () => [
                'disponivel' => true,
                'motivo' => null,
                'mes' => $this->meta->gastoNoPeriodo($inicioMes, $hoje),
                'semana' => $this->meta->gastoNoPeriodo($inicioSemana, $hoje),
            ]);
        } catch (Throwable $e) {
            Log::warning('radar_ads.meta_falhou', ['erro' => mb_substr($e->getMessage(), 0, 300)]);
            $motivo = 'A Meta não respondeu agora.';
            Cache::put($chave.':erro', $motivo, now()->addMinutes(5));

            return ['disponivel' => false, 'motivo' => $motivo, 'mes' => $vazio, 'semana' => $vazio];
        }
    }

    /**
     * Gasto e vendas atribuídas da Meta num período qualquer (Resultado e
     * margem, bloco de anúncios). null quando não deu para consultar.
     *
     * @return array{gasto: float, vendas: float}|null
     */
    public function metaNoPeriodo(Carbon $de, Carbon $ate): ?array
    {
        if (! $this->meta->configurado()) {
            return null;
        }

        $chave = 'radar-ads:meta-periodo:'.$de->toDateString().':'.$ate->toDateString();
        if (Cache::has($chave.':erro')) {
            return null;
        }

        try {
            return Cache::remember($chave, now()->addMinutes(self::META_CACHE_MINUTOS), fn () => $this->meta->gastoNoPeriodo($de, $ate));
        } catch (Throwable $e) {
            Log::warning('radar_ads.meta_periodo_falhou', ['erro' => mb_substr($e->getMessage(), 0, 300)]);
            Cache::put($chave.':erro', true, now()->addMinutes(5));

            return null;
        }
    }
}
