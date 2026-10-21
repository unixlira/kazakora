<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { computed, h, ref } from 'vue';
import DiagnosticoMeta from './DiagnosticoMeta.vue';
import EspelhoMeta from './EspelhoMeta.vue';
import { formatarData, formatarReais, statusDaCampanhaLocal } from './metaAds';
import { useStatusAoVivo } from './useStatusAoVivo';

const props = defineProps({
    campanhas: { type: Array, default: () => [] },
    config: { type: Object, required: true },
    periodo: { type: String, default: 'maximum' },
    periodos: { type: Object, default: () => ({}) },
    // Prop adiada: estrutura e métricas lidas ao vivo da Meta.
    espelho: { type: Object, default: null },
    // Modal de editar anúncio no espelho (2026-10-19).
    produtos: { type: Array, default: () => [] },
    chamadas: { type: Object, default: () => ({}) },
});

const recarregar = () => router.reload({ only: ['espelho', 'campanhas'] });

// Status ao vivo (pedido do Lira 2026-10-21): a cada 20 s a tela pergunta à
// Meta o status de tudo; campanha do KazaKora que mudou de fase recarrega a lista.
const { estado: aoVivo } = useStatusAoVivo({ aoMudarLocal: () => router.reload({ only: ['campanhas'] }) });
const campanhasAoVivo = computed(() => props.campanhas.map((c) => {
    const agora = aoVivo.locais[c.id];
    return agora ? { ...c, status: agora.status, status_rotulo: agora.status_rotulo, ultimo_erro: agora.ultimo_erro, status_meta: agora.status_meta } : c;
}));
const horaAoVivo = computed(() => (aoVivo.lidoEm
    ? new Date(aoVivo.lidoEm).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'America/Sao_Paulo' })
    : null));

// Traz para o KazaKora o que mudou direto no Gerenciador (nomes, links...).
const sincronizando = ref(false);
const sincronizar = () => router.post('/admin/publicidade/meta-ads/campanhas/sincronizar', {}, {
    preserveScroll: true,
    onStart: () => { sincronizando.value = true; },
    onFinish: () => { sincronizando.value = false; },
});

const carregando = ref(false);
const trocarPeriodo = (periodo) => router.get('/admin/publicidade/meta-ads/campanhas', periodo === 'maximum' ? {} : { periodo }, {
    only: ['espelho', 'periodo'],
    preserveScroll: true,
    preserveState: true,
    onStart: () => { carregando.value = true; },
    onFinish: () => { carregando.value = false; },
});

const { can } = usePermissions();

// Cartões do "O que fazer agora" (2026-10-21) levam à linha e ao modal do espelho.
const tabela = ref(null);
const verNaTabela = (alvo) => tabela.value?.mostrar(alvo.tipo, alvo.id);
const editarAlvo = (alvo) => tabela.value?.editar(alvo.tipo, alvo.id);

const periodo = (c) => (c.fim ? `${formatarData(c.inicio)} a ${formatarData(c.fim)}` : `A partir de ${formatarData(c.inicio)}`);

const columns = [
    {
        accessorKey: 'nome',
        header: 'Campanha',
        cell: ({ row }) => h('div', [
            h(Link, { href: `/admin/publicidade/meta-ads/campanhas/${row.original.id}`, class: 'font-semibold text-primary hover:underline' }, () => row.original.nome),
            h('div', { class: 'text-xs text-slate-500' }, row.original.objetivo_rotulo),
        ]),
    },
    {
        id: 'status',
        header: 'Status',
        accessorFn: (row) => statusDaCampanhaLocal(row.status, row.status_meta, row.status_rotulo)[0],
        cell: ({ row }) => {
            const [rotulo, cores, dica] = statusDaCampanhaLocal(row.original.status, row.original.status_meta, row.original.status_rotulo, row.original.ultimo_erro);
            return h('span', { class: `inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold ${cores}`, title: dica }, [
                row.original.status === 'enviando' ? h('i', { class: 'fas fa-circle-notch animate-spin text-[10px]', 'aria-hidden': 'true' }) : null,
                rotulo,
            ]);
        },
    },
    { accessorKey: 'orcamento_diario', header: 'Orçamento/dia', cell: ({ row }) => formatarReais(row.original.orcamento_diario) },
    { id: 'periodo', header: 'Período', accessorFn: (row) => periodo(row) },
    { id: 'estrutura', header: 'Conjuntos · anúncios', accessorFn: (row) => `${row.conjuntos} · ${row.videos}` },
    { id: 'criada_por', header: 'Criada por', accessorFn: (row) => row.criada_por ?? '-' },
    {
        id: 'acoes',
        header: '',
        enableSorting: false,
        cell: ({ row }) => (can('financeiro.create') && row.original.novo_conjunto
            ? h(Link, {
                href: `/admin/publicidade/meta-ads/campanhas/${row.original.id}/conjuntos/novo`,
                class: 'inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-primary/40 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/10',
            }, () => [h('i', { class: 'fas fa-plus text-[10px]', 'aria-hidden': 'true' }), ' Novo conjunto'])
            : null),
    },
];
</script>

