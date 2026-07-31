<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_SENT = 'sent';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ERROR = 'error';

    public const AMBIENTE_PRODUCAO = 'producao';

    public const AMBIENTE_HOMOLOGACAO = 'homologacao';

    protected $fillable = [
        'order_id',
        'status',
        'ambiente',
        'serie',
        'numero',
        'chave_acesso',
        'protocolo_autorizacao',
        'autorizada_em',
        'motivo_rejeicao',
        'xml_path',
        'danfe_path',
        'protocolo_cancelamento',
        'motivo_cancelamento',
        'cancelada_em',
    ];

    protected function casts(): array
    {
        return [
            'serie' => 'integer',
            'numero' => 'integer',
            'autorizada_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
