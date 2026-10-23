<script setup>
/**
 * Central de campanhas (pedido do Lira em 2026-10-23; texto e UX do
 * Jonathan). Meta ADS e Google ADS numa lista só, lidas ao vivo das
 * plataformas (PublicidadeCampanhasController). Só leitura: nada aqui
 * pausa, muda orçamento ou cria campanha. "Abrir no Gerenciador" é só link.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatarNumero, formatarReais } from '@/Modules/Admin/MetaAds/metaAds';

const props = defineProps({
    periodo: { type: String, required: true },
    periodos: { type: Object, required: true },
    statusRotulos: { type: Object, required: true },
    plataformas: { type: Array, required: true },
    central: { type: Object, default: null },
});

const STATUS_CORES = {
    ativa: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    em_revisao: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    com_problema: 'bg-red-500/15 text-red-700 dark:text-red-300',
    pausada: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    encerrada: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
};
const PRIORIDADE = {
    urgente: ['Urgente', 'bg-red-500/15 text-red-700 dark:text-red-300', 'bg-red-500'],
    atencao: ['Atenção', 'bg-amber-500/15 text-amber-800 dark:text-amber-300', 'bg-amber-500'],
};

const decimal = (v, casas = 2) => (v === null || v === undefined ? '-' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }));
const roas = (v) => (v === null || v === undefined ? '-' : `${decimal(v)}x`);
const pct = (v) => (v === null || v === undefined ? '-' : `${decimal(v)}%`);

const plataformaDe = computed(() => Object.fromEntries(props.plataformas.map((p) => [p.canal, p])));
const conectadas = computed(() => props.plataformas.filter((p) => p.conectada));
const desconectadas = computed(() => props.plataformas.filter((p) => !p.conectada));

// Período: recarrega só a parte ao vivo.
const carregando = ref(false);
const trocarPeriodo = (periodo) => {
    if (periodo === props.periodo) return;
    router.get('/admin/publicidade/campanhas', {
        ...(periodo === '7d' ? {} : { periodo }),
        ...(filtroPlataforma.value === 'todas' ? {} : { plataforma: filtroPlataforma.value }),
        ...(filtroStatus.value === 'todos' ? {} : { status: filtroStatus.value }),
    }, {
        only: ['central', 'periodo'],
        preserveScroll: true,
        preserveState: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

// Filtros e ordenação da lista (no navegador, a lista já veio inteira).
// Os filtros ficam na URL (?plataforma=&status=) para o link abrir igual.
const daUrl = (chave, padrao) => (typeof window === 'undefined' ? padrao : new URLSearchParams(window.location.search).get(chave) ?? padrao);
const filtroPlataforma = ref(daUrl('plataforma', 'todas'));
const filtroStatus = ref(daUrl('status', 'todos'));
watch([filtroPlataforma, filtroStatus], ([plataforma, status]) => {
    const url = new URL(window.location.href);
    plataforma === 'todas' ? url.searchParams.delete('plataforma') : url.searchParams.set('plataforma', plataforma);
    status === 'todos' ? url.searchParams.delete('status') : url.searchParams.set('status', status);
    window.history.replaceState(window.history.state, '', url);
});
const ordem = ref({ campo: 'gasto', desc: true });
const COLUNAS = [
    { campo: 'nome', rotulo: 'Campanha', texto: true },
    { campo: 'status', rotulo: 'Status', texto: true },
    { campo: 'orcamento_diario', rotulo: 'Orçamento/dia' },
    { campo: 'gasto', rotulo: 'Gasto' },
    { campo: 'impressoes', rotulo: 'Impressões' },
    { campo: 'cliques', rotulo: 'Cliques' },
    { campo: 'ctr', rotulo: 'CTR' },
    { campo: 'cpc', rotulo: 'CPC' },
    { campo: 'resultados', rotulo: 'Compras' },
    { campo: 'custo_por_resultado', rotulo: 'Custo/compra' },
    { campo: 'roas', rotulo: 'ROAS' },
];
const ORDEM_STATUS = ['ativa', 'em_revisao', 'com_problema', 'pausada', 'encerrada'];
const ordenarPor = (campo) => {
    ordem.value = ordem.value.campo === campo
        ? { campo, desc: !ordem.value.desc }
        : { campo, desc: !COLUNAS.find((c) => c.campo === campo)?.texto };
};
const ariaSort = (campo) => (ordem.value.campo === campo ? (ordem.value.desc ? 'descending' : 'ascending') : 'none');

const todas = computed(() => props.central?.campanhas ?? []);
const contagemStatus = computed(() => {
    const base = todas.value.filter((c) => filtroPlataforma.value === 'todas' || c.plataforma === filtroPlataforma.value);
    return Object.fromEntries(Object.keys(props.statusRotulos).map((s) => [s, base.filter((c) => c.status === s).length]));
});
const lista = computed(() => {
    const { campo, desc } = ordem.value;
    const valor = (c) => (campo === 'status' ? ORDEM_STATUS.indexOf(c.status) : c[campo]);
    return todas.value
        .filter((c) => filtroPlataforma.value === 'todas' || c.plataforma === filtroPlataforma.value)
        .filter((c) => filtroStatus.value === 'todos' || c.status === filtroStatus.value)
        .slice()
        .sort((a, b) => {
            const va = valor(a);
            const vb = valor(b);
            // Sem valor (sem clique, sem compra) vai sempre para o fim.
            if (va === null || va === undefined) return 1;
            if (vb === null || vb === undefined) return -1;
            const r = typeof va === 'string' ? va.localeCompare(vb, 'pt-BR') : va - vb;
            return desc ? -r : r;
        });
});

const verTodosAlertas = ref(false);
const alertas = computed(() => props.central?.alertas ?? []);
const alertasVisiveis = computed(() => (verTodosAlertas.value ? alertas.value : alertas.value.slice(0, 4)));

const hora = computed(() => (props.central?.gerado_em
    ? new Date(props.central.gerado_em).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' })
    : null));
const periodoTexto = computed(() => props.periodos[props.periodo]?.toLowerCase());
</script>

<template>
    <Head title="Campanhas Meta e Google" />

    <AdminLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-bold">Campanhas Meta e Google</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                Status e números de cada campanha, lidos ao vivo das plataformas. Esta tela só mostra:
                pausar, mudar orçamento ou criar campanha continua no Gerenciador.
            </p>
        </header>

        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="inline-flex flex-wrap rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-1" role="group" aria-label="Período">
                <button
                    v-for="(rotulo, chave) in periodos"
                    :key="chave"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    :class="chave === periodo ? 'bg-primary text-white' : 'text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300'"
                    :aria-pressed="chave === periodo"
                    @click="trocarPeriodo(chave)"
                >{{ rotulo }}</button>
            </div>
            <span v-if="carregando" class="inline-flex items-center gap-2 text-sm text-slate-500" role="status" aria-live="polite">
                <i class="fas fa-circle-notch animate-spin text-primary motion-reduce:animate-none" aria-hidden="true"></i> Atualizando…
            </span>
            <span v-else-if="hora" class="text-xs text-slate-500">Lido às {{ hora }}</span>
        </div>

        <Deferred data="central">
            <template #fallback>
                <div class="space-y-4" role="status" aria-live="polite">
                    <span class="sr-only">Consultando as plataformas…</span>
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div v-for="n in 2" :key="n" class="h-36 animate-pulse rounded-xl motion-reduce:animate-none border border-[var(--surface-border)] bg-[var(--surface)]"></div>
                    </div>
                    <div class="h-64 animate-pulse rounded-xl motion-reduce:animate-none border border-[var(--surface-border)] bg-[var(--surface)]"></div>
                </div>
            </template>

            <!-- Totais por plataforma -->
            <section class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2" aria-label="Totais por plataforma">
                <article
                    v-for="p in plataformas"
                    :key="p.canal"
                    class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm"
                >
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="flex items-center gap-2 text-base font-bold">
                            <i :class="p.icone" :style="{ color: p.cor }" aria-hidden="true"></i> {{ p.nome }}
                        </h2>
                        <span v-if="!p.conectada" class="rounded-full bg-slate-500/15 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:text-slate-300">Não conectada</span>
                        <span v-else-if="central.porPlataforma[p.canal]?.erro" class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:text-amber-300">Sem resposta</span>
                        <span v-else class="text-xs text-slate-500">
                            {{ central.porPlataforma[p.canal].totais.ativas }} ativas de {{ central.porPlataforma[p.canal].totais.campanhas }}
                        </span>
                    </div>

                    <p v-if="!p.conectada" class="text-sm text-slate-500 dark:text-slate-400">
                        Sem dados ainda. <a :href="`#conectar-${p.canal}`" class="font-medium text-primary hover:underline">Veja o que falta para conectar</a>.
                    </p>
                    <p v-else-if="central.porPlataforma[p.canal]?.erro" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="alert">
                        Não deu para ler a {{ p.nome }} agora ({{ central.porPlataforma[p.canal].erro }}). Tente de novo em alguns minutos; se continuar, o acesso pode ter vencido.
                    </p>
                    <dl v-else class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs text-slate-500">Gasto</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ formatarReais(central.porPlataforma[p.canal].totais.gasto) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Cliques</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ formatarNumero(central.porPlataforma[p.canal].totais.cliques) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Compras</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ formatarNumero(central.porPlataforma[p.canal].totais.resultados) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">ROAS</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ roas(central.porPlataforma[p.canal].totais.roas) }}</dd>
                        </div>
                    </dl>
                </article>
            </section>

            <!-- Alertas -->
            <section v-if="alertas.length" class="mb-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="alertas-titulo">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="alertas-titulo" class="text-base font-bold">O que pede ação agora</h2>
                    <span class="text-xs text-slate-500">{{ alertas.length }} {{ alertas.length === 1 ? 'alerta' : 'alertas' }}</span>
                </div>
                <ul class="space-y-2">
                    <li
                        v-for="(a, i) in alertasVisiveis"
                        :key="`${a.plataforma}-${i}-${a.titulo}`"
                        class="relative overflow-hidden rounded-lg border border-[var(--surface-border)] py-2.5 pl-4 pr-3"
                    >
                        <span class="absolute inset-y-0 left-0 w-1" :class="PRIORIDADE[a.prioridade]?.[2] ?? 'bg-slate-400'" aria-hidden="true"></span>
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm">
                                    <i :class="plataformaDe[a.plataforma]?.icone" :style="{ color: plataformaDe[a.plataforma]?.cor }" aria-hidden="true"></i>
                                    <span class="sr-only">{{ plataformaDe[a.plataforma]?.nome }}:</span>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="PRIORIDADE[a.prioridade]?.[1]">{{ PRIORIDADE[a.prioridade]?.[0] ?? 'Aviso' }}</span>
                                    <strong>{{ a.titulo }}</strong>
                                </p>
                                <p class="mt-0.5 truncate text-sm text-slate-700 dark:text-slate-200" :title="a.alvo">{{ a.alvo }}</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ a.acao }}</p>
                                <p v-if="a.prova" class="mt-0.5 text-xs tabular-nums text-slate-500">{{ a.prova }}</p>
                            </div>
                            <a v-if="a.link" :href="a.link" target="_blank" rel="noopener" class="shrink-0 text-sm font-medium text-primary hover:underline">
                                Abrir no Gerenciador <i class="fas fa-arrow-up-right-from-square text-[10px]" aria-hidden="true"></i>
                                <span class="sr-only">(abre em outra aba)</span>
                            </a>
                        </div>
                    </li>
                </ul>
                <button
                    v-if="alertas.length > 4"
                    type="button"
                    class="mt-3 text-sm font-medium text-primary hover:underline"
                    :aria-expanded="verTodosAlertas"
                    @click="verTodosAlertas = !verTodosAlertas"
                >{{ verTodosAlertas ? 'Mostrar menos' : `Ver todos (${alertas.length})` }}</button>
                <p class="mt-2 text-xs text-slate-500">Alertas de criativo e conjunto usam os últimos 7 dias; os de campanha, o período escolhido.</p>
            </section>

            <!-- Lista de campanhas -->
            <section v-if="conectadas.length" class="mb-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" aria-labelledby="lista-titulo">
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--surface-border)] p-4">
                    <div>
                        <h2 id="lista-titulo" class="text-base font-bold">Campanhas</h2>
                        <p class="text-xs text-slate-500">{{ lista.length }} de {{ todas.length }} · {{ periodoTexto }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600 dark:text-slate-300">
                            Plataforma
                            <select v-model="filtroPlataforma" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 dark:text-slate-100">
                                <option value="todas">Todas</option>
                                <option v-for="p in plataformas" :key="p.canal" :value="p.canal">{{ p.nome }}</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-slate-600 dark:text-slate-300">
                            Status
                            <select v-model="filtroStatus" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 dark:text-slate-100">
                                <option value="todos">Todos</option>
                                <option v-for="(rotulo, chave) in statusRotulos" :key="chave" :value="chave">{{ rotulo }} ({{ contagemStatus[chave] }})</option>
                            </select>
                        </label>
                    </div>
                </div>

                <p v-if="!lista.length" class="px-4 py-8 text-center text-sm text-slate-500">
                    {{ todas.length ? 'Nenhuma campanha com esse filtro.' : 'Nenhuma campanha com movimento neste período.' }}
                </p>

                <!-- Tabela (tela larga) -->
                <div v-if="lista.length" class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-3 py-2 font-semibold">
                                    <span class="sr-only">Plataforma</span>
                                </th>
                                <th
                                    v-for="col in COLUNAS"
                                    :key="col.campo"
                                    scope="col"
                                    :aria-sort="ariaSort(col.campo)"
                                    class="whitespace-nowrap px-3 py-2 font-semibold"
                                    :class="col.texto ? 'text-left' : 'text-right'"
                                >
                                    <button type="button" class="inline-flex items-center gap-1 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 dark:hover:text-slate-100" @click="ordenarPor(col.campo)">
                                        {{ col.rotulo }}
                                        <i class="fas text-[10px]" :class="ordem.campo === col.campo ? (ordem.desc ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort opacity-40'" aria-hidden="true"></i>
                                    </button>
                                </th>
                                <th scope="col" class="px-3 py-2"><span class="sr-only">Links</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in lista" :key="c.chave" class="border-t border-[var(--surface-border)] align-top hover:bg-[var(--surface-muted)]">
                                <td class="px-3 py-2.5">
                                    <i :class="plataformaDe[c.plataforma]?.icone" :style="{ color: plataformaDe[c.plataforma]?.cor }" :title="plataformaDe[c.plataforma]?.nome" aria-hidden="true"></i>
                                    <span class="sr-only">{{ plataformaDe[c.plataforma]?.nome }}</span>
                                </td>
                                <td class="max-w-[18rem] px-3 py-2.5">
                                    <Link v-if="c.detalhe" :href="c.detalhe" class="block truncate font-semibold hover:text-primary hover:underline" :title="c.nome">{{ c.nome }}</Link>
                                    <span v-else class="block truncate font-semibold" :title="c.nome">{{ c.nome }}</span>
                                    <span v-if="c.objetivo" class="text-xs text-slate-500">{{ c.objetivo }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS_CORES[c.status]" :title="c.status_plataforma ? `Na plataforma: ${c.status_plataforma}` : undefined">{{ c.status_rotulo }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ c.orcamento_diario ? formatarReais(c.orcamento_diario) : '-' }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ formatarReais(c.gasto) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatarNumero(c.impressoes) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatarNumero(c.cliques) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ pct(c.ctr) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ c.cpc === null ? '-' : formatarReais(c.cpc) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatarNumero(c.resultados) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ c.custo_por_resultado === null ? '-' : formatarReais(c.custo_por_resultado) }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ roas(c.roas) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                    <a v-if="c.gerenciador" :href="c.gerenciador" target="_blank" rel="noopener" class="text-xs font-medium text-primary hover:underline">
                                        Abrir no Gerenciador<span class="sr-only"> (abre em outra aba)</span>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cartões (celular) -->
                <ul v-if="lista.length" class="divide-y divide-[var(--surface-border)] md:hidden">
                    <li v-for="c in lista" :key="c.chave" class="p-4">
                        <div class="flex items-start gap-2">
                            <i :class="plataformaDe[c.plataforma]?.icone" :style="{ color: plataformaDe[c.plataforma]?.cor }" class="mt-1" aria-hidden="true"></i>
                            <span class="sr-only">{{ plataformaDe[c.plataforma]?.nome }}:</span>
                            <div class="min-w-0 flex-1">
                                <Link v-if="c.detalhe" :href="c.detalhe" class="block font-semibold hover:text-primary hover:underline">{{ c.nome }}</Link>
                                <span v-else class="block font-semibold">{{ c.nome }}</span>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <span class="rounded-full px-2 py-0.5 font-semibold" :class="STATUS_CORES[c.status]">{{ c.status_rotulo }}</span>
                                    <span v-if="c.orcamento_diario">{{ formatarReais(c.orcamento_diario) }}/dia</span>
                                </div>
                            </div>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                            <div><dt class="text-xs text-slate-500">Gasto</dt><dd class="font-semibold tabular-nums">{{ formatarReais(c.gasto) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Compras</dt><dd class="tabular-nums">{{ formatarNumero(c.resultados) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">ROAS</dt><dd class="font-semibold tabular-nums">{{ roas(c.roas) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Cliques</dt><dd class="tabular-nums">{{ formatarNumero(c.cliques) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">CTR</dt><dd class="tabular-nums">{{ pct(c.ctr) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">CPC</dt><dd class="tabular-nums">{{ c.cpc === null ? '-' : formatarReais(c.cpc) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">Impressões</dt><dd class="tabular-nums">{{ formatarNumero(c.impressoes) }}</dd></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-500">Custo por compra</dt><dd class="tabular-nums">{{ c.custo_por_resultado === null ? '-' : formatarReais(c.custo_por_resultado) }}</dd></div>
                        </dl>
                        <a v-if="c.gerenciador" :href="c.gerenciador" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-medium text-primary hover:underline">
                            Abrir no Gerenciador<span class="sr-only"> (abre em outra aba)</span>
                        </a>
                    </li>
                </ul>

                <p v-if="central.ocultas" class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-slate-500">
                    {{ central.ocultas }} {{ central.ocultas === 1 ? 'campanha encerrada sem gasto no período ficou fora' : 'campanhas encerradas sem gasto no período ficaram fora' }} da lista.
                </p>
            </section>
        </Deferred>

        <!-- Plataformas sem integração -->
        <section
            v-for="p in desconectadas"
            :id="`conectar-${p.canal}`"
            :key="p.canal"
            class="mb-5 scroll-mt-20 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm"
            :aria-labelledby="`conectar-${p.canal}-titulo`"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-xl" :style="{ color: p.cor, background: `${p.cor}1A` }" aria-hidden="true">
                    <i :class="p.icone"></i>
                </div>
                <div class="min-w-0">
                    <h2 :id="`conectar-${p.canal}-titulo`" class="text-base font-bold">{{ p.nome }} ainda não está conectado</h2>
                    <p class="mt-1 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                        Nenhum número do {{ p.nome }} aparece aqui até a integração ser ligada. Quando estiver, as campanhas entram
                        nesta mesma lista, ao lado das outras, com os mesmos filtros.
                    </p>
                    <h3 class="mt-4 text-sm font-semibold">O que falta</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                        <li v-for="item in p.precisa" :key="item">{{ item }}</li>
                    </ol>
                    <a
                        :href="p.painel"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                    >
                        Abrir o {{ p.painel_nome }}
                        <i class="fas fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                        <span class="sr-only">(abre em outra aba)</span>
                    </a>
                </div>
            </div>
        </section>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            ROAS é o da própria plataforma: venda que ela atribui ao anúncio dividida pelo gasto. Lucro de verdade, já com o ADS descontado, fica em
            <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Resultado e margem</Link>.
        </p>
    </AdminLayout>
</template>
