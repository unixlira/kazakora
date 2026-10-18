<?php

namespace App\Modules\Checkout\Support;

/**
 * Traduz o status_detail de recusa do Mercado Pago para o admin (pedido do
 * Lira 2026-10-18). A API de Orders (cartão) devolve o código sem prefixo
 * ("high_risk"); a de Payments (Pix, boleto) devolve "cc_rejected_high_risk".
 * Código desconhecido aparece cru, para não esconder nada.
 */
class MotivoRecusaMercadoPago
{
    private const TEXTOS = [
        'high_risk' => 'Recusado pela prevenção a fraude do Mercado Pago',
        'blacklist' => 'Recusado pela prevenção a fraude do Mercado Pago (bloqueio)',
        'insufficient_amount' => 'Cartão sem limite suficiente',
        'bad_filled_card_data' => 'Dados do cartão preenchidos errado',
        'bad_filled_card_number' => 'Número do cartão errado',
        'bad_filled_date' => 'Validade do cartão errada',
        'bad_filled_security_code' => 'Código de segurança (CVV) errado',
        'bad_filled_other' => 'Dado do cartão preenchido errado',
        'card_number_validation' => 'Número do cartão inválido',
        'call_for_authorize' => 'O banco pede que o cliente autorize a compra',
        'required_call_for_authorize' => 'O banco pede que o cliente autorize a compra',
        'card_disabled' => 'Cartão desativado ou bloqueado (o cliente precisa ativar com o banco)',
        'card_error' => 'O cartão não conseguiu processar o pagamento',
        'duplicated_payment' => 'Pagamento duplicado (mesmo valor e cartão há pouco tempo)',
        'invalid_installments' => 'O cartão não aceita esse número de parcelas',
        'max_attempts' => 'Cliente passou do limite de tentativas com esse cartão',
        'max_attempts_exceeded' => 'Cliente passou do limite de tentativas com esse cartão',
        'rejected_by_issuer' => 'Recusado pelo banco emissor do cartão',
        'rejected_by_bank' => 'Recusado pelo banco emissor do cartão',
        'rejected_by_regulations' => 'Recusado por regra regulatória',
        'rejected_insufficient_data' => 'Faltaram dados do comprador',
        'rejected_high_risk' => 'Recusado pela prevenção a fraude do Mercado Pago',
        'amount_limit_exceeded' => 'Valor acima do limite permitido',
        'invalid_users_involved' => 'Conta do comprador ou do vendedor com restrição',
        'processing_error' => 'Erro no processamento do Mercado Pago',
        'other_reason' => 'Recusado pelo banco sem motivo informado',
        'rejected_other_reason' => 'Recusado pelo banco sem motivo informado',
        'expired' => 'Expirou sem pagamento',
    ];

    public static function texto(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        $chave = str_starts_with($codigo, 'cc_rejected_') ? substr($codigo, strlen('cc_rejected_')) : $codigo;

        return self::TEXTOS[$chave] ?? $codigo;
    }
}
