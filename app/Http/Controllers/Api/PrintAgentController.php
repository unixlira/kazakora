<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Models\PrintJob;
use App\Notifications\PrintJobFailedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Fala com o agente local de impressão (programa fora deste servidor,
 * instalado num PC/Mac fisicamente na loja, com a impressora USB). Nunca é
 * chamado por um navegador — autenticado por token fixo (ver
 * AuthenticatePrintAgent), não por sessão.
 *
 * Fluxo do agente: polling em GET /jobs → POST /jobs/{id}/claim → baixa o
 * PDF em GET /jobs/{id}/label → manda pro spooler do SO local → reporta o
 * resultado em POST /jobs/{id}/complete.
 */
class PrintAgentController extends Controller
{
    public function index(): JsonResponse
    {
        // sale_id = orders.external_order_id (id de venda do próprio canal,
        // ex.: order_sn da Shopee) — pedido explícito 2026-08-09: nome do
        // arquivo arquivado localmente usa tracking_code quando existir,
        // caindo pra sale_id (nunca nulo pra pedido de canal) em vez do
        // feio "pedido-{id interno}" antigo. É por isso que precisa vir
        // aqui, não só order_id.
        $jobs = PrintJob::query()
            ->where('status', PrintJob::STATUS_QUEUED)
            ->with('order:id,external_order_id')
            ->oldest()
            ->get(['id', 'order_id', 'channel', 'tracking_code', 'created_at'])
            ->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'order_id' => $job->order_id,
                'channel' => $job->channel,
                'tracking_code' => $job->tracking_code,
                'sale_id' => $job->order?->external_order_id,
                'created_at' => $job->created_at,
            ]);

        return response()->json(['jobs' => $jobs]);
    }

    public function claim(Request $request, PrintJob $printJob): JsonResponse
    {
        $validated = $request->validate(['agent_id' => ['required', 'string', 'max:255']]);

        if ($printJob->status !== PrintJob::STATUS_QUEUED) {
            return response()->json(['message' => 'Job já foi reivindicado por outro agente.'], 409);
        }

        $printJob->update([
            'status' => PrintJob::STATUS_CLAIMED,
            'claimed_by' => $validated['agent_id'],
            'claimed_at' => now(),
        ]);

        return response()->json(['job' => $printJob]);
    }

    public function label(PrintJob $printJob): HttpResponse
    {
        abort_unless($printJob->status === PrintJob::STATUS_CLAIMED, 409, 'Job precisa ser reivindicado antes de baixar a etiqueta.');
        abort_unless(Storage::disk('local')->exists($printJob->label_path), 404, 'Arquivo da etiqueta não encontrado.');

        return response(Storage::disk('local')->get($printJob->label_path), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Arquivo bruto exatamente como o canal devolveu (zip da Shopee, pdf do
     * Mercado Livre) — o KoraSync baixa isso separado do /label (que já
     * vem convertido/pronto pra imprimir) só pra guardar uma cópia local em
     * disco (pasta de Vendas), sem afetar o fluxo de impressão em si. Não
     * existe pra etiqueta manual (sem raw_label_path) nem pra pedidos
     * antigos anteriores a essa feature — 404 nesses casos é esperado, o
     * agente só pula o arquivamento.
     */
    public function archive(PrintJob $printJob): HttpResponse
    {
        abort_unless($printJob->status === PrintJob::STATUS_CLAIMED, 409, 'Job precisa ser reivindicado antes de baixar o arquivo bruto.');
        abort_unless($printJob->raw_label_path && Storage::disk('local')->exists($printJob->raw_label_path), 404, 'Arquivo bruto da etiqueta não encontrado.');

        return response(Storage::disk('local')->get($printJob->raw_label_path), 200, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function complete(Request $request, PrintJob $printJob, OrderFulfillmentTimeline $timeline): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([PrintJob::STATUS_PRINTED, PrintJob::STATUS_FAILED])],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $printJob->update([
            'status' => $validated['status'],
            'printed_at' => $validated['status'] === PrintJob::STATUS_PRINTED ? now() : null,
            'error_message' => $validated['error_message'] ?? null,
        ]);

        if ($printJob->order) {
            $timeline->record(
                $printJob->order,
                OrderFulfillmentEvent::STEP_LABEL_PRINTED,
                $validated['status'] === PrintJob::STATUS_PRINTED ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
                $validated['error_message'] ?? 'Etiqueta impressa',
            );
        }

        if ($validated['status'] === PrintJob::STATUS_FAILED) {
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new PrintJobFailedNotification($printJob, $validated['error_message'] ?? 'Motivo não informado'));
            }
        }

        return response()->json(['job' => $printJob]);
    }
}
