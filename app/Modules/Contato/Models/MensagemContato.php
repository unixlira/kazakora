<?php

namespace App\Modules\Contato\Models;

use Illuminate\Database\Eloquent\Model;

/** Mensagem que chegou pelo formulário "Fale conosco" (pedido 2026-10-10). */
class MensagemContato extends Model
{
    protected $table = 'mensagens_contato';

    protected $fillable = ['nome', 'email', 'telefone', 'assunto', 'mensagem', 'ip', 'enviado_em'];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }
}
