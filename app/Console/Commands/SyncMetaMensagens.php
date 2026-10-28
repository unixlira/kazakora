<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Services\Meta\MetaMensagensService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Traz o Direct do Instagram e o Messenger do Facebook para Atendimento, a
 * cada 2 minutos. Rede sem permissão no token é pulada sem erro.
 */
class SyncMetaMensagens extends Command
{
    protected $signature = 'atendimento:meta-sync
        {--rede= : instagram ou facebook (padrão: as duas)}
        {--conversas=25 : conversas mais recentes lidas por rede}';

    protected $description = 'Copia as mensagens do Direct do Instagram e do Messenger do Facebook para o admin.';

    public function handle(MetaMensagensService $meta): int
    {
        $ok = true;
        $nomes = [MarketplaceChatConversa::INSTAGRAM => 'Instagram', MarketplaceChatConversa::FACEBOOK => 'Facebook'];

        foreach (MarketplaceChatConversa::REDES as $rede) {
            if ($this->option('rede') && $this->option('rede') !== $rede) {
                continue;
            }
            $faltando = MetaMensagensService::faltando($rede);
            if ($faltando !== []) {
                $this->line("{$nomes[$rede]}: pulado, falta ".implode('; ', $faltando).'.');

                continue;
            }

            try {
                $r = $meta->sincronizar($rede, (int) $this->option('conversas'));
                $this->info("{$nomes[$rede]}: {$r['conversas']} conversas, {$r['mensagens']} mensagens novas, {$r['falhas']} falhas.");
            } catch (Throwable $e) {
                $ok = false;
                $this->error("{$nomes[$rede]}: ".$e->getMessage());
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
