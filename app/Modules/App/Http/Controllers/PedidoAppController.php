<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\App\Services\CheckoutApp;
use App\Modules\App\Support\FormatoApp;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\CancelamentoDoPedido;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Meus pedidos no app (2026-10-18): os mesmos do "Minhas Compras" do site
 * (CheckoutController::myOrders) e o detalhe com as etapas do rastreio
 * (TrackingController). Só pedidos da conta do token; de outra conta = 404.
 */
class PedidoAppController extends Controller
{
    private const POR_PAGINA = 10;

    public function index(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query('pagina', 1));
        $pedidos = $request->user()->orders()
            // Como no site: Pix gerado e nunca pago não é compra; boleto no prazo é.
            ->where(fn ($q) => $q->where('status', '!=', Order::STATUS_AWAITING_PAYMENT)
                ->orWhereHas('payments', fn ($p) => $p
                    ->where('method_type', Payment::METHOD_BOLETO)
                    ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION)))
            ->latest()->latest('id')
            ->skip(($pagina - 1) * self::POR_PAGINA)
            ->take(self::POR_PAGINA + 1)
            ->get();

        return response()->json([
            'pedidos' => $pedidos->take(self::POR_PAGINA)->map(fn (Order $order) => FormatoApp::pedido($order))->values(),
            'pagina' => $pagina,
            'tem_mais' => $pedidos->count() > self::POR_PAGINA,
        ]);
    }

    public function show(Request $request, int $pedido, MercadoPagoPaymentService $mercadoPago): JsonResponse
    {
        $order = $this->doUsuario($request, $pedido);
        if (! $order) {
            return $this->naoEncontrado();
        }

        return response()->json(['pedido' => FormatoApp::pedido($order, true, $this->pixPendente($order, $mercadoPago))]);
    }

    /** Situação do pagamento; aguardando, consulta o Mercado Pago (o app chama a cada 5 s na tela do Pix). */
    public function status(Request $request, int $pedido, CheckoutApp $checkout): JsonResponse
    {
        $order = $this->doUsuario($request, $pedido);
        if (! $order) {
            return $this->naoEncontrado();
        }

        if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
            try {
                $order = $checkout->atualizarStatus($order->load('payments'));
            } catch (MercadoPagoException $e) {
                Log::channel('mercadopago')->warning('mercadopago.app.status_failed', ['order_id' => $order->id, 'message' => $e->getMessage()]);
            }
        }

        return response()->json(FormatoApp::situacao($order));
    }

    /** Cancelar pela própria conta, a mesma regra do botão de Meus pedidos (CancelamentoDoPedido). */
    public function cancelar(Request $request, int $pedido, CancelamentoDoPedido $cancelamento): JsonResponse
    {
        $order = $this->doUsuario($request, $pedido);
        if (! $order) {
            return $this->naoEncontrado();
        }

        $dados = $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'motivo.required' => 'Conte para a gente o motivo do cancelamento.',
            'motivo.min' => 'Conte para a gente o motivo do cancelamento.',
        ]);

        $resultado = $cancelamento->solicitarPeloCliente($order, $request->user(), $dados['motivo']);
        if (! $resultado['ok']) {
            return response()->json(['message' => $resultado['erro'], 'errors' => ['pedido' => [$resultado['erro']]]], 422);
        }

        return response()->json([
            'message' => 'Recebemos o seu pedido de cancelamento e ele já está sendo processado. Assim que terminar, enviamos a confirmação para o seu e-mail.',
            'pedido' => FormatoApp::pedido($order->fresh(), true),
        ]);
    }

    /** DANFE (PDF) da nota autorizada do pedido, como o "Baixar nota" do site. */
    public function nota(Request $request, int $pedido): Response|JsonResponse
    {
        $order = $this->doUsuario($request, $pedido);
        $caminho = $order?->invoice?->status === Invoice::STATUS_AUTHORIZED ? $order->invoice->danfe_path : null;

        if (! $caminho || ! Storage::disk('local')->exists($caminho)) {
            return response()->json(['message' => 'A nota fiscal deste pedido ainda não está disponível.'], 404);
        }

        return response(Storage::disk('local')->get($caminho), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="nota-fiscal-'.$order->numero.'.pdf"',
        ]);
    }

    private function doUsuario(Request $request, int $id): ?Order
    {
        return Order::query()->whereKey($id)->where('user_id', $request->user()->id)->first();
    }

    private function naoEncontrado(): JsonResponse
    {
        return response()->json(['message' => 'Pedido não encontrado.'], 404);
    }

    /** QR do Pix ainda aguardando pagamento (não fica gravado: vem do Mercado Pago). */
    private function pixPendente(Order $order, MercadoPagoPaymentService $mercadoPago): ?array
    {
        $pix = $order->payments()
            ->where('method_type', Payment::METHOD_PIX)
            ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION)
            ->whereNotNull('mercadopago_payment_id')
            ->latest('id')->first();

        if ($order->status !== Order::STATUS_AWAITING_PAYMENT || ! $pix) {
            return null;
        }

        $mp = rescue(fn () => $mercadoPago->retrievePayment($pix->mercadopago_payment_id), null, false);
        $qr = $mp['point_of_interaction']['transaction_data'] ?? [];

        return ($mp['status'] ?? null) === 'pending' && filled($qr['qr_code'] ?? null) ? [
            'copia_e_cola' => $qr['qr_code'],
            'qr_code_base64' => $qr['qr_code_base64'] ?? null,
            'expira_em' => $mp['date_of_expiration'] ?? null,
        ] : null;
    }
}
