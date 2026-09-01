<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import MetaDoDia from './MetaDoDia.vue';
import OrderRow from './OrderRow.vue';
import ScheduledRow from './ScheduledRow.vue';

defineOptions({ layout: AdminLayout });

/**
 * Versão web (dentro do admin) do painel do KoraSync — mesma tela que o
 * app desktop mostra pro operador de separação/expedição, "igualzinho"
 * (pedido explícito 2026-08-31), só sem a coluna da esquerda com o
 * logo/navegação própria (o sidebar real do admin já cumpre esse papel).
 *
 * Dados via polling nos MESMOS endpoints que o app desktop consome
 * (DashboardAgentController, reexposto pra sessão de admin em
 * korasync.api.* — ver routes/web.php) — o split Fila normal/Sem
 * estoque/Separados/Cancelados é feito aqui no cliente, réplica exata de
 * MainViewModel.UpdateOrderQueue (KazakoraAgent.App), não uma reinvenção.
 */
const props = defineProps({
    initialTab: { type: String, required: true },
});

const EXPEDICAO_TABS = [
    { key: 'fila', label: 'Fila normal', subtitle: 'Com estoque', href: '/admin/korasync/fila' },
    { key: 'sem-estoque', label: 'Sem estoque', subtitle: 'Produtos vendidos', href: '/admin/korasync/sem-estoque' },
    { key: 'vendas-futuras', label: 'Mercado Livre', subtitle: 'Vendas futuras', href: '/admin/korasync/vendas-futuras' },
];

const section = computed(() => (['separados', 'cancelados'].includes(props.initialTab) ? props.initialTab : 'expedicao'));
const activeTab = ref(EXPEDICAO_TABS.some((t) => t.key === props.initialTab) ? props.initialTab : 'fila');

function switchTab(tab) {
    const target = EXPEDICAO_TABS.find((t) => t.key === tab);

    if (!target) return;

    activeTab.value = tab;
    search.value = '';
    router.visit(target.href, { preserveScroll: true, preserveState: true, only: [] });
}

// ---------------------------------------------------------------- dados
const queue = ref([]);
const outOfStock = ref([]);
const scheduled = ref([]);
const metrics = ref({
    sales_today: 0,
    sales_yesterday: 0,
    packed_today: 0,
    shipped_today: 0,
    cancellations_and_returns_month: 0,
    returns_month: 0,
});
const loading = ref(true);
const search = ref('');

async function fetchJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) throw new Error(`Falha ao consultar ${url}`);

    return response.json();
}

async function fetchQueue() {
    try {
        const data = await fetchJson('/admin/korasync-api/queue');
        queue.value = data.queue ?? [];
        outOfStock.value = data.out_of_stock ?? [];
    } catch {
        // silencioso — próximo poll tenta de novo, mesma resiliência do app desktop
    } finally {
        loading.value = false;
    }
}

async function fetchScheduled() {
    try {
        const data = await fetchJson('/admin/korasync-api/scheduled-shipments?channel=mercado_livre');
        scheduled.value = data.scheduled_shipments ?? [];
    } catch {
        // idem
    }
}

async function fetchMetrics() {
    try {
        metrics.value = await fetchJson('/admin/korasync-api/metrics');
    } catch {
        // idem
    }
}

// Resumo "Envios de hoje" (pedido explícito 2026-09-01) — espelha os
// cartões Flex/Agência do próprio painel do Mercado Livre, calculado da
// nossa base (ver DashboardAgentController::mercadoLivreSummary()), pra
// não precisar abrir o site do canal só pra ver esse número.
const mlSummary = ref(null);
async function fetchMlSummary() {
    try {
        mlSummary.value = await fetchJson('/admin/korasync-api/mercadolivre-summary');
    } catch {
        // idem — resumo é só um complemento visual, nunca trava a tela
    }
}

let queueTimer = null;
let scheduledTimer = null;
let metricsTimer = null;
let mlSummaryTimer = null;

