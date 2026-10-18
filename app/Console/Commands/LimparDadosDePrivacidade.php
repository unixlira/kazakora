<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\FerraKoraVisit;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use Illuminate\Console\Command;

/**
 * Prazos de guarda (pedido 2026-10-10, docs/privacidade-e-cookies.md):
 * - Visitas: o Marco Civil (art. 15) manda guardar IP e data/hora por 6
 *   meses. Passou disso, apaga IP, navegador e valor do clique (click_id); a estatística (página, dia,
 *   origem) continua, sem identificar ninguém.
 * - OK dos cookies: prova guardada por 5 anos, depois apagada.
 * - orders.meta_rastreio (API de Conversões da Meta): 30 dias.
 */
class LimparDadosDePrivacidade extends Command
{
    protected $signature = 'privacidade:limpar';

    protected $description = 'Apaga IP/navegador/cookies de visitas com mais de 6 meses e OKs de cookies com mais de 5 anos';

    public function handle(): int
    {
        $visitas = SiteVisit::query()
            ->where('created_at', '<', now()->subMonths(6)->subDay())
            ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('user_id')->orWhereNotNull('click_id')->orWhereNotNull('cookies')->orWhere('visitor_id', '!=', 'anonimo'))
            ->update(['ip' => null, 'user_agent' => null, 'user_id' => null, 'click_id' => null, 'cookies' => null, 'visitor_id' => 'anonimo']);

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

        $consentimentos = ConsentimentoCookie::query()->where('aceito_em', '<', now()->subYears(5))->delete();

        $this->info("Visitas anonimizadas: {$visitas}. OKs de cookies apagados: {$consentimentos}. Rastreios da Meta apagados: {$rastreios}.");

        return self::SUCCESS;
    }
}
