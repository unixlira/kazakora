<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Support\SaldosCanais;
use Illuminate\Console\Command;

/**
 * Foto de hora em hora do saldo e dos repasses a receber de cada
 * marketplace (aba "Saldos nos canais" do Fluxo de Caixa, 2026-10-19).
 * Só lê as APIs dos canais; grava apenas em saldos_canais. --seco mostra
 * sem gravar (para conferir em produção).
 */
class ColetarSaldosCanais extends Command
{
    protected $signature = 'financeiro:coletar-saldos
        {--canal=* : Só estes canais (shopee, mercado_livre, tiktok_shop, amazon)}
        {--seco : Mostra o que coletou sem gravar}';

    protected $description = 'Coleta saldo disponível e repasses a receber de Shopee, Mercado Livre, TikTok e Amazon';

    public function handle(SaldosCanais $saldos): int
    {
        $canais = $this->option('canal') ?: null;
        $fotos = $saldos->coletar($canais, ! $this->option('seco'));

        $this->table(
            ['Empresa', 'Canal', 'Status', 'Disponível', 'A receber', 'Repasses', 'Erro'],
            collect($fotos)->map(fn ($f) => [
                $f['empresa'],
                $f['canal'],
                $f['status'],
                $f['disponivel'] === null ? '-' : number_format((float) $f['disponivel'], 2, ',', '.'),
                $f['a_receber'] === null ? '-' : number_format((float) $f['a_receber'], 2, ',', '.'),
                collect($f['repasses'] ?? [])->map(fn ($r) => ($r['data'] ?? 'sem data').': '.number_format((float) $r['valor'], 2, ',', '.'))->take(6)->implode(' | '),
                $f['erro'] ?? '',
            ])->all(),
        );

        if ($this->getOutput()->isVerbose()) {
            $this->line(json_encode($fotos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