onMounted(() => {
    fetchQueue();
    fetchScheduled();
    fetchMetrics();
    fetchMlSummary();

    // Intervalos mais folgados que o app desktop (1s/5s, ver
    // DashboardPoller.cs) de propósito — lá é 1 processo só, sempre ligado,
    // numa máquina dedicada; aqui pode ser várias abas de admin abertas ao
    // mesmo tempo, então o mesmo ritmo multiplicaria a carga no servidor
    // sem ganho real de percepção pro operador.
    queueTimer = setInterval(fetchQueue, 3000);
    scheduledTimer = setInterval(fetchScheduled, 8000);
    metricsTimer = setInterval(fetchMetrics, 20000);
    mlSummaryTimer = setInterval(fetchMlSummary, 20000);
});

onUnmounted(() => {
    clearInterval(queueTimer);
    clearInterval(scheduledTimer);
    clearInterval(mlSummaryTimer);
    clearInterval(metricsTimer);
});

// ------------------------------------------------------- split (cliente)
// Réplica exata de MainViewModel.UpdateOrderQueue: embalado tem prioridade
// sobre cancelado (info mais acionável), sem estoque decidido pela
// presença no array out_of_stock do servidor.
const outOfStockIds = computed(() => new Set(outOfStock.value.map((o) => o.id)));
const active = computed(() => queue.value.filter((o) => !o.packed_at && o.status !== 'cancelled'));
const separated = computed(() => queue.value.filter((o) => !!o.packed_at));
const cancelled = computed(() => queue.value.filter((o) => !o.packed_at && o.status === 'cancelled'));
const filaNormalAll = computed(() => active.value.filter((o) => !outOfStockIds.value.has(o.id)));
const semEstoqueAll = computed(() => active.value.filter((o) => outOfStockIds.value.has(o.id)));
const pendingSeparationCount = computed(() => active.value.length);

function matchesOrderSearch(order, term) {
    if (!term) return true;

    const needle = term.toLowerCase();
    const haystacks = [
        String(order.id),
        order.external_order_id ?? '',
        order.customer_name ?? '',
        ...(order.products ?? []).flatMap((p) => [p.name ?? '', p.sku ?? '']),
    ];

    return haystacks.some((h) => h.toLowerCase().includes(needle));
}

function matchesScheduledSearch(shipment, term) {
    if (!term) return true;

    const needle = term.toLowerCase();
    const haystacks = [
        String(shipment.order_id),
        shipment.external_order_id ?? '',
        shipment.customer_name ?? '',
        ...(shipment.products ?? []).map((p) => p.name ?? ''),
    ];

    return haystacks.some((h) => h.toLowerCase().includes(needle));
}

const filaNormalFiltered = computed(() => filaNormalAll.value.filter((o) => matchesOrderSearch(o, search.value)));
const semEstoqueFiltered = computed(() => semEstoqueAll.value.filter((o) => matchesOrderSearch(o, search.value)));
const separadosFiltered = computed(() => separated.value.filter((o) => matchesOrderSearch(o, search.value)));
const canceladosFiltered = computed(() => cancelled.value.filter((o) => matchesOrderSearch(o, search.value)));
const scheduledFiltered = computed(() => scheduled.value.filter((s) => matchesScheduledSearch(s, search.value)));

const currentOrders = computed(() => {
    if (section.value === 'separados') return separadosFiltered.value;
    if (section.value === 'cancelados') return canceladosFiltered.value;
    if (activeTab.value === 'sem-estoque') return semEstoqueFiltered.value;

    return filaNormalFiltered.value;
});

const headerLabel = computed(() => {
    if (section.value === 'separados') return 'SEPARADOS — JÁ EMBALADOS';
    if (section.value === 'cancelados') return 'CANCELADOS';
    if (activeTab.value === 'sem-estoque') return 'PRODUTOS SEM ESTOQUE — PRECISA REPOR NO FORNECEDOR';
    if (activeTab.value === 'vendas-futuras') return 'MERCADO LIVRE — VENDAS FUTURAS (ENTREGA AGENDADA)';

    return 'FILA DE SEPARAÇÃO — HOJE';
});

const headerAccent = computed(() => {
    if (section.value === 'cancelados') return 'var(--ks-error)';
    if (activeTab.value === 'sem-estoque') return 'var(--ks-warning)';
    if (activeTab.value === 'vendas-futuras') return '#FFE600';

    return 'var(--ks-brand)';
});

