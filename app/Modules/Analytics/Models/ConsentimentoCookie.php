<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/** Prova do OK no aviso de cookies (LGPD art. 8º, § 2º). Guardada por 5 anos. */
class ConsentimentoCookie extends Model
{
    public $timestamps = false;

    public const ORIGEM_KAZAKORA = 'kazakora';

    public const ORIGEM_BEAUTYKORA = 'beautykora';

    public const ORIGENS = [self::ORIGEM_KAZAKORA, self::ORIGEM_BEAUTYKORA];

    protected $table = 'consentimentos_cookies';

    protected $fillable = ['visitante_id', 'user_id', 'origem', 'versao', 'ip', 'user_agent', 'aceito_em'];

    protected function casts(): array
    {
        return ['aceito_em' => 'datetime'];
    }
}
