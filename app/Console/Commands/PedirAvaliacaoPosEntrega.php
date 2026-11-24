<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Support\PedidoDeAvaliacao;
use App\Modules\Checkout\Models\Order;
use Illuminate\Console\Command;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-11-23), de hora
 * em hora das 9h às 20h: pedido do site (KazaKora e FerraKora) entregue há 5
 * dias ou mais, sem cancelamento, devolução ou "não recebi", recebe uma vez o
 * link assinado para avaliar os produtos, por e-mail e, com modelo aprovado no
 * .env, por WhatsApp. Desligado fora do kazakora.com (PEDIDO_AVALIACAO_ATIVO).
 *
 * --simular só lista o que mandaria.
 */
class PedirAvaliacaoPosEntrega extends Command
{
    protected $signature = 'loja:pedir-avaliacao
        {--simular : Só lista o que mandaria, sem mandar nem gravar}
        {--limite= : Máximo de pedidos nesta rodada (padrão: config loja.pedido_avaliacao.limite)}';

    protected $description = 'Manda o link de avaliação aos pedidos do site entregues há 5 dias ou mais (e-mail e WhatsApp)';

    public function handle(PedidoDeAvaliacao $servico): int
    {
        $limite = max(1, (int) ($this->option('limite') ?: config('loja.pedido_avaliacao.limite', 50)));
        $pedidos = PedidoDeAvaliacao::naVez()->with(['user', 'ferrakoraCliente'])->limit($limite)->get();

        if ($this->option('simular')) {
            $this->table(['pedido', 'loja', 'entregue em', 'canais'], $pedidos->map(fn (Order $o) => [
                $o->numero,
                PedidoDeAvaliacao::ferrakora($o) ? 'FerraKora' : 'KazaKora',
                $o->entregue_em?->format('d/m/Y H:i'),
                implode(' + ', $servico->canais($o)) ?: 'nenhum',
            ])->all());
            $this->comment("Simulação: mandaria para {$pedidos->count()} pedido(s), nada foi enviado.");

            return self::SUCCESS;
        }

        if (! config('loja.pedido_avaliacao.ativo')) {
            $this->warn("Envio desligado (PEDIDO_AVALIACAO_ATIVO; só liga no kazakora.com): {$pedidos->count()} pedido(s) na vez, nada foi enviado.");

            return self::SUCCESS;
        }

        $resultado = ['enviado' => 0, 'ja_enviado' => 0, 'sem_canal' => 0, 'falhou' => 0];
        foreach ($pedidos as $order) {
            $resultado[$servico->enviar($order)]++;
        }

        $this->info("Pedidos de avaliação: {$resultado['enviado']} enviados, {$resultado['falhou']} com falha, {$resultado['sem_canal']} sem e-mail nem WhatsApp.");

        return self::SUCCESS;
    }
}
