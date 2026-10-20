<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Alerta do painel do admin (ver AlertasAdmin). */
class AlertaAdmin extends Model
{
    protected $table = 'admin_alertas';

    protected $fillable = ['tipo', 'chave', 'titulo', 'mensagem', 'link', 'ocorrencias', 'primeira_em', 'ultima_em', 'resolvido_em', 'resolvido_por'];

    protected function casts(): array
    {
        return [
            'ocorrencias' => 'integer',
            'primeira_em' => 'datetime',
            'ultima_em' => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function scopeAbertos(Builder $query): Builder
    {
        return $query->whereNull('resolvido_em');
    }

    /** @return array<string, mixed> */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'mensagem' => $this->mensagem,
            'link' => $this->link,
            'ocorrencias' => $this->ocorrencias,
            'primeiraEm' => $this->primeira_em?->format('d/m H:i'),
            'ultimaEm' => $this->ultima_em?->format('d/m H:i'),
            'resolvidoEm' => $this->resolvido_em?->format('d/m H:i'),
            'resolvidoPor' => $this->resolvido_por,
        ];
    }
}
