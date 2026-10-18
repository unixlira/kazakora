<script setup>
// Meta ADS (2026-10-18): a campanha inteira numa tela, como no Gerenciador
// da Meta: Campanha > conjuntos (cartões que abrem e fecham) > anúncios.
// Cada arquivo solto num conjunto vira um anúncio. Desenho do Jonathan
// (copy/UX) para o cenário do Lira: Black November com Árvore de Natal (3),
// Ferramentas (2) e Caixa de Ferramentas (2).
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import BuscaLocal from './BuscaLocal.vue';
import { enviarArquivo, formatarReais, pedirJson } from './metaAds';

const props = defineProps({
    campanha: { type: Object, default: null },
    conjuntos: { type: Array, default: () => [] },
    videos: { type: Array, default: () => [] },
    limites: { type: Object, required: true },
    produtos: { type: Array, default: () => [] },
    config: { type: Object, required: true },
    objetivos: { type: Object, required: true },
    generos: { type: Object, required: true },
    chamadas: { type: Object, required: true },
    orcamentoTipos: { type: Object, required: true },
    siteUrl: { type: String, required: true },
});

// Vira "editando" assim que o rascunho é criado, mesmo se um arquivo falhar
// e a pessoa tentar de novo sem sair da tela.
const campanhaId = ref(props.campanha?.id ?? null);
const editando = computed(() => campanhaId.value !== null);
const BASE = '/admin/publicidade/meta-ads/campanhas';
const hoje = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const c = props.campanha ?? {};
const MINIMO = props.limites.minimo_por_conjunto;

const form = useForm({
    nome: c.nome ?? '',
    objetivo: c.objetivo ?? (props.config.pixel ? 'OUTCOME_SALES' : 'OUTCOME_TRAFFIC'),
    orcamento_tipo: c.orcamento_tipo ?? 'campanha',
    orcamento_diario: c.orcamento_diario !== undefined ? Number(c.orcamento_diario) : 30,
    inicio: c.inicio ?? hoje,
    fim: c.fim ?? '',
    idade_min: c.idade_min ?? 18,
    idade_max: c.idade_max ?? 65,
    genero: c.genero ?? 'todos',
    localizacoes: {
        regioes: [...(c.localizacoes?.regioes ?? [])],
        cidades: [...(c.localizacoes?.cidades ?? [])],
    },
    produto_id: c.produto_id ?? '',
    link_destino: c.link_destino ?? '',
    texto_principal: c.texto_principal ?? '',
    titulo: c.titulo ?? '',
    descricao: c.descricao ?? '',
    chamada: c.chamada ?? 'SHOP_NOW',
});

const porConjunto = computed(() => form.orcamento_tipo === 'conjunto');

// Estrutura -------------------------------------------------------------------

const CAMPOS_ANUNCIO = ['produto_id', 'link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada'];
const camposDe = (v = {}) => Object.fromEntries(CAMPOS_ANUNCIO.map((k) => [k, v[k] ?? '']));
let sequencia = 0;
const novaChave = (prefixo) => `${prefixo}${Date.now().toString(36)}${(sequencia++).toString(36)}`;
const TIPOS = { 'video/mp4': 'video', 'video/quicktime': 'video', 'image/jpeg': 'imagem', 'image/png': 'imagem' };
const maxBytes = props.limites.max_mb * 1024 * 1024;
const semExtensao = (nome) => String(nome ?? '').replace(/\.[^.]+$/, '');

const anuncioSalvo = (v) => ({
    chave: `s${v.id}`, id: v.id, origemId: null, arquivo: null, tipo: v.tipo, nome: v.nome, previa: v.url,
    tamanho: `${v.tamanho_mb} MB`, campos: camposDe(v), estado: 'salvo', progresso: 0, erro: '', aberto: false,
});

const conjuntoVazio = (nome, extra = {}) => ({
    chave: novaChave('c'), id: null, nome, orcamento_diario: MINIMO * 2, publico_proprio: false,
    idade_min: form.idade_min, idade_max: form.idade_max, genero: form.genero,
    localizacoes: { regioes: [], cidades: [] }, anuncios: [], aberto: true, erros: {}, ...extra,
});

const conjuntos = ref(props.conjuntos.length
    ? props.conjuntos.map((cj) => ({
        chave: `c${cj.id}`, id: cj.id, nome: cj.nome,
        orcamento_diario: cj.orcamento_diario ?? MINIMO * 2,
        publico_proprio: cj.publico_proprio,
        idade_min: cj.idade_min ?? form.idade_min, idade_max: cj.idade_max ?? form.idade_max, genero: cj.genero ?? form.genero,
        localizacoes: { regioes: [...(cj.localizacoes?.regioes ?? [])], cidades: [...(cj.localizacoes?.cidades ?? [])] },
        anuncios: props.videos.filter((v) => v.conjunto_id === cj.id).map(anuncioSalvo),
        aberto: props.conjuntos.length === 1, erros: {},
    }))
    : [conjuntoVazio('Conjunto 1')]);

const todosAnuncios = computed(() => conjuntos.value.flatMap((cj) => cj.anuncios.map((a) => ({ a, cj }))));
const totalAnuncios = computed(() => todosAnuncios.value.length);
const aviso = ref('');

const adicionarConjunto = async () => {
    if (conjuntos.value.length >= props.limites.max_conjuntos) {
        aviso.value = `Limite de ${props.limites.max_conjuntos} conjuntos por campanha.`;
        return;
    }
    const novo = conjuntoVazio(`Conjunto ${conjuntos.value.length + 1}`);
    conjuntos.value.push(novo);
    await nextTick();
    document.getElementById(`nome-${novo.chave}`)?.focus();
};

const copiaDoAnuncio = (a) => ({
    ...a,
    chave: novaChave('a'),
    id: null,
    // Já guardado: o servidor copia o arquivo. Ainda não guardado: sobe o mesmo arquivo.
    origemId: a.id ?? a.origemId,
    previa: a.arquivo ? URL.createObjectURL(a.arquivo) : a.previa,
    campos: { ...a.campos },
    estado: 'esperando',
    progresso: 0,
    erro: '',
    aberto: false,
});

