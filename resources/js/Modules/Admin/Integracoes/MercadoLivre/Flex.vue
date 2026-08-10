<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import SubNav from './SubNav.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    costPerDelivery: { type: Number, required: true },
    currentCycle: { type: Object, required: true },
    monthToDate: { type: Object, required: true },
    history: { type: Array, default: () => [] },
    deliveries: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(new Date(`${value}T00:00:00`));
const formatDateTime = (value) => (value ? new Date(value).toLocaleString('pt-BR') : null);

const costForm = useForm({ cost_per_delivery: props.costPerDelivery });

const submitCost = () => {
    costForm.put('/admin/integracoes/mercado-livre/flex', { preserveScroll: true });
};

// Próximo fechamento: dia 15 se hoje ainda está na 1ª quinzena, senão o
// próprio fim do ciclo atual (16-fim do mês) — mesma regra de
// FlexDeliveryService::cycleContaining() do lado do backend.
const nextClosingLabel = computed(() => formatDate(props.currentCycle.end));

// Filtro por mês + número de pedido — pedido explícito 2026-08-10. O
// backend já sempre filtra por mês (nunca lista tudo de uma vez), então o
// input de mês nunca fica vazio; "pedido" é opcional, casa tanto com o id
// interno quanto com o número do próprio Mercado Livre.
const monthFilter = ref(props.filters.mes ?? '');
const orderFilter = ref(props.filters.pedido ?? '');

