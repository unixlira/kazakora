<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * API pública, pedido explícito 2026-08-21 — mesmo caminho assíncrono do
 * painel admin (GenerateInvoiceJob, ver Admin\InvoiceController::issue()):
 * emissão de NF-e sempre passa pela fila dedicada (certificado/SEFAZ podem
 * ser lentos), nunca síncrona dentro do request da API.
 */
class InvoiceController extends Controller
{
    public function show(Order $order): InvoiceResource
    {
        $order->loadMissing('invoice');

        abort_if(! $order->invoice, 404, 'Este pedido ainda não tem nota fiscal.');

        return new InvoiceResource($order->invoice);
    }

    public function store(Order $order): JsonResponse
    {
        $order->loadMissing('invoice');

        if ($order->invoice?->status === Invoice::STATUS_AUTHORIZED) {
            return response()->json(['message' => 'Este pedido já tem uma nota fiscal autorizada.'], 422);
        }

        if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT], true)) {
            return response()->json(['message' => 'O pedido ainda não foi pago — não é possível emitir a nota fiscal.'], 422);
        }

        GenerateInvoiceJob::dispatch($order->id);

        return response()->json(['message' => 'Emissão da nota fiscal agendada.'], 202);
    }

    /**
     * Mesmo padrão de link assinado temporário do label() de etiqueta (ver
     * ShipmentController) — nunca expõe o caminho de disco, expira sozinho.
     */
    public function danfeUrl(Order $order): JsonResponse
    {
        $order->loadMissing('invoice');

        abort_unless($order->invoice?->danfe_path && Storage::disk('local')->exists($order->invoice->danfe_path), 404, 'DANFE não encontrado — nota ainda não autorizada ou PDF não gerado.');

        return response()->json([
            'url' => URL::temporarySignedRoute('api.v1.pedidos.nota.danfe', now()->addMinutes(30), ['order' => $order->id]),
        ]);
    }

    public function danfe(Order $order): HttpResponse
    {
        $order->loadMissing('invoice');

        abort_unless($order->invoice?->danfe_path && Storage::disk('local')->exists($order->invoice->danfe_path), 404, 'DANFE não encontrado.');

        return response(Storage::disk('local')->get($order->invoice->danfe_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"danfe-pedido-{$order->id}.pdf\"",
        ]);
    }
}
