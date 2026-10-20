<?php

namespace App\Modules\Equipes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Squad de agentes de IA (menu Equipes, pedido do Lira 2026-10-20). A
 * primeira é a Alpha Kora; o submenu lista as squads ativas.
 */
class Squad extends Model
{
    protected $table = 'squads';

    protected $fillable = ['nome', 'slug', 'ativa'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function membros(): HasMany
    {
        return $this->hasMany(SquadMembro::class, 'squad_id')->orderBy('ordem')->orderBy('nome');
    }

    /** Para o submenu Equipes do admin. */
    public static function doMenu(): array
    {
        return static::query()->where('ativa', true)->orderBy('nome')
            ->get(['nome', 'slug'])->map(fn ($s) => ['nome' => $s->nome, 'slug' => $s->slug])->all();
    }
}
