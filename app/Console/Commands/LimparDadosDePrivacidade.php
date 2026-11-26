<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\FerraKoraVisit;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Models\VisitanteGeo;
use App\Modules\Checkout\Models\Order;
use App\Modules\Clientes\Models\ClienteEvento;
use App\Modules\Clientes\Support\HistoricoDoCliente;
use Illuminate\Console\Command;

/**
 * Prazos de guarda (pedido 2026-10-10, docs/privacidade-e-cookies.md):
 * - Visitas: o Marco Civil (art. 15) manda guardar IP e data/hora por 6
 *   meses. Passou disso, apaga IP, navegador e valor do clique (click_id); a estatística (página, dia,
 *   origem) continua, sem identificar ninguém.
 * - OK dos cookies: prova guardada por 5 anos, depois apagada.
 * - orders.meta_rastreio (API de Conversões da Meta): 30 dias.
 * - Medição do checkout (checkout_eventos, 2026-10-23): 6 meses, apagada.
 * - Lugar do IP por sessão (visitante_geolocalizacoes, 2026-10-24): 6 meses, apagado.
 * - Histórico do cliente (cliente_eventos, 2026-11-26): login, saída e
 *   ligação de visitas apagados aos 6 meses; IP e aparelho dos outros
 *   eventos também (o evento fica enquanto a conta existir).
 */
class LimparDadosDePrivacidade extends Command
{
    protected $signature = 'privacidade:limpar';

    protected $description = 'Apaga IP/navegador/cookies de visitas com mais de 6 meses e OKs de cookies com mais de 5 anos e eventos do checkout e lugares de IP com mais de 6 meses';

    public function handle(): int
    {
        // Inclui as visitas que não contam (ignorada): o prazo do IP vale para todas.
        $visitas = SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA)
            ->where('created_at', '<', now()->subMonths(6)->subDay())
            ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('user_id')->orWhereNotNull('ferrakora_cliente_id')->orWhereNotNull('click_id')->orWhereNotNull('cookies')->orWhereNotNull('latitude')->orWhereNotNull('cep')->orWhere('visitor_id', '!=', 'anonimo'))
            ->update(['ip' => null, 'user_agent' => null, 'user_id' => null, 'ferrakora_cliente_id' => null, 'click_id' => null, 'cookies' => null, 'latitude' => null, 'longitude' => null, 'cep' => null, 'visitor_id' => 'anonimo']);

        // Visitas do site FerraKora: mesmo prazo.
        $visitas += FerraKoraVisit::query()
            ->where('created_at', '<', now()->subMonths(6)->subDay())
            ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent'))
            ->update(['ip' => null, 'user_agent' => null]);

        // Rastreio da API de Conversões da Meta (2026-10-18) que não chegou a
        // ser usado (pedido nunca pago): 30 dias.
        $rastreios = Order::query()
            ->whereNotNull('meta_rastreio')
            ->where('created_at', '<', now()->subDays(30))
            ->update(['meta_rastreio' => null]);

        // Medição do checkout (2026-10-23): estatística de curto prazo, 6 meses.
        $eventosCheckout = CheckoutEvento::query()->where('created_at', '<', now()->subMonths(6)->subDay())->delete();

        // Lugar aproximado do IP por sessão (2026-10-24): mesmo prazo do IP, apagado.
        $lugares = VisitanteGeo::query()->where('updated_at', '<', now()->subMonths(6)->subDay())->delete();

        // Histórico do cliente (2026-11-26): registro de acesso some aos 6 meses; nos outros eventos sai o IP.
        $limiteHistorico = now()->subMonths(6)->subDay();
        $historico = ClienteEvento::query()->where('created_at', '<', $limiteHistorico)
            ->whereIn('tipo', HistoricoDoCliente::TIPOS_DE_ACESSO)->delete();
        $historico += ClienteEvento::query()->where('created_at', '<', $limiteHistorico)
            ->where(fn ($q) => $q->whereNotNull('ip')->orWhereNotNull('dispositivo'))
            ->update(['ip' => null, 'dispositivo' => null]);

        $consentimentos = ConsentimentoCookie::query()->where('aceito_em', '<', now()->subYears(5))->delete();

        $this->info("Visitas anonimizadas: {$visitas}. OKs de cookies apagados: {$consentimentos}. Rastreios da Meta apagados: {$rastreios}. Eventos do checkout apagados: {$eventosCheckout}. Lugares de IP apagados: {$lugares}. Eventos do histórico do cliente limpos: {$historico}.");

        return self::SUCCESS;
    }
}
