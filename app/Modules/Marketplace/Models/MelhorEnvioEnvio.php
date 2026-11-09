<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Etiqueta do Melhor Envio de um pedido do site (ver MelhorEnvioAutoShipping). */
class MelhorEnvioEnvio extends Model
{
    /** Transportadora escolhida no pedido, etiqueta ainda não comprada. */
    public const STATUS_COTADA = 'cotada';

    public const STATUS_CARRINHO = 'carrinho';

    public const STATUS_PAGA = 'paga';

    public const STATUS_GERADA = 'gerada';

    public const STATUS_ERRO = 'erro';

    protected $table = 'melhor_envio_envios';

    protected $fillable = [
        'order_id', 'status', 'me_order_id', 'protocolo', 'servico_id', 'servico', 'preco', 'rastreio',
        'peso_g', 'volume', 'erro', 'resposta', 'paga_em', 'gerada_em',
        'situacao', 'prazo_dias', 'postada_em', 'entregue_em', 'cancelada_em', 'postagem',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'volume' => 'array',
            'resposta' => 'array',
            'paga_em' => 'datetime',
            'gerada_em' => 'datetime',
            'postada_em' => 'datetime',
            'entregue_em' => 'datetime',
            'cancelada_em' => 'datetime',
            'postagem' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
