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

    /**
     * O que o CLIENTE lê no checkout quando o cartão volta recusado (pedido do
     * Lira 2026-11-24): o motivo em pt-BR, curto, com o que fazer e sempre a
     * saída pelo Pix. Antifraude (high_risk, blacklist) e código desconhecido
     * ficam com a frase geral: não dá para dizer ao cliente "fraude".
     */
    private const PARA_CLIENTE = [
        'bad_filled_security_code' => 'O banco recusou o código de segurança (CVV). Confira os números no verso do cartão e tente de novo, ou pague no Pix (aprovação na hora).',
        'bad_filled_date' => 'O banco recusou a data de validade. Confira o mês e o ano do cartão e tente de novo, ou pague no Pix (aprovação na hora).',
        'bad_filled_card_number' => 'O banco recusou o número do cartão. Confira o número e tente de novo, ou pague no Pix (aprovação na hora).',
        'card_number_validation' => 'O banco recusou o número do cartão. Confira o número e tente de novo, ou pague no Pix (aprovação na hora).',
        'bad_filled_card_data' => 'O banco recusou algum dado do cartão. Confira número, validade, CVV e nome do titular, ou pague no Pix (aprovação na hora).',
        'bad_filled_other' => 'O banco recusou algum dado do cartão. Confira número, validade, CVV e nome do titular, ou pague no Pix (aprovação na hora).',
        'insufficient_amount' => 'O cartão não tem limite para este valor. Use outro cartão ou pague no Pix (aprovação na hora).',
        'call_for_authorize' => 'O banco pediu que você autorize esta compra. Libere com o banco (app ou telefone no verso do cartão) e tente de novo, ou pague no Pix.',
        'required_call_for_authorize' => 'O banco pediu que você autorize esta compra. Libere com o banco (app ou telefone no verso do cartão) e tente de novo, ou pague no Pix.',
        'card_disabled' => 'Este cartão está bloqueado ou ainda não foi ativado. Fale com o banco, use outro cartão ou pague no Pix (aprovação na hora).',
        'invalid_installments' => 'O cartão não aceita esse número de parcelas. Escolha outra quantidade de parcelas ou pague no Pix.',
        'duplicated_payment' => 'Já existe um pagamento igual feito agora há pouco. Confira seu e-mail antes de tentar de novo, ou pague no Pix.',
        'max_attempts' => 'Muitas tentativas com este cartão. Use outro cartão ou pague no Pix (aprovação na hora).',
        'max_attempts_exceeded' => 'Muitas tentativas com este cartão. Use outro cartão ou pague no Pix (aprovação na hora).',
        'rejected_by_issuer' => 'O banco do cartão recusou o pagamento. Use outro cartão ou pague no Pix (aprovação na hora).',
        'rejected_by_bank' => 'O banco do cartão recusou o pagamento. Use outro cartão ou pague no Pix (aprovação na hora).',
        'other_reason' => 'O banco do cartão recusou o pagamento. Use outro cartão ou pague no Pix (aprovação na hora).',
        'rejected_other_reason' => 'O banco do cartão recusou o pagamento. Use outro cartão ou pague no Pix (aprovação na hora).',
        'amount_limit_exceeded' => 'O valor passou do limite deste cartão. Use outro cartão ou pague no Pix (aprovação na hora).',
    ];

    public const MENSAGEM_CLIENTE_GERAL = 'O pagamento no cartão não foi aprovado pelo Mercado Pago. Pague no Pix (aprovação na hora) ou tente outro cartão.';

    public static function texto(?string $codigo): ?string
    {
        if (blank($codigo)) {
            return null;
        }

        return self::TEXTOS[self::chave($codigo)] ?? $codigo;
    }

    public static function paraCliente(?string $codigo): string
    {
        return blank($codigo) ? self::MENSAGEM_CLIENTE_GERAL : (self::PARA_CLIENTE[self::chave($codigo)] ?? self::MENSAGEM_CLIENTE_GERAL);
    }

    private static function chave(string $codigo): string
    {
        return str_starts_with($codigo, 'cc_rejected_') ? substr($codigo, strlen('cc_rejected_')) : $codigo;
    }
}