const emptyState = computed(() => {
    if (section.value === 'separados') return { icon: 'fa-box-open', text: 'Nenhum pedido separado ainda hoje' };
    if (section.value === 'cancelados') return { icon: 'fa-circle-xmark', text: 'Nenhum pedido cancelado hoje' };
    if (activeTab.value === 'sem-estoque') return { icon: 'fa-circle-check', text: 'Nenhum pedido sem estoque agora' };
    if (activeTab.value === 'vendas-futuras') return { icon: 'fa-calendar-check', text: 'Nenhuma venda futura agendada' };

    return { icon: 'fa-list-check', text: 'Nenhum pedido na fila agora' };
});

// ------------------------------------------------------------- embalar
const packing = ref(new Set());
const packErrors = ref({});

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

async function packOrder(order) {
    if (order.packed_at || packing.value.has(order.id)) return;

    packing.value = new Set(packing.value).add(order.id);
    packErrors.value = { ...packErrors.value, [order.id]: null };

    try {
        const response = await fetch(`/admin/korasync-api/queue/${order.id}/pack`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
            },
        });

        if (!response.ok) throw new Error('Falha ao embalar');

        const data = await response.json();
        order.packed_at = data.packed_at;
    } catch {
        packErrors.value = { ...packErrors.value, [order.id]: 'Falha ao embalar — tente de novo.' };
    } finally {
        const next = new Set(packing.value);
        next.delete(order.id);
        packing.value = next;
    }
}
</script>

