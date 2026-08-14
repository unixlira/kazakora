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
const formatDate = (value) => new Date(value).toLocaleDateString('pt-BR');

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

// Status do ENVIO no canal (Shopee/Mercado Livre) — não confundir com
// order.status (esse aqui é sobre a etiqueta especificamente: confirmada,
// mas etiqueta ainda não liberada pelo canal / já pronta / já baixada /
// deu erro depois de ~4h tentando). Pedido explícito 2026-08-13.
const shipmentBadge = {
    pending: { color: 'pending', label: 'Aguardando confirmação' },
    confirmed: { color: 'in_progress', label: 'Aguardando etiqueta do canal' },
    label_ready: { color: 'completed', label: 'Etiqueta pronta' },
    label_downloaded: { color: 'completed', label: 'Etiqueta baixada' },
    error: { color: 'cancelled', label: 'Canal não liberou a etiqueta' },
};

// Venda AGENDADA pelo canal (pedido explícito 2026-08-14, achado no
// pedido #278 — Coleta/Places do Mercado Livre com etiqueta liberada só
// perto de uma data futura, scheduled_for). Sem isso na tela, "aguardando
// etiqueta do canal" parece exatamente igual a um pedido travado de
// verdade — substitui o badge genérico por um específico enquanto ainda
// não passou da data e o pedido ainda não embalou.
const shipmentDisplay = () => {
    const shipment = props.order.channel_shipment;
    const scheduled = shipment?.scheduled_for && !['label_ready', 'label_downloaded'].includes(shipment.status);

    if (!scheduled) {
        return shipmentBadge[shipment?.status] ?? { color: shipment?.status, label: shipment?.status };
    }

    const isOverdue = new Date(shipment.scheduled_for) < new Date();

    return isOverdue
        ? { color: 'cancelled', label: `Agendada pra ${formatDate(shipment.scheduled_for)} — já passou e não liberou` }
        : { color: 'pending', label: `Venda agendada — etiqueta só sai perto de ${formatDate(shipment.scheduled_for)}` };
};

const LOGISTIC_TYPE_LABELS = {
    self_service: 'Flex',
    drop_off: 'Agência / Correios',
    xd_drop_off: 'Coleta (Places)',
    cross_docking: 'Coleta',
    fulfillment: 'Full',
    turbo: 'Turbo',
};

const form = useForm({
    status: props.order.status,
});

const updateStatus = () => {
    form.patch(`/admin/pedidos/${props.order.id}`);
};

const canIssue = () => !props.order.invoice || props.order.invoice.status !== 'authorized';
const canCancel = () => props.order.invoice?.status === 'authorized';

// Só faz sentido reenviar uma nota já autorizada, e só pra pedido de
// marketplace de verdade — "loja" (venda direto no site) e
// "nota_fiscal_avulsa" (emissão manual sem canal, ver InvoiceManualController)
// não têm nenhum canal esperando a nota do outro lado.
const canResubmitToChannel = () =>
    props.order.invoice?.status === 'authorized' && !['loja', 'nota_fiscal_avulsa'].includes(props.order.origin);

const issueForm = useForm({});
const issueInvoice = () => {
    issueForm.post(`/admin/pedidos/${props.order.id}/nota/emitir`);
};

const resubmitForm = useForm({});
const resubmitToChannel = () => {
    resubmitForm.post(`/admin/pedidos/${props.order.id}/nota/reenviar-canal`);
};

const canCheckLabel = () =>
    props.order.channel_shipment && !['label_ready', 'label_downloaded'].includes(props.order.channel_shipment.status);

