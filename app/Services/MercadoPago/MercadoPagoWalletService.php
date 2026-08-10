<?php

namespace App\Services\MercadoPago;

use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Saldo disponível pra saque via relatório de liberações (release_report) —
 * confirmado ao vivo 2026-08-09/10, real e funcionando, mas ASSÍNCRONO: pedir
 * o relatório devolve 202 na hora, só fica pronto (status "enabled" na
 * listagem) uns 15-20min depois — não dá pra consultar "ao vivo" numa
 * requisição de página como a Shopee (ver ShopeeWalletService). Por isso o
 * fluxo aqui é sempre dois passos separados no tempo: requestReport() agora,
 * findReadyReportBalance() numa execução futura do comando de sync.
 *
 * Endpoints achados só testando ao vivo — a doc pública indexada por busca
 * cita um "/release_report/download" que NUNCA existiu de verdade (testado,
 * 404); o caminho real é usar o "file_name" que a listagem devolve como
 * segmento de URL.
 */
class MercadoPagoWalletService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function requestReport(Carbon $from, Carbon $to): void
    {
        $response = $this->http()->post(self::BASE_URL.'/v1/account/release_report', [
            // Sem milissegundos ("Y-m-d\TH:i:s\Z") — achado ao vivo: com
            // ".000Z" a API sempre recusava com "Must specify begin_date
            // parameter", mesmo o valor estando lá; sem os milissegundos
            // funciona (202 confirmado).
            'begin_date' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_date' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        if ($response->status() !== 202) {
            throw new MercadoPagoException('Falha ao pedir relatório de saldo do Mercado Pago: '.$response->body(), $response->status());
        }
    }

    /**
     * Relatório "enabled" mais recente da lista, ou null se não tem nenhum
     * pronto ainda (todos "pending"/não existe nenhum pedido feito ainda).
     *
     * @return array{file_name: string, date_created: string}|null
     */
    public function latestReadyReport(): ?array
    {
        $response = $this->http()->get(self::BASE_URL.'/v1/account/release_report/list');

        if ($response->failed()) {
            throw new MercadoPagoException('Falha ao consultar relatórios de saldo do Mercado Pago: '.$response->body(), $response->status());
        }

        $reports = collect($response->json() ?? [])
            ->filter(fn ($report) => ($report['status'] ?? null) === 'enabled')
            ->sortByDesc('date_created')
            ->values();

        if ($reports->isEmpty()) {
            return null;
        }

        return [
            'file_name' => $reports[0]['file_name'],
            'date_created' => $reports[0]['date_created'],
        ];
    }

    /**
     * Baixa o CSV e devolve o BALANCE_AMOUNT da última linha com data (a
     * última linha do arquivo é um rodapé de totais sem data, não é saldo
     * de verdade — ver exemplo real comentado abaixo).
     *
     * Exemplo de linha real (2026-08-09):
     * "2026-08-08T00:12:00.000-03:00;172677044586;payment;0.00;24.24;-24.24;0.00;0.00;available_money;...;500.49;account_money;"
     * Rodapé (sem data, ignorado): ";;;500.49;0.00;597.13;-37.54;0.00;;;;;0.00;;"
     */
    public function downloadBalance(string $fileName): ?float
    {
        $response = $this->http()->get(self::BASE_URL.'/v1/account/release_report/'.$fileName);

        if ($response->failed()) {
            throw new MercadoPagoException('Falha ao baixar relatório de saldo do Mercado Pago: '.$response->body(), $response->status());
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response->body()));

        if (count($lines) < 2) {
            return null;
        }

        $header = str_getcsv(array_shift($lines), ';');
        $dateIndex = array_search('DATE', $header, true);
        $balanceIndex = array_search('BALANCE_AMOUNT', $header, true);

        if ($dateIndex === false || $balanceIndex === false) {
            throw new RuntimeException('Relatório de saldo do Mercado Pago veio sem as colunas DATE/BALANCE_AMOUNT esperadas.');
        }

        $lastBalance = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, ';');

            // Linha de rodapé (totais do período) não tem data — não é um
            // saldo de verdade, pula.
            if (($fields[$dateIndex] ?? '') === '') {
                continue;
            }

            if (isset($fields[$balanceIndex]) && $fields[$balanceIndex] !== '') {
                $lastBalance = (float) $fields[$balanceIndex];
            }
        }

        return $lastBalance;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken(config('services.mercadopago.access_token'));
    }
}
