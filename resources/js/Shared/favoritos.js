// Favoritos da loja (pedido do Lira 2026-10-15, mesmo esquema da Minha Bancada
// do FerraKora): sem login a lista fica só neste navegador (localStorage); ao
// entrar, o que estava aqui se junta à conta e o navegador esvazia, para o
// próximo de um computador dividido não ver. Com login, a conta manda: o
// AppLayout passa os ids compartilhados (`favorites.ids`) para cá.
import { reactive } from 'vue';
import Swal from 'sweetalert2';

const CHAVE = 'kazakora.favoritos';
const LIMITE = 200;

const estado = reactive({ ids: [], logado: false });

function lerGuardado() {
    try {
        const salvo = JSON.parse(window.localStorage.getItem(CHAVE) || '[]');
        return Array.isArray(salvo) ? salvo.map(Number).filter((id) => id > 0).slice(0, LIMITE) : [];
    } catch {
        return [];
    }
}

function guardarLocal(ids) {
    try {
        if (ids.length) window.localStorage.setItem(CHAVE, JSON.stringify(ids));
        else window.localStorage.removeItem(CHAVE);
    } catch {
        // aba anônima sem localStorage: vale só até recarregar
    }
}

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

async function chamar(url, corpo) {
    const resposta = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(corpo ?? {}),
    });
    if (!resposta.ok) throw new Error('Não deu para salvar o favorito agora. Tente de novo.');
    return resposta.json();
}

let sincronizando = null;

/** Chamado pelo AppLayout a cada página: quem está logado e o que a conta tem. */
export function atualizarFavoritos(logado, idsDaConta = []) {
    estado.logado = Boolean(logado);
    if (typeof window === 'undefined') return;

    if (!estado.logado) {
        estado.ids = lerGuardado();
        return;
    }

    const locais = lerGuardado();
    if (!locais.length) {
        if (!sincronizando) estado.ids = [...idsDaConta];
        return;
    }

    estado.ids = [...new Set([...locais, ...idsDaConta])];
    sincronizando ??= chamar('/favoritos/sincronizar', { produtos: locais })
        .then((data) => {
            guardarLocal([]);
            estado.ids = data.ids ?? estado.ids;
        })
        .catch(() => {})
        .finally(() => {
            sincronizando = null;
        });
}

export const ehFavorito = (productId) => estado.ids.includes(productId);
export const totalFavoritos = () => estado.ids.length;
export const idsFavoritos = () => estado.ids;

// Aviso de fundo laranja e letra vermelha ao adicionar e ao tirar (pedido
// 2026-10-15). Laranja claro (#feab86) com vermelho #991b1b dá 4,6:1.
function avisar(titulo, tirou) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: tirou ? 'info' : 'success',
        iconColor: '#991b1b',
        title: titulo,
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true,
        customClass: { popup: 'kz-aviso-favorito' },
    });
}

/** Põe ou tira o produto dos favoritos. Muda na hora; se a conta recusar, volta. */
export async function alternarFavorito(productId) {
    const antes = estado.ids;
    const tirar = antes.includes(productId);

    if (!tirar && antes.length >= LIMITE) {
        avisar(`Você chegou ao limite de ${LIMITE} favoritos. Tire algum para guardar outro.`, true);
        return;
    }

    estado.ids = tirar ? antes.filter((id) => id !== productId) : [productId, ...antes];
    avisar(tirar ? 'Removido dos favoritos' : 'Adicionado aos favoritos', tirar);

    if (!estado.logado) {
        guardarLocal(estado.ids);
        return;
    }

    try {
        const data = await chamar(`/favoritos/${productId}`);
        estado.ids = data.ids ?? estado.ids;
    } catch (erro) {
        estado.ids = antes;
        Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: erro.message, showConfirmButton: false, timer: 5000 });
    }
}
