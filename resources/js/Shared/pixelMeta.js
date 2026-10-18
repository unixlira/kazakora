// Pixel da Meta (pedido 2026-10-16). Cookie de publicidade de terceiro: só
// carrega DEPOIS do OK do aviso de cookies (docs/privacidade-e-cookies.md).
// Quem decide o que disparar em cada página é `rastrearPagina`, chamada pelo
// app.js a cada navegação; as páginas não precisam saber do pixel.
let iniciado = false;
// Pixel liberado na página atual (OK de cookies + ID). Os eventos de clique
// (AddToCart) e do modal de pagamento (Purchase) só disparam com ele.
let liberado = false;
// Última página rastreada: POST que volta para a mesma página (carrinho pelo
// card, CEP) não repete PageView/ViewContent.
let ultimaUrl = null;

function carregar(id) {
    if (iniciado) return;
    iniciado = true;
    /* eslint-disable */
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    /* eslint-enable */
    window.fbq('init', id);
}

const numero = (v) => Math.round(Number(v ?? 0) * 100) / 100;

function itens(lista) {
    return (lista ?? []).map((i) => ({
        id: String(i.product_id ?? i.product?.id ?? i.id),
        quantity: Number(i.quantity ?? 1),
        item_price: numero(i.product_price ?? Number(i.subtotal ?? 0) / Math.max(1, Number(i.quantity ?? 1))),
    }));
}

export function rastrearPagina(page) {
    const cookies = page?.props?.cookies;
    liberado = false;
    if (!cookies?.aceito || !cookies.pixelMeta) return;
    if (page.url.startsWith('/admin')) return;
    carregar(cookies.pixelMeta);
    liberado = true;
    if (page.url === ultimaUrl) return;
    ultimaUrl = page.url;

    window.fbq('track', 'PageView');

    const { component, props } = page;
    if (component.startsWith('Catalog/ProductDetail') && props.product) {
        window.fbq('track', 'ViewContent', {
            content_type: 'product',
            content_ids: [String(props.product.id)],
            content_name: props.product.name,
            value: numero(props.product.final_price),
            currency: 'BRL',
        });
    } else if (component === 'Checkout/CheckoutV2' && props.items?.length) {
        window.fbq('track', 'InitiateCheckout', {
            content_type: 'product',
            contents: itens(props.items),
            num_items: props.items.reduce((t, i) => t + Number(i.quantity ?? 1), 0),
            value: numero(props.subtotal),
            currency: 'BRL',
        });
    } else if (component === 'Checkout/Confirmation' && props.metaPurchase) {
        rastrearPurchase(props.metaPurchase);
    }
}

// event_id dos eventos de clique: o mesmo vai ao Pixel e ao servidor (API de
// Conversões, pedido 2026-10-18), e a Meta conta uma vez só.
export function novoEventId() {
    try {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    } catch (e) { /* navegador antigo */ }
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

// "Comprar" do card e da página do produto. O servidor manda o AddToCart com
// o mesmo eventId (CartController), só com o OK de cookies, como aqui.
export function rastrearAddToCart({ id, preco, quantidade = 1 }, eventId) {
    if (!liberado || !window.fbq || id == null) return;
    const qtd = Math.max(1, Number(quantidade) || 1);
    window.fbq('track', 'AddToCart', {
        content_type: 'product',
        content_ids: [String(id)],
        contents: [{ id: String(id), quantity: qtd, item_price: numero(preco) }],
        value: numero(Number(preco ?? 0) * qtd),
        currency: 'BRL',
    }, { eventID: eventId });
}

// Purchase só com o pagamento APROVADO (modal do checkout ou página de
// confirmação). eventID "purchase-{pedido}" vem do servidor, o mesmo do
// EnviarPurchaseMetaJob. Uma vez por pedido, mesmo recarregando a página.
export function rastrearPurchase(metaPurchase) {
    if (!liberado || !window.fbq || !metaPurchase?.event_id) return;
    const chave = `kz_${metaPurchase.event_id}`;
    try {
        if (localStorage.getItem(chave)) return;
        localStorage.setItem(chave, '1');
    } catch (e) { /* sem localStorage: dispara mesmo assim */ }
    window.fbq('track', 'Purchase', metaPurchase.dados, { eventID: metaPurchase.event_id });
}
