<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leads (pedido do Lira 2026-11-06): o que o cliente digitou no checkout,
 * campo a campo, com o carrinho. Gravado por
 * App\Modules\Checkout\Support\LeadsDigitados; só a tela Admin > Leads lê.
 * Nada aqui manda mensagem para o cliente.
 */
class Lead extends Model
{
    public const STATUS_ABERTO = 'aberto';

    public const STATUS_CONVERTIDO = 'convertido';

    public const CAMPOS = ['nome', 'email', 'cpf', 'telefone', 'cep'];

    protected $table = 'leads';

    protected $fillable = [
        'site',
        'session_id',
        'visitor_id',
        'user_id',
        'order_id',
        'nome',
        'email',
        'cpf',
        'telefone',
        'cep',
        'ultimo_campo',
        'itens',
        'quantidade_itens',
        'subtotal',
        'status',
        'origem',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ip',
        'user_agent',
        'convertido_em',
    ];

    protected $hidden = ['session_id', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'itens' => 'array',
            'quantidade_itens' => 'integer',
            'subtotal' => 'decimal:2',
            'convertido_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
