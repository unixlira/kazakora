<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\LivroDownloadToken;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reenvia o e-mail de confirmação de um pedido (Lira, 24/11/2026: o pedido de
 * teste chegou com menos livros digitais do que a página prometia). Antes
 * completa os livros do pedido pela regra atual da página
 * (garantirParaPedido: só acrescenta, nunca tira) e manda o OrderConfirmation
 * de novo, com os livros que cabem anexados e o link de todos.
 */
class ReenviarConfirmacaoPedido extends Command
{
    protected $signature = 'pedido:reenviar-confirmacao
        {pedido : id do pedido ou número (ex.: 2611249ABCDEF)}
        {--para= : manda para este e-mail em vez do e-mail do pedido}
        {--dry-run : só mostra o que faria (livros, anexos e links), sem gravar nem enviar}
        {--forcar : envia mesmo com o pedido sem pagamento confirmado}';

    protected $description = 'Completa os livros digitais do pedido e reenvia o e-mail de confirmação';

    public function handle(): int
    {
        $arg = trim((string) $this->argument('pedido'));
        $order = Order::query()->where('external_order_id', $arg)->first()
            ?? (ctype_digit($arg) ? Order::query()->find((int) $arg) : null);

        if (! $order) {
            $this->error("Pedido {$arg} não encontrado (procurei pelo número e pelo id).");

            return self::FAILURE;
        }

        $order->loadMissing('user', 'invoice');
        $para = (string) ($this->option('para') ?: ($order->user?->email ?: $order->shipping_email));
        $seco = (bool) $this->option('dry-run');

        $this->line("Pedido #{$order->id} (número {$order->numero}), status {$order->status}, para: ".($para ?: '(sem e-mail)'));

        if ($para === '') {
            $this->error('O pedido não tem e-mail. Use --para=.');

            return self::FAILURE;
        }

        if (! LivroDigital::pedidoLiberado($order) && ! $this->option('forcar')) {
            $this->error('Pedido sem pagamento confirmado: o e-mail diz "recebemos seu pagamento". Use --forcar se quiser mesmo assim.');

            return self::FAILURE;
        }

        $gravados = LivroDigital::doPedido($order);
        $daRegra = LivroDigital::idsDaRegra($order);
        $faltando = $daRegra->diff($gravados->pluck('id'))->values();

        if ($seco) {
            $livros = $gravados->concat(LivroDigital::query()->whereIn('id', $faltando)->get())->sortBy('id')->values();
        } else {
            $livros = LivroDigital::garantirParaPedido($order);
        }

        $this->mostrarLivros($order, $livros, $faltando->all(), $seco);

        if ($seco) {
            $this->info('Dry-run: nada gravado nem enviado.');

            return self::SUCCESS;
        }

        $invoiceAttached = $order->invoice?->status === Invoice::STATUS_AUTHORIZED
            && $order->invoice->danfe_path
            && Storage::disk('local')->exists($order->invoice->danfe_path);

        try {
            Mail::to($para)->send(new OrderConfirmation($order->fresh(['items', 'invoice', 'user'])));
        } catch (Throwable $e) {
            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => 'order_confirmation',
                'attempt' => 1,
                'status' => OrderEmailLog::STATUS_FAILED,
                'invoice_attached' => $invoiceAttached,
                'error_message' => 'Reenvio manual: '.$e->getMessage(),
            ]);
            $this->error("Falhou: {$e->getMessage()}");

            return self::FAILURE;
        }

        OrderEmailLog::create([
            'order_id' => $order->id,
            'mailable' => 'order_confirmation',
            'attempt' => 1,
            'status' => OrderEmailLog::STATUS_SENT,
            'invoice_attached' => $invoiceAttached,
        ]);

        $this->info("E-mail de confirmação reenviado para {$para}.");

        return self::SUCCESS;
    }

    /** @param  list<int>  $novos */
    private function mostrarLivros(Order $order, $livros, array $novos, bool $seco): void
    {
        if ($livros->isEmpty()) {
            $this->warn('O pedido não ganhou livro digital (pedido de marketplace/FerraKora, ou nenhum produto dele mostra livros na página).');

            return;
        }

        $ligado = LivroDigital::emailPedidoLigado();
        $separados = OrderConfirmation::separarAnexos($livros);
        $anexos = $ligado ? $separados['anexos']->pluck('id')->all() : [];

        // Link: o que já existe; sem dry-run, o e-mail usa este mesmo (ou cria o primeiro).
        $link = fn (LivroDigital $livro) => $seco
            ? (LivroDownloadToken::query()->where('order_id', $order->id)->where('livro_digital_id', $livro->id)->whereNull('revogado_em')->latest('id')->first()?->url() ?? '(criado no envio)')
            : $livro->urlDoPedido($order);

        $this->table(['id', 'livro', 'no pedido', 'PDF', 'no e-mail', 'link'], $livros->map(fn (LivroDigital $livro) => [
            $livro->id,
            $livro->titulo,
            in_array($livro->id, $novos, true) ? ($seco ? 'FALTA (seria gravado)' : 'gravado agora') : 'já estava',
            $livro->arquivoExiste() ? number_format($separados['tamanhos'][$livro->id] / 1024 / 1024, 2, ',', '.').' MB' : 'AUSENTE (avisa o Lira)',
            in_array($livro->id, $anexos, true) ? 'anexo + link' : 'só link',
            $link($livro),
        ])->all());

        if (! $ligado) {
            $this->line('Chave "livros anexados no e-mail" desligada: todos vão só com o link.');
        }
    }
}
