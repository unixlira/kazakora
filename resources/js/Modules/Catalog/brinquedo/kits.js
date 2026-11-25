// Kits da página de brinquedo (pedido do Lira 24/11/2026): cada variação vira um
// cartão de kit com selo, preço "de/por", preço por brinquedo e economia.
// Nada aqui é inventado: os números saem do preço cadastrado de cada variação.

/** Quantos brinquedos vêm no kit: "Livro Falante + Lousa Mágica LED" = 2. */
export const unidadesDoKit = (variante) => {
    const nome = String(variante?.variation || variante?.name || '');
    const partes = nome.split(/\s\+\s/).map((parte) => parte.trim()).filter(Boolean);
    return Math.max(1, partes.length);
};

export const nomeDoKit = (variante) => String(variante?.variation || variante?.name || '').trim();
/** Nome sem o "(modelo sortido)": o aviso vai na linha de baixo. */
export const nomeCurtoDoKit = (variante) => nomeDoKit(variante).replace(/\s*\(modelo sortido\)\s*$/i, '');

/** "1 livro falante + 1 lousa mágica LED" vira a linha de baixo do cartão. */
export const itensDoKit = (variante) => {
    const n = unidadesDoKit(variante);
    // 3 ou mais brinquedos: o argumento do kit completo (pedido do Lira 24/11/2026, "os 3 deixam a criança sem tela").
    const base = n >= 3 ? `${n} brincadeiras para trocar a tela por diversão` : (n > 1 ? `${n} brinquedos no kit` : '1 brinquedo');
    // Kit com modelo sortido (cor "Sortido" ou "(modelo sortido)" no nome): avisa, sem prometer a cor da foto.
    const sortido = /sortid/i.test(nomeDoKit(variante)) || /sortid/i.test(String(variante?.color ?? ''));
    return sortido ? `${base} · modelo sortido conforme estoque` : base;
};

export const precoDoKit = (variante) => Number(variante?.final_price ?? 0);

/** Preço "de" (riscado): o mesmo que o card da loja usa. */
export const precoDeDoKit = (variante) => {
    if ((variante?.oferta_do_dia || variante?.oferta_relampago) && variante?.preco_sem_oferta) return Number(variante.preco_sem_oferta);
    return variante?.has_discount && variante?.preco_de ? Number(variante.preco_de) : null;
};

export const porUnidade = (variante) => Math.round((precoDoKit(variante) / unidadesDoKit(variante)) * 100) / 100;

export const economia = (variante) => {
    const de = precoDeDoKit(variante);
    return de && de > precoDoKit(variante) ? Math.round((de - precoDoKit(variante)) * 100) / 100 : 0;
};

/** Ordem dos cartões: do mais barato ao mais caro. */
export const ordenarKits = (opcoes) => [...opcoes].sort((a, b) => precoDoKit(a) - precoDoKit(b));

/**
 * Kit que já abre marcado (como na referência): o do meio entre os que têm
 * estoque; com 2 kits, o segundo (o maior). Sem estoque em nenhum, o do meio.
 */
export const kitRecomendado = (opcoes) => {
    const lista = ordenarKits(opcoes);
    if (!lista.length) return null;
    const comEstoque = lista.filter((variante) => Number(variante.stock) > 0);
    const base = comEstoque.length ? comEstoque : lista;
    return base[Math.min(1, base.length - 1)] ?? base[0];
};

/**
 * Selo de cada kit: o mais barato "Para começar", o recomendado "Recomendado" e,
 * com 3 ou mais, o(s) mais caro(s) "Mais completo". Só com 1 kit, nenhum selo.
 */
export const selosDosKits = (opcoes) => {
    const lista = ordenarKits(opcoes);
    const selos = {};
    if (lista.length < 2) return selos;
    const recomendado = kitRecomendado(lista);
    selos[lista[0].id] = { texto: 'Para começar', tom: 'escuro' };
    // Com 3 ou mais, os de maior preço (pode ser mais de um: o mesmo kit em duas cores) são "Mais completo".
    if (lista.length >= 3) {
        const maior = precoDoKit(lista[lista.length - 1]);
        lista.filter((variante) => precoDoKit(variante) === maior && variante.id !== lista[0].id)
            .forEach((variante) => { selos[variante.id] = { texto: 'Mais completo', tom: 'verde' }; });
    }
    if (recomendado) selos[recomendado.id] = { texto: 'Recomendado', tom: 'amarelo', estrela: true };
    return selos;
};

/** Foto do kit: a marcada como da variação, senão a principal. */
export const fotoDoKit = (variante) => {
    const imagens = variante?.images ?? [];
    const foto = imagens.find((i) => i.is_variant) ?? imagens.find((i) => i.is_primary) ?? imagens[0];
    return foto ? (foto.media_url ?? foto.url) : null;
};

/** Título com a(s) última(s) palavra(s) num marcador colorido, sem o emoji do fim. */
export const tituloComMarcador = (titulo) => {
    const limpo = String(titulo ?? '').replace(/[\p{Extended_Pictographic}\u{FE0F}\u{200D}]/gu, '').replace(/\s+/g, ' ').trim();
    // "Livro comum × Livro Falante": marca o lado do produto, depois do "×".
    const vs = limpo.split(/\s[×x]\s/);
    if (vs.length === 2) return { antes: `${vs[0]} ×`, marcado: vs[1] };
    const palavras = limpo.split(' ');
    if (palavras.length < 3) return { antes: '', marcado: limpo };
    const n = palavras.length > 6 ? 2 : 1;
    return { antes: palavras.slice(0, -n).join(' '), marcado: palavras.slice(-n).join(' ') };
};

export const semEmoji = (texto) => String(texto ?? '').replace(/[\p{Extended_Pictographic}\u{FE0F}\u{200D}]/gu, '').replace(/\s+/g, ' ').trim();
