<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\AmazonMensagensService;
use App\Modules\Marketplace\Support\TikTokShopChatService;
use App\Services\TikTokShop\TikTokShopClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Traz as mensagens de comprador da Amazon (caixa de e-mail) e do TikTok Shop
 * (Customer Service API) para Perguntas MK Place, a cada 2 minutos. Canal sem
 * configuração é pulado sem erro.
 */
class SyncMarketplaceChat extends Command
{
    protected $signature = 'atendimento:chat-sync
        {--canal= : amazon ou tiktok_shop (padrão: os dois)}
        {--dias= : Amazon: relê os e-mails dos últimos N dias (primeira carga)}
        {--paginas=1 : TikTok: páginas de conversas (20 por página)}';

    protected $description = 'Copia as mensagens de comprador da Amazon e do TikTok Shop para o admin.';

    public function handle(AmazonMensagensService $amazon, TikTokShopChatService $tiktok): int
    {
        $canal = $this->option('canal');
        $ok = true;

        if (! $canal || $canal === 'amazon') {
            if (! AmazonMensagensService::configurado()) {
                $this->line('Amazon: e-mail não configurado, pulado.');
            } else {
                try {
                    $r = $amazon->sincronizar($this->option('dias') !== null ? (int) $this->option('dias') : null);
                    $this->info("Amazon: {$r['novas']} mensagens novas, {$r['falhas']} falhas.");
                } catch (Throwable $e) {
                    $ok = false;
                    $this->error('Amazon: '.$e->getMessage());
                }
            }
        }

        if (! $canal || $canal === 'tiktok_shop') {
            if (! TikTokShopClient::conectado()) {
                $this->line('TikTok Shop: app ou loja não conectados, pulado.');
            } else {
                try {
                    $r = $tiktok->sincronizar((int) $this->option('paginas'));
                    $this->info("TikTok Shop: {$r['conversas']} conversas, {$r['mensagens']} mensagens novas, {$r['falhas']} falhas.");
                } catch (Throwable $e) {
                    $ok = false;
                    $this->error('TikTok Shop: '.$e->getMessage());
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
