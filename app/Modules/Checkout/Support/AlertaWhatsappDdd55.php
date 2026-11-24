<?php

namespace App\Modules\Checkout\Support;

/**
 * Alerta de WhatsApp com DDD 55 no checkout (pedido do Lira 2026-11-23).
 *
 * Muita gente digita o "55" do Brasil na frente do celular e o número fica
 * com DDD 55 (noroeste do RS: Santa Maria, Santo Ângelo, Uruguaiana, Santa
 * Rosa, Cruz Alta, Ijuí...), e o WhatsApp do rastreio não chega. Com DDD 55
 * e CEP de entrega fora da área do DDD 55 (97000-000 a 98999-999), a tela
 * pede para a pessoa conferir o número.
 *
 * A tela roda a MESMA regra em resources/js/Modules/Checkout/alertaWhatsapp.js;
 * tests/Unit/AlertaWhatsappDdd55Test.php confere as duas com os mesmos casos.
 */
final class AlertaWhatsappDdd55
{
    public const DDD = '55';

    public const CEP_AREA_DE = 97000000;

    public const CEP_AREA_ATE = 98999999;

    public static function deveAlertar(?string $celular, ?string $cep): bool
    {
        $numero = preg_replace('/\D/', '', (string) $celular);
        $cepDigitos = preg_replace('/\D/', '', (string) $cep);
        if (strlen($numero) < 10 || strlen($cepDigitos) !== 8 || substr($numero, 0, 2) !== self::DDD) {
            return false;
        }

        $valor = (int) $cepDigitos;

        return $valor < self::CEP_AREA_DE || $valor > self::CEP_AREA_ATE;
    }

    /** DDD + número sem o 55 do país, quando a pessoa digitou 55 + DDD + número (12 ou 13 dígitos). */
    public static function sugestaoSem55(?string $digitado): ?string
    {
        $numero = preg_replace('/\D/', '', (string) $digitado);
        $tamanho = strlen($numero);

        return ($tamanho === 12 || $tamanho === 13) && str_starts_with($numero, self::DDD) ? substr($numero, 2) : null;
    }
}
