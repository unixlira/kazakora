<?php

namespace App\Modules\Materiais\Mail;

use App\Modules\Materiais\Models\LeadMaterial;
use App\Support\MarcaDoEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Link do material gratuito por e-mail, logo depois do cadastro (pedido do
 * Lira 26/11/2026). O PDF já baixou na tela; o e-mail é a cópia para depois.
 */
class MaterialPorEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly LeadMaterial $lead,
        public readonly string $link,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::ferrakora();
        $dados = (array) config("materiais.lista.{$this->lead->material}", []);

        return $this
            ->from((string) config('mail.from.address'), 'FerraKora')
            ->subject('Seu '.($dados['titulo'] ?? 'material').' chegou')
            ->view('emails.ferrakora.material', [
                'marcaEmail' => $marca,
                'lead' => $this->lead,
                'titulo' => $dados['titulo'] ?? 'Material gratuito',
                'link' => $this->link,
                'oferta' => isset($dados['oferta']) ? $marca['site'].$dados['oferta'] : null,
                'ofertaTexto' => $dados['oferta_texto'] ?? 'Ver oferta',
            ]);
    }
}
