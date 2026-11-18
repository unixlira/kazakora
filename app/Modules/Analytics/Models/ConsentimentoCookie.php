<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Prova da escolha no aviso de cookies (LGPD art. 8º, § 2º), guardada por 5
 * anos: "aceito" (consentimento completo) ou, desde 2026-11-18, "basico"
 * (recusou o que não é necessário). `aceito_em` é a data/hora da escolha.
 */
class ConsentimentoCookie extends Model
{
    public $timestamps = false;

    public const ORIGEM_KAZAKORA = 'kazakora';

    public const ORIGEM_BEAUTYKORA = 'beautykora';

    public const ORIGEM_FERRAKORA = 'ferrakora';

    public const ORIGENS = [self::ORIGEM_KAZAKORA, self::ORIGEM_BEAUTYKORA, self::ORIGEM_FERRAKORA];

    public const ESCOLHA_ACEITO = 'aceito';

    public const ESCOLHA_BASICO = 'basico';

    public const ESCOLHAS = [self::ESCOLHA_ACEITO, self::ESCOLHA_BASICO];

    protected $table = 'consentimentos_cookies';

    protected $fillable = ['visitante_id', 'user_id', 'origem', 'versao', 'escolha', 'ip', 'user_agent', 'aceito_em'];

    protected function casts(): array
    {
        return ['aceito_em' => 'datetime'];
    }
}
