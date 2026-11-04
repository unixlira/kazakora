<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\EmEsperaTikTok;
use Illuminate\Console\Command;

/**
 * Importa um JSON da tela Finanças › Em espera do TikTok (resposta de
 * onhold_orders, {"pedidos": [...]} ou a lista de pedidos). O caminho normal
 * é o agente do TikTok pela API (/api/tiktok/em-espera); isto serve para
 * carga à mão e teste (pedido do Lira 04/11/2026).
 */
class ImportarEmEsperaTikTok extends Command
{
    protected $signature = 'tiktok:em-espera-importar {arquivo : JSON com os pedidos da tela Em espera do seller-br}';

    protected $description = 'Grava nos pedidos do TikTok ainda não liquidados os valores previstos pelo TikTok (Em espera)';

    public function handle(EmEsperaTikTok $emEspera): int
    {
        $conteudo = @file_get_contents((string) $this->argument('arquivo'));
        $dados = is_string($conteudo) ? json_decode($conteudo, true, 512, JSON_BIGINT_AS_STRING) : null;
        $pedidos = $dados['data']['order_records'] ?? $dados['pedidos'] ?? (is_array($dados) && array_is_list($dados) ? $dados : null);

        if (! is_array($pedidos)) {
            $this->error('Arquivo sem pedidos da tela Em espera do TikTok.');

            return self::FAILURE;
        }

        $r = $emEspera->aplicar(array_values($pedidos));
        $this->info("TikTok Em espera: {$r['recebidos']} lido(s), {$r['gravados']} gravado(s), {$r['ja_liquidados']} já no extrato, {$r['taxa_real']} com taxa real, ".count($r['sem_pedido']).' sem pedido no sistema.');
        if ($r['sem_pedido']) {
            $this->line('Sem pedido: '.implode(', ', $r['sem_pedido']));
        }

        return self::SUCCESS;
    }
}
