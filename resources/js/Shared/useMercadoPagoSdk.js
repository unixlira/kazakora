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

    return mensagem || padrao;
}
