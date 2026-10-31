<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cobranças que os canais fazem sem pedido e que ficavam fora do lucro
 * (auditoria do financeiro, 2026-10-31): na fatura do Mercado Livre, Full
 * (retirada e armazenagem) e manutenção da Minha página, com os estornos; na
 * carteira da Shopee, os débitos sem pedido (DIFAL). Entram na linha de taxas
 * por ContributionMargin::cobrancasSemPedido().
 *
 *   php artisan financeiro:lancamentos                     (últimos 45 dias)
 *   php artisan financeiro:lancamentos --desde=2026-05-01  (histórico)
 *   php artisan financeiro:lancamentos --seco              (só mostra)
 */
class SincronizarLancamentosFinanceiros extends Command
{
    protected $signature = 'financeiro:lancamentos
        {--desde= : data inicial (padrão: 45 dias atrás)}
        {--seco : só mostra o que gravaria}';

    protected $description = 'Grava as cobranças sem pedido do Mercado Livre (Full, Minha página) e da Shopee (DIFAL)';

    /** Tipos da fatura do ML: cobrança e estorno de cada uma. */
    private const TIPOS_ML = ['CESM', 'BESM', 'CFRS', 'CFWA'];

    public function handle(MercadoLivreAuthService $mlAuth, ShopeeClient $shopee): int
    {
        $desde = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->subDays(45)->startOfDay();

        $linhas = [...$this->faturaMercadoLivre($mlAuth, $desde), ...$this->carteiraShopee($shopee, $desde)];

        foreach ($linhas as $linha) {
            $this->line("{$linha['data']} {$linha['canal']} {$linha['tipo']} {$linha['valor']} {$linha['descricao']}");
        }
        $this->info(count($linhas).' lançamento(s), R$ '.number_format(array_sum(array_column($linhas, 'valor')), 2, ',', '.').'.');

        if (! $this->option('seco') && $linhas !== []) {
            $agora = now();
            DB::table('financeiro_lancamentos')->upsert(
                array_map(fn ($l) => $l + ['created_at' => $agora, 'updated_at' => $agora], $linhas),
                ['external_id'],
                ['data', 'canal', 'tipo', 'descricao', 'valor', 'updated_at'],
            );
        }

        return self::SUCCESS;
    }

    /** @return list<array{data: string, canal: string, tipo: string, descricao: ?string, valor: float, external_id: string}> */
    private function faturaMercadoLivre(MercadoLivreAuthService $auth, Carbon $desde): array
    {
        try {
            $token = $auth->ensureValidToken($auth->currentToken())->access_token;
            $base = 'https://api.mercadolibre.com/billing/integration';
            $periodos = Http::timeout(60)->withToken($token)->get("{$base}/monthly/periods", ['group' => 'ML', 'document_type' => 'BILL', 'offset' => 0, 'limit' => 12])->throw()->json('results') ?? [];

            $linhas = [];
            foreach ($periodos as $periodo) {
                if (Carbon::parse($periodo['period']['date_to'])->lt($desde)) {
                    continue;
                }
                $offset = 0;
                do {
                    $pagina = Http::timeout(60)->retry(4, 30000, throw: false)->withToken($token)
                        ->get("{$base}/periods/key/{$periodo['key']}/group/ML/details", ['document_type' => 'BILL', 'limit' => 500, 'offset' => $offset])
                        ->throw()->json();
                    foreach ($pagina['results'] ?? [] as $item) {
                        $info = $item['charge_info'] ?? [];
                        $data = Carbon::parse($info['creation_date_time'] ?? 'now')->timezone(config('app.timezone'));
                        if (! in_array($info['detail_sub_type'] ?? '', self::TIPOS_ML, true) || $data->lt($desde)) {
                            continue;
                        }
                        $valor = abs((float) ($info['detail_amount'] ?? 0));
                        $linhas[] = [
                            'data' => $data->toDateString(),
                            'canal' => Order::ORIGIN_MERCADO_LIVRE,
                            'tipo' => (string) $info['detail_sub_type'],
                            'descricao' => mb_substr((string) ($info['transaction_detail'] ?? ''), 0, 255),
                            'valor' => ($info['detail_type'] ?? '') === 'BONUS' ? -$valor : $valor,
                            'external_id' => 'ml:'.($info['detail_id'] ?? md5(json_encode($info))),
                        ];
                    }
                    $offset += 500;
                } while ($offset < (int) ($pagina['total'] ?? 0));
            }

            return $linhas;
        } catch (Throwable $erro) {
            $this->error('Fatura do Mercado Livre: '.$erro->getMessage());

            return [];
        }
    }

    /** @return list<array{data: string, canal: string, tipo: string, descricao: ?string, valor: float, external_id: string}> */
    private function carteiraShopee(ShopeeClient $shopee, Carbon $desde): array
    {
        $linhas = [];
        try {
            for ($de = $desde->copy(); $de->lt(now()); $de->addDays(7)) {
                $ate = $de->copy()->addDays(7)->min(now());
                $pagina = 1;
                do {
                    $resposta = $shopee->get('/api/v2/payment/get_wallet_transaction_list', [
                        'page_no' => $pagina++, 'page_size' => 100, 'create_time_from' => $de->timestamp, 'create_time_to' => $ate->timestamp - 1,
                    ])['response'] ?? [];
                    foreach ($resposta['transaction_list'] ?? [] as $t) {
                        if (($t['transaction_type'] ?? '') !== 'ADJUSTMENT_CENTER_DEDUCT' || ($t['order_sn'] ?? '') !== '') {
                            continue;
                        }
                        $linhas[] = [
                            'data' => Carbon::createFromTimestamp($t['create_time'], config('app.timezone'))->toDateString(),
                            'canal' => Order::ORIGIN_SHOPEE,
                            'tipo' => 'ADJUSTMENT_CENTER_DEDUCT',
                            'descricao' => mb_substr((string) ($t['reason'] ?? ''), 0, 255),
                            'valor' => abs((float) $t['amount']),
                            'external_id' => 'shopee:'.$t['transaction_id'],
                        ];
                    }
                } while (($resposta['more'] ?? false) && $pagina < 30);
            }
        } catch (Throwable $erro) {
            $this->error('Carteira da Shopee: '.$erro->getMessage());
        }

        return $linhas;
    }
}
