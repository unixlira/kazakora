// Kits da página de brinquedo (pedido do Lira 24/11/2026): cada variação vira um
// cartão de kit com selo, preço "de/por", preço por brinquedo e economia.
// Nada aqui é inventado: os números saem do preço cadastrado de cada variação.
// Desde 25/11/2026 o conteúdo da página (pagina_brinquedo.kits) pode dar a cada kit um nome de oferta
// (kit_nome), o que vem nele (kit_itens), quantos brinquedos (kit_unidades) e as partes com ícone
// (kit_partes); ver aplicarKits.

/** Quantos brinquedos vêm no kit: "Livro Falante + Lousa Mágica LED" = 2 (conta a composição). */
export const unidadesDoKit = (variante) => {
    if (Number(variante?.kit_unidades) > 0) return Number(variante.kit_unidades);
    const nome = composicaoDoKit(variante);
    const partes = nome.split(/\s\+\s/).map((parte) => parte.trim()).filter(Boolean);
    return Math.max(1, partes.length);
};

export const nomeDoKit = (variante) => String(variante?.variation || variante?.name || '').trim();
/**
 * Variação no formato "Kit Sem Telas (Livro + Lousa + Quebra-Cabeça)" (pedido do Lira 24/11/2026):
 * o nome do kit é a parte de fora dos parênteses e o que vem no kit, a de dentro. Sem parênteses,
 * o nome inteiro serve para os dois (e "(modelo sortido)" no fim não conta como composição).
 */
const PARENTESES = /\s*\(([^()]*)\)\s*$/;
export const nomeCurtoDoKit = (variante) => {
    if (variante?.kit_nome) return variante.kit_nome;
    const nome = nomeDoKit(variante).replace(/\s*\(modelo sortido\)\s*$/i, '');
    return nome.replace(PARENTESES, '').trim() || nome;
};
export function composicaoDoKit(variante) {
    if (variante?.kit_itens) return variante.kit_itens;
    const nome = nomeDoKit(variante).replace(/\s*\(modelo sortido\)\s*$/i, '');
    const m = nome.match(PARENTESES);
    return (m ? m[1] : nome).trim();
}

/** "1 livro falante + 1 lousa mágica LED" vira a linha de baixo do cartão. */
export const itensDoKit = (variante) => {
    const n = unidadesDoKit(variante);
    // Linha de baixo do cartão: o que vem no kit (como "200 peças + 10 bonecos" na referência).
    const composicao = composicaoDoKit(variante);
    const base = composicao !== nomeCurtoDoKit(variante) ? composicao : (n > 1 ? `${n} brinquedos no kit` : '1 brinquedo');
    // Kit com modelo sortido (cor "Sortido" ou "(modelo sortido)" no nome): avisa, sem prometer a cor da foto.
    const sortido = /sortid/i.test(nomeDoKit(variante)) || /sortid/i.test(String(variante?.color ?? ''));
    return sortido ? `${base} · modelo sortido` : base;
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
const imagemDoKit = (variante) => {
    const imagens = variante?.images ?? [];
    return imagens.find((i) => i.is_variant) ?? imagens.find((i) => i.is_primary) ?? imagens[0] ?? null;
};
export const fotoDoKit = (variante) => {
    const foto = imagemDoKit(variante);
    return foto ? (foto.media_url ?? foto.url) : null;
};
/** srcset da foto do kit (512 e 1200 px) para o navegador escolher pela largura. */
export const srcsetDoKit = (variante) => {
    const foto = imagemDoKit(variante);
    return foto?.thumb_url && foto.media_url && foto.thumb_url !== foto.media_url ? `${foto.thumb_url} 512w, ${foto.media_url} 1200w` : null;
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

/** Argumento do kit com 3 ou mais brinquedos (pedido do Lira: "os 3 deixam a criança sem tela"). */
export const argumentoDoKit = (variante) => (unidadesDoKit(variante) >= 3 ? `${unidadesDoKit(variante)} brincadeiras para trocar a tela por diversão` : '');

/**
 * Junta às opções (variações ou o produto sozinho) o nome de oferta, os itens e as partes de cada kit do
 * conteúdo da página (pagina_brinquedo.kits.itens, na ordem do mais barato ao mais caro). Se o número de
 * kits do conteúdo não bate com o de opções (variação nova ou desligada), fica o nome cadastrado.
 */
export const aplicarKits = (opcoes, conteudo) => {
    const itens = Array.isArray(conteudo?.itens) ? conteudo.itens : [];
    const ordem = ordenarKits(opcoes);
    if (!itens.length || itens.length !== ordem.length) return opcoes;
    // Cada kit pode dizer o id do produto/variação ("id"); se todos batem, vale o id; senão, a ordem de preço.
    const ids = new Set(ordem.map((opcao) => opcao.id));
    const peloId = itens.every((kit) => ids.has(Number(kit?.id)));
    const porId = new Map(peloId ? itens.map((kit) => [Number(kit.id), kit]) : ordem.map((opcao, i) => [opcao.id, itens[i]]));
    return opcoes.map((opcao) => {
        const kit = porId.get(opcao.id) ?? {};
        return {
            ...opcao,
            kit_nome: semEmoji(kit.nome ?? '') || null,
            kit_itens: semEmoji(kit.itens ?? '') || null,
            kit_unidades: Number(kit.unidades) > 0 ? Number(kit.unidades) : null,
            kit_partes: Array.isArray(kit.partes) ? kit.partes.filter((p) => p?.texto).slice(0, 4) : null,
        };
    });
};
