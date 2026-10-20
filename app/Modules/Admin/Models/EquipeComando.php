<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comando extra de um membro da Equipe (config/equipe.php), escrito pelo
 * Lira na tela Equipe › membro. Só os ativos chegam ao agente.
 */
class EquipeComando extends Model
{
    protected $table = 'equipe_comandos';

    protected $fillable = ['membro', 'texto', 'ativo', 'user_id'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /** @return array<string, array<string, mixed>> */
    public static function membros(): array
    {
        return config('equipe.membros', []);
    }

    public static function membroExiste(string $slug): bool
    {
        return isset(self::membros()[$slug]);
    }

    /**
     * Textos ativos de um membro, do mais antigo para o mais novo.
     *
     * @return list<string>
     */
    public static function textosAtivos(string $membro): array
    {
        return self::query()->ativos()->where('membro', $membro)->orderBy('id')->pluck('texto')->all();
    }

    /** @return array<string, mixed> */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'texto' => $this->texto,
            'ativo' => $this->ativo,
            'autor' => $this->user?->name ?? 'Sistema',
            'criadoEm' => $this->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'alteradoEm' => $this->updated_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
        ];
    }
}
