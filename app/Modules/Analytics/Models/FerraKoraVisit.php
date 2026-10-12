<?php

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Página aberta no site FerraKora (pedido 2026-10-12), gravada por
 * FerraKoraVisitaController. Depois de 6 meses o IP e o navegador são
 * apagados (privacidade:limpar), igual a site_visits.
 */
class FerraKoraVisit extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ferrakora_visits';

    protected $fillable = ['ip', 'path', 'user_agent'];
}
