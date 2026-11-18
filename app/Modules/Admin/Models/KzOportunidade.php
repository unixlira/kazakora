<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto físico escalando na Biblioteca de Anúncios (Oportunidades ›
 * KazaKora). Vem de `oportunidades-kazakora:importar`; não é editado pela tela.
 */
class KzOportunidade extends Model
{
    protected $table = 'kz_oportunidades';

    protected $guarded = ['id'];

    protected $casts = [
        'ativa' => 'boolean',
        'paises' => 'array',
        'preco' => 'decimal:2',
        'pontuacao' => 'decimal:1',
        'inicio_mais_antigo' => 'date',
        'coletado_em' => 'datetime',
    ];

    public function anuncios(): HasMany
    {
        return $this->hasMany(KzOportunidadeAnuncio::class, 'kz_oportunidade_id');
    }

    public function criativos(): HasMany
    {
        return $this->hasMany(KzCriativo::class, 'kz_oportunidade_id');
    }
}
