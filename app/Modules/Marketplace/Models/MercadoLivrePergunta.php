<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MercadoLivrePergunta extends Model
{
    protected $table = 'ml_perguntas';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'precisa_humano' => 'boolean',
            'perguntada_em' => 'datetime',
            'rascunho_em' => 'datetime',
            'respondida_em' => 'datetime',
        ];
    }

    /** Esperando resposta da loja (excluída, banida ou fechada no ML não conta). */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', 'UNANSWERED')->whereNull('respondida_em');
    }

    /** @return array<string, mixed> */
    public function paraCaixa(?string $produto = null): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'item_id' => $this->item_id,
            'produto' => $produto,
            'link_anuncio' => preg_match('/^MLB(\d+)$/', $this->item_id, $m) ? "https://produto.mercadolivre.com.br/MLB-{$m[1]}" : null,
            'status' => $this->status,
            'pergunta' => $this->pergunta,
            'resposta_rascunho' => $this->resposta_rascunho,
            'precisa_humano' => $this->precisa_humano,
            'resposta_enviada' => $this->resposta_enviada,
            'perguntada_em' => $this->perguntada_em?->toIso8601String(),
            'rascunho_em' => $this->rascunho_em?->toIso8601String(),
            'respondida_em' => $this->respondida_em?->toIso8601String(),
        ];
    }
}
