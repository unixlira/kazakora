<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resumo do pagamento para a tela do pedido no admin (Lira, 2026-10-20): a
 * equipe precisa ver de relance se o cliente pagou e, principalmente, quando
 * gerou o Pix e não pagou e o pedido caiu por prazo vencido
 * (orders:expire-abandoned). Antes só aparecia "Cancelado" no pagamento.
 *
 * Só para venda do site: no marketplace quem recebe é o canal.
 */
class SituacaoDoPagamento
{
    /** Motivo gravado pelo orders:expire-abandoned quando o pedido tinha Pix. */
    public const MOTIVO_PIX_EXPIRADO = 'Pix gerado e não pago: cancelado automaticamente, prazo do Pix expirado';

    /**
     * @return array{codigo: string, tom: string, selo: string, titulo: string, detalhe: ?string}|null
     */
    public static function doPedido(Order $order): ?array
    {
        if ($order->origin !== Order::ORIGIN_STORE) {
            return null;
        }

        /** @var Collection<int, Payment> $pagamentos */
        $pagamentos = $order->payments->sortByDesc('id')->values();
        $cancelado = $order->status === Order::STATUS_CANCELLED;
        $automatico = $cancelado && $order->cancelado_por === null
            && ($order->motivo_cancelamento === null || $order->motivo_cancelamento === self::MOTIVO_PIX_EXPIRADO);

        if ($estornado = $pagamentos->firstWhere('status', Payment::STATUS_REFUNDED)) {
            return self::montar('estornado', 'warning', 'Pagamento estornado',
                self::meio($estornado).' pago e devolvido ao cliente.',
                'Estorno registrado em '.self::data($estornado->updated_at).'.');
        }

        if ($pago = $pagamentos->firstWhere('status', Payment::STATUS_CAPTURED)) {
            return $cancelado
                ? self::montar('pago_cancelado', 'error', 'Pago, pedido cancelado',
                    self::meio($pago).' pago, mas o pedido está cancelado e o estorno não aparece.',
                    'Confira o estorno antes de dar o caso por encerrado.')
                : self::montar('pago', 'success', 'Pago', self::meio($pago).' pago.', 'Confirmado em '.self::data($pago->updated_at).'.');
        }

        if ($autorizado = $pagamentos->firstWhere('status', Payment::STATUS_AUTHORIZED)) {
            return self::montar('autorizado', 'info', 'Autorizado', self::meio($autorizado).' autorizado, aguardando captura.', null);
        }

        $pix = $pagamentos->firstWhere('method_type', Payment::METHOD_PIX);

        if ($pix && ! $cancelado) {
            $vence = self::vencimentoDoPix($pix);

            return $vence?->isPast()
                ? self::montar('pix_vencido', 'warning', 'Pix vencido', 'Pix gerado e não pago; o prazo venceu às '.self::hora($vence).'.',
                    'O pedido é cancelado sozinho em até 15 min depois do vencimento, depois de conferir no Mercado Pago.')
                : self::montar('pix_aguardando', 'info', 'Aguardando Pix', 'Pix gerado, aguardando o cliente pagar.',
                    $vence ? 'Gerado em '.self::data($pix->created_at).', vence às '.self::hora($vence).'.' : null);
        }

        if ($pix && $automatico) {
            $vence = self::vencimentoDoPix($pix);

            return self::montar('pix_expirado', 'error', 'Pix expirado, não pago',
                'Pix gerado e não pago: pedido cancelado porque o prazo expirou.',
                'Pix gerado em '.self::data($pix->created_at)
                .($vence ? ', venceu às '.self::hora($vence) : '')
                .($order->cancelado_em ? '; cancelado em '.self::data($order->cancelado_em) : '').'.');
        }

        if ($pix) {
            return self::montar('pix_nao_pago_cancelado', 'error', 'Pix não pago',
                'Pix gerado e não pago; o pedido foi cancelado antes do pagamento.',
                $order->motivo_cancelamento ? 'Motivo: '.$order->motivo_cancelamento : null);
        }

        $boleto = $pagamentos->firstWhere('method_type', Payment::METHOD_BOLETO);

        if ($boleto) {
            if (! $cancelado) {
                return self::montar('boleto_aguardando', 'info', 'Aguardando boleto', 'Boleto gerado, aguardando pagamento.',
                    $boleto->expira_em ? 'Vence em '.self::data($boleto->expira_em, 'd/m/Y').'.' : null);
            }

            return self::montar('boleto_nao_pago', 'error', 'Boleto não pago', 'Boleto gerado e não pago; pedido cancelado.',
                $order->motivo_cancelamento ? 'Motivo: '.$order->motivo_cancelamento : null);
        }

        if ($recusado = $pagamentos->first(fn (Payment $p) => $p->motivo_recusa !== null)) {
            return self::montar('recusado', 'error', 'Pagamento recusado', self::meio($recusado).' recusado.',
                MotivoRecusaMercadoPago::texto($recusado->motivo_recusa));
        }

        if ($pagamentos->isEmpty()) {
            return $cancelado
                ? self::montar('sem_pagamento_cancelado', 'error', 'Sem pagamento', 'Pedido cancelado sem pagamento registrado.', null)
                : self::montar('sem_pagamento', 'warning', 'Sem pagamento', 'Nenhum pagamento registrado ainda.', null);
        }

        return $cancelado
            ? self::montar('nao_pago_cancelado', 'error', 'Não pago', self::meio($pagamentos->first()).' não concluído; pedido cancelado.', null)
            : self::montar('aguardando', 'warning', 'Aguardando pagamento', self::meio($pagamentos->first()).' aguardando confirmação.', null);
    }

    public static function vencimentoDoPix(Payment $pix): ?CarbonInterface
    {
        if ($pix->expira_em) {
            return $pix->expira_em;
        }

        return $pix->created_at?->copy()->addSeconds($pix->provider === Payment::PROVIDER_STRIPE
            ? StripePaymentService::PIX_EXPIRES_AFTER_SECONDS
            : MercadoPagoPaymentService::PIX_VALIDADE_HORAS * 3600);
    }

    private static function meio(Payment $payment): string
    {
        return match ($payment->method_type) {
            Payment::METHOD_PIX => 'Pix',
            Payment::METHOD_BOLETO => 'Boleto',
            Payment::METHOD_CARD => 'Cartão',
            default => 'Pagamento',
        };
    }

    private static function data(?CarbonInterface $quando, string $formato = 'd/m/Y H:i'): string
    {
        return $quando ? $quando->copy()->timezone('America/Sao_Paulo')->format($formato) : '—';
    }

    private static function hora(CarbonInterface $quando): string
    {
        return self::data($quando, 'H:i \d\e d/m');
    }

    /** @return array{codigo: string, tom: string, selo: string, titulo: string, detalhe: ?string} */
    private static function montar(string $codigo, string $tom, string $selo, string $titulo, ?string $detalhe): array
    {
        return compact('codigo', 'tom', 'selo', 'titulo', 'detalhe');
    }
}
