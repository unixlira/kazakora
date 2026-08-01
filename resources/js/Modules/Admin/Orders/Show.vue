<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    invoiceGenerationLogs: {
        type: Array,
        default: () => [],
    },
    emailLogs: {
        type: Array,
        default: () => [],
    },
    auditLogs: {
        type: Array,
        default: () => [],
    },
    fulfillmentEvents: {
        type: Array,
        default: () => [],
    },
});

const FULFILLMENT_STEP_LABELS = {
    webhook_received: 'Pedido recebido',
    stock_updated: 'Estoque atualizado',
    invoice_issued: 'Nota fiscal emitida',
    invoice_submitted: 'Nota enviada ao canal',
    shipping_confirmed: 'Frete confirmado',
    label_generated: 'Etiqueta gerada',
    label_printed: 'Etiqueta impressa',
};

const fulfillmentStatusBadge = {
    success: { color: 'completed', label: 'OK' },
    pending: { color: 'pending', label: 'Pendente' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const STATUS_LABELS_PT = {
    pending: 'Pendente',
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
    cancelled: 'Cancelado',
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDateTime = (value) => new Date(value).toLocaleString('pt-BR');

const invoiceBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    signed: { color: 'shipped', label: 'Assinada' },
    sent: { color: 'shipped', label: 'Enviada à SEFAZ' },
    authorized: { color: 'completed', label: 'Emitida' },
    rejected: { color: 'cancelled', label: 'Rejeitada' },
    denied: { color: 'cancelled', label: 'Denegada' },
    cancelled: { color: 'cancelled', label: 'Cancelada' },
    error: { color: 'cancelled', label: 'Erro' },
};

const emailBadge = {
    sent: { color: 'completed', label: 'Enviado' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const generationLogBadge = {
    success: { color: 'completed', label: 'Sucesso' },
    retrying: { color: 'in_progress', label: 'Tentando novamente' },
    failed: { color: 'cancelled', label: 'Falhou' },
};

const channelBadge = {
    loja: { color: 'shipped', label: 'Site' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
};

const form = useForm({
    status: props.order.status,
});

const updateStatus = () => {
    form.patch(`/admin/pedidos/${props.order.id}`);
};

const canIssue = () => !props.order.invoice || props.order.invoice.status !== 'authorized';
const canCancel = () => props.order.invoice?.status === 'authorized';

const issueForm = useForm({});
const issueInvoice = () => {
    issueForm.post(`/admin/pedidos/${props.order.id}/nota/emitir`);
};

const showCancelForm = ref(false);
const cancelForm = useForm({ motivo: '' });
const cancelInvoice = () => {
    cancelForm.post(`/admin/pedidos/${props.order.id}/nota/cancelar`, {
        onSuccess: () => {
            showCancelForm.value = false;
            cancelForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="`Pedido #${order.id}`" />

    <AdminLayout>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">Pedido #{{ order.id }}</h1>
            <StatusBadge :status="channelBadge[order.origin]?.color ?? order.origin" :label="channelBadge[order.origin]?.label ?? order.origin" />
            <span v-if="order.external_order_id" class="text-sm text-slate-400">Ref. no canal: {{ order.external_order_id }}</span>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Itens</h2>

                    <ul class="mt-4 space-y-2 text-sm">
                        <li v-for="item in order.items" :key="item.id" class="flex justify-between">
                            <span>{{ item.product_name }} × {{ item.quantity }}</span>
                            <span>{{ formatPrice(item.subtotal) }}</span>
                        </li>
                    </ul>

                    <div class="mt-4 flex justify-between border-t border-[var(--surface-border)] pt-4 font-bold">
                        <span>Total</span>
                        <span>{{ formatPrice(order.total) }}</span>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Entrega</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ order.shipping_name }} — {{ order.shipping_phone }}<br>
                        {{ order.shipping_street }}, {{ order.shipping_number }}
                        <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                        {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                        CEP {{ order.shipping_zip }}
                    </p>
                </div>

                <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Nota fiscal e e-mail</h2>

                    <div class="mt-3 flex flex-wrap items-center gap-6 text-sm">
                        <div>
                            <span class="text-slate-500">Nota fiscal:</span>
                            <StatusBadge
                                v-if="order.invoice"
                                :status="invoiceBadge[order.invoice.status]?.color ?? order.invoice.status"
                                :label="invoiceBadge[order.invoice.status]?.label ?? order.invoice.status"
                            />
                            <span v-else class="text-slate-400">— ainda não emitida</span>
                        </div>
                        <div v-if="order.invoice?.chave_acesso" class="font-mono text-xs text-slate-500">
                            Chave: {{ order.invoice.chave_acesso }}
                        </div>
                    </div>

                    <Can permission="pedidos.edit">
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button
                                v-if="canIssue()"
                                type="button"
                                :disabled="issueForm.processing"
                                class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                                @click="issueInvoice"
                            >
                                Emitir nota
                            </button>
                            <button
                                v-if="canCancel() && !showCancelForm"
                                type="button"
                                class="rounded-lg border border-error px-3 py-1.5 text-sm font-medium text-error hover:bg-error/10"
                                @click="showCancelForm = true"
                            >
                                Cancelar nota
                            </button>
                        </div>

                        <form v-if="showCancelForm" class="mt-3 space-y-2 rounded-lg border border-[var(--surface-border)] p-3" @submit.prevent="cancelInvoice">
                            <label for="motivo_cancelamento" class="block text-sm font-medium">Motivo do cancelamento (mín. 15 caracteres)</label>
                            <textarea
                                id="motivo_cancelamento"
                                v-model="cancelForm.motivo"
                                rows="2"
                                minlength="15"
                                required
                                class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                            ></textarea>
                            <p v-if="cancelForm.errors.motivo" class="text-xs text-error">{{ cancelForm.errors.motivo }}</p>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    :disabled="cancelForm.processing"
                                    class="rounded-lg bg-error px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Confirmar cancelamento
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--surface-muted)]"
                                    @click="showCancelForm = false; cancelForm.reset(); cancelForm.clearErrors();"
                                >
                                    Voltar
                                </button>
                            </div>
                        </form>
                    </Can>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Linha do tempo do pedido</h3>
                    <ul v-if="fulfillmentEvents.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="event in fulfillmentEvents" :key="event.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <StatusBadge
                                    :status="fulfillmentStatusBadge[event.status]?.color ?? event.status"
                                    :label="fulfillmentStatusBadge[event.status]?.label ?? event.status"
                                />
                                <span class="ml-2 font-medium">{{ FULFILLMENT_STEP_LABELS[event.step] ?? event.step }}</span>
                                <p v-if="event.message" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ event.message }}</p>
                            </div>
                            <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDateTime(event.created_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhum evento registrado ainda.</p>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Histórico de emissão da nota</h3>
                    <ul v-if="invoiceGenerationLogs.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="log in invoiceGenerationLogs" :key="log.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <StatusBadge
                                    :status="generationLogBadge[log.status]?.color ?? log.status"
                                    :label="generationLogBadge[log.status]?.label ?? log.status"
                                />
                                <span class="ml-2 text-xs text-slate-400">tentativa {{ log.attempt }}</span>
                                <p v-if="log.error_message" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ log.error_message }}</p>
                            </div>
                            <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDateTime(log.created_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhuma tentativa de emissão registrada ainda.</p>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Histórico de e-mail de recibo</h3>
                    <ul v-if="emailLogs.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="log in emailLogs" :key="log.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <StatusBadge
                                    :status="emailBadge[log.status]?.color ?? log.status"
                                    :label="emailBadge[log.status]?.label ?? log.status"
                                />
                                <span class="ml-2 text-xs text-slate-400">tentativa {{ log.attempt }}</span>
                                <span v-if="log.status === 'sent' && !log.invoice_attached" class="ml-2 text-xs text-amber-600 dark:text-amber-400">
                                    sem a nota em anexo
                                </span>
                                <p v-if="log.error_message" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ log.error_message }}</p>
                            </div>
                            <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDateTime(log.created_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhum e-mail de recibo enviado ainda.</p>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Histórico de alterações</h3>
                    <ul v-if="auditLogs.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="log in auditLogs" :key="log.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <span class="font-medium">{{ log.user?.name ?? 'Sistema' }}</span>
                                <span v-if="log.old_values?.status && log.new_values?.status" class="text-slate-400">
                                    alterou o status de
                                    <strong>{{ STATUS_LABELS_PT[log.old_values.status] ?? log.old_values.status }}</strong>
                                    para
                                    <strong>{{ STATUS_LABELS_PT[log.new_values.status] ?? log.new_values.status }}</strong>
                                </span>
                                <span v-else class="text-slate-400">atualizou o pedido</span>
                            </div>
                            <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDateTime(log.created_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhuma alteração registrada ainda.</p>
                </div>
            </div>

            <div class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <h2 class="font-semibold">Cliente</h2>
                <p class="mt-2 text-sm text-slate-500">
                    {{ order.user?.name }}<br>
                    {{ order.user?.email }}
                </p>

                <Can permission="pedidos.edit">
                    <form class="mt-6 space-y-2" @submit.prevent="updateStatus">
                        <label for="status" class="block text-sm font-medium">Status</label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        >
                            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                        </select>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full rounded-lg bg-primary py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Atualizar status
                        </button>
                    </form>
                </Can>
            </div>
        </div>
    </AdminLayout>
</template>
