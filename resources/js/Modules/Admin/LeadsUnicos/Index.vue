<script setup>
// Leads, tela única (plano do Lira 25/11/2026): junta Leads, Carrinhos
// abandonados e Sequência numa lista só, sem rolagem lateral. O detalhe fica
// em duas janelas por linha: Remarketing (envios WhatsApp/e-mail) e Jornada
// (passo a passo do checkout até o pagamento). Atualiza a lista a cada 5 s
// sem fechar a janela aberta. Só consulta + as duas ações que já existiam
// (antecipar a 1ª mensagem, um ou todos). Rota temporária /admin/leads-v2.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { DataTable, StatusBadge } from '@/Shared/Components/DataTable';
import { maskPhone } from '@/Shared/useMasks';
import { useServerTable } from '@/Shared/useServerTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, h, onMounted, onUnmounted, ref } from 'vue';
import JornadaModal from './JornadaModal.vue';
import RemarketingModal from './RemarketingModal.vue';
import { dataHora, dinheiro, relativo } from './formato';

const props = defineProps({
    leads: { type: Object, default: () => ({ data: [] }) },
    resumo: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    base: { type: String, default: '/admin/leads-v2' },
});

const { visit } = useServerTable(props.base, {
    loja: props.filters.loja ?? null,
    status: props.filters.status ?? null,
    q: props.filters.q ?? null,
});

// Tempo real: recarga parcial (só lista e cards) a cada 5 s, parada com a aba escondida.
// As janelas guardam só a chave da linha e carregam o detalhe sozinhas: a recarga não as fecha.
const INTERVALO_MS = 5000;
let relogio = null;
let recarregando = false;
const recarregar = () => {
    if (document.hidden || recarregando) return;
    recarregando = true;
    router.reload({ only: ['leads', 'resumo'], preserveScroll: true, preserveState: true, onFinish: () => { recarregando = false; } });
};
onMounted(() => { relogio = setInterval(recarregar, INTERVALO_MS); });
onUnmounted(() => clearInterval(relogio));

const linhas = computed(() => props.leads?.data ?? []);
const nomeDaLoja = (id) => props.sites.find((s) => s.id === id)?.nome ?? id;
const COR_LOJA = { kazakora: 'bg-orange-100 text-orange-800', ferrakora: 'bg-indigo-100 text-indigo-800', beautykora: 'bg-pink-100 text-pink-800' };

const chips = [
    { label: 'Todos', value: null },
    { label: 'No checkout', value: 'agora' },
    { label: 'Abertos', value: 'abertos' },
    { label: 'Em remarketing', value: 'remarketing' },
    { label: 'Pagos', value: 'pagos' },
    { label: 'Parados', value: 'parados' },
];
const cards = computed(() => [
    { rotulo: 'No checkout agora (5 min)', valor: props.resumo.agora ?? 0, chip: 'agora', pulsa: (props.resumo.agora ?? 0) > 0 },
    { rotulo: 'Abertos', valor: props.resumo.abertos ?? 0, chip: 'abertos' },
    { rotulo: 'Valor em aberto', valor: dinheiro(props.resumo.valor_aberto), chip: 'abertos' },
    { rotulo: 'Em remarketing', valor: props.resumo.remarketing ?? 0, chip: 'remarketing' },
    { rotulo: 'Pagos', valor: props.resumo.pagos ?? 0, chip: 'pagos' },
    { rotulo: 'Pararam', valor: props.resumo.parados ?? 0, chip: 'parados' },
]);

const busca = ref(props.filters.q ?? '');
let buscaTimer = null;
const buscar = () => {
    clearTimeout(buscaTimer);
    buscaTimer = setTimeout(() => visit({ q: busca.value }), 350);
};
onUnmounted(() => clearTimeout(buscaTimer));

// Janelas (só a chave e o nome; o conteúdo vem do servidor quando abre).
const remarketingDe = ref(null);
const jornadaDe = ref(null);
const abrirRemarketing = (l) => { remarketingDe.value = { chave: l.chave, nome: l.nome }; };
const abrirJornada = (l) => { jornadaDe.value = { chave: l.chave, nome: l.nome }; };

// Antecipar a 1ª mensagem: o mesmo serviço do botão "WhatsApp + e-mail" de Carrinhos abandonados.
const confirmar = ref(null); // { chave, nome } ou 'todos'
const enviando = ref(false);
const antecipar = () => {
    const alvo = confirmar.value;
    enviando.value = true;
    const url = alvo === 'todos' ? `${props.base}/antecipar-todos` : `${props.base}/${alvo.chave}/antecipar`;
    router.post(url, alvo === 'todos' ? { loja: props.filters.loja ?? null } : {}, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; confirmar.value = null; },
    });
};

