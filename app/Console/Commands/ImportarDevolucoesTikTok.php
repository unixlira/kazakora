<?php

namespace App\Console\Commands;

use App\Modules\Admin\Http\Controllers\DevolucoesController;
use App\Modules\Marketplace\Support\ReturnsSyncService;
use Illuminate\Console\Command;

/**
 * Importa um JSON da tela de devoluções do TikTok (resposta de
 * api/v1/reverse/component/orders/list, ou {"cartoes": [...]}, ou a lista
 * de cartões) para o controle de devoluções. O caminho normal é o agente do
 * TikTok pela API (/api/tiktok/devolucoes); isto serve para carga à mão e
 * teste (pedido do Lira 03/11/2026).
 */
class ImportarDevolucoesTikTok extends Command
{
    protected $signature = 'devolucoes:tiktok-importar {arquivo : JSON com os cartões da tela de devoluções do seller-br}';

    protected $description = 'Importa devoluções do TikTok Shop de um JSON da tela de devoluções do seller-br';

    public function handle(ReturnsSyncService $sync): int
    {
        $conteudo = @file_get_contents((string) $this->argument('arquivo'));
        $dados = is_string($conteudo) ? json_decode($conteudo, true) : null;

        // A resposta crua do seller-br às vezes vem como texto JSON dentro de JSON.
        if (is_string($dados)) {
            $dados = json_decode($dados, true);
        }

        $cartoes = $dados['data']['cards'] ?? $dados['cartoes'] ?? (array_is_list((array) $dados) ? $dados : null);

        if (! is_array($cartoes)) {
            $this->error('Arquivo sem cartões de devolução do TikTok.');

            return self::FAILURE;
        }

        $gravados = $sync->sincronizarTikTok(array_values($cartoes));
        DevolucoesController::limparContagem();

        $this->info('TikTok Shop: '.count($cartoes)." cartão(ões) lido(s), {$gravados} gravado(s).");

        return self::SUCCESS;
    }
}
