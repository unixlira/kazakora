<?php

namespace App\Notifications\Cliente;

use Illuminate\Notifications\Notification;

/**
 * Conta criada na finalização sem login (pedido 2026-10-11): avisa no sino
 * que a senha temporária foi para o e-mail e leva a trocar por uma senha
 * pessoal.
 */
class SenhaTemporariaEnviada extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Enviamos uma senha temporária para o seu e-mail',
            'body' => "Criamos a sua conta na KazaKora com o e-mail {$notifiable->email}. A senha temporária está na sua caixa de entrada (confira também o spam). Por segurança, troque por uma senha só sua.",
            'link' => '/perfil',
            'acao' => 'Criar minha senha',
            'icone' => 'fa-key',
        ];
    }
}
