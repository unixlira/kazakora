// Meta ADS (2026-10-13): formatação, cores e chamadas compartilhadas pelas telas.

export const formatarReais = (valor) =>
    valor === null || valor === undefined
        ? '-'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);

export const formatarData = (data) => (data ? new Date(`${String(data).slice(0, 10)}T00:00:00`).toLocaleDateString('pt-BR') : '-');

export const formatarNumero = (valor) => (valor === null || valor === undefined ? '-' : new Intl.NumberFormat('pt-BR').format(valor));

export const STATUS_ESTILOS = {
    rascunho: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    enviando: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    enviada_pausada: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    ativa: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    erro: 'bg-red-500/15 text-red-700 dark:text-red-300',
    gerenciador: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
};

// effective_status da Meta => [rótulo, cores] (espelho e modais de anúncio).
export const STATUS_META = {
    ACTIVE: ['Ativo', 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'],
    PAUSED: ['Pausado', 'bg-slate-500/15 text-slate-600 dark:text-slate-300'],
    CAMPAIGN_PAUSED: ['Campanha pausada', 'bg-slate-500/15 text-slate-600 dark:text-slate-300'],
    ADSET_PAUSED: ['Conjunto pausado', 'bg-slate-500/15 text-slate-600 dark:text-slate-300'],
    IN_PROCESS: ['Em processamento', 'bg-sky-500/15 text-sky-700 dark:text-sky-300'],
    PENDING_REVIEW: ['Em análise', 'bg-sky-500/15 text-sky-700 dark:text-sky-300'],
    WITH_ISSUES: ['Com problema', 'bg-red-500/15 text-red-700 dark:text-red-300'],
    DISAPPROVED: ['Reprovado', 'bg-red-500/15 text-red-700 dark:text-red-300'],
    ARCHIVED: ['Arquivado', 'bg-slate-500/15 text-slate-500'],
};

export const statusMeta = (s) => STATUS_META[s] ?? [s || '-', 'bg-slate-500/15 text-slate-600 dark:text-slate-300'];

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const erroDaResposta = (status, corpo) => {
    const erro = new Error(
        corpo?.errors ? Object.values(corpo.errors)[0]?.[0] : (corpo?.message || (status === 413 ? 'Arquivo grande demais para o servidor.' : `Erro ${status} no servidor.`)),
    );
    erro.erros = corpo?.errors ?? null;
    return erro;
};

/** fetch JSON com o token de sessão do Laravel; lança Error com .erros (validação). */
export const pedirJson = async (metodo, url, dados = null) => {
    const resposta = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: dados ? JSON.stringify(dados) : undefined,
    });
    const corpo = await resposta.json().catch(() => null);
    if (!resposta.ok) throw erroDaResposta(resposta.status, corpo);
    return corpo;
};

/** Sobe um arquivo com barra de progresso (fetch não mostra progresso de envio). */
export const enviarArquivo = (url, campo, arquivo, aoProgredir, extras = {}) => new Promise((resolve, reject) => {
    const dados = new FormData();
    dados.append(campo, arquivo);
    Object.entries(extras).forEach(([chave, valor]) => dados.append(chave, valor ?? ''));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-XSRF-TOKEN', xsrf());
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) aoProgredir?.(Math.round((e.loaded / e.total) * 100));
    };
    xhr.onload = () => {
        let corpo = null;
        try { corpo = JSON.parse(xhr.responseText); } catch { /* resposta sem JSON (ex.: 413 do nginx) */ }
        if (xhr.status >= 200 && xhr.status < 300) resolve(corpo);
        else reject(erroDaResposta(xhr.status, corpo));
    };
    xhr.onerror = () => reject(new Error('A conexão caiu durante o envio. Tente de novo.'));
    xhr.send(dados);
});

// "O que fazer agora" (2026-10-21): prioridade => [rótulo, selo, faixa à esquerda].
export const PRIORIDADES = {
    urgente: ['Urgente', 'bg-red-500/15 text-red-700 dark:text-red-300', 'bg-red-500'],
    atencao: ['Atenção', 'bg-amber-500/15 text-amber-800 dark:text-amber-300', 'bg-amber-500'],
    oportunidade: ['Oportunidade', 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', 'bg-emerald-500'],
    aviso: ['Aviso', 'bg-primary/15 text-primary', 'bg-primary'],
};

/**
 * Coluna "Botão (CTA)": { texto, dica, mono, alerta, vazio } a partir do
 * cta do espelho (null = sem dado; codigos vazio = sem botão).
 */
export const ctaDoAnuncio = (cta) => {
    if (!cta) return { texto: '-', dica: 'Sem dado do botão', mono: false, alerta: false, vazio: true };
    const rotulo = (c) => cta.rotulos?.[c] ?? c;
    const alerta = Boolean(cta.desalinhado);
    if (cta.sem_botao) return { texto: 'Sem botão', dica: cta.codigos.join(', ') || 'NO_BUTTON', mono: false, alerta, vazio: true };
    if (cta.codigos.length > 1) {
        return { texto: `Vários (${cta.codigos.length})`, dica: cta.codigos.map((c) => `${rotulo(c)} (${c})`).join(', '), mono: false, alerta, vazio: false };
    }
    const codigo = cta.codigos[0];
    const conhecido = Boolean(cta.rotulos?.[codigo]);
    return {
        texto: conhecido ? `${rotulo(codigo)}${cta.dinamico ? ' · dinâmico' : ''}` : codigo,
        dica: codigo,
        mono: !conhecido,
        alerta,
        vazio: false,
    };
};