<template>
    <Head title="KoraSync" />

    <div class="korasync-shell -m-4 rounded-none border-0 p-4 md:m-0 md:rounded-2xl md:border md:p-6" style="background: var(--ks-bg); border-color: var(--ks-border)">
        <div class="mb-4 flex items-center gap-2">
            <i class="fas fa-truck-fast text-lg" style="color: var(--ks-brand)"></i>
            <h1 class="text-lg font-bold" style="color: var(--ks-text)">KoraSync</h1>
            <span class="text-sm" style="color: var(--ks-text-secondary)">— {{ section === 'expedicao' ? 'Expedição' : (section === 'separados' ? 'Separados' : 'Cancelados') }}</span>
        </div>

        <!-- Abas da Expedição — mesmas 3 do app desktop, some fora dela -->
        <div v-if="section === 'expedicao'" class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <button
                v-for="tab in EXPEDICAO_TABS"
                :key="tab.key"
                type="button"
                class="rounded-xl border px-4 py-2.5 text-center transition-colors"
                :style="activeTab === tab.key
                    ? { background: tab.key === 'sem-estoque' ? 'var(--ks-warning)' : (tab.key === 'vendas-futuras' ? '#FFE600' : 'var(--ks-brand)'), borderColor: 'transparent', color: '#00170F' }
                    : { background: 'var(--ks-card)', borderColor: 'var(--ks-border)', color: 'var(--ks-text)' }"
                @click="switchTab(tab.key)"
            >
                <div class="text-sm font-bold">{{ tab.label }}</div>
                <div class="text-xs opacity-80">{{ tab.subtitle }}</div>
            </button>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="rounded-xl border" style="background: var(--ks-card); border-color: var(--ks-border)">
                <div class="flex flex-col gap-3 border-b p-4 md:flex-row md:items-center md:justify-between" style="border-color: var(--ks-border)">
                    <div class="flex items-center gap-2">
                        <span class="h-4 w-1 rounded-full" :style="{ background: headerAccent }"></span>
                        <span class="text-xs font-bold tracking-wide" style="color: var(--ks-text-secondary)">{{ headerLabel }}</span>
                    </div>

                    <div class="relative w-full md:w-64">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs" style="color: var(--ks-text-secondary)"></i>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Buscar por pedido, cliente, SKU..."
                            class="w-full rounded-lg border py-2 pl-8 pr-3 text-sm outline-none focus:ring-1"
                            style="background: var(--ks-row-bg); border-color: var(--ks-border); color: var(--ks-text)"
                        >
                    </div>
                </div>

                <div v-if="activeTab === 'vendas-futuras' && section === 'expedicao' && mlSummary" class="grid grid-cols-2 gap-3 border-b p-4 sm:grid-cols-4" style="border-color: var(--ks-border)">
                    <div class="rounded-lg border p-3" style="background: var(--ks-row-bg); border-color: var(--ks-border)">
                        <div class="text-[11px] font-bold uppercase tracking-wide" style="color: var(--ks-text-secondary)">Flex — prontas</div>
                        <div class="text-xl font-black" style="color: var(--ks-text)">{{ mlSummary.flex.prontos }}<span class="text-sm font-normal" style="color: var(--ks-text-secondary)"> / {{ mlSummary.flex.total }}</span></div>
                    </div>
                    <div class="rounded-lg border p-3" style="background: var(--ks-row-bg); border-color: var(--ks-border)">
                        <div class="text-[11px] font-bold uppercase tracking-wide" style="color: var(--ks-text-secondary)">Agência — prontas</div>
                        <div class="text-xl font-black" style="color: var(--ks-text)">{{ mlSummary.agencia.prontos }}<span class="text-sm font-normal" style="color: var(--ks-text-secondary)"> / {{ mlSummary.agencia.total }}</span></div>
                    </div>
                    <div class="rounded-lg border p-3" style="background: var(--ks-row-bg); border-color: var(--ks-border)">
                        <div class="text-[11px] font-bold uppercase tracking-wide" style="color: var(--ks-text-secondary)">Nota fiscal pendente</div>
                        <div class="text-xl font-black" :style="{ color: mlSummary.agencia.nfe_pendente > 0 ? 'var(--ks-error)' : 'var(--ks-text)' }">{{ mlSummary.agencia.nfe_pendente }}</div>
                    </div>
                    <div class="rounded-lg border p-3" style="background: var(--ks-row-bg); border-color: var(--ks-border)">
                        <div class="text-[11px] font-bold uppercase tracking-wide" style="color: var(--ks-text-secondary)">Canceladas hoje</div>
                        <div class="text-xl font-black" style="color: var(--ks-text)">{{ mlSummary.agencia.cancelada }}</div>
                    </div>
                </div>

                <div class="max-h-[65vh] overflow-y-auto p-4">
                    <div v-if="loading" class="flex flex-col items-center gap-2 py-16" style="color: var(--ks-text-secondary)">
                        <i class="fas fa-spinner fa-spin text-2xl"></i>
                        <p class="text-sm">Carregando…</p>
                    </div>

                    <template v-else-if="activeTab === 'vendas-futuras' && section === 'expedicao'">
                        <div v-if="scheduledFiltered.length === 0" class="flex flex-col items-center gap-2 py-16 text-center" style="color: var(--ks-text-secondary)">
                            <i class="fas text-3xl" :class="emptyState.icon"></i>
                            <p class="text-sm font-bold">{{ emptyState.text }}</p>
                        </div>
                        <ScheduledRow v-for="shipment in scheduledFiltered" :key="shipment.order_id" :shipment="shipment" />
                    </template>

                    <template v-else>
                        <div v-if="currentOrders.length === 0" class="flex flex-col items-center gap-2 py-16 text-center" style="color: var(--ks-text-secondary)">
                            <i class="fas text-3xl" :class="emptyState.icon"></i>
                            <p class="text-sm font-bold">{{ emptyState.text }}</p>
                        </div>
                        <OrderRow
                            v-for="order in currentOrders"
                            :key="order.id"
                            :order="order"
                            :packing="packing.has(order.id)"
                            :error="packErrors[order.id]"
                            :show-pack-button="section === 'expedicao'"
                            @pack="packOrder"
                        />
                    </template>
                </div>
            </div>

            <MetaDoDia :metrics="metrics" :out-of-stock-count="outOfStock.length" :pending-separation-count="pendingSeparationCount" />
        </div>
    </div>
</template>

<style scoped>
.korasync-shell {
    --ks-bg: #F3F4F7;
    --ks-card: #FFFFFF;
    --ks-row-bg: #F7F8FA;
    --ks-border: #E4E5EC;
    --ks-text: #12131A;
    --ks-text-secondary: #6B6D80;
    --ks-brand: #04D7B6;
    --ks-warning: #FFB74D;
    --ks-error: #FF5252;
    --ks-processing: #40C4FF;
}

:global(.dark) .korasync-shell {
    --ks-bg: #0A0B10;
    --ks-card: #181A26;
    --ks-row-bg: #1A1A20;
    --ks-border: #2A2D3C;
    --ks-text: #FFFFFF;
    --ks-text-secondary: #8B8DA0;
}
</style>
