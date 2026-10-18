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
};

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
