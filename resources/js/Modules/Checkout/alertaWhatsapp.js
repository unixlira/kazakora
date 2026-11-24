// Alerta de WhatsApp com DDD 55 (pedido do Lira 2026-11-23). Muita gente
// digita o "55" do Brasil na frente do celular e o número fica com DDD 55
// (noroeste do RS: Santa Maria, Santo Ângelo, Uruguaiana, Santa Rosa, Cruz
// Alta, Ijuí...), e o WhatsApp do rastreio não chega. Com DDD 55 e CEP de
// entrega FORA da área do DDD 55 (CEP 97000-000 a 98999-999), o checkout
// pede para a pessoa conferir o número.
//
// MESMA REGRA de App\Modules\Checkout\Support\AlertaWhatsappDdd55 (PHP); o
// teste tests/Unit/AlertaWhatsappDdd55Test.php roda os dois com os mesmos casos.

export const DDD_ALERTA = '55';
export const CEP_AREA_DE = 97000000;
export const CEP_AREA_ATE = 98999999;

const digitos = (valor) => String(valor ?? '').replace(/\D/g, '');

/** O celular (com ou sem máscara) tem DDD 55 e o CEP (8 dígitos) é de fora da área do DDD 55. */
export function deveAlertarDdd55(celular, cep) {
    const numero = digitos(celular);
    const cepDigitos = digitos(cep);
    if (numero.length < 10 || cepDigitos.length !== 8) return false;
    if (numero.slice(0, 2) !== DDD_ALERTA) return false;
    const valor = Number(cepDigitos);

    return valor < CEP_AREA_DE || valor > CEP_AREA_ATE;
}

/**
 * Número sugerido sem o 55 do país: só quando a pessoa digitou 55 + DDD +
 * número (12 ou 13 dígitos). Volta só os dígitos (DDD + número) ou null.
 */
export function sugestaoSem55(digitado) {
    const numero = digitos(digitado);
    if ((numero.length === 12 || numero.length === 13) && numero.startsWith(DDD_ALERTA)) {
        return numero.slice(2);
    }

    return null;
}
