<?php

namespace App\Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma coleta de saldo e repasses de um marketplace (ver
 * SaldosCanais e a migration create_saldos_canais_table).
 */
class SaldoCanal extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_PARCIAL = 'parcial';

    public const STATUS_ERRO = 'erro';

    protected $table = 'saldos_canais';

    protected $fillable = [
        'empresa',
        'canal',
        'coletado_em',
        'status',
        'disponivel',
        'a_receber',
        'repasses',
        'detalhe',
        'erro',
    ];

    protected function casts(): array
    {
        return [
            'coletado_em' => 'datetime',
            'disponivel' => 'decimal:2',
            'a_receber' => 'decimal:2',
            'repasses' => 'array',
            'detalhe' => 'array',
        ];
    }
}
