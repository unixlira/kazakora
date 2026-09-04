<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Porteiro do clique de "separado/embalado" do KoraSync (Fase 3,
 * 2026-09-04).
 *
 * Antes disso o clique era cego: gravava packed_at e pronto. Um pedido
 * cancelado no marketplace DEPOIS de já ter aparecido na fila só saía da
 * tela no poll seguinte, se um webhook de cancelamento tivesse chegado —
 * e o relato real de 2026-08-31 ("vendas do Mercado Livre estão canceladas
 * algumas horas depois... isso foi um erro que eu poderia ter enviado os
 * produtos") mostra que essa janela é grande o suficiente pra despachar
 * mercadoria de venda cancelada.
 *
 * Agora, no momento do clique, o pedido é reconsultado no canal antes de
 * liberar a separação — é o único instante em que a informação
 * REALMENTE importa, porque é quando o produto sai da prateleira.
 */
class SeparationGateService
{
    public const RESULT_OK = 'ok';

    public const RESULT_CANCELLED = 'cancelled';

    /**
     * Mensagem pedida no briefing da Fase 3 — o texto importa: o operador
     * já tem o produto na mão quando lê isso, então precisa saber o que
     * fazer com ele, não só que deu errado.
     */
    public const CANCELLED_MESSAGE = 'O pedido foi cancelado no marketplace. Este item não deve ser enviado. Separe o produto para reaproveitamento em outro pedido do mesmo SKU.';

    /**
     * Canais em que vale reconsultar o pedido antes de liberar. TikTok Shop
     * fica FORA de propósito: ele entra pela ponte do Bling e o
     * TikTokShopDriver ainda é stub (não tem importOrder de verdade pra
     * consultar), e a etiqueta dele continua saindo pelo painel do TikTok —
     * lá o clique é só "separei", sem validação e sem etiqueta, exatamente
     * como o briefing pede.
     */
    private const VALIDATED_CHANNELS = [
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
    ];

    public function __construct(
        private readonly OrderImportService $importer,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    /**
     * @return array{result: string, message: ?string, checked: bool}
     */
    public function validate(Order $order): array
    {
        if ($order->status === Order::STATUS_CANCELLED) {
            return $this->cancelled($order, 'já estava cancelado no Kazakora');
        }

        if (! in_array($order->origin, self::VALIDATED_CHANNELS, true) || ! $order->external_order_id) {
            return ['result' => self::RESULT_OK, 'message' => null, 'checked' => false];
        }

        try {
            $this->importer->import($order->origin, (string) $order->external_order_id);
        } catch (Throwable $exception) {
            // Falha-aberto de propósito: se a API do canal está fora do ar,
            // travar o clique para o galpão inteiro é pior que o risco que
            // isso cobre — o pedido continua com o aviso de cancelado no
            // card assim que um webhook chegar, que é a defesa que já
            // existia antes desta classe. O operador é avisado de que a
            // conferência não aconteceu, em vez de achar que aconteceu.
            Log::warning('separation.channel_check_failed', [
                'order_id' => $order->id,
                'channel' => $order->origin,
                'message' => $exception->getMessage(),
            ]);

            return [
                'result' => self::RESULT_OK,
                'message' => 'Não deu pra confirmar o status no canal agora — separado assim mesmo. Confira o pedido antes de despachar.',
                'checked' => false,
            ];
        }

        if ($order->refresh()->status === Order::STATUS_CANCELLED) {
            return $this->cancelled($order, 'cancelamento detectado na conferência do clique');
        }

        return ['result' => self::RESULT_OK, 'message' => null, 'checked' => true];
    }

    /**
     * @return array{result: string, message: string, checked: bool}
     */
    private function cancelled(Order $order, string $motivo): array
    {
        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_ORDER_PACKED,
            OrderFulfillmentEvent::STATUS_FAILED,
            "Separação bloqueada no KoraSync — {$motivo}. Produto deve voltar pra prateleira.",
        );

        return [
            'result' => self::RESULT_CANCELLED,
            'message' => self::CANCELLED_MESSAGE,
            'checked' => true,
        ];
    }
}
