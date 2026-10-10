<?php

namespace App\Modules\Contato\Models;

use Illuminate\Database\Eloquent\Model;

/** Mensagem que chegou pelo formulário "Fale conosco" (pedido 2026-10-10). */
class MensagemContato extends Model
{
    protected $table = 'mensagens_contato';

    protected $fillable = ['nome', 'email', 'telefone', 'assunto', 'mensagem', 'ip', 'enviado_em', 'lida_em'];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime', 'lida_em' => 'datetime'];
    }

    public function scopeNaoLidas($query)
    {
        return $query->whereNull('lida_em');
    }

    /** Formato da caixa de e-mails do admin e do aviso no topo. */
    public function paraCaixa(): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'assunto' => $this->assunto,
            'mensagem' => $this->mensagem,
            'previa' => \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $this->mensagem), 110),
            'lida' => $this->lida_em !== null,
            'recebida_em' => $this->created_at?->toISOString(),
        ];
    }
}
