let loadPromise = null;

/**
 * SDK do Mercado Pago (Bricks) não tem pacote npm oficial pro Vue — o
 * próprio Mercado Pago recomenda o script direto via CDN, que expõe
 * `window.MercadoPago`. Carrega uma única vez mesmo se chamado várias
 * vezes (troca de método de pagamento, remontagem do componente).
 */
export function loadMercadoPagoSdk() {
    if (window.MercadoPago) {
        return Promise.resolve(window.MercadoPago);
    }

    if (loadPromise) {
        return loadPromise;
    }

    loadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://sdk.mercadopago.com/js/v2';
        script.async = true;
        script.onload = () => resolve(window.MercadoPago);
        script.onerror = () => {
            loadPromise = null;
            reject(new Error('Não foi possível carregar o SDK do Mercado Pago.'));
        };
        document.head.appendChild(script);
    });

    return loadPromise;
}

/**
 * Erro do Card Payment Brick em português para o cliente. Abaixo de R$ 0,50
 * o Mercado Pago recusa o cartão com "There is no valid payment type for
 * this amount, try sending a larger amount.".
 */
export function mensagemErroCartao(error, padrao = 'Não foi possível carregar o formulário do cartão.') {
    const mensagem = String(error?.message ?? '');

    if (/no valid payment type|larger amount/i.test(mensagem)) {
        return 'O valor mínimo para pagar no cartão de crédito é R$ 0,50. Escolha o Pix ou adicione mais itens ao carrinho.';
    }

    // Cartão que o Mercado Pago não aceita como crédito (débito, pré-pago ou
    // número de teste): sem isso aparecia o código cru na tela.
    if (/no_payment_method_for_provided_bin/i.test(mensagem)) {
        return 'Este cartão não foi aceito como cartão de crédito. Confira o número ou use outro cartão de crédito.';
    }

    if (/installments/i.test(mensagem)) {
        return 'Não foi possível carregar as parcelas deste cartão. Confira o número ou tente outro cartão.';
    }

    return mensagem || padrao;
}

/**
 * Motivo do erro do cartão (checkout 2026-11-24). Achado real: o lead 21
 * clicou Finalizar com cartão e só viu "Confira os dados do cartão.", sem
 * saber o que corrigir, e a medição gravou "erro no cartão" sem motivo.
 *
 * O erro do Card Payment Brick vem em formatos diferentes conforme a origem:
 * - onError: { type: 'critical' | 'non_critical', cause: 'fields_setup_failed', message };
 * - getFormData()/token rejeitado: Error com message, ou { cause: [{ code, description }] }
 *   com os códigos clássicos do token (205 número, 208/209/325/326 validade,
 *   224/E302 CVV, 221/316 nome, 212/213/214/322/323/324 documento, E301 número);
 * - campos seguros: [{ field: 'cardNumber' | 'expirationDate' | 'securityCode' |
 *   'cardholderName' | 'identificationNumber', message }].
 * Por isso a leitura junta todas as "pistas" (code, cause, field, message) e
 * procura nelas, sem depender de um formato só.
 *
 * Os códigos são uma lista FECHADA, igual a CheckoutEvento::MOTIVOS_CARTAO no
 * servidor: só o código vai para a medição, nunca o que foi digitado.
 */
export const MOTIVOS_CARTAO = ['numero', 'validade', 'cvv', 'nome', 'documento', 'bin', 'parcelas', 'nao_carregou', 'sem_token', 'outro'];

const REGRAS_MOTIVO = [
    ['nao_carregou', /fields_setup_failed|settings_empty|missing_container|missing_amount|missing_locale|missing_required_callbacks|incorrect_initialization|invalid_sdk_instance|already_initialized|payment_brick_initialization|get_payment_methods_failed|get_identification_types_failed|carregar o SDK/i],
    ['bin', /no_payment_method_for_provided_bin|get_card_bin_payment_methods_failed|unauthorized_payment_method|bin_not_found|invalid_bin/i],
    ['numero', /card_?number|^(205|E301)$|invalid card number/i],
    ['validade', /expiration|^(208|209|325|326|E205)$/i],
    ['cvv', /security_?code|^(224|E302|E203)$|\bcvv\b/i],
    ['nome', /cardholder_?name|^(221|316)$/i],
    ['documento', /identification|^(212|213|214|322|323|324)$|doc_?number|doc_?type/i],
    ['parcelas', /installments|missing_payment_information|get_card_issuers_failed|issuer/i],
    ['sem_token', /card_token_creation_failed|token/i],
];

