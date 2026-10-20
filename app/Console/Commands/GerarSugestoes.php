<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\Sugestoes\GeradorDeSugestoes;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sugestões do dia (pedido do Lira 2026-10-20): roda de madrugada e deixa o
 * board de Indicadores › Sugestões do dia pronto para a manhã. Só lê e
 * recomenda; não muda preço nem anúncio. Detalhes em GeradorDeSugestoes.
 */
class GerarSugestoes extends Command
{
    protected $signature = 'sugestoes:gerar
        {--dia= : Dia das sugestões (AAAA-MM-DD), padrão hoje}
        {--sem-precos : Não pesquisa preço de concorrente (mais rápido)}';

    protected $description = 'Lê o Meta Ads, pesquisa preço de concorrente e monta as sugestões do dia';

    public function handle(GeradorDeSugestoes $gerador): int
    {
        $dia = $this->option('dia') ? Carbon::parse((string) $this->option('dia')) : null;
        $rodada = $gerador->gerar($dia, ! $this->option('sem-precos'));

        $this->info("{$rodada->total} sugestões para {$rodada->dia->format('d/m/Y')}.");
        foreach ($rodada->fontes ?? [] as $fonte => $f) {
            $this->line(sprintf('  %s: %s, %d consultas, %d resultados%s', $fonte, $f['ok'] ? 'ok' : 'erro', $f['consultas'], $f['ofertas'], $f['erro'] ? ' ('.$f['erro'].')' : ''));
        }

        return self::SUCCESS;
    }
}
