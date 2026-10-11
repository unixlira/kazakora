<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/** Prova do OK no aviso de cookies (LGPD art. 8º, § 2º). Guardada por 5 anos. */
class ConsentimentoCookie extends Model
{
    public $timestamps = false;

    protected $table = 'consentimentos_cookies';

    protected $fillable = ['visitante_id', 'user_id', 'versao', 'ip', 'user_agent', 'aceito_em'];

    protected function casts(): array
    {
        return ['aceito_em' => 'datetime'];
    }
}
