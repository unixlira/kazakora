<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\AcompanhamentoMelhorEnvio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Melhor Envio (cadastrado no app KazaKora em Integrações › Área
 * Dev, 2026-11-09): {"event": "order.posted", "data": {id, status, tracking,
 * posted_at, delivered_at, ...}}. Assinado com X-ME-Signature =
 * HMAC-SHA256 do corpo com o secret do app (base64). Responde rápido (o
 * Melhor Envio desiste em 6 s e tenta de novo a cada 15 min, 5 vezes).
 */
class MelhorEnvioWebhookController extends Controller
{
    public function __invoke(Request $request, AcompanhamentoMelhorEnvio $acompanhamento): JsonResponse
    {
        $corpo = $request->getContent();
        $log = Log::channel(config('melhorenvio.log_channel'));

        // Teste de cadastro sem conteúdo: só confirma que a URL responde.
        if (! filled($request->input('data.id'))) {
            return response()->json(['ok' => true]);
        }

        if (! self::assinaturaValida($corpo, (string) $request->header('X-ME-Signature'))) {
            $log->warning('melhorenvio.webhook_assinatura_invalida', ['event' => $request->input('event'), 'id' => $request->input('data.id')]);

            return response()->json(['ok' => false], 401);
        }

        $envio = MelhorEnvioEnvio::query()->with('order')->where('me_order_id', $request->input('data.id'))->first();
        $log->info('melhorenvio.webhook', ['event' => $request->input('event'), 'id' => $request->input('data.id'), 'order_id' => $envio?->order_id]);

        // Etiqueta que não é de pedido do site (ou já encerrado) não muda nada aqui.
        if (! $envio || $envio->order?->origin !== Order::ORIGIN_STORE
            || ! in_array($envio->order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED], true)) {
            return response()->json(['ok' => true]);
        }

        $resultado = $acompanhamento->refletir($envio, (array) $request->input('data'));

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    public static function assinaturaValida(string $corpo, string $assinatura): bool
    {
        $secret = (string) config('services.melhorenvio.client_secret');
        if ($secret === '' || $assinatura === '') {
            return false;
        }
        $hmac = hash_hmac('sha256', $corpo, $secret, true);

        return hash_equals(base64_encode($hmac), $assinatura) || hash_equals(bin2hex($hmac), strtolower($assinatura));
    }
}
