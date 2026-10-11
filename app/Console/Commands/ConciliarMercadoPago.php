<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Support\ConciliacaoMercadoPago;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Baixa os pagamentos da conta do Mercado Pago e grava o que entrou de
 * verdade em cada venda do ML e do site (ver ConciliacaoMercadoPago).
 *
 *   php artisan mercadopago:conciliar                  # últimos 60 dias
 *   php artisan mercadopago:conciliar --desde=2026-06-01 --seco
 */
class ConciliarMercadoPago extends Command
{
    protected $signature = 'mercadopago:conciliar {--desde= : AAAA-MM-DD (padrão: 60 dias atrás)} {--ate= : AAAA-MM-DD (padrão: amanhã)} {--seco : só mostra o que mudaria}';

    protected $description = 'Concilia a conta do Mercado Pago: taxa real das vendas do ML e do site, estornos e bônus do Flex';

    public function handle(ConciliacaoMercadoPago $conciliacao): int
    {
        $desde = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : Carbon::today()->subDays(60);
        $ate = $this->option('ate') ? Carbon::parse($this->option('ate'))->addDay()->startOfDay() : Carbon::tomorrow();
        $seco = (bool) $this->option('seco');

        $baixa = $conciliacao->baixar($desde, $ate);
        $this->info("{$baixa['baixados']} pagamento(s) de {$desde->format('d/m/Y')} a {$ate->copy()->subDay()->format('d/m/Y')}: ".collect($baixa['por_canal'])->map(fn ($n, $c) => "$c=$n")->implode(', '));

        $r = $conciliacao->aplicar($desde, $ate, $seco);
        $this->info(($seco ? '[seco] ' : '')."Taxas reais: {$r['taxas']} pedido(s) · antes R$ ".number_format($r['taxa_antes'], 2, ',', '.').' → agora R$ '.number_format($r['taxa_depois'], 2, ',', '.'));
        $this->info("Estornos novos: {$r['estornos']} · estornos desfeitos: {$r['desfeitos']} · taxas manuais preservadas: {$r['manuais']}");
        foreach ($r['divergencias'] as $linha) {
            $this->line('  · '.$linha);
        }

        return self::SUCCESS;
    }
}
