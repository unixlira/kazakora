<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Console\Command;

/**
 * Prazos de guarda (pedido 2026-10-10, docs/privacidade-e-cookies.md):
 * - Visitas: o Marco Civil (art. 15) manda guardar IP e data/hora por 6
 *   meses. Passou disso, apaga IP e navegador; a estatística (página, dia,
 *   origem) continua, sem identificar ninguém.
 * - OK dos cookies: prova guardada por 5 anos, depois apagada.
 */
class LimparDadosDePrivacidade extends Command
{
    protected $signature = 'privacidade:limpar';

    protected $description = 'Apaga IP/navegador de visitas com mais de 6 meses e OKs de cookies com mais de 5 anos';

    public function handle(): int
    {
        $visitas = SiteVisit::query()
            ->where('created_at', '<', now()->subMonths(6)->subDay())
            ->where(fn ($query) => $query->whereNotNull('ip')->orWhereNotNull('user_agent')->orWhereNotNull('user_id')->orWhere('visitor_id', '!=', 'anonimo'))
            ->update(['ip' => null, 'user_agent' => null, 'user_id' => null, 'visitor_id' => 'anonimo']);

        $consentimentos = ConsentimentoCookie::query()->where('aceito_em', '<', now()->subYears(5))->delete();

        $this->info("Visitas anonimizadas: {$visitas}. OKs de cookies apagados: {$consentimentos}.");

        return self::SUCCESS;
    }
}
