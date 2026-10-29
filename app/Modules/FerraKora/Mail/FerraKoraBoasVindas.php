<?php

namespace App\Modules\FerraKora\Mail;

use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;

/**
 * Boas-vindas da conta FerraKora criada no checkout (pedido do Lira
 * 2026-10-29: igual ao da KazaKora, com tudo da FerraKora). Leva a senha
 * temporária; o site pede uma senha pessoal no primeiro acesso.
 */
class FerraKoraBoasVindas extends Mailable
{
    public function __construct(
        public readonly FerraKoraCliente $cliente,
        public readonly string $senhaTemporaria,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::ferrakora();

        return $this
            ->from((string) config('mail.from.address'), 'FerraKora')
            ->subject('Bem-vindo à FerraKora!')
            ->view('emails.ferrakora.boas-vindas', [
                'marcaEmail' => $marca,
                'cliente' => $this->cliente,
                'senhaTemporaria' => $this->senhaTemporaria,
                'entrar' => $marca['site'].'/entrar',
            ]);
    }
}
