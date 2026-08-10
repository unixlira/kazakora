<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Saldo disponível pra saque, um valor por canal — ver migration pro porquê
 * de existir uma tabela pra isso em vez de consultar ao vivo (Mercado Pago
 * não tem "saldo agora", só relatório assíncrono de minutos).
 */
class ChannelWalletBalance extends Model
{
    protected $fillable = [
        'channel',
        'balance',
        'balance_as_of',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'balance_as_of' => 'datetime',
        ];
    }
}
