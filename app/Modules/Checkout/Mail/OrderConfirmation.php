<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * Não é ShouldQueue: sempre enviada de dentro de
 * App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob, que já é o job na
 * fila — enviar aqui de novo criaria um segundo job invisível, escondendo o
 * resultado real do try/catch de retry/log do job.
 */
class OrderConfirmation extends Mailable
{
    /** Anexo acima disso some (provedor recusa ou joga no spam); fica só o link. */
    private const LIMITE_ANEXOS_BYTES = 15 * 1024 * 1024;

    /** Livros digitais ganhos no pedido (Promoção PDFs): link de download sempre. */
    public readonly Collection $brindes;

    /** Com a chave de Entregáveis › Livros Digitais ligada, os PDFs vão também anexados. */
    public readonly bool $livrosAnexados;

    public function __construct(public readonly Order $order)
    {
        $this->order->loadMissing('items', 'invoice');
        $livros = LivroDigital::doPedido($this->order);

        // Pedido que não passou pelo checkout com os livros gravados (falha
        // na hora, pedido antigo): grava agora com a regra da página.
        if ($livros->isEmpty()) {
            $livros = LivroDigital::garantirParaPedido($this->order);
        }

        $this->brindes = $livros
            ->filter(fn (LivroDigital $livro) => Storage::disk('local')->exists($livro->arquivo))
            ->values();

        $this->livrosAnexados = $this->brindes->isNotEmpty()
            && LivroDigital::emailPedidoLigado()
            && $this->brindes->sum(fn (LivroDigital $livro) => Storage::disk('local')->size($livro->arquivo)) <= self::LIMITE_ANEXOS_BYTES;
    }

    public function build(): self
    {
        // Pedido do site FerraKora sai com a marca dele (2026-10-15).
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            // Remetente com o nome do site só no FerraKora; KazaKora segue o MAIL_FROM_NAME.
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Pedido {$this->order->numero} confirmado - {$marca['nome']}")
            ->view('emails.order-confirmation', [
                'marcaEmail' => $marca,
                'brindes' => $this->brindes,
                'livrosAnexados' => $this->livrosAnexados,
                'livrosImagem' => $this->brindes->isNotEmpty() ? PromocoesDoProduto::imagemDosLivros($this->brindes) : null,
            ]);
    }

    /**
     * Anexa o DANFE em PDF (e o XML) ao e-mail de confirmação quando a NF-e do pedido
     * já foi emitida (autorizada pela SEFAZ) e o PDF já foi gerado — ver
     * App\Modules\Fiscal\Services\InvoiceService.
     */
    public function attachments(): array
    {
        $invoice = $this->order->invoice;
        $anexos = [];

        if ($invoice?->danfe_path && Storage::disk('local')->exists($invoice->danfe_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $invoice->danfe_path)
                ->as("nfe-pedido-{$this->order->id}.pdf")
                ->withMime('application/pdf');
        }

        // Pedido 2026-10-10: a nota vai também em XML (o arquivo oficial).
        if ($invoice?->xml_path && Storage::disk('local')->exists($invoice->xml_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $invoice->xml_path)
                ->as("nfe-pedido-{$this->order->id}.xml")
                ->withMime('application/xml');
        }

        // Pedido 2026-10-21: com a chave da promoção ligada, os livros de brinde.
        foreach ($this->livrosAnexados ? $this->brindes : [] as $livro) {
            $anexos[] = Attachment::fromStorageDisk('local', $livro->arquivo)
                ->as($livro->nome_arquivo)
                ->withMime('application/pdf');
        }

        return $anexos;
    }
}
