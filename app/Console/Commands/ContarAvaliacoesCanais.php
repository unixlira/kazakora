<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ContagemAvaliacoesCanais;
use Illuminate\Console\Command;

/**
 * Lê quantas avaliações reais cada anúncio vinculado tem na Shopee e no
 * Mercado Livre (só leitura nas APIs) para a tela Avaliações › Cadastro e
 * controle (pedido do Lira 2026-10-18).
 */
class ContarAvaliacoesCanais extends Command
{
    protected $signature = 'avaliacoes:contar-canais';

    protected $description = 'Conta as avaliações reais dos anúncios da Shopee e do Mercado Livre';

    public function handle(ContagemAvaliacoesCanais $contagem): int
    {
        $r = $contagem->atualizar();

        $this->info("Shopee: {$r['shopee']} anúncios lidos. Mercado Livre: {$r['mercado_livre']}. Erros: {$r['erros']}.");

        return self::SUCCESS;
    }
}
