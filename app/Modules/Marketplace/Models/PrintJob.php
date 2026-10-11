<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    /**
     * De onde veio a impressão — ver a migration que criou a coluna. Existe
     * pra "quem imprimiu isso duas vezes?" ser consulta, não escavação.
     */
    public const ORIGEM_AUTOMATICA = 'automatico';

    public const ORIGEM_LOTE = 'lote';

    public const ORIGEM_REIMPRESSAO = 'reimpressao';

    public const ORIGEM_MANUAL = 'manual';

    public const ORIGEM_TESTE = 'teste';

    protected $fillable = [
        'origin',
        'order_id',
        'channel',
        'tracking_code',
        'is_thank_you',
        'label_path',
        'raw_label_path',
        'status',
        'claimed_by',
        'claimed_at',
        'printed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
            'is_thank_you' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
