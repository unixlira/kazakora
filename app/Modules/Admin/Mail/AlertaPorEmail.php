<?php

namespace App\Modules\Admin\Mail;

use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;

/**
 * E-mail estilizado de um alerta do admin (pedido do Lira 25/11/2026: "não gosto de e-mail de texto
 * puro"), com o logo da KazaKora, o título em destaque, o texto e o botão para abrir o link do alerta.
 */
class AlertaPorEmail extends Mailable
{
    public function __construct(
        public readonly string $titulo,
        public readonly string $mensagem,
        public readonly ?string $link = null,
        public readonly string $tipoNome = 'Alerta',
    ) {
    }

    public function build(): self
    {
        $tom = match (true) {
            str_starts_with($this->titulo, 'Desligar') => ['#b91c1c', '#fef2f2', '⛔'],
            str_starts_with($this->titulo, 'Subir') => ['#15803d', '#f0fdf4', '📈'],
            str_starts_with($this->titulo, 'Fraco') => ['#b45309', '#fffbeb', '⚠️'],
            default => ['#1b3a5c', '#f1f5f9', '🔔'],
        };

        return $this->subject('[KazaKora] '.$this->titulo)
            ->view('emails.alerta-admin', ['marca' => MarcaDoEmail::kazakora(), 'cor' => $tom[0], 'fundo' => $tom[1], 'icone' => $tom[2]]);
    }
}
