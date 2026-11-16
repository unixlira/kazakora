<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\RastreioPrePostagem;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Situação de cada pré-postagem nos Correios (pedido do Lira 2026-11-16).
 * A etiqueta AP623701379BR (pedido Amazon #3181) foi gerada em 16/10, nunca
 * foi postada (o produto foi na caixa do #3180) e expirou sem ninguém ver:
 * o `status` continuava "gerada". A cada 2 horas consulta o rastreio das
 * etiquetas geradas nos últimos N dias que ainda não foram entregues nem
 * expiraram, as menos consultadas primeiro e com teto por rodada, e grava
 * situação, último evento, histórico e datas (RastreioPrePostagem).
 *
 * À mão para um código: correios:acompanhar-pre-postagens --codigo=AP623701379BR
 */
class AcompanharPrePostagensCorreios extends Command
{
    protected $signature = 'correios:acompanhar-pre-postagens
        {--codigo= : consulta só este código de objeto (ignora dias e teto)}
        {--dias=60 : etiquetas geradas nos últimos N dias}
        {--limite=150 : teto de consultas por rodada}
        {--simular : só mostra, não grava}';

    protected $description = 'Grava a situação de cada pré-postagem nos Correios (postada, entregue, expirada) pelo rastreio';

    public function handle(CorreiosRastroService $rastro, RastreioPrePostagem $aplicar): int
    {
        $simular = (bool) $this->option('simular');
        $codigo = strtoupper(trim((string) $this->option('codigo')));

        if ($codigo !== '') {
            return $this->umCodigo($codigo, $rastro, $aplicar, $simular);
        }

        $contagem = ['consultadas' => 0, 'postadas' => 0, 'entregues' => 0, 'expiradas' => 0, 'devolvidas' => 0, 'falhas' => 0];

        $lote = CorreiosPrePostagem::query()
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->whereNotNull('codigo_objeto')
            ->where('codigo_objeto', '!=', '')
            ->whereNull('entregue_em')
            ->whereNull('expirada_em')
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('dias'))))
            // Nunca consultadas primeiro, depois as consultadas há mais tempo:
            // com o teto, a fila gira e nenhuma fica sem consulta.
            ->orderByRaw('rastreio_consultado_em IS NOT NULL')
            ->orderBy('rastreio_consultado_em')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();

        foreach ($lote as $pre) {
            $contagem['consultadas']++;

            try {
                $r = $rastro->consultar($pre->codigo_objeto);
            } catch (Throwable $e) {
                $contagem['falhas']++;
                Log::warning('correios.pre_postagem_rastreio_falhou', ['id' => $pre->id, 'codigo' => $pre->codigo_objeto, 'error' => $e->getMessage()]);

                continue;
            }

            $aplicar->aplicar($pre, $r);
            $this->line("{$pre->codigo_objeto} #{$pre->id}: {$pre->rastreio_situacao} — ".($r['ultimo'] ?? 'sem evento'));

            match ($pre->rastreio_situacao) {
                CorreiosPrePostagem::SITUACAO_POSTADA => $contagem['postadas']++,
                CorreiosPrePostagem::SITUACAO_ENTREGUE => $contagem['entregues']++,
                CorreiosPrePostagem::SITUACAO_EXPIRADA => $contagem['expiradas']++,
                CorreiosPrePostagem::SITUACAO_DEVOLVIDA => $contagem['devolvidas']++,
                default => null,
            };

            if (! $simular) {
                $pre->save();
            }
        }

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }

    private function umCodigo(string $codigo, CorreiosRastroService $rastro, RastreioPrePostagem $aplicar, bool $simular): int
    {
        try {
            $r = $rastro->consultar($codigo);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach (array_reverse($r['eventos'] ?? []) as $evento) {
            $this->line(trim(($evento['data'] ?? '').'  '.($evento['descricao'] ?? '').'  '.($evento['local'] ?? '')));
        }
        $this->line('Último: '.($r['ultimo'] ?? 'sem evento'));

        $registros = CorreiosPrePostagem::query()->where('codigo_objeto', $codigo)->get();

        if ($registros->isEmpty()) {
            $this->warn("Nenhuma pré-postagem com o código {$codigo} no sistema: nada gravado.");

            return self::SUCCESS;
        }

        foreach ($registros as $pre) {
            $aplicar->aplicar($pre, $r);
            $this->info("#{$pre->id}: {$pre->rastreio_situacao}".($simular ? ' (simulado, não gravado)' : ''));

            if (! $simular) {
                $pre->save();
            }
        }

        return self::SUCCESS;
    }
}
