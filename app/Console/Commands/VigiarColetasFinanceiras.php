<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vigia de hora em hora da coleta financeira (2026-10-16). Pega o que o
 * agente não conseguiu avisar sozinho (agente parado, PC desligado):
 * - TikTok: nenhum relatório chegou há mais de 26 h (o agente roda às 23h30);
 * - Amazon: venda de mais de 4 dias sem taxa real (a coleta pela SP-API,
 *   amazon:taxas-reais, não trouxe a taxa; desde 2026-10-17 não é mais a
 *   tela da Seller Central na Cora Box).
 * Falha vira WhatsApp da Manuela ao Lira; o mesmo problema repete a cada 6 h.
 */
class VigiarColetasFinanceiras extends Command
{
    protected $signature = 'financeiro:vigiar-coletas
        {--horas-tiktok=26 : Horas sem relatório do TikTok antes de avisar}
        {--dias-amazon=4 : Dias que uma venda da Amazon pode ficar sem taxa real}';

    protected $description = 'Avisa o Lira no WhatsApp (Manuela) se a coleta financeira do TikTok ou da Amazon parou';

    private const REPETIR_MINUTOS = 360;

    public function handle(AlertaColetaFinanceira $alerta): int
    {
        foreach (['TikTok' => $this->tiktok(), 'Amazon' => $this->amazon()] as $canal => $motivo) {
            if ($motivo === null) {
                $this->info("{$canal}: ok");

                continue;
            }

            $status = $alerta->avisar($canal, $motivo, 'vigia-'.mb_strtolower($canal), self::REPETIR_MINUTOS);
            $this->warn("{$canal}: {$motivo} (WhatsApp: {$status})");
        }

        return self::SUCCESS;
    }

    private function tiktok(): ?string
    {
        $horas = (int) $this->option('horas-tiktok');
        $ultimo = DB::table('tiktok_report_uploads')->max('created_at');

        if ($ultimo !== null && Carbon::parse($ultimo)->gt(now()->subHours($horas))) {
            return null;
        }

        $desde = $ultimo ? 'desde '.Carbon::parse($ultimo)->format('d/m H:i') : 'nunca';

        return "Nenhum relatório do TikTok chegou {$desde} (mais de {$horas} h). Confira o agente do TikTok na Cora Box (sessão, Chrome, tarefa das 23h30).";
    }

    private function amazon(): ?string
    {
        $dias = (int) $this->option('dias-amazon');

        $pedidos = Order::query()
            ->vendaEfetiva()
            ->where('orders.origin', Order::ORIGIN_AMAZON)
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->whereBetween('orders.created_at', [now()->subDays(30), now()->subDays($dias)])
            ->whereNotExists(fn ($q) => $q->from('order_channel_fees')
                ->whereColumn('order_channel_fees.order_id', 'orders.id')
                ->where('order_channel_fees.source', '!=', 'estimate'))
            ->orderBy('orders.id')
            ->pluck('orders.id');

        if ($pedidos->isEmpty()) {
            return null;
        }

        $lista = $pedidos->take(8)->map(fn ($id) => "#{$id}")->implode(', ').($pedidos->count() > 8 ? '...' : '');

        return "{$pedidos->count()} venda(s) da Amazon com mais de {$dias} dias sem taxa real ({$lista}). A coleta pela API da Amazon (Finances, amazon:taxas-reais) não trouxe a taxa: {$this->situacaoColetaAmazon()}";
    }

    /** O que a última execução de amazon:taxas-reais deixou registrado. */
    private function situacaoColetaAmazon(): string
    {
        $ultima = Cache::get(TaxasReaisAmazon::CACHE_ULTIMA);

        if (! is_array($ultima)) {
            return 'o comando não rodou nos últimos 30 dias, confira o agendador.';
        }

        $quando = fn (?string $iso) => $iso ? Carbon::parse($iso)->setTimezone('America/Sao_Paulo')->format('d/m H:i') : 'nunca';

        if (! ($ultima['ok'] ?? false)) {
            return "a última execução ({$quando($ultima['em'] ?? null)}) falhou: ".Str::limit((string) ($ultima['erro'] ?? 'sem detalhe'), 120).". Último sucesso: {$quando($ultima['ultimo_ok'] ?? null)}.";
        }

        return "a API respondeu ({$quando($ultima['em'] ?? null)}), mas ainda sem transação desses pedidos (envio não confirmado na Amazon ou pedido com outro número).";
    }
}
