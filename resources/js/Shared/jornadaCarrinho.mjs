// Internal, fail-open measurement. Never import marketing SDKs or form payloads.
const cents = (v) => v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Math.round(Number(v) * 100);
export function snapshot(p = {}) {
    return { currency: 'BRL', subtotal: cents(p.subtotal), discount: cents(p.discountAmount),
        shipping: cents(p.shipping), interest: cents(p.interest), total: cents(p.total),
        items: (p.items ?? []).slice(0, 20).map(i => ({ product_id: Number(i.product.id), quantity: Number(i.quantity), unit_price: cents(i.unit_price ?? (i.subtotal != null && i.quantity > 0 ? i.subtotal / i.quantity : i.product.final_price)) })) };
}
function hex() {
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}
let memorySession;
function session() {
    try {
        let id = sessionStorage.getItem('kazakora_jornada');
        if (!/^[a-f0-9]{32}$/.test(id ?? '')) sessionStorage.setItem('kazakora_jornada', id = hex());
        return id;
    } catch { return memorySession ??= hex(); }
}
export function registrarJornada(evento, antes, depois = antes, estado = 'observado', motivo = 'navegacao') {
    try {
        const e = { evento, jornada: { id: hex(), sessao: session(), estado, motivo, antes, depois } };
        const body = JSON.stringify({ pagina: session().slice(0, 12), eventos: [e] });
        if (new TextEncoder().encode(body).length > 8192) return;
        fetch('/finalizacao/eventos', { method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: { 'Content-Type': 'text/plain' }, body }).catch(() => {});
    } catch { /* Measurement cannot break buying. */ }
}
export function instalarJornada(router, initial, send) {
    let current = initial;
    let pending = null;
    const emit = (evento, antes, depois, estado, motivo) => {
        try {
            if (send) send({ evento, jornada: { antes, depois, estado, motivo } });
            else registrarJornada(evento, antes, depois, estado, motivo);
        } catch { /* fail-open */ }
    };
    const view = page => {
        if (!/^(Cart|Catalog|Checkout)\//.test(page?.component ?? '')) return;
        const s = snapshot(page.props);
        const name = page.component === 'Cart/Index' ? 'carrinho_visto' : page.component.startsWith('Checkout/') ? 'checkout_inicio' : 'pagina_vista';
        emit(name, s, s, 'observado', 'navegacao');
    };
    router.on('start', ({detail:{visit:v}}) => {
        pending = null;
        const path = new URL(String(v.url), 'https://local.invalid').pathname;
        const method = v.method.toLowerCase();
        let event, reason;
        if (path === '/comprar-agora' && method === 'post') [event,reason] = ['checkout_inicio','adicao'];
        else if (/^\/carrinho(?:\/\d+)?$/.test(path)) {
            if (method === 'post') [event,reason] = ['carrinho_adicionar','adicao'];
            if (method === 'patch') [event,reason] = [Number(v.data.quantity) <= 0 ? 'carrinho_remover' : 'carrinho_quantidade','quantidade'];
            if (method === 'delete') [event,reason] = ['carrinho_remover','remocao'];
        }
        if (!event) return;
        const before = snapshot(current?.props);
        const selected = [current?.props?.product, ...(current?.props?.variations ?? [])].find(p => p && Number(p.id) === Number(v.data?.product_id));
        const intended = selected ? snapshot({ items: [{ product: selected, quantity: Number(v.data.quantity) }] }) : before;
        pending = { event, reason, before };
        // Selected IDs/prices only; resulting totals remain unknown until server response.
        emit(event,before,intended,'intent',reason);
    });
    router.on('error', () => { pending = null; });
    router.on('invalid', () => { pending = null; });
    router.on('exception', () => { pending = null; });
    router.on('success', ({detail:{page}}) => {
        if (pending) emit(pending.event,pending.before,snapshot(page.props),'observado',pending.reason);
        pending = null;
        current = page;
    });
    router.on('navigate', ({detail:{page}}) => { current = page; view(page); });
    view(initial);
}
