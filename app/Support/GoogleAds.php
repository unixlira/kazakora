<?php

namespace App\Support;

use App\Modules\Checkout\Models\Order;
use App\Services\Meta\ConversoesMeta;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;

/**
 * Tag do Google Ads da loja (pedido 2026-11-19, PMax do travesseiro).
 * O front (Shared/tagGoogle.js) carrega o gtag.js só com o aceite de cookies
 * e manda a conversão "Compra" quando o pagamento é aprovado. Mesmo
 * consentimento do Pixel da Meta; detalhe em docs/privacidade-e-cookies.md.
 */
class GoogleAds
{
    /** AW-123456789 do .env, ou null (tag desligada). */
    public static function id(): ?string
    {
        $id = trim((string) config('services.google_ads_tag.conversion_id'));

        return preg_match('/^AW-\d{6,15}$/', $id) === 1 ? $id : null;
    }

    public static function rotuloCompra(): ?string
    {
        $rotulo = trim((string) config('services.google_ads_tag.purchase_label'));

        return preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $rotulo) === 1 ? $rotulo : null;
    }

    /**
     * Conversão de compra para o front, ou null. Só pedido pago do
     * kazakora.com, com a tag configurada e com o aceite de cookies desta
     * requisição (o Finalizar pedido grava o aceite antes de criar o pedido).
     *
     * @return array<string, mixed>|null
     */
    public static function compra(Order $order, Request $request): ?array
    {
        $id = self::id();
        $rotulo = self::rotuloCompra();

        if ($id === null || $rotulo === null || $order->status !== Order::STATUS_PAID
            || ! ConversoesMeta::pedidoDaLoja($order) || ! Cookies::aceitou($request)) {
            return null;
        }

        $compra = [
            'id' => $id,
            'send_to' => "{$id}/{$rotulo}",
            'value' => round((float) $order->total, 2),
            'currency' => 'BRL',
            'transaction_id' => (string) ($order->external_order_id ?: $order->id),
        ];

        if (config('services.google_ads_tag.enhanced_conversions')) {
            $dados = self::dadosDoCliente($order);
            if ($dados !== []) {
                $compra['user_data'] = $dados;
            }
        }

        return $compra;
    }

    /**
     * Conversões otimizadas: e-mail e celular normalizados e com hash
     * SHA-256 (o Google não recebe o texto). Mesmo dado que a API de
     * Conversões da Meta já manda com o mesmo aceite.
     *
     * @return array<string, string>
     */
    private static function dadosDoCliente(Order $order): array
    {
        $order->loadMissing('user');
        $dados = [];

        $email = strtolower(trim((string) ($order->user?->email ?? '')));
        if ($email !== '' && str_contains($email, '@')) {
            $dados['sha256_email_address'] = hash('sha256', $email);
        }

        $fone = preg_replace('/\D/', '', (string) ($order->shipping_phone ?: $order->user?->phone ?? ''));
        if (strlen($fone) >= 10) {
            $dados['sha256_phone_number'] = hash('sha256', '+'.(str_starts_with($fone, '55') && strlen($fone) >= 12 ? $fone : '55'.$fone));
        }

        return $dados;
    }
}
