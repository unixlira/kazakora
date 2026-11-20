<?php

namespace App\Modules\Infoprodutos\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Evento anônimo de uma página de infoproduto (sem IP, sem cookie). */
class InfoprodutoEvento extends Model
{
    public const UPDATED_AT = null;

    /** Sessão do robô que revisa os anúncios da Meta (rede 2a03:2880::/32 e afins, 2026-11-20). */
    public const ROBO_META = 'meta-revisao';

    /** Mostrar as sessões de robô (as telas ligam com ?robos=1). */
    public static bool $comRobos = false;

    /**
     * Só visitas de gente: sem as de teste (a não ser que $comTestes) e sem
     * as de robô (a não ser que self::$comRobos).
     */
    public function scopeDePessoas(Builder $query, bool $comTestes = false): Builder
    {
        return $query->when(! $comTestes, fn ($q) => $q->where('teste', false))
            ->when(! self::$comRobos, fn ($q) => $q->whereNull('robo'));
    }

    protected $table = 'infoproduto_eventos';

    protected $guarded = [];

    protected $casts = ['fbclid' => 'boolean', 'teste' => 'boolean', 'created_at' => 'datetime'];
}
