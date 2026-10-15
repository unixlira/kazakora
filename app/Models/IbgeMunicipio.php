<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Município do IBGE. id = código de 7 dígitos usado na NF-e (cMun);
 * nome_normalizado = sem acento, minúsculo, para casar o nome digitado.
 */
class IbgeMunicipio extends Model
{
    protected $table = 'ibge_municipios';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'nome', 'nome_normalizado', 'estado_id', 'uf'];

    public static function normalizar(string $nome): string
    {
        return Str::of($nome)->ascii()->lower()->trim()->toString();
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(IbgeEstado::class, 'estado_id');
    }
}
