<?php

namespace App\Modules\Admin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de disparos de notificação promocional pra clientes do site
 * (cupom, promoção, etc.) — pedido explícito 2026-08-06 ("dispara
 * notificação para os usuários do site... não aparece para admins").
 * Não é a mesma coisa que a notificação em si (App\Notifications\PromotionalNotification,
 * que vai pra tabela polimórfica `notifications` de cada cliente) — esse
 * model é só o registro "essa campanha foi enviada, por quem, pra quantos",
 * pra dar histórico na tela do admin.
 */
class PromotionalNotificationCampaign extends Model
{
    protected $fillable = [
        'title',
        'message',
        'link',
        'created_by',
        'recipients_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
