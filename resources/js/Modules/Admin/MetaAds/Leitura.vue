<script setup>
/**
 * Leitura das campanhas (pedido do Lira em 2026-11-25): conjunto a conjunto,
 * na ordem de leitura do professor, com semáforo em cada número e uma frase
 * "O que fazer". Feita para quem está aprendendo a ler tráfego.
 * Só lê a Meta (LeituraCampanhasMeta, guardado 5 min).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InfoTooltip from '@/Shared/Components/InfoTooltip.vue';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { formatarNumero, formatarReais, statusMeta } from './metaAds';

const props = defineProps({
    lojas: { type: Object, default: () => ({}) },
    loja: { type: String, required: true },
    periodo: { type: String, required: true },
    periodos: { type: Object, default: () => ({}) },
    status: { type: String, required: true },
    statusFiltros: { type: Object, default: () => ({}) },
    faixas: { type: Object, default: () => ({}) },
    metricaProfessor: { type: Boolean, default: false },
    podeEditar: { type: Boolean, default: false },
    dados: { type: Object, default: null },
});

const URL_TELA = '/admin/publicidade/meta-ads/leitura';
const filtros = (mudanca = {}) => ({ loja: props.loja, periodo: props.periodo, status: props.status, ...mudanca });
const ir = (mudanca) => router.get(URL_TELA, filtros(mudanca), { preserveScroll: true });
const atualizando = ref(false);
const atualizar = () => {
    atualizando.value = true;
    router.get(URL_TELA, filtros({ atualizar: 1 }), {
        only: ['dados'], preserveScroll: true, preserveState: true, replace: true,
        onFinish: () => { atualizando.value = false; },
    });
};

const linhas = computed(() => props.dados?.linhas ?? []);

// Formatação ----------------------------------------------------------------
const reais = (v) => (v === null || v === undefined ? '—' : formatarReais(v));
const pct = (v, casas = 1) => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: casas })}%`);
const num = (v, casas = 2) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: casas }));
const roas = (v) => (v === null || v === undefined ? '—' : `${num(v)}x`);
const dataHora = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');

// Semáforo: cor + ícone + texto para leitor de tela (não só cor).
const SEMAFORO = {
    verde: { icone: 'fa-circle-check', classe: 'text-emerald-700 dark:text-emerald-300', fundo: 'bg-emerald-500/10' },
    amarelo: { icone: 'fa-triangle-exclamation', classe: 'text-amber-700 dark:text-amber-300', fundo: 'bg-amber-500/10' },
    vermelho: { icone: 'fa-circle-xmark', classe: 'text-rose-700 dark:text-rose-300', fundo: 'bg-rose-500/10' },
};
const NIVEL_ACAO = {
    vermelho: 'border-rose-400 bg-rose-500/10 text-rose-800 dark:text-rose-200',
    amarelo: 'border-amber-400 bg-amber-500/10 text-amber-900 dark:text-amber-200',
    verde: 'border-emerald-400 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
    espera: 'border-slate-300 bg-slate-500/10 text-slate-700 dark:text-slate-200',
    ok: 'border-emerald-300 bg-transparent text-emerald-800 dark:text-emerald-200',
    info: 'border-slate-300 bg-transparent text-slate-600 dark:text-slate-300',
};

// Colunas, na ordem de leitura do professor ---------------------------------
const f = props.faixas;
const COLUNAS = [
    { grupo: 'Atenção', chave: 'hook', rotulo: 'Hook', valor: (l) => pct(l.m.hook), sem: 'hook',
      ajuda: ['O que é: quantas pessoas pararam 3 segundos no vídeo, a cada 100 que viram o anúncio.', `Bom é: ${f.hook?.verde_min}% ou mais (abaixo de ${f.hook?.amarelo_min}% é ruim). Anúncio sem vídeo mostra —.`] },
    { grupo: 'Atenção', chave: 'frequencia', rotulo: 'Frequência', valor: (l) => num(l.m.frequencia), sem: 'frequencia',
      ajuda: ['O que é: quantas vezes, em média, a mesma pessoa viu o anúncio.', `Bom é: até ${num(f.frequencia?.verde_max)} (acima de ${num(f.frequencia?.amarelo_max)} cansa o público).`] },
    { grupo: 'Entrega', chave: 'gasto', rotulo: 'Valor usado', valor: (l) => reais(l.m.gasto),
      ajuda: ['O que é: quanto a Meta gastou do seu orçamento no período.', 'Bom é: o que você planejou; sozinho não diz se está bom.'] },
    { grupo: 'Entrega', chave: 'cpm', rotulo: 'CPM', valor: (l) => reais(l.m.cpm), sem: 'cpm',
      ajuda: ['O que é: quanto custa mostrar o anúncio 1.000 vezes.', `Bom é: até R$ ${f.cpm?.verde_max} (acima de R$ ${f.cpm?.amarelo_max} está caro).`] },
    { grupo: 'Clique', chave: 'ctr', rotulo: 'CTR do link', valor: (l) => pct(l.m.ctr_link, 2), sem: 'ctr',
      ajuda: ['O que é: de cada 100 pessoas que viram, quantas clicaram no link.', `Bom é: ${f.ctr?.verde_min}% ou mais (abaixo de ${f.ctr?.amarelo_min}% o anúncio não convence).`] },
    { grupo: 'Clique', chave: 'cpc', rotulo: 'CPC do link', valor: (l) => reais(l.m.cpc_link), sem: 'cpc',
      ajuda: ['O que é: quanto custou cada clique no link.', `Bom é: até R$ ${f.cpc?.verde_max} (acima de R$ ${f.cpc?.amarelo_max} está caro).`] },
    { grupo: 'Funil', chave: 'pagina', rotulo: 'Página', valor: (l) => formatarNumero(l.m.visualizacoes_pagina), sub: (l) => reais(l.m.custo_pagina),
      ajuda: ['O que é: quantas pessoas viram a página do produto (e o custo de cada uma).', 'Bom é: perto do número de cliques; muito menor = a página demora a abrir.'] },
    { grupo: 'Funil', chave: 'carrinho', rotulo: 'Carrinho', valor: (l) => formatarNumero(l.m.carrinho), sub: (l) => reais(l.m.custo_carrinho),
      ajuda: ['O que é: quantas pessoas puseram no carrinho (e o custo de cada uma).', 'Bom é: ter carrinho; página visitada sem carrinho = olhe página, preço e frete.'] },
    { grupo: 'Funil', chave: 'checkout', rotulo: 'Checkout', valor: (l) => formatarNumero(l.m.checkout), sub: (l) => reais(l.m.custo_checkout),
      ajuda: ['O que é: quantas pessoas foram para o pagamento (e o custo de cada uma).', 'Bom é: boa parte de quem pôs no carrinho chegar aqui.'] },
    { grupo: 'Venda', chave: 'compras', rotulo: 'Compras', valor: (l) => formatarNumero(l.m.compras),
      ajuda: ['O que é: vendas que a Meta atribuiu ao conjunto.', 'Bom é: vender antes de gastar o CPA máximo.'] },
    { grupo: 'Venda', chave: 'cpa', rotulo: 'CPA', valor: (l) => reais(l.m.cpa), sem: 'cpa',
      sub: (l) => (l.produto ? `alvo ${reais(l.produto.cpa_alvo)}` : null),
      ajuda: ['O que é: custo por compra (valor usado ÷ compras).', 'Bom é: até o CPA alvo do produto (verde); até o CPA máximo, o 0 a 0 (amarelo); acima, prejuízo (vermelho).'] },
    { grupo: 'Venda', chave: 'roas', rotulo: 'ROAS', valor: (l) => roas(l.m.roas),
      ajuda: ['O que é: quanto voltou em venda para cada R$ 1 de anúncio (o da Meta).', 'Bom é: o suficiente para dar lucro; olhe a coluna Lucro, que já tira os custos.'] },
    { grupo: 'Lucro', chave: 'lucro', rotulo: 'Lucro real (estimado)', valor: (l) => reais(l.m.lucro),
      ajuda: ['O que é: venda − valor usado − compras × (custo do produto + frete médio + insumos) − venda × (imposto + taxa do cartão).', 'Bom é: positivo. É estimado: usa as médias do produto e a venda que a Meta atribuiu.'] },
];
// Ponto de extensão: "Métrica do professor" (fórmula a definir; LeituraCampanhasMeta::metricaDoProfessor).
if (props.metricaProfessor) {
    COLUNAS.push({ grupo: 'Lucro', chave: 'professor', rotulo: 'Métrica do professor', valor: (l) => num(l.m.professor),
        ajuda: ['O que é: a métrica ensinada na aula (fórmula a definir).', 'Bom é: a definir.'] });
}
const GRUPOS = computed(() => {
    const lista = [];
    COLUNAS.forEach((c) => {
        const ultimo = lista[lista.length - 1];
        if (ultimo?.nome === c.grupo) ultimo.span += 1; else lista.push({ nome: c.grupo, span: 1 });
    });
    return lista;
});
const COR_GRUPO = { Atenção: 'bg-violet-500/10', Entrega: 'bg-sky-500/10', Clique: 'bg-cyan-500/10', Funil: 'bg-amber-500/10', Venda: 'bg-emerald-500/10', Lucro: 'bg-lime-500/10' };
const semaforoDe = (l, c) => (c.sem ? l.sem?.[c.sem] : null);

// Como ler (recolhível) ------------------------------------------------------
const COMO_LER = [
    'CPA alvo × máximo: o máximo é o 0 a 0 (preço − custos); o alvo é a metade. Abaixo do alvo, ótimo; acima do máximo, prejuízo.',
    '1 campanha por oferta; a análise é no CONJUNTO, não no anúncio.',
    'Teste roda até vender ou até gastar o CPA máximo sem vender; aí desliga.',
    'Achou o melhor: duplique e ponha 3 criativos; vá desligando até sobrar um.',
    'CBO (orçamento na campanha) deixa a Meta distribuir entre os conjuntos.',
    'Escala: +20% a cada 2 dias, entre 0 h e 10 h; conjunto fraco: −20%, sem desligar.',
];

// Ciclo e CPA (editáveis) ----------------------------------------------------
const editandoCiclo = reactive({});
const editandoCpa = reactive({});
const ORIGEM_CICLO = { campanha: 'definido nesta campanha', produto: 'definido no produto', nome: 'pelo nome da campanha', meta: 'início da campanha na Meta' };
const fecharCiclo = (l) => { delete editandoCiclo[l.campanha_id]; };
const fecharCpa = (p) => { delete editandoCpa[p.product_id]; };
const abrirCiclo = (l) => { editandoCiclo[l.campanha_id] = (l.ciclo.inicio || '').replace(' ', 'T'); };
const salvarCiclo = (l, limpar = false) => {
    router.post(`${URL_TELA}/ciclo`, { loja: props.loja, campanha_id: l.campanha_id, inicio: limpar ? null : editandoCiclo[l.campanha_id] }, {
        preserveScroll: true, onSuccess: () => { delete editandoCiclo[l.campanha_id]; },
    });
};
const abrirCpa = (p) => { editandoCpa[p.product_id] = { alvo: p.manual ? p.cpa_alvo : '', maximo: p.manual ? p.cpa_maximo : '' }; };
const salvarCpa = (p, limpar = false) => {
    const v = editandoCpa[p.product_id];
    router.post(`${URL_TELA}/cpa`, {
        product_id: p.product_id,
        cpa_alvo: limpar || v.alvo === '' ? null : v.alvo,
        cpa_maximo: limpar || v.maximo === '' ? null : v.maximo,
    }, { preserveScroll: true, onSuccess: () => { delete editandoCpa[p.product_id]; } });
};
const contaDoCpa = (p) => [
    `0 a 0 = preço médio ${reais(p.preco)} − custo ${reais(p.custo)} − frete ${reais(p.frete)} − imposto ${num(p.imposto_pct)}% (${reais(p.imposto)}) − insumos ${reais(p.insumos)} − taxa ${num(p.taxa_pct)}% (${reais(p.taxa)}) = ${reais(p.calculado_maximo)}.`,
    `Alvo = metade = ${reais(p.calculado_alvo)}. Fonte: ${p.fonte}.`,
    ...(p.manual ? [`Valendo o digitado: alvo ${reais(p.cpa_alvo)}, máximo ${reais(p.cpa_maximo)}.`] : []),
];
const INPUT = 'w-full rounded border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-xs';
const BOTAO = 'rounded border border-[var(--surface-border)] px-2 py-0.5 text-xs hover:bg-slate-500/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400';
</script>

<template>
    <Head title="Leitura das campanhas" />

    <AdminLayout>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold">Leitura das campanhas</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Conjunto a conjunto, lido da Meta. Leia da esquerda para a direita: o primeiro número ruim é o problema.</p>
            </div>
            <button type="button" :class="BOTAO" class="!px-3 !py-1.5 !text-sm" :disabled="atualizando" @click="atualizar">
                <i class="fas fa-rotate-right mr-1" :class="{ 'fa-spin': atualizando }" aria-hidden="true"></i>Atualizar
            </button>
        </div>

        <details class="mb-3 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3" open>
            <summary class="cursor-pointer text-sm font-semibold">Como ler (6 passos)</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
                <li v-for="(passo, i) in COMO_LER" :key="i">{{ passo }}</li>
            </ol>
        </details>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div role="group" aria-label="Loja" class="flex gap-1">
                <button v-for="(nome, chave) in lojas" :key="chave" type="button" :aria-pressed="chave === loja" @click="ir({ loja: chave })"
                    class="rounded-full border px-3 py-1 text-sm" :class="chave === loja ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-[var(--surface-border)]'">{{ nome }}</button>
            </div>
            <label class="ml-auto flex items-center gap-1 text-sm">
                <span class="text-slate-500">Status</span>
                <select class="rounded border-[var(--surface-border)] bg-[var(--surface)] text-sm" :value="status" @change="ir({ status: $event.target.value })">
                    <option v-for="(rotulo, chave) in statusFiltros" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
            </label>
            <label class="flex items-center gap-1 text-sm">
                <span class="text-slate-500">Período</span>
                <select class="rounded border-[var(--surface-border)] bg-[var(--surface)] text-sm" :value="periodo" @change="ir({ periodo: $event.target.value })">
                    <option v-for="(rotulo, chave) in periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
            </label>
        </div>

        <p v-if="!Object.keys(lojas).length" class="rounded bg-amber-50 p-3 text-sm text-amber-800">A Meta não está configurada.</p>

        <Deferred data="dados">
            <template #fallback><p class="py-10 text-center text-sm text-slate-500">Lendo os conjuntos da Meta…</p></template>

            <p v-if="dados?.erro" class="mb-3 rounded bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ dados.erro }}</p>
            <p v-else-if="!linhas.length" class="rounded border border-[var(--surface-border)] p-6 text-center text-sm text-slate-500">Nenhum conjunto {{ status === 'ativos' ? 'ativo' : '' }} nesta conta.</p>

            <!-- Computador: tabela com cabeçalho de grupo fixo; rola só dentro do card. -->
            <div v-if="linhas.length" class="hidden max-h-[75vh] overflow-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] md:block">
                <table class="w-full border-separate border-spacing-0 text-xs">
                    <thead>
                        <tr>
                            <th rowspan="2" class="sticky left-0 top-0 z-30 w-44 min-w-44 border-b border-[var(--surface-border)] bg-[var(--surface)] p-2 text-left align-bottom">Conjunto</th>
                            <th v-for="g in GRUPOS" :key="g.nome" :colspan="g.span" scope="colgroup"
                                class="sticky top-0 z-20 h-7 border-b border-l border-[var(--surface-border)] bg-[var(--surface)] px-1 text-center font-semibold uppercase tracking-wide">
                                <span class="block rounded" :class="COR_GRUPO[g.nome]">{{ g.nome }}</span>
                            </th>
                            <th rowspan="2" class="sticky top-0 z-20 w-56 min-w-56 border-b border-l border-[var(--surface-border)] bg-[var(--surface)] p-2 text-left align-bottom">
                                O que fazer
                                <InfoTooltip label="O que é a coluna O que fazer" :paragraphs="['Olha os números na ordem (hook → CPM → CTR → CPC → página → carrinho → checkout → compra) e mostra só o primeiro problema, com a ação.', 'Corte e escala usam os dias do ciclo: gastou o CPA máximo sem vender = desligar; CPA no alvo nos 2 últimos dias = subir 20%.']" />
                            </th>
                        </tr>
                        <tr>
                            <th v-for="c in COLUNAS" :key="c.chave" scope="col" :title="c.ajuda.join(' ')"
                                class="sticky top-7 z-20 border-b border-[var(--surface-border)] bg-[var(--surface)] px-1 py-1 text-right font-medium leading-tight">
                                <span class="inline-flex items-center justify-end gap-0.5">{{ c.rotulo }} <InfoTooltip :label="`O que é ${c.rotulo}`" :paragraphs="c.ajuda" /></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in linhas" :key="l.id" class="align-top">
                            <th scope="row" class="sticky left-0 z-10 w-44 min-w-44 max-w-44 border-b border-[var(--surface-border)] bg-[var(--surface)] p-2 text-left font-normal">
                                <a :href="l.gerenciador" target="_blank" rel="noopener" class="block break-words font-semibold hover:underline">{{ l.nome }}</a>
                                <span class="block break-words text-[11px] text-slate-500">{{ l.campanha }}</span>
                                <span class="mt-0.5 inline-block rounded px-1 text-[10px]" :class="statusMeta(l.status)[1]">{{ statusMeta(l.status)[0] }}</span>
                                <!-- Ciclo -->
                                <div class="mt-1 text-[11px] text-slate-500">
                                    <template v-if="editandoCiclo[l.campanha_id] === undefined">
                                        Ciclo: {{ dataHora(l.ciclo.inicio) }}
                                        <button v-if="podeEditar" type="button" class="ml-1 text-indigo-600 underline" :aria-label="`Editar início do ciclo de ${l.campanha}`" @click="abrirCiclo(l)">editar</button>
                                        <span class="block text-[10px]">{{ ORIGEM_CICLO[l.ciclo.origem] ?? 'sem início' }}</span>
                                    </template>
                                    <form v-else class="space-y-1" @submit.prevent="salvarCiclo(l)">
                                        <label class="block">Início do ciclo<input v-model="editandoCiclo[l.campanha_id]" type="datetime-local" :class="INPUT"></label>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="submit" :class="BOTAO">Salvar</button>
                                            <button type="button" :class="BOTAO" @click="salvarCiclo(l, true)">Usar o da Meta</button>
                                            <button type="button" :class="BOTAO" @click="fecharCiclo(l)">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                                <!-- Produto e CPA -->
                                <div v-if="l.produto" class="mt-1 text-[11px]">
                                    <span class="block truncate text-slate-500" :title="l.produto.nome">{{ l.produto.nome }}</span>
                                    <template v-if="editandoCpa[l.produto.product_id] === undefined">
                                        <span class="inline-flex items-center gap-1">alvo {{ reais(l.produto.cpa_alvo) }} · máx {{ reais(l.produto.cpa_maximo) }}
                                            <InfoTooltip label="Como o CPA foi calculado" :paragraphs="contaDoCpa(l.produto)" /></span>
                                        <button v-if="podeEditar" type="button" class="ml-1 text-indigo-600 underline" :aria-label="`Editar CPA de ${l.produto.nome}`" @click="abrirCpa(l.produto)">editar</button>
                                    </template>
                                    <form v-else class="space-y-1" @submit.prevent="salvarCpa(l.produto)">
                                        <label class="block">CPA alvo (R$)<input v-model="editandoCpa[l.produto.product_id].alvo" type="number" step="0.01" min="0" :placeholder="String(l.produto.calculado_alvo)" :class="INPUT"></label>
                                        <label class="block">CPA máximo (R$)<input v-model="editandoCpa[l.produto.product_id].maximo" type="number" step="0.01" min="0" :placeholder="String(l.produto.calculado_maximo)" :class="INPUT"></label>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="submit" :class="BOTAO">Salvar</button>
                                            <button type="button" :class="BOTAO" @click="salvarCpa(l.produto, true)">Usar o calculado</button>
                                            <button type="button" :class="BOTAO" @click="fecharCpa(l.produto)">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                            </th>
                            <td v-for="c in COLUNAS" :key="c.chave" class="whitespace-nowrap border-b border-[var(--surface-border)] px-1 py-2 text-right tabular-nums"
                                :class="semaforoDe(l, c) ? SEMAFORO[semaforoDe(l, c).cor].fundo : ''">
                                <span class="inline-flex items-center gap-1" :class="semaforoDe(l, c) ? SEMAFORO[semaforoDe(l, c).cor].classe : ''">
                                    <i v-if="semaforoDe(l, c)" class="fas" :class="SEMAFORO[semaforoDe(l, c).cor].icone" aria-hidden="true"></i>
                                    <span v-if="semaforoDe(l, c)" class="sr-only">{{ semaforoDe(l, c).rotulo }}:</span>
                                    {{ c.valor(l) }}
                                </span>
                                <span v-if="c.sub && c.sub(l)" class="block text-[10px] text-slate-500">{{ c.sub(l) }}</span>
                            </td>
                            <td class="border-b border-l border-[var(--surface-border)] p-2">
                                <p class="rounded border-l-4 px-2 py-1 font-medium leading-snug" :class="NIVEL_ACAO[l.acao.nivel]">{{ l.acao.frase }}</p>
                                <p v-if="l.acao.motivo" class="mt-1 text-[11px] leading-snug text-slate-600 dark:text-slate-300">{{ l.acao.motivo }}</p>
                                <p class="mt-1 text-[10px] text-slate-500">No ciclo: {{ reais(l.ciclo.gasto) }} · {{ l.ciclo.compras }} {{ l.ciclo.compras === 1 ? 'venda' : 'vendas' }}<template v-if="l.ciclo.compras"> · sem vender desde a última: {{ reais(l.ciclo.gasto_sem_venda) }}</template></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Celular: um cartão por conjunto. -->
            <ul v-if="linhas.length" class="space-y-3 md:hidden">
                <li v-for="l in linhas" :key="l.id" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                    <a :href="l.gerenciador" target="_blank" rel="noopener" class="block font-semibold">{{ l.nome }}</a>
                    <span class="block text-xs text-slate-500">{{ l.campanha }} · ciclo {{ dataHora(l.ciclo.inicio) }}</span>
                    <span v-if="l.produto" class="block text-xs text-slate-500">CPA alvo {{ reais(l.produto.cpa_alvo) }} · máx {{ reais(l.produto.cpa_maximo) }}</span>
                    <p class="mt-2 rounded border-l-4 px-2 py-1 text-sm font-medium" :class="NIVEL_ACAO[l.acao.nivel]">{{ l.acao.frase }}</p>
                    <p v-if="l.acao.motivo" class="mt-1 text-xs">{{ l.acao.motivo }}</p>
                    <template v-for="g in GRUPOS" :key="g.nome">
                        <h3 class="mt-2 rounded px-1 text-[11px] font-semibold uppercase tracking-wide" :class="COR_GRUPO[g.nome]">{{ g.nome }}</h3>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-0.5 px-1 text-sm">
                            <template v-for="c in COLUNAS.filter((x) => x.grupo === g.nome)" :key="c.chave">
                                <dt class="text-slate-500">{{ c.rotulo }}</dt>
                                <dd class="text-right tabular-nums" :class="semaforoDe(l, c) ? SEMAFORO[semaforoDe(l, c).cor].classe : ''">
                                    <i v-if="semaforoDe(l, c)" class="fas mr-1" :class="SEMAFORO[semaforoDe(l, c).cor].icone" aria-hidden="true"></i>
                                    <span v-if="semaforoDe(l, c)" class="sr-only">{{ semaforoDe(l, c).rotulo }}:</span>{{ c.valor(l) }}
                                    <span v-if="c.sub && c.sub(l)" class="text-xs text-slate-500">({{ c.sub(l) }})</span>
                                </dd>
                            </template>
                        </dl>
                    </template>
                </li>
            </ul>

            <p v-if="dados?.gerado_em" class="mt-2 text-xs text-slate-500">
                Lido da Meta às {{ new Date(dados.gerado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) }} (guardado 5 min; “Atualizar” lê de novo).
                A Meta separa os números por dia: o ciclo conta o dia inteiro do início.
            </p>
        </Deferred>
    </AdminLayout>
</template>
