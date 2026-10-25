<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contato de quem chegou na Entrega do checkout (pedido 2026-10-24,
 * recuperação de carrinho abandonado). Gravado por
 * App\Modules\Checkout\Support\LeadsDoCheckout; nada aqui manda mensagem
 * para o cliente. Dado pessoal: nome, e-mail, CPF e telefone ficam fora
 * do toArray() de log/JSON público (só a tela do admin lê).
 */
class LeadCheckout extends Model
{
    public const STATUS_ABERTO = 'aberto';

    public const STATUS_CONVERTIDO = 'convertido';

    public const ETAPA_ENTREGA = 'entrega';

    public const ETAPA_PAGAMENTO = 'pagamento';

    protected $table = 'lead_checkout';

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
        'itens',
        'quantidade_itens',
        'subtotal',
        'status',
        'etapa',
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
