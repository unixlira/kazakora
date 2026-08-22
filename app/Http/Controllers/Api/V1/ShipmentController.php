<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;

/**
 * API pública, pedido explícito 2026-08-21. label() é servido a partir de
 * uma URL ASSINADA (ver ChannelShipmentResource::label_url e a rota
 * 'signed'), não do token de API — separa "posse de um link válido por 30
 * min" de "posse do token do parceiro", que é o padrão real de mercado pra
 * download de arquivo (o link pode ser repassado pro transportador/sistema
 * de impressão sem entregar a credencial completa da API).
 */
class ShipmentController extends Controller
{
    public function label(Order $order): HttpResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        abort_unless($shipment?->label_path && Storage::disk('local')->exists($shipment->label_path), 404, 'Etiqueta ainda não foi baixada pra este pedido.');

        return response(Storage::disk('local')->get($shipment->label_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"etiqueta-pedido-{$order->id}.pdf\"",
        ]);
    }
}
