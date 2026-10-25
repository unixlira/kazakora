// Regra de frete por lugar (cidade em 2026-10-24, distrito em 2026-10-25):
// tipos, texto da regra e chamadas JSON das janelas de Fretes por estado.

export const TIPOS = [
    { chave: 'gratis', rotulo: 'Frete grátis' },
    { chave: 'fixo', rotulo: 'Valor fixo' },
    { chave: 'acrescimo', rotulo: 'Correios + valor' },
    { chave: 'desconto', rotulo: 'Correios − valor' },
    { chave: 'teto', rotulo: 'Correios até no máximo' },
];

export const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);

export const normalizar = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export const formatarCep = (cep) => (cep && cep.length === 8 ? `${cep.slice(0, 5)}-${cep.slice(5)}` : cep || '');

export function descrever(regra, semRegra = 'Correios normal') {
    if (!regra) return semRegra;
    const v = dinheiro(regra.valor);
    const texto = {
        fixo: regra.valor === 0 ? 'Frete grátis' : `Frete fixo de ${v}`,
        acrescimo: `Correios + ${v}`,
        desconto: `Correios − ${v}`,
        teto: `Correios até ${v}`,
    }[regra.tipo_preco] ?? regra.tipo_preco;

    return regra.prazo_dias ? `${texto} · ${regra.prazo_dias} ${regra.prazo_dias === 1 ? 'dia útil' : 'dias úteis'}` : texto;
}

/** Campos do formulário a partir da regra salva (ou vazios). */
export function camposDaRegra(regra) {
    return {
        tipo: !regra ? 'gratis' : regra.tipo_preco === 'fixo' && regra.valor === 0 ? 'gratis' : regra.tipo_preco,
        valor: regra?.valor ?? 0,
        prazo_dias: regra?.prazo_dias ?? '',
    };
}

/** Corpo do PUT a partir do formulário. */
export function corpoDaRegra(form) {
    const gratis = form.tipo === 'gratis';

    return {
        tipo_preco: gratis ? 'fixo' : form.tipo,
        valor: gratis ? 0 : Number(form.valor || 0),
        prazo_dias: form.prazo_dias === '' || form.prazo_dias === null ? null : Number(form.prazo_dias),
        ativo: true,
    };
}

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

export async function pedir(url, metodo = 'GET', corpo = null) {
    const r = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        body: corpo ? JSON.stringify(corpo) : null,
    });
    const dados = await r.json().catch(() => ({}));
    if (r.status === 422) throw new Error(Object.values(dados.errors ?? {}).flat().join(' ') || 'Regra inválida.');
    if (!r.ok) throw new Error('Não deu certo agora. Tente de novo em instantes.');

    return dados;
}
