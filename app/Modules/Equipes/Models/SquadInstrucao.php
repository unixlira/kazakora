<?php

namespace App\Modules\Equipes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Instrução extra de um membro: texto que o agente segue em toda tarefa,
 * além das skills dele. Nunca libera ação que precise de OK do Lira.
 * Excluir = soft delete (fica no banco, some da tela e da API).
 */
class SquadInstrucao extends Model
{
    use SoftDeletes;

    protected $table = 'squad_instrucoes';

    protected $fillable = ['membro_id', 'texto', 'ativa', 'criada_por'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function membro(): BelongsTo
    {
        return $this->belongsTo(SquadMembro::class, 'membro_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }
}