const resumoDaSituacao = (l) => {
    const s = l.situacao ?? {};
    return s.detalhe ?? null;
};

const botao = (titulo, icone, cor, onClick) => h('button', {
    type: 'button',
    title: titulo,
    'aria-label': titulo,
    class: `inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-300 ${cor} hover:bg-slate-50`,
    onClick,
}, [h('i', { class: `fas ${icone} text-xs`, 'aria-hidden': 'true' })]);

const acoes = (l) => [
    botao('Remarketing', 'fa-bullhorn', 'text-orange-600', () => abrirRemarketing(l)),
    botao('Jornada', 'fa-route', 'text-sky-700', () => abrirJornada(l)),
    l.pode_antecipar ? botao('Antecipar 1ª mensagem (WhatsApp + e-mail)', 'fa-paper-plane', 'text-emerald-700', () => { confirmar.value = { chave: l.chave, nome: l.nome }; }) : null,
];

const chipLoja = (site) => h('span', { class: ['shrink-0 rounded px-1.5 py-px text-[10px] font-semibold', COR_LOJA[site] ?? 'bg-slate-100 text-slate-700'] }, nomeDaLoja(site));

const badgeSituacao = (l) => h('div', { class: 'flex min-w-0 flex-wrap items-center gap-1' }, [
    h(StatusBadge, { status: l.situacao.codigo, tone: l.situacao.tom, label: l.situacao.rotulo, class: l.situacao.codigo === 'agora' ? 'animate-pulse' : '' }),
    l.pedido ? h(Link, { href: `/admin/pedidos/${l.pedido.id}`, class: 'truncate text-[11px] text-sky-700 hover:underline', title: 'Abrir o pedido' }, () => `#${l.pedido.numero ?? l.pedido.id}`) : null,
]);

// 5 colunas + ações, larguras do plano (§4.3), com uma troca: Ações 8% e Última atividade 12%
// (em 6% os três ícones empilhavam e a linha passava de 3 linhas em 1280/1366). Cada célula no máximo 3 linhas; texto longo com reticências e title.
const columns = [
    {
        id: 'cliente',
        header: 'Cliente',
        enableSorting: false,
        meta: { width: '26%' },
        cell: ({ row }) => {
            const l = row.original;
            return h('div', { class: 'min-w-0 leading-snug' }, [
                h('div', { class: 'flex min-w-0 items-center gap-1.5' }, [
                    h('span', { class: 'truncate font-semibold', title: l.nome ?? '' }, l.nome ?? 'Sem nome'),
                    chipLoja(l.site),
                ]),
                h('div', { class: 'truncate text-xs text-slate-600', title: l.email ?? '' }, l.email ?? '—'),
                h('div', { class: 'truncate text-xs text-slate-500' }, l.telefone ? maskPhone(l.telefone) : '—'),
            ]);
        },
    },
    {
        id: 'carrinho',
        header: 'Carrinho',
        enableSorting: false,
        meta: { width: '26%' },
        cell: ({ row }) => {
            const itens = row.original.itens ?? [];
            const mostrar = itens.length > 2 ? itens.slice(0, 1) : itens;
            const resto = itens.length - mostrar.length;
            return h('div', { class: 'min-w-0 text-xs leading-snug' }, [
                ...mostrar.map((i) => {
                    const texto = `${i.quantidade}x ${i.nome}${i.variacao ? ` (${i.variacao})` : ''}`;
                    return h('div', { class: 'truncate', title: texto }, texto);
                }),
                resto > 0 ? h('div', { class: 'truncate text-slate-500', title: itens.slice(1).map((i) => `${i.quantidade}x ${i.nome}`).join('\n') }, `+ ${resto} ${resto === 1 ? 'item' : 'itens'}`) : null,
                h('div', { class: 'text-sm font-bold' }, `Total ${dinheiro(row.original.total)}`),
            ]);
        },
    },
    {
        id: 'situacao',
        header: 'Situação',
        enableSorting: false,
        meta: { width: '16%' },
        cell: ({ row }) => {
            const l = row.original;
            const detalhe = resumoDaSituacao(l);
            return h('div', { class: 'min-w-0 space-y-0.5' }, [
                badgeSituacao(l),
                // Com o número do pedido o badge pode ir para 2 linhas: o detalhe fica em 1 (célula com no máximo 3).
                detalhe ? h('div', { class: [l.pedido ? 'line-clamp-1' : 'line-clamp-2', 'text-[11px] leading-snug text-slate-500'], title: detalhe }, detalhe) : null,
            ]);
        },
    },
    {
        id: 'proximo',
        header: 'Próximo contato',
        enableSorting: false,
        meta: { width: '12%' },
        cell: ({ row }) => h('div', { class: 'text-xs' }, row.original.proximo_contato
            ? [h('div', {}, dataHora(row.original.proximo_contato)), h('div', { class: 'text-slate-400' }, `disparo ${(row.original.disparos ?? 0) + 1}/${row.original.total_disparos}`)]
            : '—'),
    },
    {
        id: 'atividade',
        header: 'Última atividade',
        enableSorting: false,
        meta: { width: '12%' },
        cell: ({ row }) => h('div', { class: 'min-w-0 text-xs' }, [
            h('div', { title: dataHora(row.original.ultima_atividade, true) }, relativo(row.original.ultima_atividade)),
            row.original.origem ? h('div', { class: 'truncate text-[11px] text-slate-400', title: row.original.origem }, row.original.origem) : null,
        ]),
    },
    {
        id: 'acoes',
        header: 'Ações',
        enableSorting: false,
        meta: { width: '8%', cellClass: 'text-center' },
        cell: ({ row }) => h('div', { class: 'flex flex-wrap items-center justify-center gap-1' }, acoes(row.original)),
    },
];
</script>

