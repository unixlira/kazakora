<?php

namespace App\Services\MercadoPago;

/**
 * Credenciais que o checkout da loja usa (pedido 2026-10-10). Com
 * MERCADOPAGO_MODO_TESTE=true e as duas chaves de teste preenchidas, Pix e
 * cartão vão para a conta de teste do Mercado Pago — nada é cobrado de
 * verdade. Faltando qualquer chave de teste, segue com as reais (nunca fica
 * metade teste, metade real).
 */
class CredenciaisCheckout
{
    public static function emTeste(): bool
    {
        return (bool) config('services.mercadopago.modo_teste')
            && filled(config('services.mercadopago.teste_access_token'))
            && filled(config('services.mercadopago.teste_public_key'));
    }

    public static function accessToken(): ?string
    {
        return self::emTeste()
            ? config('services.mercadopago.teste_access_token')
            : config('services.mercadopago.access_token');
    }

    public static function publicKey(): ?string
    {
        return self::emTeste()
            ? config('services.mercadopago.teste_public_key')
            : config('services.mercadopago.public_key');
    }
}
