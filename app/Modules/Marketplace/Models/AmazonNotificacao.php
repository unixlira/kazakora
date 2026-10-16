<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um aviso da Amazon (ORDER_CHANGE) lido da fila SQS. Ver
 * OuvirNotificacoesAmazon e ProcessarNotificacaoAmazon.
 */
class AmazonNotificacao extends Model
{
    public const STATUS_RECEBIDA = 'recebida';

    public const STATUS_PROCESSADA = 'processada';

    public const STATUS_IGNORADA = 'ignorada';

    public const STATUS_FALHOU = 'falhou';

    protected $table = 'amazon_notificacoes';

    protected $fillable = [
        'notification_id',
        'notification_type',
        'amazon_order_id',
        'order_change_type',
        'order_status',
        'event_time',
        'payload',
        'status',
        'resultado',
        'processada_em',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'event_time' => 'datetime',
            'processada_em' => 'datetime',
        ];
    }

    /**
     * Grava o aviso cru. Devolve null quando a mensagem não é um aviso da
     * SP-API (sem NotificationId) e a notificação já existente quando é
     * repetição — `wasRecentlyCreated` diz se é nova.
     *
     * @param  array<string, mixed>  $mensagem
     */
    public static function registrar(array $mensagem): ?self
    {
        $id = $mensagem['NotificationMetadata']['NotificationId'] ?? null;

        if (! $id) {
            return null;
        }

        $pedido = $mensagem['Payload']['OrderChangeNotification'] ?? [];

        return self::query()->firstOrCreate(
            ['notification_id' => (string) $id],
            [
                'notification_type' => (string) ($mensagem['NotificationType'] ?? 'desconhecido'),
                'amazon_order_id' => $pedido['AmazonOrderId'] ?? null,
                'order_change_type' => $pedido['OrderChangeType'] ?? null,
                'order_status' => $pedido['Summary']['OrderStatus'] ?? null,
                'event_time' => isset($mensagem['EventTime'])
                    ? Carbon::parse($mensagem['EventTime'])->setTimezone(config('app.timezone'))
                    : null,
                'payload' => $mensagem,
                'status' => self::STATUS_RECEBIDA,
            ],
        );
    }

    public function concluir(string $status, string $resultado): void
    {
        $this->update(['status' => $status, 'resultado' => $resultado, 'processada_em' => now()]);
    }
}