<template>
    <Head title="Leads" />

    <AdminLayout>
        <div class="mb-1 flex flex-wrap items-baseline gap-2">
            <h1 class="text-2xl font-bold">Leads</h1>
            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">versão nova, em teste</span>
        </div>
        <p class="mb-4 text-sm text-slate-500">
            Todos os checkouts numa lista só: o que o cliente preencheu, o carrinho, a situação e a sequência de
            remarketing. Atualiza sozinho a cada 5 segundos (última às {{ dataHora(resumo.atualizado_em, true) }}).
            Os ícones abrem o Remarketing e a Jornada de cada lead.
        </p>

        <div class="mb-4 grid grid-cols-6 gap-3 max-[1099px]:grid-cols-3 max-sm:grid-cols-2">
            <button v-for="c in cards" :key="c.rotulo" type="button"
                class="rounded-lg border p-3 text-left transition-colors hover:bg-[var(--surface-muted)]"
                :class="filters.status === c.chip ? 'border-primary' : 'border-[var(--surface-border)]'"
                @click="visit({ status: c.chip })">
                <div class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span v-if="c.pulsa" class="inline-block h-2 w-2 animate-pulse rounded-full bg-purple-500"></span>
                    {{ c.rotulo }}
                </div>
                <div class="truncate text-xl font-bold tabular-nums">{{ c.valor }}</div>
            </button>
        </div>

        <div class="mb-3 flex flex-col gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-sm text-slate-600" for="filtro-loja">Loja</label>
                <select id="filtro-loja" class="rounded-md border border-slate-300 px-2 py-1 text-sm" :value="filters.loja ?? ''" @change="visit({ loja: $event.target.value || null })">
                    <option value="">Todas</option>
                    <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.nome }}</option>
                </select>
                <div class="relative min-w-0 flex-1 basis-56">
                    <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                    <input v-model="busca" type="search" placeholder="Buscar por nome, e-mail, telefone, CPF ou CEP..."
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-1.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none"
                        @input="buscar">
                </div>
                <button v-if="(resumo.antecipar ?? 0) > 0" type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700"
                    @click="confirmar = 'todos'">
                    <i class="fas fa-paper-plane text-xs" aria-hidden="true"></i>
                    Antecipar 1ª mensagem para todos ({{ resumo.antecipar }})
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-for="c in chips" :key="c.label" type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="(filters.status ?? null) === c.value ? 'bg-primary text-white' : 'bg-[var(--surface-muted)] text-slate-500 hover:bg-[var(--surface-border)]'"
                    @click="visit({ status: c.value })">
                    {{ c.label }}
                </button>
            </div>
        </div>

        <!-- 1024 px ou mais: tabela de largura fixa, sem rolagem lateral. -->
        <div class="hidden lg:block xl:min-w-[960px]" data-teste="tabela">
            <DataTable :columns="columns" :data="linhas" :paginator="leads" row-key="chave" fixed hide-search
                empty-message="Nenhum lead com esses filtros." />
        </div>

        <!-- Menos de 1024 px: um cartão por lead. -->
        <div class="space-y-3 lg:hidden" data-teste="cartoes">
            <p v-if="!linhas.length" class="rounded-xl border border-[var(--surface-border)] p-6 text-center text-sm text-slate-400">Nenhum lead com esses filtros.</p>
            <article v-for="l in linhas" :key="l.chave" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate font-semibold">{{ l.nome ?? 'Sem nome' }}</span>
                            <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold" :class="COR_LOJA[l.site] ?? 'bg-slate-100'">{{ nomeDaLoja(l.site) }}</span>
                        </div>
                        <div class="truncate text-xs text-slate-600">{{ l.email ?? '—' }}</div>
                        <div class="text-xs text-slate-500">{{ l.telefone ? maskPhone(l.telefone) : '—' }}</div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button type="button" title="Remarketing" aria-label="Remarketing" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 text-orange-600" @click="abrirRemarketing(l)"><i class="fas fa-bullhorn text-xs" aria-hidden="true"></i></button>
                        <button type="button" title="Jornada" aria-label="Jornada" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 text-sky-700" @click="abrirJornada(l)"><i class="fas fa-route text-xs" aria-hidden="true"></i></button>
                        <button v-if="l.pode_antecipar" type="button" title="Antecipar 1ª mensagem" aria-label="Antecipar 1ª mensagem" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 text-emerald-700" @click="confirmar = { chave: l.chave, nome: l.nome }"><i class="fas fa-paper-plane text-xs" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-1">
                    <StatusBadge :status="l.situacao.codigo" :tone="l.situacao.tom" :label="l.situacao.rotulo" :class="l.situacao.codigo === 'agora' ? 'animate-pulse' : ''" />
                    <Link v-if="l.pedido" :href="`/admin/pedidos/${l.pedido.id}`" class="text-[11px] text-sky-700 hover:underline">#{{ l.pedido.numero ?? l.pedido.id }}</Link>
                </div>
                <div v-if="l.situacao.detalhe" class="mt-0.5 line-clamp-2 text-[11px] text-slate-500">{{ l.situacao.detalhe }}</div>
                <div class="mt-2 text-xs">
                    <div v-for="(i, n) in l.itens.slice(0, 2)" :key="n" class="truncate">{{ i.quantidade }}x {{ i.nome }}</div>
                    <div v-if="l.itens.length > 2" class="text-slate-500">+ {{ l.itens.length - 2 }} itens</div>
                    <div class="text-sm font-bold">Total {{ dinheiro(l.total) }}</div>
                </div>
                <div class="mt-2 flex flex-wrap justify-between gap-x-3 gap-y-1 text-[11px] text-slate-500">
                    <span :title="dataHora(l.ultima_atividade, true)">{{ relativo(l.ultima_atividade) }}<template v-if="l.origem"> · {{ l.origem }}</template></span>
                    <span v-if="l.proximo_contato">Próximo contato {{ dataHora(l.proximo_contato) }}</span>
                </div>
            </article>
            <ServerPagination :paginator="leads" />
        </div>

        <RemarketingModal :lead="remarketingDe" :base="base" @close="remarketingDe = null"
            @antecipar="(l) => { confirmar = l; }" />
        <JornadaModal :lead="jornadaDe" :base="base" @close="jornadaDe = null" />

        <ConfirmModal :open="!!confirmar" title="Antecipar a 1ª mensagem?" confirm-label="Enviar agora" :loading="enviando"
            @close="confirmar = null" @confirm="antecipar">
            <p v-if="confirmar === 'todos'" class="text-sm">
                Vai sair a 1ª mensagem da sequência (WhatsApp + e-mail) para <strong>{{ resumo.antecipar }}</strong>
                {{ resumo.antecipar === 1 ? 'cliente' : 'clientes' }}{{ filters.loja ? ` da ${nomeDaLoja(filters.loja)}` : '' }}, agora, uma por uma.
            </p>
            <p v-else-if="confirmar" class="text-sm">
                Vai sair agora a 1ª mensagem da sequência (WhatsApp + e-mail) para <strong>{{ confirmar.nome ?? 'o cliente' }}</strong>.
                Depois a sequência segue o calendário a partir daqui.
            </p>
        </ConfirmModal>
    </AdminLayout>
</template>