const applyFilters = () => {
    router.get('/admin/integracoes/mercado-livre/flex', {
        mes: monthFilter.value || undefined,
        pedido: orderFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

// Detalhe da entrega — tudo já veio junto na lista (cliente/endereço/
// produtos/valor), então abrir o modal é só local, sem nova requisição.
const selectedDelivery = ref(null);
const openDelivery = (delivery) => { selectedDelivery.value = delivery; };
const closeDelivery = () => { selectedDelivery.value = null; };
</script>

<template>
    <Head title="Custo Flex — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Custo do Mercado Envios Flex</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Cobrança quinzenal (dia 1-15 e 16 até o fim do mês) sobre cada entrega feita via Flex (logística "self_service").
            </p>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <CardStats stat-subtitle="FRETES FLEX — CICLO ATUAL" :stat-title="String(currentCycle.count)" stat-icon-name="fas fa-truck-fast" variant="info" />
            <CardStats stat-subtitle="A PAGAR NO CICLO ATUAL" :stat-title="formatPrice(currentCycle.total)" stat-icon-name="fas fa-file-invoice-dollar" variant="warning" />
            <CardStats stat-subtitle="FRETES FLEX NO MÊS" :stat-title="String(monthToDate.count)" stat-icon-name="fas fa-calendar-days" variant="secondary" />
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h3 class="mb-1 text-base font-semibold">Ciclo em andamento</h3>
                <p class="mb-4 text-xs text-slate-400">
                    {{ formatDate(currentCycle.start) }} a {{ formatDate(currentCycle.end) }} — fecha e envia o e-mail de cobrança em {{ nextClosingLabel }}.
                </p>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500 dark:text-slate-400">{{ currentCycle.count }} entrega(s) × {{ formatPrice(costPerDelivery) }}</span>
                    <span class="text-lg font-bold">{{ formatPrice(currentCycle.total) }}</span>
                </div>
            </div>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h3 class="mb-1 text-base font-semibold">Valor por entrega</h3>
                <p class="mb-4 text-xs text-slate-400">Usado pra calcular todo ciclo daqui pra frente e o abatimento no Financeiro.</p>
                <form class="flex items-end gap-3" @submit.prevent="submitCost">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Valor (R$)</label>
                        <input v-model="costForm.cost_per_delivery" type="number" step="0.01" min="0"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                        <p v-if="costForm.errors.cost_per_delivery" class="mt-1 text-xs text-error">{{ costForm.errors.cost_per_delivery }}</p>
                    </div>
                    <button type="submit" :disabled="costForm.processing"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        Salvar
                    </button>
                </form>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-4">
                <div>
                    <h3 class="text-base font-semibold">Entregas Flex</h3>
                    <p class="text-xs text-slate-400">Clique numa linha pra ver cliente, endereço, produto e valor.</p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Mês</label>
                        <input v-model="monthFilter" type="month"
                            class="rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm"
                            @change="applyFilters" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Número do pedido</label>
                        <input v-model="orderFilter" type="text" placeholder="Ex: 215 ou 2000017855498108"
                            class="w-56 rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm"
                            @keyup.enter="applyFilters" />
                    </div>
                    <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                        @click="applyFilters">
                        Filtrar
                    </button>
                </div>
            </div>

            <div v-if="deliveries.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-4 py-3 font-medium">Data</th>
                            <th class="px-4 py-3 font-medium">Pedido</th>
                            <th class="px-4 py-3 font-medium">Cliente</th>
                            <th class="px-4 py-3 font-medium">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="delivery in deliveries" :key="delivery.id"
                            class="cursor-pointer border-b border-[var(--surface-border)] last:border-0 hover:bg-lightprimary"
                            @click="openDelivery(delivery)">
                            <td class="px-4 py-3">{{ formatDateTime(delivery.orderPlacedAt) }}</td>
                            <td class="px-4 py-3">
                                #{{ delivery.orderId }}
                                <span v-if="delivery.externalOrderId" class="block text-xs text-slate-400">{{ delivery.externalOrderId }}</span>
                            </td>
                            <td class="px-4 py-3">{{ delivery.customerName ?? '—' }}</td>
                            <td class="px-4 py-3 font-semibold">{{ delivery.total !== null ? formatPrice(delivery.total) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="p-4 text-sm text-slate-400">Nenhuma entrega Flex encontrada nesse mês{{ filters.pedido ? ' pra esse pedido' : '' }}.</p>
        </div>

        <!-- Detalhe da entrega — modal simples com os tokens do próprio
             admin (--surface/--surface-border). BUG REAL 2026-08-10: tinha
             Teleport pro <body>, fora da div ".admin-shell" que é onde
             essas variáveis de cor são registradas (ver app.css) — fora
             dali "var(--surface)" não resolve pra nada, o modal ficava
             sem fundo/sem texto visível, só o overlay escuro por cima de
             tudo. Removido o Teleport (não precisa: a página inteira já
             está dentro do ".admin-shell", position:fixed já cobre a tela
             toda igual, e não tem nenhum ancestral com transform/filter
             que quebraria isso). -->
        <div v-if="selectedDelivery" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="closeDelivery"></div>
            <div class="relative w-full max-w-lg overflow-y-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-xl" style="max-height: 90vh;">
                <button type="button" class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-[var(--surface-muted)]"
                    aria-label="Fechar" @click="closeDelivery">
                    <i class="fas fa-xmark"></i>
                </button>

                <h3 class="mb-4 text-lg font-semibold">Entrega Flex — Pedido #{{ selectedDelivery.orderId }}</h3>

                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Data do pedido (Mercado Livre)</dt>
                        <dd class="mt-0.5">{{ formatDateTime(selectedDelivery.orderPlacedAt) }}</dd>
                    </div>
                    <div v-if="selectedDelivery.externalOrderId">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Número do pedido (Mercado Livre)</dt>
                        <dd class="mt-0.5">{{ selectedDelivery.externalOrderId }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Cliente</dt>
                        <dd class="mt-0.5">{{ selectedDelivery.customerName ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Endereço</dt>
                        <dd class="mt-0.5">{{ selectedDelivery.address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Produto(s)</dt>
                        <dd class="mt-0.5">
                            <ul v-if="selectedDelivery.products.length" class="list-inside list-disc">
                                <li v-for="(product, index) in selectedDelivery.products" :key="index">
                                    {{ product.quantity > 1 ? `${product.quantity}x ` : '' }}{{ product.name }}
                                </li>
                            </ul>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-[var(--surface-border)] pt-3">
                        <dt class="font-medium">Valor do pedido</dt>
                        <dd class="text-lg font-bold">{{ selectedDelivery.total !== null ? formatPrice(selectedDelivery.total) : '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Custo Flex dessa entrega</span>
                        <span>{{ formatPrice(costPerDelivery) }}</span>
                    </div>
                </dl>

                <Link :href="`/admin/pedidos/${selectedDelivery.orderId}`" class="mt-5 block text-center text-sm text-primary hover:underline">
                    Ver pedido completo →
                </Link>
            </div>
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Histórico de fechamentos</h3>
                <p class="text-xs text-slate-400">Ciclos já fechados — o e-mail de cobrança é enviado automaticamente em cada um.</p>
            </div>

            <div v-if="history.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-4 py-3 font-medium">Período</th>
                            <th class="px-4 py-3 font-medium">Fretes</th>
                            <th class="px-4 py-3 font-medium">Valor/entrega</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">E-mail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in history" :key="row.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="px-4 py-3">{{ formatDate(row.periodStart) }} a {{ formatDate(row.periodEnd) }}</td>
                            <td class="px-4 py-3">{{ row.deliveriesCount }}</td>
                            <td class="px-4 py-3">{{ formatPrice(row.costPerDelivery) }}</td>
                            <td class="px-4 py-3 font-semibold">{{ formatPrice(row.totalAmount) }}</td>
                            <td class="px-4 py-3">
                                <span v-if="row.emailSentAt" class="inline-flex items-center gap-1 text-xs text-success">
                                    <i class="fas fa-circle-check"></i> {{ formatDateTime(row.emailSentAt) }}
                                </span>
                                <span v-else-if="row.emailError" class="inline-flex items-center gap-1 text-xs text-error" :title="row.emailError">
                                    <i class="fas fa-triangle-exclamation"></i> Falhou
                                </span>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="p-4 text-sm text-slate-400">Nenhum ciclo fechado ainda — o primeiro fecha automaticamente dia 15 ou no fim do mês.</p>
        </div>
    </AdminLayout>
</template>
