<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import SubNav from './SubNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    costPerDelivery: { type: Number, required: true },
    currentCycle: { type: Object, required: true },
    monthToDate: { type: Object, required: true },
    history: { type: Array, default: () => [] },
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
