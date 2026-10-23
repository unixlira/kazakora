<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\NovoPixDoPedido;
use Illuminate\Console\Command;
use Throwable;

/**
 * Novo Pix (24 h por padrão) para pedido do site cancelado por Pix vencido
 * ou ainda aguardando pagamento (pedido do Lira 2026-10-23). Reabre o mesmo
 * pedido, reserva o estoque e imprime o link do rastreio, onde o cliente vê
 * o QR Code e o copia e cola sem login. Ver NovoPixDoPedido.
 */
class NovoPixDoPedidoCommand extends Command
{
    protected $signature = 'pedidos:novo-pix
        {pedido* : id do pedido ou número da loja (ex.: 261023P6RK58W7)}
        {--horas=24 : validade do Pix novo, em horas}
        {--dry-run : só confere e mostra o que faria, sem gravar nem criar Pix}';

    protected $description = 'Reabre pedido do site com Pix vencido e gera um Pix novo de N horas, com link do rastreio para o cliente pagar';

    public function handle(NovoPixDoPedido $novoPix): int
    {
        $horas = (int) $this->option('horas');

        if ($horas < 1 || $horas > 720) {
            $this->error('--horas precisa ficar entre 1 e 720.');

            return self::INVALID;
        }

        $simulacao = (bool) $this->option('dry-run');
        $falhas = 0;

        if ($simulacao) {
            $this->warn('Simulação (--dry-run): nada é gravado e nenhum Pix é criado.');
        }

        foreach ($this->argument('pedido') as $chave) {
            $order = $this->localizar((string) $chave);

            if (! $order) {
                $this->error("Pedido {$chave}: não encontrado.");
                $falhas++;

                continue;
            }

            $order->load(['payments', 'items', 'user']);
            $rotulo = "Pedido #{$order->id} ({$order->numero})";

            if ($aberto = NovoPixDoPedido::pixAberto($order)) {
                $this->info("{$rotulo}: já tem Pix válido, nada a gerar.");
                $this->resumo($order, (float) $aberto->amount, $aberto->expira_em);

                continue;
            }

            if ($motivo = $novoPix->recusa($order)) {
                $this->error("{$rotulo}: recusado, {$motivo}.");
                $falhas++;

                continue;
            }

            if ($falta = $novoPix->faltaEstoque($order)) {
                $this->error("{$rotulo}: sem estoque para reabrir. ".implode('; ', $falta).'.');
                $falhas++;

                continue;
            }

            if ($simulacao) {
                $this->info("{$rotulo}: pode reabrir (estoque ok). Geraria Pix de {$horas} h.");
                $this->resumo($order, (float) $order->total, now()->addHours($horas));

                continue;
            }

            try {
                $payment = $novoPix->gerar($order, $horas);
            } catch (Throwable $exception) {
                $this->error("{$rotulo}: não gerado, {$exception->getMessage()}.");
                $falhas++;

                continue;
            }

            $this->info("{$rotulo}: reaberto, estoque reservado e Pix novo gerado.");
            $this->resumo($order->fresh(), (float) $payment->amount, $payment->expira_em);
        }

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function localizar(string $chave): ?Order
    {
        $chave = strtoupper(trim(str_replace('#', '', $chave)));

        return Order::query()->where('external_order_id', $chave)->first()
            ?? (ctype_digit($chave) ? Order::query()->find((int) $chave) : null);
    }

    private function resumo(Order $order, float $valor, $vencimento): void
    {
        $ref = $order->trackingRef();

        $this->line('  Número: '.($order->numero ?? $order->id));
        $this->line('  Cliente: '.($order->shipping_name ?: $order->user?->name ?: '—'));
        $this->line('  Valor: R$ '.number_format($valor, 2, ',', '.'));
        $this->line('  Vencimento: '.($vencimento ? $vencimento->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i').' (horário de Brasília)' : '—'));
        $this->line('  Ref do rastreio: '.$ref);
        $this->line('  Link: '.route('rastreio.ver', $ref));
    }
}
