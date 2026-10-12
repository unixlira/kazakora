<?php

namespace App\Modules\Contato\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mensagem que chegou pelo formulário "Fale conosco" (pedido 2026-10-10).
 * Excluir no admin manda para a lixeira (SoftDeletes, pedido 2026-10-12).
 */
class MensagemContato extends Model
{
    use SoftDeletes;

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

    public function scopeLidas($query)
    {
        return $query->whereNotNull('lida_em');
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
            'excluida_em' => $this->deleted_at?->toISOString(),
        ];
    }
}