const vagas = () => props.limites.max_videos - totalAnuncios.value;

const duplicarConjunto = (i) => {
    if (conjuntos.value.length >= props.limites.max_conjuntos) {
        aviso.value = `Limite de ${props.limites.max_conjuntos} conjuntos por campanha.`;
        return;
    }
    const original = conjuntos.value[i];
    if (original.anuncios.length > vagas()) {
        aviso.value = `Limite de ${props.limites.max_videos} anúncios por campanha. Não cabe a cópia de "${original.nome}".`;
        return;
    }
    conjuntos.value.splice(i + 1, 0, {
        ...original,
        chave: novaChave('c'),
        id: null,
        nome: `${original.nome} (cópia)`.slice(0, 120),
        localizacoes: { regioes: [...original.localizacoes.regioes], cidades: original.localizacoes.cidades.map((x) => ({ ...x })) },
        anuncios: original.anuncios.map(copiaDoAnuncio),
        aberto: true,
        erros: {},
    });
};

const moverConjunto = (i, delta) => {
    const j = i + delta;
    if (j < 0 || j >= conjuntos.value.length) return;
    const lista = conjuntos.value;
    [lista[i], lista[j]] = [lista[j], lista[i]];
};

// Remover não pergunta: mostra "Desfazer" por 8 segundos.
const desfazer = ref(null);
let timerDesfazer = null;
const oferecerDesfazer = (mensagem, restaurar) => {
    clearTimeout(timerDesfazer);
    desfazer.value = { mensagem, restaurar };
    timerDesfazer = setTimeout(() => { desfazer.value = null; }, 8000);
};
const aoDesfazer = () => {
    desfazer.value?.restaurar();
    desfazer.value = null;
};

const removerConjunto = (i) => {
    const [tirado] = conjuntos.value.splice(i, 1);
    if (!conjuntos.value.length) conjuntos.value.push(conjuntoVazio('Conjunto 1'));
    const n = tirado.anuncios.length;
    oferecerDesfazer(`Conjunto "${tirado.nome}"${n ? ` e ${n} anúncio${n > 1 ? 's' : ''}` : ''} removido${n ? 's' : ''}.`, () => {
        if (conjuntos.value.length === 1 && !conjuntos.value[0].id && !conjuntos.value[0].anuncios.length) conjuntos.value = [];
        conjuntos.value.splice(Math.min(i, conjuntos.value.length), 0, tirado);
    });
};

const adicionarArquivos = (cj, lista) => {
    aviso.value = '';
    const arquivos = Array.from(lista ?? []);
    let foraDoLimite = 0;
    for (const arquivo of arquivos) {
        if (!TIPOS[arquivo.type]) {
            aviso.value = `"${arquivo.name}" não foi aceito. Use MP4, MOV, JPG ou PNG.`;
            continue;
        }
        if (arquivo.size > maxBytes) {
            aviso.value = `"${arquivo.name}" passa de ${props.limites.max_mb} MB. Comprima antes de enviar.`;
            continue;
        }
        if (vagas() <= 0) {
            foraDoLimite++;
            continue;
        }
        const novo = {
            chave: novaChave('a'), id: null, origemId: null, arquivo, tipo: TIPOS[arquivo.type], nome: arquivo.name,
            previa: URL.createObjectURL(arquivo), tamanho: `${(arquivo.size / 1048576).toFixed(1)} MB`,
            campos: camposDe(), estado: 'esperando', progresso: 0, erro: '', aberto: false,
        };
        cj.anuncios.push(novo);
        emPrevia.value = novo.chave;
    }
    if (foraDoLimite) {
        aviso.value = `Limite de ${props.limites.max_videos} anúncios por campanha. ${foraDoLimite} arquivo${foraDoLimite > 1 ? 's ficaram' : ' ficou'} de fora.`;
    }
    cj.aberto = true;
};

const escolherArquivos = (cj) => document.getElementById(`arquivos-${cj.chave}`)?.click();

const arrastandoEm = ref(null);
const aoSoltar = (cj, evento) => {
    arrastandoEm.value = null;
    adicionarArquivos(cj, evento.dataTransfer?.files);
};

const duplicarAnuncio = (cj, i) => {
    if (vagas() <= 0) {
        aviso.value = `Limite de ${props.limites.max_videos} anúncios por campanha.`;
        return;
    }
    cj.anuncios.splice(i + 1, 0, copiaDoAnuncio(cj.anuncios[i]));
};

const removerAnuncio = (cj, i) => {
    const [tirado] = cj.anuncios.splice(i, 1);
    oferecerDesfazer('Anúncio removido.', () => cj.anuncios.splice(Math.min(i, cj.anuncios.length), 0, tirado));
};

const aoEscolherProduto = () => {
    const p = props.produtos.find((x) => x.id === Number(form.produto_id));
    if (!p) return;
    form.link_destino = p.link;
    if (!form.titulo) form.titulo = p.nome.slice(0, 255);
};

const aoEscolherProdutoDoAnuncio = (a) => {
    const p = props.produtos.find((x) => x.id === Number(a.campos.produto_id));
    if (!p) return;
    a.campos.link_destino = p.link;
    if (!a.campos.titulo) a.campos.titulo = p.nome.slice(0, 255);
};

// Público próprio: ao ligar, começa com uma cópia do público da campanha.
const alternarPublico = (cj, usarDaCampanha) => {
    cj.publico_proprio = !usarDaCampanha;
    if (cj.publico_proprio) {
        cj.idade_min = form.idade_min;
        cj.idade_max = form.idade_max;
        cj.genero = form.genero;
        cj.localizacoes = { regioes: [...form.localizacoes.regioes], cidades: form.localizacoes.cidades.map((x) => ({ ...x })) };
    }
};

onBeforeUnmount(() => {
    clearTimeout(timerDesfazer);
    todosAnuncios.value.forEach(({ a }) => { if (a.arquivo) URL.revokeObjectURL(a.previa); });
});

// Herança e pendências --------------------------------------------------------

const preenchido = (v) => v !== null && v !== undefined && String(v).trim() !== '';
const ou = (proprio, padrao) => (preenchido(proprio) ? proprio : padrao);

