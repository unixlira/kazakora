<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Estado (UF) do IBGE. id = código IBGE da UF. */
class IbgeEstado extends Model
{
    protected $table = 'ibge_estados';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'sigla', 'nome'];

    public function municipios(): HasMany
    {
        return $this->hasMany(IbgeMunicipio::class, 'estado_id');
    }
}