const pistasDoErro = (error, nivel = 0, pistas = []) => {
    if (error == null || nivel > 3) return pistas;
    if (typeof error === 'string' || typeof error === 'number') {
        pistas.push(String(error));
        return pistas;
    }
    if (Array.isArray(error)) {
        error.forEach((item) => pistasDoErro(item, nivel + 1, pistas));
        return pistas;
    }
    if (typeof error === 'object') {
        ['code', 'field', 'cause', 'message', 'description', 'error', 'errors', 'type'].forEach((chave) => {
            if (chave in error) pistasDoErro(error[chave], nivel + 1, pistas);
        });
    }
    return pistas;
};

/** Código da lista MOTIVOS_CARTAO para um erro do Brick (null se não reconhecer). */
export function motivoDoErroCartao(error) {
    const pistas = pistasDoErro(error).filter((p) => p !== 'critical' && p !== 'non_critical');
    for (const [motivo, regra] of REGRAS_MOTIVO) {
        if (pistas.some((p) => regra.test(p))) return motivo;
    }
    return null;
}

/**
 * Último recurso: o Brick marca em vermelho o campo errado e escreve o aviso
 * dele embaixo (ex.: "Preencha o código de segurança"). Lê só esses avisos e
 * os rótulos dos campos marcados como inválidos para saber QUAL campo; o que
 * a pessoa digitou não é lido (número, validade e CVV ficam em iframes do
 * Mercado Pago) e nada desse texto sai da página: só o código do motivo.
 */
const REGRAS_TEXTO = [
    ['numero', /n[uú]mero do cart[aã]o|cart[aã]o inv[aá]lido|card_?number/i],
    ['validade', /validade|vencimento|expira/i],
    ['cvv', /seguran[cç]a|cvv|security/i],
    ['nome', /titular|nome|cardholder/i],
    ['documento', /cpf|cnpj|documento|identification/i],
    ['parcelas', /parcela|installment/i],
];

export function motivoNaTelaDoCartao(container) {
    if (!container?.querySelectorAll) return null;
    const textos = [];
    container.querySelectorAll('[class*="error" i], [role="alert"], [aria-invalid="true"]').forEach((el) => {
        if (el.getAttribute('aria-invalid') === 'true') {
            textos.push(el.getAttribute('name') ?? '', el.id ?? '', el.getAttribute('aria-label') ?? '');
        } else {
            textos.push(String(el.textContent ?? '').slice(0, 120));
        }
    });
    const pistas = textos.map((t) => t.trim()).filter(Boolean);
    for (const [motivo, regra] of REGRAS_TEXTO) {
        if (pistas.some((p) => regra.test(p))) return motivo;
    }
    return null;
}

/**
 * Frase curta para o cliente, sempre com a saída pelo Pix. `pix` vem montado
 * da tela ("pague no Pix com 5% de desconto").
 */
export function mensagemDoMotivoCartao(motivo, pix = 'pague no Pix') {
    switch (motivo) {
        case 'numero': return `Número do cartão incompleto ou inválido. Corrija ou ${pix}.`;
        case 'validade': return `Data de validade inválida. Confira o mês e o ano ou ${pix}.`;
        case 'cvv': return `Código de segurança (CVV) incompleto. Confira os números no verso do cartão ou ${pix}.`;
        case 'nome': return `Nome do titular em branco. Escreva o nome como está no cartão ou ${pix}.`;
        case 'documento': return `CPF do titular inválido. Confira o CPF do dono do cartão ou ${pix}.`;
        case 'bin': return `Este cartão não é aceito como crédito. Use outro cartão de crédito ou ${pix}.`;
        case 'parcelas': return `Escolha em quantas parcelas pagar ou ${pix}.`;
        case 'nao_carregou': return `O formulário do cartão não carregou. Atualize a página ou ${pix}.`;
        default: return `Não conseguimos validar o cartão. Confira os campos marcados em vermelho ou ${pix}.`;
    }
}
