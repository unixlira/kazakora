// Medição do checkout (pedido do Lira 2026-10-23): o que a pessoa fez na
// tela (abriu, rolou, focou, preencheu, clicou Finalizar, saiu e o tempo
// ativo), para entender quem sai sem digitar nada. NUNCA manda o que foi
// digitado: só o NOME do campo (data-medir="...") e se ficou preenchido.
// Vai em lote para POST /finalizacao/eventos; ao sair, por sendBeacon.
// Leitura: php artisan checkout:funil. Sem Pixel/terceiros.

const URL_EVENTOS = '/finalizacao/eventos';
const MARCOS = [25, 50, 75, 100];
const LOTE = 30;

const codigoDaPagina = () => {
    const bytes = new Uint8Array(8);
    (window.crypto ?? window.msCrypto)?.getRandomValues?.(bytes);
    return Array.from(bytes, (b) => (b % 36).toString(36)).join('').padEnd(12, '0').slice(0, 12);
};

export function useMedicaoCheckout() {
    const pagina = codigoDaPagina();
    let ligada = false;
    let fila = [];
    let timer = null;
    const jaFeitos = new Set();
    let tentativa = 0;
    let ativoMs = 0;
    let visivelDesde = null;
    let rolagemMax = 0;
    let saidaPendente = false;
    let observador = null;
    let quadro = null;

    const enviar = (aoSair = false) => {
        clearTimeout(timer);
        timer = null;
        while (fila.length) {
            const corpo = JSON.stringify({ pagina, eventos: fila.splice(0, LOTE) });
            try {
                const blob = new Blob([corpo], { type: 'text/plain;charset=UTF-8' });
                if (aoSair && navigator.sendBeacon && navigator.sendBeacon(URL_EVENTOS, blob)) continue;
                fetch(URL_EVENTOS, { method: 'POST', body: corpo, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, credentials: 'same-origin', keepalive: true }).catch(() => {});
            } catch {
                // medição nunca atrapalha a compra
            }
        }
    };

    const registrar = (evento, extra = {}, chave = null, imediato = false) => {
        if (!ligada) return;
        if (chave) {
            if (jaFeitos.has(chave)) return;
            jaFeitos.add(chave);
        }
        fila.push({ evento, ...extra });
        if (imediato || fila.length >= 10) enviar();
        else if (!timer) timer = setTimeout(() => enviar(), 5000);
    };

    const medirRolagem = () => {
        quadro = null;
        const doc = document.documentElement;
        const altura = Math.max(doc.scrollHeight, document.body?.scrollHeight ?? 0);
        if (!altura) return;
        const pct = Math.min(100, Math.round(((window.scrollY + window.innerHeight) / altura) * 100));
        if (pct > rolagemMax) rolagemMax = pct;
        MARCOS.filter((m) => rolagemMax >= m).forEach((m) => registrar('rolagem', { valor: m }, `rolagem:${m}`));
    };
    const aoRolar = () => {
        if (!quadro) quadro = requestAnimationFrame(medirRolagem);
    };

    const campoDe = (alvo) => alvo?.dataset?.medir ?? null;
    const aoFocar = (event) => {
        const campo = campoDe(event.target);
        if (campo) registrar('foco', { campo }, `foco:${campo}`);
    };
    const aoSairDoCampo = (event) => {
        const campo = campoDe(event.target);
        if (campo && String(event.target.value ?? '').trim() !== '') registrar('preencheu', { campo }, `preencheu:${campo}`);
    };

    const registrarSaida = () => {
        if (!ligada || !saidaPendente) return;
        if (visivelDesde !== null) ativoMs += performance.now() - visivelDesde;
        visivelDesde = null;
        saidaPendente = false;
        fila.push({ evento: 'saiu', segundos: Math.min(86400, Math.round(ativoMs / 1000)), rolagem: rolagemMax });
        enviar(true);
    };
    const aoMudarVisibilidade = () => {
        if (document.visibilityState === 'hidden') {
            registrarSaida();
        } else {
            visivelDesde = performance.now();
            saidaPendente = true;
        }
    };

    const iniciar = (formulario) => {
        if (ligada || typeof window === 'undefined') return;
        ligada = true;
        visivelDesde = document.visibilityState === 'visible' ? performance.now() : null;
        saidaPendente = true;
        registrar('abriu', {}, 'abriu', true);
        document.addEventListener('focusin', aoFocar, true);
        document.addEventListener('focusout', aoSairDoCampo, true);
        document.addEventListener('visibilitychange', aoMudarVisibilidade);
        window.addEventListener('pagehide', registrarSaida);
        window.addEventListener('scroll', aoRolar, { passive: true });
        medirRolagem();
        if (formulario && 'IntersectionObserver' in window) {
            observador = new IntersectionObserver((entradas) => {
                if (entradas.some((e) => e.isIntersecting)) {
                    registrar('viu_formulario', {}, 'viu_formulario');
                    observador?.disconnect();
                }
            }, { threshold: 0.3 });
            observador.observe(formulario);
        }
    };

    // Saiu da tela pelo próprio site (Inertia): conta como saída.
    const parar = () => {
        if (!ligada) return;
        registrarSaida();
        enviar(true);
        ligada = false;
        observador?.disconnect();
        document.removeEventListener('focusin', aoFocar, true);
        document.removeEventListener('focusout', aoSairDoCampo, true);
        document.removeEventListener('visibilitychange', aoMudarVisibilidade);
        window.removeEventListener('pagehide', registrarSaida);
        window.removeEventListener('scroll', aoRolar);
    };

    // Erros: um por campo em cada clique no Finalizar.
    const finalizarClicado = () => {
        tentativa += 1;
        registrar('finalizar', {}, null, true);
    };
    const erro = (campo) => registrar('erro', { campo }, `erro:${tentativa}:${campo}`);

    return { iniciar, parar, registrar, finalizarClicado, erro };
}

// Chave de erro do servidor/tela -> campo medido (sem o texto do erro).
const CAMPO_DO_ERRO = {
    'guest.name': 'nome',
    'new_address.recipient_name': 'nome',
    'new_address.phone': 'celular',
    'guest.phone': 'celular',
    'guest.cpf': 'cpf',
    cpf: 'cpf',
    'guest.email': 'email',
    'new_address.zip': 'cep',
    'new_address.street': 'endereco',
    'new_address.number': 'numero',
    'new_address.complement': 'complemento',
    'new_address.neighborhood': 'bairro',
    'new_address.city': 'cidade',
    'new_address.state': 'uf',
    code: 'cupom',
    shipping_method_id: 'entrega',
    payment: 'pagamento',
    payment_method: 'pagamento',
    cart: 'carrinho',
};

export const campoDoErro = (chave) => CAMPO_DO_ERRO[chave] ?? (String(chave).startsWith('mp_') ? 'cartao' : 'outro');
