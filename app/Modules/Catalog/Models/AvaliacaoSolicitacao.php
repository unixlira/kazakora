<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um e-mail "Avalie sua compra" por pedido do site entregue (pedido do Lira
 * 2026-10-18). A linha única por pedido é a trava contra envio em dobro.
 * Ver SolicitacaoAvaliacao e o comando avaliacoes:solicitar.
 */
class AvaliacaoSolicitacao extends Model
{
    public const STATUS_ENVIANDO = 'enviando';

    public const STATUS_ENVIADO = 'enviado';

    public const STATUS_FALHOU = 'falhou';

    /** Pedido sem e-mail, sem conta, fora das promoções ou já avaliado: não envia, só registra o motivo. */
    public const STATUS_PULADO = 'pulado';

    /** Falha de envio é tentada de novo na rodada seguinte até este limite. */
    public const MAX_TENTATIVAS = 3;

    protected $table = 'avaliacao_solicitacoes';

    protected $fillable = ['order_id', 'user_id', 'email', 'coupon_id', 'status', 'tentativas', 'erro', 'enviado_em', 'avaliado_em'];

    protected function casts(): array
    {
        return [
            'enviado_em' => 'datetime',
            'avaliado_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
