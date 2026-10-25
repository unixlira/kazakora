<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ShopeeChatService;
use Illuminate\Console\Command;

/**
 * Sincroniza o chat da Shopee (Atendimento > Shopee) a cada 2 minutos:
 * conversas não lidas e recentes, mais as mensagens novas delas.
 */
class SyncShopeeChat extends Command
{
    protected $signature = 'shopee:chat-sync
        {--paginas=1 : Páginas de conversas recentes (25 por página). Use mais na primeira carga.}
        {--max=20 : Máximo de conversas com mensagens copiadas por execução.}';

    protected $description = 'Copia conversas e mensagens do chat da Shopee para o admin.';

    public function handle(ShopeeChatService $chat): int
    {
        $r = $chat->sincronizar((int) $this->option('paginas'), (int) $this->option('max'));

        $this->info(sprintf(
            'Chat Shopee: %d conversas atualizadas, %d mensagens novas, %d falhas%s.',
            $r['conversas'], $r['mensagens'], $r['falhas'], $r['limitado'] ? ' (limite da Shopee: continua no próximo passe)' : '',
        ));

        return $r['lista_ok'] ? self::SUCCESS : self::FAILURE;
    }
}
