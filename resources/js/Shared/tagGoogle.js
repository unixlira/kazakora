// Tag do Google Ads (pedido 2026-11-19, PMax do travesseiro). Cookie de
// publicidade de terceiro: só carrega DEPOIS do aceite de cookies em vigor
// (`cookies.aceito`, o mesmo do Pixel da Meta). Quem não aceitou nunca baixa
// o gtag.js. Detalhe em docs/privacidade-e-cookies.md.
// - `rastrearPaginaGoogle`: chamada pelo app.js a cada navegação. Carrega a
//   tag (ela lê o ?gclid= da página e grava o _gcl_aw) e, na página de
//   confirmação paga, manda a compra.
// - `rastrearCompraGoogle`: conversão "Compra" com valor e número do pedido.
//   O servidor só entrega os dados (GoogleAds::compra) com o aceite, pedido
//   pago e da loja; o Google desduplica pelo transaction_id.
let iniciado = false;

function carregar(id) {
    if (iniciado) return;
    iniciado = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
    // Consent Mode: aceite dado = anúncios liberados; Analytics não é usado.
    window.gtag('consent', 'default', {
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'granted',
        analytics_storage: 'denied',
    });
    window.gtag('js', new Date());
    window.gtag('config', id, { allow_enhanced_conversions: true });
    const t = document.createElement('script');
    t.async = true;
    t.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    document.head.appendChild(t);
}

export function rastrearPaginaGoogle(page) {
    const cookies = page?.props?.cookies;
    if (!cookies?.aceito || !cookies.googleAds) return;
    if (page.url.startsWith('/admin')) return;
    carregar(cookies.googleAds);
    if (page.component === 'Checkout/Confirmation' && page.props.googleCompra) {
        rastrearCompraGoogle(page.props.googleCompra);
    }
}

export function rastrearCompraGoogle(compra) {
    if (!compra?.id || !compra.send_to || !compra.transaction_id) return;
    const chave = `kzg_${compra.transaction_id}`;
    try {
        if (localStorage.getItem(chave)) return;
        localStorage.setItem(chave, '1');
    } catch (e) { /* sem localStorage: manda mesmo assim, o Google desduplica */ }
    carregar(compra.id);
    if (compra.user_data) window.gtag('set', 'user_data', compra.user_data);
    window.gtag('event', 'conversion', {
        send_to: compra.send_to,
        value: compra.value,
        currency: compra.currency,
        transaction_id: compra.transaction_id,
    });
}
