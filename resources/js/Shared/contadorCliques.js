/**
 * Contador de cliques do painel (pedido do Lira 2026-10-25): conta quais
 * menus, abas e botões do admin a equipe usa. Um único listener delegado no
 * documento (fase de captura, para pegar o clique mesmo se o botão parar a
 * propagação), fila em memória e envio em lote para POST /admin/cliques.
 *
 * O que conta:
 * - qualquer elemento com data-clique="nome legível" (botões das telas);
 * - links do menu lateral (#menu-admin) e do topo (.admin-topo);
 * - abas (role="tab").
 * Só vai o NOME do elemento, a página e o destino do link. Nunca o que foi
 * digitado. Qualquer falha aqui é engolida: o clique segue normal.
 *
 * Marcar um botão novo: <button data-clique="Pedidos: emitir nota">.
 */

const ENDPOINT = '/admin/cliques';
const ESPERA_MS = 4000;
const MAX_ITENS = 50;

let iniciado = false;
let fila = new Map();
let temporizador = null;

const limpar = (texto) => (texto || '').replace(/\s+/g, ' ').trim().slice(0, 80);
// Texto solto do link: sem números (contador de pendências, nº de pedido).
const limparTexto = (texto) => limpar((texto || '').replace(/\d+/g, ' '));

export const normalizarCaminho = (url) => {
    try {
        const caminho = new URL(url, window.location.origin).pathname;
        return caminho
            .split('/')
            .map((parte) => (/^(\d+|(?=.*\d)[A-Za-z0-9_-]{16,})$/.test(parte) ? '{id}' : parte))
            .join('/')
            .replace(/\/$/, '') || '/';
    } catch {
        return null;
    }
};

const lerCookie = (nome) => {
    const achado = document.cookie.split('; ').find((c) => c.startsWith(`${nome}=`));
    return achado ? decodeURIComponent(achado.slice(nome.length + 1)) : '';
};

/** Descobre o que foi clicado; null = clique que não conta. */
const identificar = (alvo) => {
    if (!(alvo instanceof Element)) return null;

    const marcado = alvo.closest('[data-clique]');
    if (marcado) {
        const link = marcado.closest('a[href]');
        const dentroDoMenu = marcado.closest('#menu-admin');
        const dentroDoTopo = marcado.closest('.admin-topo');
        return {
            elemento: limpar(marcado.getAttribute('data-clique')),
            tipo: dentroDoMenu ? 'menu' : dentroDoTopo ? 'topo' : marcado.closest('[role="tab"]') ? 'aba' : 'botao',
            destino: link ? link.getAttribute('href') : null,
        };
    }

    const aba = alvo.closest('[role="tab"]');
    if (aba) {
        return { elemento: `Aba: ${limparTexto(aba.textContent)}`, tipo: 'aba', destino: aba.getAttribute('href') };
    }

    const link = alvo.closest('a[href], button');
    if (!link) return null;
    const menu = link.closest('#menu-admin');
    const topo = link.closest('.admin-topo');
    if (!menu && !topo) return null;
    const nome = limparTexto(link.getAttribute('aria-label') || link.getAttribute('title') || link.textContent);
    if (!nome) return null;

    return { elemento: `${menu ? 'Menu' : 'Topo'}: ${nome}`, tipo: menu ? 'menu' : 'topo', destino: link.getAttribute('href') };
};

const enviar = () => {
    if (temporizador) {
        clearTimeout(temporizador);
        temporizador = null;
    }
    if (fila.size === 0) return;

    const cliques = [...fila.values()].slice(0, MAX_ITENS);
    fila = new Map();

    try {
        fetch(ENDPOINT, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': lerCookie('XSRF-TOKEN'),
            },
            body: JSON.stringify({ cliques }),
        }).catch(() => {});
    } catch {
        /* sem rede ou navegador antigo: perde a contagem, nunca o clique */
    }
};

const registrar = (evento) => {
    try {
        const achado = identificar(evento.target);
        if (!achado || !achado.elemento) return;

        const pagina = normalizarCaminho(window.location.href);
        const destino = achado.destino && !achado.destino.startsWith('#') ? normalizarCaminho(achado.destino) : null;
        const chave = `${achado.elemento}|${pagina}`;
        const atual = fila.get(chave);
        if (atual) {
            atual.quantos += 1;
        } else {
            fila.set(chave, { elemento: achado.elemento, tipo: achado.tipo, pagina, destino, quantos: 1 });
        }

        if (fila.size >= MAX_ITENS) {
            enviar();
        } else if (!temporizador) {
            temporizador = setTimeout(enviar, ESPERA_MS);
        }
    } catch {
        /* idem: o contador nunca atrapalha o painel */
    }
};

export function iniciarContadorCliques() {
    if (iniciado || typeof document === 'undefined') return;
    iniciado = true;
    document.addEventListener('click', registrar, { capture: true, passive: true });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') enviar();
    });
    window.addEventListener('pagehide', enviar);
}
