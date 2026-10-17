// Pixel da Meta (pedido 2026-10-16). Cookie de publicidade de terceiro: só
// carrega DEPOIS do OK do aviso de cookies (docs/privacidade-e-cookies.md).
// Quem decide o que disparar em cada página é `rastrearPagina`, chamada pelo
// app.js a cada navegação; as páginas não precisam saber do pixel.
let iniciado = false;

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
    if (!cookies?.aceito || !cookies.pixelMeta) return;
    if (page.url.startsWith('/admin')) return;
    carregar(cookies.pixelMeta);

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
    } else if (component === 'Checkout/Confirmation' && props.order) {
        // Uma vez por pedido, mesmo se o cliente recarregar a página.
        const chave = `kz_purchase_${props.order.id}`;
        try {
            if (localStorage.getItem(chave)) return;
            localStorage.setItem(chave, '1');
        } catch (e) { /* sem localStorage: dispara mesmo assim */ }
        window.fbq('track', 'Purchase', {
            content_type: 'product',
            contents: itens(props.order.items),
            value: numero(props.order.total),
            currency: 'BRL',
        }, { eventID: `order-${props.order.id}` });
    }
}
