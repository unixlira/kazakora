<script setup>
/**
 * Publicidade › Estratégia Shopee (2026-10-14): campanhas de ADS da Shopee
 * com o resultado em reais e uma situação para decidir de olho. O botão
 * Ação do Cauê (AcaoCaue.vue, tarefa 569) pausa, retoma ou muda a meta de
 * ROAS só com o clique do Lira; sem SHOPEE_ADS_ESCRITA=true ele simula. Ver
 * PublicidadeShopeeController e EstrategiaShopeeAds. Visual preto com fonte
 * branca nos dois temas (.shopee-preto no app.css).
 *
 * UX do Jonathan (2026-10-14): a tela abre com a conclusão em uma frase,
 * depois "Decida hoje" e só então os 4 cards. A conta fica num <details>.
 * "Abrir na Shopee" ainda leva à página do produto: a URL da campanha na
 * Shopee Ads não está confirmada (ver EstrategiaShopeeAds::linha).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import AcaoCaue from './AcaoCaue.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    estrategia: { type: Object, required: true },
    filtro: { type: Object, required: true },
    periodos: { type: Array, default: () => [] },
    saldo: { type: [Number, null], default: null },
});

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const numero = (v) => new Intl.NumberFormat('pt-BR').format(v ?? 0);
const um = (v) => (v === null || v === undefined ? '—' : v.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));
const pct = (v) => (v === null || v === undefined ? '—' : `${um(v)}%`);
const data = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('pt-BR');
const dataHora = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : null);
const comSinal = (v) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${dinheiro(v)}`);

const SITUACAO = {
    queimando: { cor: 'bg-lighterror text-error', ponto: 'bg-error' },
    prejuizo: { cor: 'bg-lighterror text-error', ponto: 'bg-error' },
    sem_margem: { cor: 'bg-lighterror text-error ring-1 ring-error/50', ponto: 'bg-error', icone: 'fas fa-ban' },
    margem_fina: { cor: 'bg-lightwarning text-warning', ponto: 'bg-warning' },
    saudavel: { cor: 'bg-lightsuccess text-success', ponto: 'bg-success' },
    escalar: { cor: 'bg-lightinfo text-info', ponto: 'bg-info' },
    pouco_dado: { cor: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', ponto: 'bg-slate-400' },
    sem_custo: { cor: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', ponto: 'bg-slate-400' },
};
const CAUSA = { capa: 'Capa/título', pagina: 'Página/preço', lance: 'Lance', margem: 'Margem' };
const TIPO = { automatica: 'Automática', meta_roas: 'Meta de ROAS', manual: 'Manual' };
const TENDENCIA = {
    subindo: { icone: 'fas fa-arrow-trend-up text-success', texto: 'ROAS subindo nos últimos 7 dias' },
    caindo: { icone: 'fas fa-arrow-trend-down text-error', texto: 'ROAS caindo nos últimos 7 dias' },
    estavel: { icone: 'fas fa-arrow-right text-slate-400', texto: 'ROAS estável nos últimos 7 dias' },
};

const k = computed(() => props.estrategia.kpis);
const campanhas = computed(() => props.estrategia.campanhas);
const conf = computed(() => props.estrategia.conferencia);

// Frase de conclusão do topo (proposta do Jonathan, 2026-10-14).
const lucrou = computed(() => (k.value.resultado ?? 0) >= 0);
const conclusao = computed(() => {
    const base = `Em ${props.estrategia.dias} dias o ADS da Shopee deu ${dinheiro(Math.abs(k.value.resultado ?? 0))} de ${lucrou.value ? 'lucro' : 'prejuízo'}.`;
    if (!k.value.em_prejuizo) return base;
    return `${base} ${k.value.em_prejuizo} de ${k.value.com_gasto} campanhas com gasto perdem dinheiro: juntas, ${dinheiro(Math.abs(k.value.prejuizo))}. Comece pela lista abaixo.`;
});

// Cards.
const gastoDia = computed(() => (props.estrategia.dias ? k.value.gasto / props.estrategia.dias : 0));
const notaSaldo = computed(() => {
    if (props.saldo === null) return 'sem conexão com a Shopee Ads agora';
    if (gastoDia.value <= 0) return `${k.value.ativas} campanhas ativas, sem gasto no período`;
    return `dura cerca de ${Math.floor(props.saldo / gastoDia.value)} dias no ritmo atual`;
});
const notaLucro = computed(() => (k.value.em_prejuizo
    ? `${k.value.em_prejuizo} de ${k.value.com_gasto} campanhas perdendo ${dinheiro(Math.abs(k.value.prejuizo))}`
    : 'nenhuma campanha perdendo dinheiro'));
const divergente = computed(() => !conf.value.confere && conf.value.gasto_loja > 0);
const metaRoas = computed(() => k.value.roas_meta_loja);
const notaRoas = computed(() => {
    const gastoVenda = `${dinheiro(k.value.gasto)} de gasto, ${dinheiro(k.value.venda)} de venda`;
    const loja = divergente.value ? ` · Shopee Ads: ${dinheiro(conf.value.gasto_loja)} de gasto` : '';
    return `${metaRoas.value === null ? 'sem meta calculada' : `meta ${um(metaRoas.value)}`} · ${gastoVenda}${loja}`;
});
const varianteRoas = computed(() => {
    if (metaRoas.value === null || k.value.roas === null) return 'secondary';
    return k.value.roas >= metaRoas.value ? 'success' : 'error';
});
const queimando = computed(() => campanhas.value.filter((c) => c.situacao === 'queimando').length);

// Linha de ROAS do "Decida hoje".
const linhaRoas = (c) => (c.roas_empate
    ? `ROAS ${um(c.roas ?? 0)}, precisa de ${um(c.roas_empate)} para empatar`
    : `ROAS ${um(c.roas ?? 0)}, a margem não cobre ADS em nenhum ROAS`);

// Filtros (na tela; o período vai para o servidor).
const situacoes = ref([]);
const status = ref('ativas');
const tipo = ref('');
const causa = ref('');
const gastoMinimo = ref(10);
const busca = ref('');
const estoqueBaixo = ref(false);
const ordem = ref('resultado');

const filtradas = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    const lista = campanhas.value.filter((c) => {
        if (situacoes.value.length && !situacoes.value.includes(c.situacao)) return false;
        if (status.value === 'ativas' && !c.ativa) return false;
        if (status.value === 'paradas' && c.ativa) return false;
        if (tipo.value && c.tipo !== tipo.value) return false;
        if (causa.value && c.causa !== causa.value) return false;
        if (c.gasto < (Number(gastoMinimo.value) || 0)) return false;
        if (estoqueBaixo.value && !(c.dias_estoque !== null && c.dias_estoque < 15)) return false;
        if (termo && ![c.nome, c.sku, c.item_id].some((v) => String(v ?? '').toLowerCase().includes(termo))) return false;
        return true;
    });
    const chave = {
        resultado: (c) => c.resultado ?? 0,
        gasto: (c) => -c.gasto,
        roas: (c) => (c.roas !== null && c.roas_empate ? c.roas / c.roas_empate : 999),
        conversao: (c) => c.conversao ?? 999,
    }[ordem.value];
    return [...lista].sort((a, b) => chave(a) - chave(b));
});

// Contador de cada situação já com os outros filtros aplicados.
const contagem = computed(() => {
    const base = campanhas.value.filter((c) => (status.value === 'todas' || (status.value === 'ativas') === c.ativa) && c.gasto >= (Number(gastoMinimo.value) || 0));
    return Object.fromEntries(Object.keys(props.estrategia.situacoes).map((s) => [s, base.filter((c) => c.situacao === s).length]));
});

const alternar = (s) => {
    situacoes.value = situacoes.value.includes(s) ? situacoes.value.filter((x) => x !== s) : [...situacoes.value, s];
};
const limpar = () => {
    situacoes.value = [];
    status.value = 'ativas';
    tipo.value = '';
    causa.value = '';
    gastoMinimo.value = 10;
    busca.value = '';
    estoqueBaixo.value = false;
};

const desde = ref(props.filtro.desde);
const ate = ref(props.filtro.ate);
const irPeriodo = (dias) => router.get('/admin/publicidade/shopee', { dias }, { preserveScroll: true });
const irIntervalo = () => desde.value && ate.value && router.get('/admin/publicidade/shopee', { desde: desde.value, ate: ate.value }, { preserveScroll: true });

const corResultado = (v) => (v === null ? 'text-slate-400' : v < 0 ? 'text-error' : 'text-success');
const abaixo = (c) => c.roas !== null && c.roas_empate && c.roas < c.roas_empate;

// Decisão do Cauê pela situação: vem marcada no painel do botão Ação.
const DECISAO = { queimando: 'PAUSAR', sem_margem: 'PAUSAR', margem_fina: 'META' };
const produtoAcao = (c) => ({
    item_id: c.item_id,
    nome: c.nome,
    gasto: c.gasto,
    roas_real: c.roas,
    roas_empate: c.roas_empate,
    conversao: c.conversao,
    decisao: c.situacao === 'prejuizo' ? (c.roas_meta && c.roas_meta <= 25 ? 'META' : 'PAUSAR') : (DECISAO[c.situacao] ?? ''),
});
const recarregar = () => router.reload({ only: ['estrategia'], preserveScroll: true });
</script>

<template>
    <Head title="Estratégia Shopee" />

    <AdminLayout>
        <div class="shopee-preto">
        <div class="mb-2 flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fas fa-chess-knight" style="color: #EE4D2D" aria-hidden="true"></i>
                Estratégia Shopee
            </h1>
            <div class="ml-auto flex flex-wrap items-center gap-2" role="group" aria-label="Período">
                <button
                    v-for="d in periodos"
                    :key="d"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary"
                    :class="filtro.dias === d ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] hover:border-primary hover:text-primary'"
                    :aria-pressed="filtro.dias === d"
                    @click="irPeriodo(d)"
                >{{ d }} dias</button>
                <label class="sr-only" for="shp-desde">De</label>
                <input id="shp-desde" v-model="desde" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm focus-visible:outline-2 focus-visible:outline-primary" />
                <label class="sr-only" for="shp-ate">Até</label>
                <input id="shp-ate" v-model="ate" type="date" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm focus-visible:outline-2 focus-visible:outline-primary" />
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="irIntervalo">Aplicar período</button>
            </div>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            {{ data(estrategia.desde) }} a {{ data(estrategia.ate) }} · {{ dataHora(estrategia.sincronizado_em) ? `atualizado ${dataHora(estrategia.sincronizado_em)}` : 'ainda não atualizado' }}
        </p>

        <div v-if="!campanhas.length" class="mb-6 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] p-6 text-sm text-slate-500 dark:text-slate-400">
            Ainda não chegaram campanhas da Shopee. A busca roda a cada hora. Se continuar vazio, avise o Homer.
        </div>

        <template v-else>
            <p
                class="mb-4 rounded-xl border-l-4 px-4 py-3 text-base font-semibold"
                :class="lucrou ? 'border-success bg-lightsuccess text-success' : 'border-error bg-lighterror text-error'"
                role="status"
            >{{ conclusao }}</p>

            <div
                v-if="divergente"
                class="mb-4 rounded-lg border-l-4 border-warning bg-lightwarning px-4 py-2 text-sm text-warning"
                role="status"
            >
                <template v-if="conf.diferenca > 0">
                    {{ dinheiro(conf.diferenca) }} de gasto ({{ um(conf.diferenca_pct) }}%) não aparecem em nenhuma campanha da lista.
                    Pode ser campanha da loja inteira ou sincronização atrasada. Confira na Shopee Ads antes de pausar.
                </template>
                <template v-else>
                    A soma das campanhas passa {{ dinheiro(Math.abs(conf.diferenca)) }} ({{ um(conf.diferenca_pct) }}%) do gasto total da Shopee Ads.
                    Pode ser sincronização atrasada. Confira na Shopee Ads antes de pausar.
                </template>
                <span class="block text-xs">Campanhas: {{ dinheiro(conf.gasto_campanhas) }} · Shopee Ads: {{ dinheiro(conf.gasto_loja) }}.</span>
            </div>

            <section class="mb-6 rounded-xl border border-l-4 border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" :class="estrategia.decidir.length ? 'border-l-error' : 'border-l-success'" aria-labelledby="decidir-titulo">
                <h2 id="decidir-titulo" class="mb-2 text-base font-semibold">Decida hoje: as que mais tiram dinheiro</h2>
                <ol v-if="estrategia.decidir.length" class="divide-y divide-[var(--surface-border)]">
                    <li v-for="c in estrategia.decidir" :key="c.campaign_id" class="flex flex-wrap items-start gap-x-4 gap-y-2 py-3 first:pt-1 last:pb-0">
                        <div class="min-w-0 flex-1 basis-64">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full px-2 py-0.5 text-xs font-bold" :class="SITUACAO[c.situacao].cor">
                                    <i v-if="SITUACAO[c.situacao].icone" :class="SITUACAO[c.situacao].icone" aria-hidden="true"></i>
                                    <span v-else class="h-2 w-2 rounded-full" :class="SITUACAO[c.situacao].ponto" aria-hidden="true"></span>{{ estrategia.situacoes[c.situacao] }}
                                </span>
                                <span class="min-w-0 truncate font-semibold" :title="c.nome">{{ c.nome }}</span>
                            </div>
                            <p class="mt-1 text-sm font-semibold">{{ c.sugestao }}</p>
                            <p class="mt-0.5 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ linhaRoas(c) }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold tabular-nums text-error">{{ dinheiro(c.resultado) }}</span>
                            <AcaoCaue v-if="c.item_id" :produto="produtoAcao(c)" @aplicado="recarregar" />
                            <a
                                v-if="c.link"
                                :href="c.link"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="whitespace-nowrap rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                            >Abrir na Shopee<span class="sr-only"> (abre em outra aba)</span></a>
                        </div>
                    </li>
                </ol>
                <p v-else class="text-sm font-semibold text-success">Nenhuma campanha tirando dinheiro neste período.</p>
            </section>

            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <CardStats compact note-wrap stat-subtitle="SALDO DE ADS" :stat-title="saldo === null ? 'Indisponível' : dinheiro(saldo)" :stat-note="notaSaldo" stat-icon-name="fas fa-wallet" variant="neutral" />
                <CardStats compact note-wrap stat-subtitle="LUCRO DO ADS" :stat-title="comSinal(k.resultado)" :title-class="lucrou ? 'text-success' : 'text-error'" :stat-note="notaLucro" :stat-icon-name="lucrou ? 'fas fa-arrow-trend-up' : 'fas fa-triangle-exclamation'" :variant="lucrou ? 'success' : 'error'" />
                <CardStats compact note-wrap stat-subtitle="ROAS" :stat-title="um(k.roas)" :stat-note="notaRoas" stat-icon-name="fas fa-bullseye" :variant="varianteRoas" />
                <CardStats compact note-wrap stat-subtitle="GASTO SEM VENDA" :stat-title="dinheiro(k.gasto_sem_venda)" :stat-note="queimando ? `${queimando} ${queimando === 1 ? 'campanha gastou' : 'campanhas gastaram'} sem vender` : 'nenhuma campanha queimando'" stat-icon-name="fas fa-fire" :variant="k.gasto_sem_venda > 0 ? 'error' : 'neutral'" />
            </div>

            <details class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                <summary class="cursor-pointer font-medium focus-visible:outline-2 focus-visible:outline-primary">Como a conta é feita</summary>
                <p class="mt-2">
                    Lucro do ADS = venda que veio do anúncio × margem do produto − gasto com anúncio.
                    Empate é o ROAS mínimo para não perder dinheiro.
                    Meta é o ROAS que deixa 15% de lucro, pela mesma conta do
                    <Link href="/admin/dashboard-financeiro" class="font-medium text-primary hover:underline">Resultado e margem</Link>.
                </p>
            </details>

            <section class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-label="Filtros">
                <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Situação">
                    <button
                        v-for="(rotulo, s) in estrategia.situacoes"
                        :key="s"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-primary"
                        :class="situacoes.includes(s) ? 'border-primary bg-lightprimary text-primary' : 'border-[var(--surface-border)]'"
                        :aria-pressed="situacoes.includes(s)"
                        @click="alternar(s)"
                    >
                        <i v-if="SITUACAO[s].icone" :class="[SITUACAO[s].icone, 'text-error']" aria-hidden="true"></i>
                        <span v-else class="h-2 w-2 rounded-full" :class="SITUACAO[s].ponto" aria-hidden="true"></span>
                        {{ rotulo }} <span class="tabular-nums text-slate-500">{{ contagem[s] }}</span>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                    <label class="text-xs font-semibold text-slate-500 xl:col-span-2">Buscar
                        <input v-model="busca" type="search" placeholder="Nome, SKU ou item" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" />
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Status
                        <select v-model="status" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary">
                            <option value="ativas">Ativas</option>
                            <option value="paradas">Pausadas/encerradas</option>
                            <option value="todas">Todas</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Tipo
                        <select v-model="tipo" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary">
                            <option value="">Todos</option>
                            <option v-for="(r, t) in TIPO" :key="t" :value="t">{{ r }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Causa
                        <select v-model="causa" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary">
                            <option value="">Todas</option>
                            <option v-for="(r, t) in CAUSA" :key="t" :value="t">{{ r }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Gasto mínimo (R$)
                        <input v-model="gastoMinimo" type="number" min="0" step="1" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary" />
                    </label>
                    <label class="text-xs font-semibold text-slate-500">Ordenar por
                        <select v-model="ordem" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm font-normal focus-visible:outline-2 focus-visible:outline-primary">
                            <option value="resultado">Maior prejuízo</option>
                            <option value="gasto">Maior gasto</option>
                            <option value="roas">Mais longe do empate</option>
                            <option value="conversao">Menor conversão</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-3">
                        <label class="inline-flex items-center gap-2 pb-2 text-sm"><input v-model="estoqueBaixo" type="checkbox" /> Estoque &lt; 15 dias</label>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    {{ filtradas.length }} de {{ campanhas.length }} campanhas.
                    <button type="button" class="font-medium text-primary hover:underline" @click="limpar">Limpar filtros</button>
                </p>
            </section>

            <section class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-3 py-2 font-semibold">Campanha</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Situação</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Resultado</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Gasto</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Venda</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS de empate</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">ROAS p/ 15% de lucro</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Meta configurada</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Taxa de clique</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Conversão</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Custo por clique</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Pedidos</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold">Estoque</th>
                                <th scope="col" class="px-3 py-2 text-center font-semibold">7 dias</th>
                                <th scope="col" class="px-3 py-2 text-center font-semibold">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in filtradas" :key="c.campaign_id" class="border-t border-[var(--surface-border)] align-top">
                                <td class="max-w-xs px-3 py-2">
                                    <a v-if="c.link" :href="c.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 font-semibold hover:text-primary hover:underline" :title="c.nome">
                                        {{ c.nome }}<span class="sr-only"> (abre a Shopee em outra aba)</span>
                                    </a>
                                    <span v-else class="line-clamp-2 font-semibold">{{ c.nome }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ TIPO[c.tipo] }}<template v-if="c.orcamento"> · {{ dinheiro(c.orcamento) }}/dia</template><template v-if="!c.ativa"> · {{ c.status }}</template><template v-if="c.sku"> · {{ c.sku }}</template>
                                    </span>
                                    <span class="mt-1 block text-xs text-slate-600 dark:text-slate-300">{{ c.sugestao }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-bold" :class="SITUACAO[c.situacao].cor">
                                        <i v-if="SITUACAO[c.situacao].icone" :class="SITUACAO[c.situacao].icone" aria-hidden="true"></i>
                                        <span v-else class="h-2 w-2 rounded-full" :class="SITUACAO[c.situacao].ponto" aria-hidden="true"></span>{{ estrategia.situacoes[c.situacao] }}
                                    </span>
                                    <span v-if="c.causa" class="mt-1 block text-xs text-slate-500">{{ CAUSA[c.causa] }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums" :class="corResultado(c.resultado)">
                                    {{ dinheiro(c.resultado) }}
                                    <span v-if="c.estimado && c.resultado !== null" class="block text-xs font-normal text-slate-500" title="Sem venda no período: margem estimada com a taxa média da Shopee">estimado</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dinheiro(c.gasto) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinheiro(c.venda) }}
                                    <span class="block text-xs text-slate-500" title="Venda só do produto anunciado">direta {{ dinheiro(c.venda_direta) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="abaixo(c) ? 'text-error' : ''">{{ um(c.roas) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ um(c.roas_empate) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ um(c.roas_meta) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.meta_configurada && c.roas_meta && c.meta_configurada < c.roas_meta ? 'text-warning font-semibold' : ''">{{ um(c.meta_configurada) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.ctr !== null && c.impressoes >= 500 && c.ctr < 2 ? 'text-error' : ''">{{ pct(c.ctr) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="c.conversao !== null && c.cliques >= 100 && c.conversao < 1 ? 'text-error' : ''">
                                    {{ pct(c.conversao) }}
                                    <span class="block text-xs text-slate-500">{{ numero(c.cliques) }} cliques</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ dinheiro(c.cpc) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ numero(c.pedidos) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.estoque === null ? '—' : numero(c.estoque) }}
                                    <span v-if="c.dias_estoque !== null" class="block text-xs" :class="c.dias_estoque < 15 ? 'text-error' : 'text-slate-500'">{{ c.dias_estoque }} dias</span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <i v-if="c.tendencia" :class="TENDENCIA[c.tendencia].icone" :title="TENDENCIA[c.tendencia].texto" aria-hidden="true"></i>
                                    <span v-if="c.tendencia" class="sr-only">{{ TENDENCIA[c.tendencia].texto }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <AcaoCaue v-if="c.item_id" :produto="produtoAcao(c)" @aplicado="recarregar" />
                                    <span v-else class="text-slate-400" title="Campanha da loja inteira, sem produto">—</span>
                                </td>
                            </tr>
                            <tr v-if="!filtradas.length">
                                <td colspan="16" class="px-3 py-6 text-center text-slate-500">Nenhuma campanha com esses filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
        </div>
    </AdminLayout>
</template>