const checkLabelForm = useForm({});
const checkLabel = () => {
    checkLabelForm.post(`/admin/pedidos/${props.order.id}/verificar-etiqueta`);
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
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Itens</h2>
                        <span class="text-sm text-slate-500">{{ order.units_count ?? 0 }} unidade(s)</span>
                    </div>

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
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold">Entrega</h2>
                        <span v-if="order.channel_shipment?.shipping_method" class="text-sm text-slate-500">
                            Tipo de envio: <strong>{{ LOGISTIC_TYPE_LABELS[order.channel_shipment.shipping_method] ?? order.channel_shipment.shipping_method }}</strong>
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ order.shipping_name }} — {{ order.shipping_phone }}<br>
                        <span v-if="order.shipping_whatsapp">WhatsApp: {{ order.shipping_whatsapp }}<br></span>
                        <span v-if="order.shipping_email">E-mail: {{ order.shipping_email }}<br></span>
                        {{ order.shipping_street }}, {{ order.shipping_number }}
                        <span v-if="order.shipping_complement">- {{ order.shipping_complement }}</span><br>
                        {{ order.shipping_neighborhood }} - {{ order.shipping_city }}/{{ order.shipping_state }}<br>
                        CEP {{ order.shipping_zip }}
                    </p>

                    <!-- Status da ETIQUETA no canal (pedido explícito
                         2026-08-13) — antes disso não tinha jeito de ver
                         nem forçar isso pelo painel, só via intervenção
                         manual direto no servidor. Só aparece pra pedido de
                         marketplace de verdade (channel_shipment só existe
                         pra Shopee/ML, nunca pra venda direta no site). -->
                    <div v-if="order.channel_shipment" class="mt-4 border-t border-[var(--surface-border)] pt-3">
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="text-slate-500">Etiqueta:</span>
                            <StatusBadge :status="shipmentDisplay().color" :label="shipmentDisplay().label" />
                            <span v-if="order.channel_shipment.tracking_code" class="font-mono text-xs text-slate-500">
                                Rastreio: {{ order.channel_shipment.tracking_code }}
                            </span>
                        </div>
                        <p v-if="order.channel_shipment.scheduled_for" class="mt-2 text-xs text-slate-400">
                            O próprio {{ channelBadge[order.channel_shipment.channel]?.label ?? order.channel_shipment.channel }} decidiu agendar essa entrega —
                            não é um problema do nosso lado, é assim mesmo pra esse tipo de envio (Coleta/Places).
                        </p>
                        <p v-if="order.channel_shipment.error_message" class="mt-2 text-sm text-error">
                            {{ order.channel_shipment.error_message }}
                        </p>
                        <Can permission="pedidos.edit">
                            <button
                                v-if="canCheckLabel()"
                                type="button"
                                :disabled="checkLabelForm.processing"
                                title="Consulta o canal (Shopee/ML) agora mesmo e grava a etiqueta se já estiver liberada — o mesmo que o sistema já tenta sozinho automaticamente, só que na hora"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary disabled:cursor-not-allowed disabled:opacity-50"
                                @click="checkLabel"
                            >
                                <i class="fas fa-rotate" :class="{ 'animate-spin': checkLabelForm.processing }"></i>
                                Verificar etiqueta agora
                            </button>
                        </Can>
                    </div>
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
                        <a v-if="order.invoice?.status === 'authorized'" :href="`/admin/pedidos/${order.id}/nota/danfe`"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary">
                            <i class="fas fa-file-pdf"></i> Baixar DANFE
                        </a>
                        <a v-if="order.invoice?.status === 'authorized'" :href="`/admin/pedidos/${order.id}/nota/xml`"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary">
                            <i class="fas fa-file-code"></i> Baixar XML
                        </a>
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
                            <button
                                v-if="canResubmitToChannel()"
                                type="button"
                                :disabled="resubmitForm.processing"
                                title="A nota já é enviada automaticamente assim que sai — use isso só se o canal (Shopee/ML) travou esperando ela, ex.: envio automático falhou ou a nota foi reemitida depois"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary disabled:cursor-not-allowed disabled:opacity-50"
                                @click="resubmitToChannel"
                            >
                                <i class="fas fa-rotate" :class="{ 'animate-spin': resubmitForm.processing }"></i>
                                Reenviar nota pro canal
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
