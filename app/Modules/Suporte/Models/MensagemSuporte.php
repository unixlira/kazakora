<?php

namespace App\Modules\Suporte\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem do chat de suporte — tudo fica registrado (pedido 2026-10-11). */
class MensagemSuporte extends Model
{
    public const CLIENTE = 'cliente';

    public const MANUELA = 'manuela';

    public const ATENDENTE = 'atendente';

    public const SISTEMA = 'sistema';

    protected $table = 'suporte_mensagens';

    protected $fillable = ['chamado_id', 'autor', 'user_id', 'conteudo'];

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class, 'chamado_id');
    }

    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'autor' => $this->autor,
            'conteudo' => $this->conteudo,
            'enviada_em' => $this->created_at?->toIso8601String(),
        ];
    }
}