const pendenciasDoAnuncio = (a) => {
    const lista = [];
    if (!preenchido(ou(a.campos.texto_principal, form.texto_principal))) lista.push('Escreva o texto aqui ou nos padrões da campanha.');
    if (!preenchido(ou(a.campos.titulo, form.titulo))) lista.push('Escreva o título aqui ou nos padrões da campanha.');
    if (a.estado === 'erro') lista.push(a.erro || 'O arquivo não subiu.');
    return lista;
};

const pendenciasDoConjunto = (cj) => {
    const lista = [];
    if (!preenchido(cj.nome)) lista.push('Dê um nome para o conjunto.');
    if (!cj.anuncios.length) lista.push('Este conjunto não tem anúncio. Adicione um ou remova o conjunto.');
    if (porConjunto.value && Number(cj.orcamento_diario || 0) < MINIMO) lista.push(`Mínimo de ${formatarReais(MINIMO)} por dia neste conjunto.`);
    return lista;
};

const orcamentoDiario = computed(() => (porConjunto.value
    ? conjuntos.value.reduce((soma, cj) => soma + Number(cj.orcamento_diario || 0), 0)
    : Number(form.orcamento_diario || 0)));

const minimoDaCampanha = computed(() => MINIMO * Math.max(conjuntos.value.length, 1));

const pendenciasDaCampanha = computed(() => {
    const lista = [];
    if (!preenchido(form.nome)) lista.push('Dê um nome para a campanha.');
    if (form.fim && form.inicio && form.fim < form.inicio) lista.push('A data de fim tem que ser depois do início.');
    if (!porConjunto.value && orcamentoDiario.value < minimoDaCampanha.value) {
        lista.push(conjuntos.value.length > 1
            ? `Com ${conjuntos.value.length} conjuntos, o mínimo é ${formatarReais(minimoDaCampanha.value)} por dia (${formatarReais(MINIMO)} por conjunto).`
            : `O mínimo é ${formatarReais(MINIMO)} por dia.`);
    }
    return lista;
});

// Para o resumo: cada pendência aponta para onde resolver.
const pendencias = computed(() => [
    ...pendenciasDaCampanha.value.map((texto) => ({ texto, alvo: 'campanha' })),
    ...conjuntos.value.flatMap((cj) => [
        ...pendenciasDoConjunto(cj).map((texto) => ({ texto, alvo: `conjunto-${cj.chave}`, cj })),
        ...cj.anuncios.flatMap((a) => pendenciasDoAnuncio(a).map((texto) => ({ texto, alvo: `anuncio-${a.chave}`, cj, a }))),
    ]),
]);