<template>
    <Head title="Campanhas Meta" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-2xl font-bold">
                <span class="flex items-center gap-3">
                    <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                    Meta
                </span>
                <span class="text-base font-normal text-slate-500 dark:text-slate-400">campanhas no Facebook e Instagram</span>
            </h1>
            <div class="flex flex-wrap gap-2">
                <Link href="/admin/publicidade/meta-ads"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fas fa-chart-line text-xs" aria-hidden="true"></i> Gasto e resultados
                </Link>
                <button v-if="can('financeiro.create') && props.config.configurado" type="button" :disabled="sincronizando"
                    title="Traz da Meta nomes, orçamento, status, textos e links de destino mudados no Gerenciador"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:opacity-60"
                    @click="sincronizar">
                    <i :class="['fas fa-arrows-rotate text-xs', { 'animate-spin': sincronizando }]" aria-hidden="true"></i>
                    {{ sincronizando ? 'Sincronizando…' : 'Sincronizar com a Meta' }}
                </button>
                <Link v-if="can('financeiro.create')" href="/admin/publicidade/meta-ads/campanhas/nova"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Nova campanha
                </Link>
            </div>
        </div>

        <div v-if="!props.config.configurado" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Meta não configurada.</strong>
            Dá para montar rascunhos e subir os vídeos, mas o envio fica desligado até preencher no servidor:
            {{ props.config.faltando.join(', ') }}.
        </div>

        <section v-if="props.config.configurado" class="mb-8" aria-labelledby="na-meta-titulo">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="na-meta-titulo" class="text-lg font-bold">Na Meta agora</h2>
                    <p class="text-sm text-slate-500">Campanhas, conjuntos e anúncios como estão no Gerenciador, com os números da própria Meta.</p>
                    <p class="mt-1 flex items-center gap-1.5 text-xs text-slate-500" role="status" aria-live="polite">
                        <span class="h-2 w-2 rounded-full" :class="aoVivo.erro ? 'bg-amber-500' : 'bg-emerald-500 motion-safe:animate-pulse'" aria-hidden="true"></span>
                        <template v-if="aoVivo.erro">Status ao vivo parado: {{ aoVivo.erro }}</template>
                        <template v-else-if="horaAoVivo">Status ao vivo, conferido na Meta às {{ horaAoVivo }}. Atualiza sozinho a cada 20 s.</template>
                        <template v-else>Conferindo o status na Meta…</template>
                    </p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500">Período</span>
                    <select :value="props.periodo" :disabled="carregando"
                        class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm"
                        @change="trocarPeriodo($event.target.value)">
                        <option v-for="(rotulo, chave) in props.periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                    <i v-if="carregando" class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
                </label>
            </div>
            <Deferred data="espelho">
                <template #fallback>
                    <div class="flex items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm text-slate-500" role="status" aria-live="polite">
                        <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i> Consultando a Meta…
                    </div>
                </template>
                <p v-if="props.espelho?.erro" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    Não deu para ler a Meta agora: {{ props.espelho.erro }}
                </p>
                <DiagnosticoMeta v-if="!props.espelho?.erro" :diagnostico="props.espelho?.diagnostico" :periodos="props.periodos"
                    :pode-editar="can('financeiro.create')" @ver="verNaTabela" @editar="editarAlvo" @ultimos7="trocarPeriodo('last_7d')" />
                <EspelhoMeta v-if="!props.espelho?.erro" ref="tabela" :campanhas="props.espelho?.campanhas ?? []" :ao-vivo="aoVivo.meta" :diagnostico="props.espelho?.diagnostico" :acoes="can('financeiro.create')" :editar-anuncios="can('financeiro.create')"
                    :produtos="props.produtos" :chamadas="props.chamadas" :class="{ 'opacity-60': carregando }" @mudou="recarregar" />
            </Deferred>
        </section>

        <h2 class="mb-1 text-lg font-bold">Montadas no KazaKora</h2>
        <p class="mb-4 text-sm text-slate-500">
            Monte a campanha como no Gerenciador da Meta: vários conjuntos e vários anúncios em cada, de uma vez.
            Tudo vai para a Meta <strong>pausado</strong>; só começa a gastar quando você clicar em Ativar.
        </p>

        <DataTable
            :columns="columns"
            :data="campanhasAoVivo"
            search-placeholder="Buscar campanha..."
            empty-message="Nenhuma campanha ainda."
        />
    </AdminLayout>
</template>
