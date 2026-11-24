<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Support\AvisoLivroDigital;
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
    /** Soma dos PDFs anexados acima disso some (provedor recusa ou joga no spam). */
    public const LIMITE_ANEXOS_BYTES = 15 * 1024 * 1024;

    /**
     * Todos os livros digitais ganhos no pedido (Promoção PDFs), cada um com
     * link de download no corpo. Desde 24/11/2026 o livro sem PDF no disco
     * não some mais calado: fica com o link e o Lira é avisado.
     */
    public readonly Collection $brindes;

    /** Os que vão também anexados (chave ligada e até o limite, do menor para o maior). */
    public readonly Collection $anexados;

    /** Algum livro vai anexado (o texto do quadro do presente muda). */
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

        $this->brindes = $livros->values();

        foreach ($this->brindes as $livro) {
            if (! $livro->arquivoExiste()) {
                AvisoLivroDigital::arquivoAusente($livro, $this->order);
            }
        }

        $this->anexados = LivroDigital::emailPedidoLigado() ? self::separarAnexos($this->brindes)['anexos'] : new Collection;
        $this->livrosAnexados = $this->anexados->isNotEmpty();
    }

    /**
     * Quais livros cabem anexados (pedido 2026-11-24): do menor para o maior
     * até LIMITE_ANEXOS_BYTES somados; os que não cabem e os sem PDF no
     * disco ficam só com o link. Antes era tudo ou nada: passou do limite,
     * nenhum ia anexado.
     *
     * @return array{anexos: Collection<int, LivroDigital>, links: Collection<int, LivroDigital>, tamanhos: array<int, int>}
     */
    public static function separarAnexos(Collection $livros): array
    {
        $tamanhos = [];
        foreach ($livros as $livro) {
            $tamanhos[$livro->id] = $livro->arquivoExiste() ? $livro->tamanhoNoDisco() : -1;
        }

        $soma = 0;
        $idsAnexos = [];
        foreach ($livros->sortBy(fn (LivroDigital $livro) => [$tamanhos[$livro->id], $livro->id]) as $livro) {
            $tamanho = $tamanhos[$livro->id];
            if ($tamanho < 0 || $soma + $tamanho > self::LIMITE_ANEXOS_BYTES) {
                continue;
            }
            $soma += $tamanho;
            $idsAnexos[] = $livro->id;
        }

        // Mantém a ordem do pedido (a mesma da lista no corpo).
        return [
            'anexos' => $livros->filter(fn (LivroDigital $livro) => in_array($livro->id, $idsAnexos, true))->values(),
            'links' => $livros->reject(fn (LivroDigital $livro) => in_array($livro->id, $idsAnexos, true))->values(),
            'tamanhos' => $tamanhos,
        ];
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
                'notaAnexada' => $this->notaAnexada(),
                'brindes' => $this->brindes,
                'livrosAnexados' => $this->livrosAnexados,
                'idsAnexados' => $this->anexados->pluck('id')->all(),
            ]);
    }

    /** O PDF da nota existe e vai anexado: só então o aviso aparece no e-mail. */
    public function notaAnexada(): bool
    {
        $invoice = $this->order->invoice;

        return (bool) ($invoice?->danfe_path && Storage::disk('local')->exists($invoice->danfe_path));
    }

    /**
     * Anexa o DANFE em PDF ao e-mail de confirmação quando a NF-e do pedido
     * já foi emitida (autorizada pela SEFAZ) e o PDF já foi gerado (ver
     * App\Modules\Fiscal\Services\InvoiceService).
     */
    public function attachments(): array
    {
        $invoice = $this->order->invoice;
        $anexos = [];

        if ($this->notaAnexada()) {
            $anexos[] = Attachment::fromStorageDisk('local', $invoice->danfe_path)
                ->as("nfe-pedido-{$this->order->id}.pdf")
                ->withMime('application/pdf');
        }

        // Pedido 2026-10-22: só o PDF da nota; o XML não vai mais ao cliente.

        // Pedido 2026-10-21: com a chave da promoção ligada, os livros de
        // brinde que cabem no limite (os outros vão só com o link).
        foreach ($this->anexados as $livro) {
            $anexos[] = Attachment::fromStorageDisk('local', $livro->arquivo)
                ->as($livro->nome_arquivo)
                ->withMime('application/pdf');
        }

        return $anexos;
    }
}