const irPara = async (p) => {
    if (p.cj) p.cj.aberto = true;
    if (p.a) {
        p.a.aberto = true;
        emPrevia.value = p.a.chave;
    }
    await nextTick();
    document.getElementById(p.alvo)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

// Resumo e prévia --------------------------------------------------------------

const dias = computed(() => {
    if (!form.fim || !form.inicio) return null;
    const ms = new Date(`${form.fim}T00:00:00`) - new Date(`${form.inicio}T00:00:00`);
    return ms >= 0 ? Math.round(ms / 86400000) + 1 : null;
});
const totalPrevisto = computed(() => (dias.value ? dias.value * orcamentoDiario.value : null));

const emPrevia = ref(null);
const indiceEmPrevia = computed(() => {
    const i = todosAnuncios.value.findIndex(({ a }) => a.chave === emPrevia.value);
    return i >= 0 ? i : 0;
});
const itemEmPrevia = computed(() => todosAnuncios.value[indiceEmPrevia.value] ?? null);
const navegarPrevia = (delta) => {
    const n = todosAnuncios.value.length;
    if (!n) return;
    emPrevia.value = todosAnuncios.value[(indiceEmPrevia.value + delta + n) % n].a.chave;
};
const previa = computed(() => {
    const a = itemEmPrevia.value?.a;
    const campos = a?.campos ?? {};
    return {
        midia: a?.previa ?? null,
        tipo: a?.tipo ?? 'video',
        texto: ou(campos.texto_principal, form.texto_principal),
        titulo: ou(campos.titulo, form.titulo),
        descricao: ou(campos.descricao, form.descricao),
        chamada: ou(campos.chamada, form.chamada),
    };
});

const verAnuncio = async (cj, a) => {
    cj.aberto = true;
    a.aberto = true;
    emPrevia.value = a.chave;
    await nextTick();
    document.getElementById(`anuncio-${a.chave}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

const padroesAbertos = ref(!preenchido(c.texto_principal));
const resumoPadroes = computed(() => {
    const local = form.localizacoes.regioes.length + form.localizacoes.cidades.length;
    return `${form.idade_min} a ${form.idade_max} anos, ${props.generos[form.genero]?.toLowerCase()}, ${local ? `${local} local(is)` : 'Brasil inteiro'} · botão ${props.chamadas[form.chamada]}`;
});

// Salvar ----------------------------------------------------------------------
// 1) campanha, conjuntos e texto dos anúncios já guardados num pedido só;
// 2) arquivos novos (ou cópias) um por um, com barra de progresso.
// Nada disso fala com a Meta.

const salvando = ref(false);
const salvoAs = ref('');
const erroGeral = ref('');

const salvar = async (depois = 'ficar') => {
    if (salvando.value) return;
    salvando.value = true;
    form.clearErrors();
    erroGeral.value = '';
    desfazer.value = null;
    conjuntos.value.forEach((cj) => { cj.erros = {}; });

    let ordem = 0;
    const comOrdem = conjuntos.value.flatMap((cj) => cj.anuncios.map((a) => ({ a, cj, ordem: ordem++ })));
    const guardados = comOrdem.filter(({ a }) => a.id);
    guardados.forEach(({ a }) => { a.erro = ''; });

    const dados = {
        ...form.data(),
        orcamento_diario: porConjunto.value ? null : form.orcamento_diario,
        conjuntos: conjuntos.value.map((cj) => ({
            id: cj.id, chave: cj.chave, nome: cj.nome,
            orcamento_diario: porConjunto.value ? cj.orcamento_diario : null,
            publico_proprio: cj.publico_proprio,
            ...(cj.publico_proprio ? { idade_min: cj.idade_min, idade_max: cj.idade_max, genero: cj.genero, localizacoes: cj.localizacoes } : {}),
        })),
        anuncios: guardados.map(({ a, cj, ordem: o }) => ({ id: a.id, conjunto: cj.chave, ordem: o, ...a.campos })),
    };

    let mapa;
    try {
        const resposta = await pedirJson(editando.value ? 'PUT' : 'POST', editando.value ? `${BASE}/${campanhaId.value}` : BASE, dados);
        campanhaId.value = resposta.id;
        mapa = resposta.conjuntos ?? {};
    } catch (erro) {
        if (erro.erros) {
            const daCampanha = {};
            for (const [chave, mensagens] of Object.entries(erro.erros)) {
                const doConjunto = chave.match(/^conjuntos\.(\d+)\.(.+)$/);
                const doAnuncio = chave.match(/^anuncios\.(\d+)\./);
                if (doConjunto && conjuntos.value[Number(doConjunto[1])]) {
                    const cj = conjuntos.value[Number(doConjunto[1])];
                    cj.erros[doConjunto[2]] = mensagens[0];
                    cj.aberto = true;
                } else if (doAnuncio && guardados[Number(doAnuncio[1])]) {
                    const { a, cj } = guardados[Number(doAnuncio[1])];
                    a.erro = mensagens[0];
                    a.aberto = true;
                    cj.aberto = true;
                } else {
                    daCampanha[chave] = mensagens[0];
                }
            }
            form.setError(daCampanha);
            erroGeral.value = 'Nada foi salvo. Corrija o que está em vermelho e salve de novo.';
        } else {
            erroGeral.value = erro.message;
        }
        salvando.value = false;
        return;
    }

    conjuntos.value.forEach((cj) => { if (mapa[cj.chave]) cj.id = mapa[cj.chave]; });

    let falhou = false;
    for (const { a, cj, ordem: o } of comOrdem.filter(({ a }) => !a.id)) {
        a.estado = 'subindo';
        a.erro = '';
        try {
            const extras = { ...a.campos, conjunto_id: cj.id, ordem: o };
            const resposta = a.origemId
                ? await pedirJson('POST', `${BASE}/${campanhaId.value}/videos/${a.origemId}/duplicar`, extras)
                : await enviarArquivo(`${BASE}/${campanhaId.value}/videos`, 'video', a.arquivo, (p) => { a.progresso = p; }, extras);
            if (a.arquivo) URL.revokeObjectURL(a.previa);
            Object.assign(a, { id: resposta.video.id, origemId: null, arquivo: null, previa: resposta.video.url, estado: 'salvo' });
        } catch (erro) {
            a.estado = 'erro';
            a.erro = erro.message;
            a.aberto = true;
            cj.aberto = true;
            falhou = true;
        }
    }

    salvando.value = false;

    if (falhou) {
        erroGeral.value = 'O rascunho foi salvo, mas algum anúncio não. Veja o motivo no anúncio e salve de novo.';
        return;
    }

    if (depois === 'revisar') {
        router.visit(`${BASE}/${campanhaId.value}`);
        return;
    }

    salvoAs.value = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
    window.history.replaceState(window.history.state, '', `${BASE}/${campanhaId.value}/editar`);
};

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm';
const cartao = 'rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm';
const botaoIcone = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-[var(--surface-muted)] disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <Head :title="editando ? 'Editar campanha Meta' : 'Nova campanha Meta'" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                {{ editando ? 'Editar campanha' : 'Nova campanha' }}
            </h1>
            <Link href="/admin/publicidade/meta-ads/campanhas" class="text-sm font-medium text-primary hover:underline">Voltar para a lista</Link>
        </div>

        <div v-if="!props.config.configurado" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Meta não configurada.</strong> O rascunho e os arquivos ficam salvos, mas só dá para enviar depois de configurar.
        </div>

        <form class="grid grid-cols-1 gap-6 lg:grid-cols-3" @submit.prevent="salvar('ficar')">
            <div class="space-y-6 lg:col-span-2">
                <!-- 1. Campanha -->
                <section id="campanha" :class="[cartao, 'space-y-4 p-5']">
                    <h2 class="text-lg font-semibold">Campanha</h2>
                    <div>
                        <label for="nome" class="block text-sm font-medium">Nome</label>
                        <input id="nome" v-model="form.nome" type="text" maxlength="200" :class="campo" placeholder="Ex.: Black November">
                        <InputError :message="form.errors.nome" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label for="objetivo" class="block text-sm font-medium">Objetivo</label>
                            <select id="objetivo" v-model="form.objetivo" :class="campo">
                                <option v-for="(rotulo, valor) in props.objetivos" :key="valor" :value="valor">{{ rotulo }}</option>
                            </select>
                            <p v-if="form.objetivo === 'OUTCOME_SALES' && !props.config.pixel" class="mt-1 text-xs text-amber-700">
                                Vendas precisa do pixel da Meta configurado. Sem ele, use Tráfego.
                            </p>
                            <InputError :message="form.errors.objetivo" />
                        </div>
                        <div>
                            <label for="inicio" class="block text-sm font-medium">Início</label>
                            <input id="inicio" v-model="form.inicio" type="date" required :class="campo">
                            <InputError :message="form.errors.inicio" />
                        </div>
                        <div>
                            <label for="fim" class="block text-sm font-medium">Fim (opcional)</label>
                            <input id="fim" v-model="form.fim" type="date" :min="form.inicio" :class="campo">
                            <InputError :message="form.errors.fim" />
                        </div>
                    </div>

                    <fieldset>
                        <legend class="block text-sm font-medium">Orçamento</legend>
                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label v-for="(rotulo, valor) in props.orcamentoTipos" :key="valor"
                                class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                                :class="form.orcamento_tipo === valor ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)]'">
                                <input v-model="form.orcamento_tipo" type="radio" name="orcamento_tipo" :value="valor" class="mt-0.5">
                                <span>
                                    <span class="block font-medium">{{ rotulo }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ valor === 'campanha'
                                            ? 'A Meta divide o valor entre os conjuntos que vendem mais.'
                                            : 'Cada conjunto gasta o valor que você definir nele.' }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <div v-if="!porConjunto" class="mt-3 sm:w-1/2">
                            <label for="orcamento" class="block text-sm font-medium">Orçamento da campanha por dia (R$)</label>
                            <input id="orcamento" v-model.number="form.orcamento_diario" type="number" step="0.01" min="1" :class="campo">
                            <InputError :message="form.errors.orcamento_diario" />
                        </div>
                        <p v-else class="mt-3 text-sm text-slate-500">Soma dos conjuntos: <strong>{{ formatarReais(orcamentoDiario) }}</strong> por dia.</p>
                        <p v-for="p in pendenciasDaCampanha.filter((t) => t.includes('mínimo'))" :key="p" class="mt-1 text-sm text-amber-700" role="status">{{ p }}</p>
                    </fieldset>
                </section>

                <!-- 2. Padrões para todos os anúncios -->
                <section :class="cartao">
                    <button type="button" class="flex w-full items-start justify-between gap-3 p-5 text-left" :aria-expanded="padroesAbertos" aria-controls="padroes" @click="padroesAbertos = !padroesAbertos">
                        <span>
                            <span class="block text-lg font-semibold">Padrões para todos os anúncios</span>
                            <span class="block text-xs text-slate-500">{{ resumoPadroes }}</span>
                        </span>
                        <i class="fas mt-1 text-slate-400" :class="padroesAbertos ? 'fa-chevron-up' : 'fa-chevron-down'" aria-hidden="true"></i>
                    </button>
                    <div v-show="padroesAbertos" id="padroes" class="space-y-5 border-t border-[var(--surface-border)] p-5">
                        <div>
                            <h3 class="text-sm font-semibold">Público</h3>
                            <p class="text-xs text-slate-500">Vale para os conjuntos que usam o público da campanha.</p>
                            <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="idade_min" class="block text-sm font-medium">Idade mínima</label>
                                    <input id="idade_min" v-model.number="form.idade_min" type="number" min="18" max="65" required :class="campo">
                                    <InputError :message="form.errors.idade_min" />
                                </div>
                                <div>
                                    <label for="idade_max" class="block text-sm font-medium">Idade máxima</label>
                                    <input id="idade_max" v-model.number="form.idade_max" type="number" min="18" max="65" required :class="campo">
                                    <InputError :message="form.errors.idade_max" />
                                </div>
                                <div>
                                    <label for="genero" class="block text-sm font-medium">Gênero</label>
                                    <select id="genero" v-model="form.genero" :class="campo">
                                        <option v-for="(rotulo, valor) in props.generos" :key="valor" :value="valor">{{ rotulo }}</option>
                                    </select>
                                </div>
                            </div>
                            <BuscaLocal id="busca-local" class="mt-3" :local="form.localizacoes" :configurado="props.config.configurado" />
                        </div>

                        <div class="space-y-4 border-t border-[var(--surface-border)] pt-4">
                            <div>
                                <h3 class="text-sm font-semibold">Texto</h3>
                                <p class="text-xs text-slate-500">Todo anúncio que deixar o campo vazio usa este. Se cada anúncio tem o seu texto, pode deixar em branco.</p>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="produto" class="block text-sm font-medium">Produto (opcional)</label>
                                    <select id="produto" v-model="form.produto_id" :class="campo" @change="aoEscolherProduto">
                                        <option value="">Nenhum (link livre)</option>
                                        <option v-for="p in props.produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                                    </select>
                                    <InputError :message="form.errors.produto_id" />
                                </div>
                                <div>
                                    <label for="link" class="block text-sm font-medium">Link de destino</label>
                                    <input id="link" v-model="form.link_destino" type="url" maxlength="500" :class="campo" :placeholder="`${props.siteUrl}/`">
                                    <InputError :message="form.errors.link_destino" />
                                </div>
                            </div>
                            <div>
                                <label for="texto" class="block text-sm font-medium">Texto principal</label>
                                <textarea id="texto" v-model="form.texto_principal" rows="3" maxlength="2000" :class="campo" />
                                <InputError :message="form.errors.texto_principal" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="titulo" class="block text-sm font-medium">Título</label>
                                    <input id="titulo" v-model="form.titulo" type="text" maxlength="255" :class="campo">
                                    <InputError :message="form.errors.titulo" />
                                </div>
                                <div>
                                    <label for="descricao" class="block text-sm font-medium">Descrição (opcional)</label>
                                    <input id="descricao" v-model="form.descricao" type="text" maxlength="255" :class="campo">
                                </div>
                                <div>
                                    <label for="chamada" class="block text-sm font-medium">Botão</label>
                                    <select id="chamada" v-model="form.chamada" :class="campo">
                                        <option v-for="(rotulo, valor) in props.chamadas" :key="valor" :value="valor">{{ rotulo }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 3. Conjuntos -->
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Conjuntos e anúncios</h2>
                    <span class="text-xs text-slate-500">
                        {{ conjuntos.length }} de {{ props.limites.max_conjuntos }} conjuntos · {{ totalAnuncios }} de {{ props.limites.max_videos }} anúncios ·
                        vídeo MP4/MOV ou imagem JPG/PNG até {{ props.limites.max_mb }} MB
                    </span>
                </div>
                <p v-if="aviso" class="text-sm text-amber-700" role="alert">{{ aviso }}</p>

                <section v-for="(cj, ci) in conjuntos" :id="`conjunto-${cj.chave}`" :key="cj.chave" :class="[cartao, pendenciasDoConjunto(cj).length || Object.keys(cj.erros).length ? 'border-amber-400' : '']" :aria-label="`Conjunto ${cj.nome}`">
                    <header class="flex flex-wrap items-center gap-2 p-4">
                        <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :aria-expanded="cj.aberto" :aria-controls="`corpo-${cj.chave}`" @click="cj.aberto = !cj.aberto">
                            <i class="fas text-xs text-slate-400" :class="cj.aberto ? 'fa-chevron-down' : 'fa-chevron-right'" aria-hidden="true"></i>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ cj.nome || 'Sem nome' }}</span>
                                <span class="block text-xs text-slate-500">
                                    {{ cj.anuncios.length }} anúncio{{ cj.anuncios.length === 1 ? '' : 's' }}<template v-if="porConjunto"> · {{ formatarReais(Number(cj.orcamento_diario || 0)) }}/dia</template>
                                </span>
                            </span>
                            <span v-if="cj.publico_proprio" class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">Público próprio</span>
                        </button>
                        <div class="flex items-center gap-1">
                            <button type="button" :class="botaoIcone" :disabled="ci === 0" :aria-label="`Mover ${cj.nome} para cima`" title="Mover para cima" @click="moverConjunto(ci, -1)"><i class="fas fa-arrow-up text-xs" aria-hidden="true"></i></button>
                            <button type="button" :class="botaoIcone" :disabled="ci === conjuntos.length - 1" :aria-label="`Mover ${cj.nome} para baixo`" title="Mover para baixo" @click="moverConjunto(ci, 1)"><i class="fas fa-arrow-down text-xs" aria-hidden="true"></i></button>
                            <button type="button" :class="botaoIcone" :aria-label="`Duplicar ${cj.nome}`" title="Duplicar" :disabled="salvando" @click="duplicarConjunto(ci)"><i class="far fa-copy text-xs" aria-hidden="true"></i></button>
                            <button type="button" :class="[botaoIcone, 'hover:text-red-600']" :aria-label="`Remover ${cj.nome}`" title="Remover" :disabled="salvando" @click="removerConjunto(ci)"><i class="fas fa-trash text-xs" aria-hidden="true"></i></button>
                        </div>
                    </header>

                    <div v-show="cj.aberto" :id="`corpo-${cj.chave}`" class="space-y-4 border-t border-[var(--surface-border)] p-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div :class="porConjunto ? 'sm:col-span-2' : 'sm:col-span-3'">
                                <label :for="`nome-${cj.chave}`" class="block text-sm font-medium">Nome do conjunto</label>
                                <input :id="`nome-${cj.chave}`" v-model="cj.nome" type="text" maxlength="120" :class="campo" placeholder="Ex.: Árvore de Natal">
                                <InputError :message="cj.erros.nome" />
                            </div>
                            <div v-if="porConjunto">
                                <label :for="`orcamento-${cj.chave}`" class="block text-sm font-medium">Orçamento por dia (R$)</label>
                                <input :id="`orcamento-${cj.chave}`" v-model.number="cj.orcamento_diario" type="number" step="0.01" :min="MINIMO" :class="campo">
                                <InputError :message="cj.erros.orcamento_diario" />
                            </div>
                        </div>

                        <div>
                            <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" :checked="!cj.publico_proprio" @change="alternarPublico(cj, $event.target.checked)">
                                Usar o público da campanha
                            </label>
                            <div v-if="cj.publico_proprio" class="mt-3 space-y-3 rounded-lg bg-slate-500/5 p-3">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div>
                                        <label :for="`idade-min-${cj.chave}`" class="block text-xs font-medium">Idade mínima</label>
                                        <input :id="`idade-min-${cj.chave}`" v-model.number="cj.idade_min" type="number" min="18" max="65" :class="campo">
                                    </div>
                                    <div>
                                        <label :for="`idade-max-${cj.chave}`" class="block text-xs font-medium">Idade máxima</label>
                                        <input :id="`idade-max-${cj.chave}`" v-model.number="cj.idade_max" type="number" min="18" max="65" :class="campo">
                                        <InputError :message="cj.erros.idade_max" />
                                    </div>
                                    <div>
                                        <label :for="`genero-${cj.chave}`" class="block text-xs font-medium">Gênero</label>
                                        <select :id="`genero-${cj.chave}`" v-model="cj.genero" :class="campo">
                                            <option v-for="(rotulo, valor) in props.generos" :key="valor" :value="valor">{{ rotulo }}</option>
                                        </select>
                                    </div>
                                </div>
                                <BuscaLocal :id="`local-${cj.chave}`" :local="cj.localizacoes" :configurado="props.config.configurado" />
                            </div>
                        </div>

                        <!-- Anúncios do conjunto -->
                        <ol v-if="cj.anuncios.length" class="space-y-2">
                            <li v-for="(a, ai) in cj.anuncios" :id="`anuncio-${a.chave}`" :key="a.chave"
                                class="rounded-lg border"
                                :class="a.estado === 'erro' || a.erro ? 'border-red-400' : (itemEmPrevia?.a.chave === a.chave ? 'border-primary' : 'border-[var(--surface-border)]')"
                                @focusin="emPrevia = a.chave">
                                <div class="flex items-center gap-3 p-2">
                                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :aria-expanded="a.aberto" @click="a.aberto = !a.aberto; emPrevia = a.chave">
                                        <img v-if="a.tipo === 'imagem'" :src="a.previa" alt="" class="h-14 w-11 shrink-0 rounded bg-black object-cover">
                                        <video v-else :src="a.previa" class="h-14 w-11 shrink-0 rounded bg-black object-cover" preload="metadata" muted></video>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold" :title="a.nome">{{ semExtensao(a.nome) }}</span>
                                            <span class="block truncate text-xs text-slate-500">
                                                {{ props.produtos.find((p) => p.id === Number(a.campos.produto_id))?.nome ?? (a.campos.titulo || 'Sem produto') }} · {{ a.tamanho }}
                                            </span>
                                        </span>
                                    </button>
                                    <span v-if="a.estado === 'esperando'" class="hidden text-xs text-slate-500 sm:inline">Sobe ao salvar</span>
                                    <i v-if="pendenciasDoAnuncio(a).length" class="fas fa-circle text-[8px] text-amber-500" aria-label="Com pendência" role="img"></i>
                                    <i v-else class="fas fa-circle text-[8px] text-emerald-500" aria-label="Pronto" role="img"></i>
                                    <button type="button" :class="botaoIcone" :aria-label="`Duplicar ${a.nome}`" title="Duplicar" :disabled="salvando" @click="duplicarAnuncio(cj, ai)"><i class="far fa-copy text-xs" aria-hidden="true"></i></button>
                                    <button type="button" :class="[botaoIcone, 'hover:text-red-600']" :aria-label="`Remover ${a.nome}`" title="Remover" :disabled="salvando || a.estado === 'subindo'" @click="removerAnuncio(cj, ai)"><i class="fas fa-xmark text-xs" aria-hidden="true"></i></button>
                                </div>
                                <div v-if="a.estado === 'subindo'" class="mx-2 mb-2 h-1.5 overflow-hidden rounded-full bg-slate-500/20" role="progressbar" :aria-valuenow="a.progresso" aria-valuemin="0" aria-valuemax="100" :aria-label="`Enviando ${a.nome}`">
                                    <div class="h-full bg-primary transition-all" :style="{ width: `${a.progresso}%` }"></div>
                                </div>

                                <div v-show="a.aberto" class="grid grid-cols-1 gap-3 border-t border-[var(--surface-border)] p-3 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label :for="`produto-${a.chave}`" class="block text-xs font-medium">Produto</label>
                                        <select :id="`produto-${a.chave}`" v-model="a.campos.produto_id" :class="campo" @change="aoEscolherProdutoDoAnuncio(a)">
                                            <option value="">Nenhum (usa o link padrão)</option>
                                            <option v-for="p in props.produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                                        </select>
                                    </div>
                                    <div v-for="(meta, chave) in {
                                        texto_principal: { rotulo: 'Texto principal', padrao: form.texto_principal, area: true },
                                        titulo: { rotulo: 'Título', padrao: form.titulo },
                                        descricao: { rotulo: 'Descrição', padrao: form.descricao },
                                        link_destino: { rotulo: 'Link de destino', padrao: form.link_destino || `${props.siteUrl}/` },
                                    }" :key="chave" :class="meta.area ? 'sm:col-span-2' : ''">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <label :for="`${chave}-${a.chave}`" class="block text-xs font-medium">{{ meta.rotulo }}</label>
                                            <button v-if="preenchido(a.campos[chave]) && preenchido(meta.padrao)" type="button" class="text-xs text-primary hover:underline" @click="a.campos[chave] = ''">Voltar ao padrão</button>
                                            <span v-else-if="!preenchido(a.campos[chave]) && preenchido(meta.padrao)" class="text-xs text-slate-400">Padrão da campanha</span>
                                        </div>
                                        <textarea v-if="meta.area" :id="`${chave}-${a.chave}`" v-model="a.campos[chave]" rows="2" maxlength="2000" :class="campo" :placeholder="meta.padrao || 'Escreva o texto deste anúncio'" />
                                        <input v-else :id="`${chave}-${a.chave}`" v-model="a.campos[chave]" :type="chave === 'link_destino' ? 'url' : 'text'" :maxlength="chave === 'link_destino' ? 500 : 255" :class="campo" :placeholder="meta.padrao || (chave === 'descricao' ? 'Opcional' : '')">
                                    </div>
                                    <div>
                                        <label :for="`chamada-${a.chave}`" class="block text-xs font-medium">Botão</label>
                                        <select :id="`chamada-${a.chave}`" v-model="a.campos.chamada" :class="campo">
                                            <option value="">Padrão ({{ props.chamadas[form.chamada] }})</option>
                                            <option v-for="(rotulo, valor) in props.chamadas" :key="valor" :value="valor">{{ rotulo }}</option>
                                        </select>
                                    </div>
                                    <ul v-if="pendenciasDoAnuncio(a).length || a.erro" class="space-y-0.5 text-xs sm:col-span-2" role="alert">
                                        <li v-for="p in pendenciasDoAnuncio(a)" :key="p" class="text-amber-700">{{ p }}</li>
                                        <li v-if="a.erro && a.estado !== 'erro'" class="text-red-600">{{ a.erro }}</li>
                                    </ul>
                                </div>
                            </li>
                        </ol>

                        <p v-if="cj.anuncios.length > props.limites.recomendado_por_conjunto" class="text-xs text-amber-700" role="status">
                            Mais de {{ props.limites.recomendado_por_conjunto }} anúncios no mesmo conjunto dividem demais a entrega. Funciona, mas o ideal é até {{ props.limites.recomendado_por_conjunto }}.
                        </p>
                        <p v-for="p in pendenciasDoConjunto(cj)" :key="p" class="text-xs text-amber-700">{{ p }}</p>

                        <div
                            class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-4 text-center text-sm transition-colors"
                            :class="arrastandoEm === cj.chave ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)]'"
                            @dragover.prevent="arrastandoEm = cj.chave"
                            @dragleave.prevent="arrastandoEm = null"
                            @drop.prevent="aoSoltar(cj, $event)"
                        >
                            <p class="text-slate-500">
                                {{ cj.anuncios.length ? 'Solte vídeos ou imagens aqui. Cada arquivo vira um anúncio.' : 'Solte vídeos ou imagens aqui ou clique em + Anúncio.' }}
                            </p>
                            <button type="button" :disabled="totalAnuncios >= props.limites.max_videos || salvando"
                                class="rounded-lg border border-primary px-4 py-1.5 text-sm font-medium text-primary hover:bg-primary/10 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="escolherArquivos(cj)">
                                <i class="fas fa-plus text-xs" aria-hidden="true"></i> Anúncio
                            </button>
                            <input :id="`arquivos-${cj.chave}`" type="file" accept="video/mp4,video/quicktime,image/jpeg,image/png" multiple class="sr-only"
                                :aria-label="`Escolher vídeos ou imagens para ${cj.nome}`" tabindex="-1"
                                @change="adicionarArquivos(cj, $event.target.files); $event.target.value = ''">
                        </div>
                    </div>
                </section>

                <button type="button" :disabled="conjuntos.length >= props.limites.max_conjuntos || salvando"
                    class="w-full rounded-xl border-2 border-dashed border-[var(--surface-border)] p-3 text-sm font-medium text-primary hover:border-primary hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="adicionarConjunto">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Conjunto
                </button>
            </div>

            <!-- Resumo e prévia -->
            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                <div :class="[cartao, 'p-4']">
                    <p class="truncate font-semibold">{{ form.nome || 'Campanha sem nome' }}</p>
                    <p class="text-sm">{{ conjuntos.length }} conjunto{{ conjuntos.length === 1 ? '' : 's' }} · {{ totalAnuncios }} anúncio{{ totalAnuncios === 1 ? '' : 's' }}</p>
                    <p class="text-sm text-slate-500">
                        {{ formatarReais(orcamentoDiario) }}/dia ·
                        <template v-if="totalPrevisto !== null">até {{ formatarReais(totalPrevisto) }} no total</template>
                        <template v-else>sem data de fim</template>
                    </p>

                    <ul class="mt-3 space-y-2 border-t border-[var(--surface-border)] pt-3 text-sm">
                        <li v-for="cj in conjuntos" :key="cj.chave">
                            <button type="button" class="flex w-full items-center gap-2 text-left font-medium hover:text-primary" @click="irPara({ alvo: `conjunto-${cj.chave}`, cj })">
                                <i class="fas fa-circle text-[8px]" :class="pendenciasDoConjunto(cj).length ? 'text-amber-500' : 'text-emerald-500'" aria-hidden="true"></i>
                                <span class="truncate">{{ cj.nome || 'Sem nome' }}</span>
                            </button>
                            <ul class="ml-4 mt-1 space-y-0.5">
                                <li v-for="a in cj.anuncios" :key="a.chave">
                                    <button type="button" class="flex w-full items-center gap-2 text-left text-xs text-slate-600 hover:text-primary dark:text-slate-300"
                                        :class="itemEmPrevia?.a.chave === a.chave ? 'font-semibold text-primary' : ''" @click="verAnuncio(cj, a)">
                                        <i class="fas fa-circle text-[6px]" :class="pendenciasDoAnuncio(a).length ? 'text-amber-500' : 'text-emerald-500'" aria-hidden="true"></i>
                                        <span class="truncate">{{ semExtensao(a.nome) }}</span>
                                    </button>
                                </li>
                            </ul>
                        </li>
                    </ul>
                    <button v-if="pendencias.length" type="button" class="mt-3 text-sm font-medium text-amber-700 hover:underline" @click="irPara(pendencias[0])">
                        {{ pendencias.length }} pendência{{ pendencias.length === 1 ? '' : 's' }}
                    </button>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between text-sm font-semibold text-slate-500">
                        <span>Prévia aproximada</span>
                        <span v-if="totalAnuncios > 1" class="flex items-center gap-1">
                            <button type="button" :class="botaoIcone" aria-label="Anúncio anterior" @click="navegarPrevia(-1)"><i class="fas fa-chevron-left text-xs" aria-hidden="true"></i></button>
                            Anúncio {{ indiceEmPrevia + 1 }} de {{ totalAnuncios }}
                            <button type="button" :class="botaoIcone" aria-label="Próximo anúncio" @click="navegarPrevia(1)"><i class="fas fa-chevron-right text-xs" aria-hidden="true"></i></button>
                        </span>
                    </div>
                    <div :class="[cartao, 'overflow-hidden']">
                        <div class="flex items-center gap-2 p-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">KK</div>
                            <div class="text-sm font-semibold">KazaKora <span class="block text-xs font-normal text-slate-500">Patrocinado</span></div>
                        </div>
                        <p class="whitespace-pre-line px-3 pb-3 text-sm">{{ previa.texto || 'Texto principal do anúncio' }}</p>
                        <img v-if="previa.midia && previa.tipo === 'imagem'" :src="previa.midia" alt="Prévia da imagem do anúncio" class="aspect-[4/5] w-full bg-black object-cover">
                        <video v-else-if="previa.midia" :key="previa.midia" :src="previa.midia" class="aspect-[4/5] w-full bg-black object-cover" muted loop autoplay playsinline aria-label="Prévia do vídeo do anúncio"></video>
                        <div v-else class="flex aspect-[4/5] w-full items-center justify-center bg-slate-500/10 p-6 text-center text-sm text-slate-500">A prévia aparece quando o anúncio tiver vídeo ou imagem.</div>
                        <div class="flex items-center justify-between gap-3 bg-slate-500/5 p-3">
                            <div class="min-w-0">
                                <p class="truncate text-xs uppercase text-slate-500">kazakora.com</p>
                                <p class="truncate text-sm font-semibold">{{ previa.titulo || 'Título' }}</p>
                                <p v-if="previa.descricao" class="truncate text-xs text-slate-500">{{ previa.descricao }}</p>
                            </div>
                            <span class="shrink-0 rounded-md bg-slate-500/15 px-3 py-1.5 text-xs font-semibold">{{ props.chamadas[previa.chamada] }}</span>
                        </div>
                    </div>
                    <p v-if="itemEmPrevia" class="mt-1 text-xs text-slate-500">Conjunto: {{ itemEmPrevia.cj.nome }}</p>
                </div>
            </aside>

            <!-- Rodapé fixo -->
            <div class="sticky bottom-0 z-20 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)]/95 px-4 py-3 shadow-lg backdrop-blur lg:col-span-3">
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <p v-if="erroGeral" class="mr-auto text-sm text-red-600" role="alert">{{ erroGeral }}</p>
                    <p v-else-if="desfazer" class="mr-auto text-sm" role="status">
                        {{ desfazer.mensagem }}
                        <button type="button" class="ml-1 font-semibold text-primary hover:underline" @click="aoDesfazer">Desfazer</button>
                    </p>
                    <p v-else-if="salvoAs" class="mr-auto text-sm text-emerald-700" role="status">Rascunho salvo às {{ salvoAs }}.</p>
                    <p v-else class="mr-auto hidden text-xs text-slate-500 sm:block">Salvar guarda tudo aqui no servidor. Nada vai para a Meta ainda.</p>

                    <span v-if="pendencias.length" class="text-xs text-slate-500">Resolva as pendências para enviar.</span>
                    <button type="submit" :disabled="salvando"
                        class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:cursor-not-allowed disabled:opacity-50">
                        {{ salvando ? 'Salvando…' : 'Salvar rascunho' }}
                    </button>
                    <button type="button" :disabled="salvando || pendencias.length > 0"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                        @click="salvar('revisar')">
                        Revisar e enviar para a Meta
                    </button>
                </div>
            </div>
        </form>
    </AdminLayout>
</template>
