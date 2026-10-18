<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import QrCodeSvg from '@/Shared/Components/QrCodeSvg.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import Swal from 'sweetalert2';
import { statusOptions } from '@/Shared/statusLabels';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { shippingBadge } from './envio';

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
    packingVideos: { type: Array, default: () => [] },
    // Tudo o que o pedido tem (OrderController::detalheCompleto, 2026-10-16).
    detalhe: { type: Object, default: () => ({}) },
    // Prévia do botão "Cancelar pedido" (CancelamentoDoPedido::previa, 2026-10-17).
    cancelamento: { type: Object, default: null },
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

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR') : '—');

const d = computed(() => props.detalhe ?? {});

// Mesmo nome de envio da listagem (envio.js).
const envioSelo = computed(() => shippingBadge({
    origin: props.order.origin,
    shipping_type: d.value.envio?.tipo,
    shipping_type_short: d.value.envio?.curto,
}));

const PAYMENT_METHOD_LABELS = {
    pix: 'Pix',
    card: 'Cartão',
    credit_card: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    boleto: 'Boleto',
    account_money: 'Saldo Mercado Pago',
    ticket: 'Boleto',
    bank_transfer: 'Pix / transferência',
};
const paymentMethodLabel = (value) => PAYMENT_METHOD_LABELS[value] ?? value ?? '—';

const formatDocument = (value) => {
    const digits = String(value ?? '').replace(/\D/g, '');
    if (digits.length === 11) return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    if (digits.length === 14) return digits.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    return value || '—';
};

const formatWeight = (grams) => (grams == null ? null : `${Number(grams).toLocaleString('pt-BR')} g`);
const dimensions = (pre) => [pre.comprimento, pre.largura, pre.altura].every((v) => v != null && Number(v) > 0)
    ? `${pre.comprimento} × ${pre.largura} × ${pre.altura} cm`
    : (pre.diametro ? `diâmetro ${pre.diametro} cm` : null);

const copiedKey = ref(null);
const copy = async (text, key) => {
    try {
        await navigator.clipboard.writeText(String(text));
        copiedKey.value = key;
        setTimeout(() => { if (copiedKey.value === key) copiedKey.value = null; }, 1500);
    } catch {
        // Navegador sem permissão de área de transferência: o texto continua visível para copiar à mão.
    }
};

const SHIPMENT_DATES = [
    ['confirmado_em', 'Confirmado no canal'],
    ['etiqueta_pronta_em', 'Etiqueta pronta'],
    ['agendado_para', 'Agendado para'],
    ['entrega_prometida', 'Entrega prometida'],
    ['enviado_em', 'Enviado'],
    ['primeira_tentativa_em', 'Primeira tentativa'],
    ['entregue_em', 'Entregue'],
    ['nao_entregue_em', 'Não entregue'],
    ['cancelado_em', 'Cancelado no canal'],
    ['consultado_em', 'Última consulta ao canal'],
];

const temDevolucao = computed(() => {
    const dev = d.value.devolucao ?? {};
    return Boolean(dev.estornado_em || dev.devolvido_pelo_canal_em || dev.resolucao || dev.notas_de_devolucao?.length || dev.reclamacoes?.length);
});

const channelBadge = {
    loja: { color: 'shipped', label: 'KazaKora' },
    mercado_livre: { color: 'pending', label: 'Mercado Livre' },
    shopee: { color: 'processing', label: 'Shopee' },
    tiktok_shop: { color: 'completed', label: 'TikTok Shop' },
    amazon: { color: '#146EB4', label: 'Amazon' },
};

// Pedido do site do FerraKora ou da Beauty Kora (2026-10-16): origin continua
// "loja", o selo mostra a loja pelo site_origem (igual à listagem).
const siteBadge = {
    ferrakora: { color: '#F27A2A', label: 'FerraKora' },
    beautykora: { color: '#D63384', label: 'Beauty Kora' },
};
const originBadge = computed(() => (props.order.origin === 'loja' ? siteBadge[props.order.site_origem] : null)
    ?? channelBadge[props.order.origin]
    ?? { color: props.order.origin, label: props.order.origin });

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
        // Rótulo e cor vêm do mapa central (contexto "shipment").
        return { color: shipment?.status ?? null, label: null };
    }

    const isOverdue = new Date(shipment.scheduled_for) < new Date();

    return isOverdue
        ? { color: 'red', label: `Agendada pra ${formatDate(shipment.scheduled_for)} — já passou e não liberou` }
        : { color: 'yellow', label: `Venda agendada — etiqueta só sai perto de ${formatDate(shipment.scheduled_for)}` };
};

const form = useForm({
    status: props.order.status,
    origin: props.order.origin,
});

const updateStatus = () => {
    // Cancelar pelo select pulava o motivo e a prévia: manda para o botão.
    if (form.status === 'cancelled' && props.order.status !== 'cancelled' && props.cancelamento?.pode) {
        form.status = props.order.status;
        abrirCancelamento();
        return;
    }

    form.patch(`/admin/pedidos/${props.order.id}`);
};

// Botão "Cancelar pedido" (2026-10-17): mostra o que vai acontecer (nota,
// estorno, estoque, cliente, canal) e pede o motivo antes de confirmar.
// Tudo num modal só (Lira, 2026-10-17): antes o motivo ficava num painel da
// lateral e o "Poxa, você tem certeza" abria um SweetAlert por cima.
const showCancelOrder = ref(false);
const cancelOrderForm = useForm({ motivo: '' });
const abrirCancelamento = () => {
    showCancelOrder.value = true;
};
const fecharCancelamento = () => {
    if (cancelOrderForm.processing) {
        return;
    }

    showCancelOrder.value = false;
    cancelOrderForm.reset();
    cancelOrderForm.clearErrors();
};
const cancelOrder = () => {
    if (cancelOrderForm.motivo.trim().length < 5) {
        return;
    }

    cancelOrderForm.post(`/admin/pedidos/${props.order.id}/cancelar`, {
        preserveScroll: true,
        onSuccess: (page) => {
            showCancelOrder.value = false;
            cancelOrderForm.reset();
            mostrarResultadoDoCancelamento(page.props.flash?.cancelamento);
        },
    });
};

// Pedido #3205 (2026-10-17): o resultado vinha num aviso solto. Agora o
// modal lista cada etapa (nota, estorno, estoque, pedido) com o que deu certo
// e o que ficou para tratar.
const escapar = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const mostrarResultadoDoCancelamento = (resultado) => {
    if (!resultado?.etapas) {
        return;
    }

    const linhas = resultado.etapas.map((etapa) => `
        <li style="display:flex;gap:.5rem;align-items:flex-start;margin:.4rem 0">
            <span style="color:${etapa.ok ? '#16a34a' : '#dc2626'};font-weight:700">${etapa.ok ? '✓' : '✗'}</span>
            <span><strong>${escapar(etapa.etapa)}</strong><br>${escapar(etapa.texto)}</span>
        </li>`).join('');

    Swal.fire({
        icon: resultado.ok ? 'success' : 'warning',
        title: resultado.ok ? 'Pedido cancelado com sucesso' : 'Pedido cancelado, mas falta uma etapa',
        html: `<ul style="text-align:left;list-style:none;padding:0;margin:0">${linhas}</ul>`,
        confirmButtonText: 'Ok',
    });
};

// Pedido explícito 2026-08-21 (pedido #559, importado errado como "loja"
// em vez do canal certo): corrige um erro de seleção na importação, sem
// disparar nenhum pipeline automático (etiqueta/nota não recomeçam
// sozinhos só por trocar o canal — ver OrderController::update()).
const CHANNEL_EDIT_OPTIONS = {
    loja: 'KazaKora',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
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
    props.order.channel_shipment
    // 2026-08-31: geração de etiqueta automática via Bling pro TikTok Shop
    // exige plano pago (R$200/mês) que o usuário decidiu não contratar —
    // envio desse canal agora é tratado manualmente direto no TikTok. Esse
    // botão consultando o Bling às vezes acha um rastreio que só existe
    // porque o próprio usuário já resolveu manual lá, e reimprime aqui por
    // cima (pedido #1132, mesmo dia) — duplicando etiqueta sem necessidade.
    && props.order.channel_shipment.channel !== 'tiktok_shop'
    && !['label_ready', 'label_downloaded'].includes(props.order.channel_shipment.status);

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
            <StatusBadge :status="originBadge.color" :label="originBadge.label" />
            <StatusBadge :status="order.status" context="order" />
            <StatusBadge :status="envioSelo.tone" :label="envioSelo.label.replace('\n', ' ')" :title="d.envio?.label" />
            <span v-if="order.external_order_id" class="text-sm text-slate-400">Ref. no canal: {{ order.external_order_id }}</span>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <!-- Itens e valores -->
                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Itens</h2>
                        <span class="text-sm text-slate-500">{{ order.units_count ?? 0 }} unidade(s)</span>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                    <th class="py-2 pr-3 font-medium">Produto</th>
                                    <th class="py-2 pr-3 font-medium">SKU</th>
                                    <th class="py-2 pr-3 text-right font-medium">Qtd</th>
                                    <th class="py-2 pr-3 text-right font-medium">Preço</th>
                                    <th class="py-2 text-right font-medium">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in d.itens ?? []" :key="item.id" class="border-b border-[var(--surface-border)] align-top last:border-0">
                                    <td class="py-2 pr-3">
                                        <div class="font-medium">{{ item.nome }}</div>
                                        <div v-if="item.variacao" class="text-xs text-slate-500">Variação: {{ item.variacao }}</div>
                                        <div v-if="item.anuncio_no_canal || item.variacao_no_canal" class="text-xs text-slate-400">
                                            Anúncio no canal: <span class="font-mono">{{ item.anuncio_no_canal ?? '—' }}</span>
                                            <span v-if="item.variacao_no_canal"> · variação <span class="font-mono">{{ item.variacao_no_canal }}</span></span>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs">{{ item.sku ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ item.quantidade }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ formatPrice(item.preco) }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ formatPrice(item.subtotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <dl class="mt-4 space-y-1 border-t border-[var(--surface-border)] pt-3 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="tabular-nums">{{ formatPrice(d.valores?.subtotal ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Frete</dt><dd class="tabular-nums">{{ (d.valores?.frete ?? 0) > 0 ? formatPrice(d.valores.frete) : 'Grátis / pago pelo canal' }}</dd></div>
                        <div v-if="d.valores?.cupom || d.valores?.desconto_cupom > 0" class="flex justify-between">
                            <dt class="text-slate-500">Cupom <span v-if="d.valores?.cupom" class="font-mono text-xs">{{ d.valores.cupom }}</span></dt>
                            <dd class="tabular-nums text-success">− {{ formatPrice(Math.max(d.valores?.desconto_cupom ?? 0, 0)) }}</dd>
                        </div>
                        <div v-if="d.valores?.desconto_pix > 0" class="flex justify-between"><dt class="text-slate-500">Desconto Pix</dt><dd class="tabular-nums text-success">− {{ formatPrice(d.valores.desconto_pix) }}</dd></div>
                        <div v-if="d.valores?.desconto_plataforma > 0" class="flex justify-between"><dt class="text-slate-500">Desconto da plataforma</dt><dd class="tabular-nums text-success">− {{ formatPrice(d.valores.desconto_plataforma) }}</dd></div>
                        <div class="flex justify-between border-t border-[var(--surface-border)] pt-2 font-bold"><dt>Total pago</dt><dd class="tabular-nums">{{ formatPrice(order.total) }}</dd></div>
                    </dl>
                </section>

                <!-- Pagamento -->
                <section class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Pagamento</h2>
                    <ul v-if="d.pagamentos?.length" class="mt-3 space-y-3 text-sm">
                        <li v-for="pagamento in d.pagamentos" :key="pagamento.id" class="rounded-lg border border-[var(--surface-border)] p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge :status="pagamento.status" context="payment" />
                                <span class="font-medium">{{ paymentMethodLabel(pagamento.meio) }}</span>
                                <span class="text-slate-400">· {{ pagamento.provedor ?? '—' }}</span>
                                <span class="ml-auto font-semibold tabular-nums">{{ formatPrice(pagamento.valor) }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                <span v-if="pagamento.transacao">Transação: <span class="font-mono">{{ pagamento.transacao }}</span>
                                    <button type="button" class="ml-1 text-primary" :aria-label="`Copiar transação ${pagamento.transacao}`" @click="copy(pagamento.transacao, `tx-${pagamento.id}`)">
                                        <i class="fas" :class="copiedKey === `tx-${pagamento.id}` ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>
                                    </button>
                                </span>
                                <span v-if="pagamento.pedido_no_provedor">Pedido no provedor: <span class="font-mono">{{ pagamento.pedido_no_provedor }}</span></span>
                                <span>Criado {{ formatDateTime(pagamento.criado_em) }}</span>
                                <span>Atualizado {{ formatDateTime(pagamento.atualizado_em) }}</span>
                                <span v-if="pagamento.expira_em">Vence {{ formatDateTime(pagamento.expira_em) }}</span>
                                <a v-if="pagamento.boleto_url" :href="pagamento.boleto_url" target="_blank" rel="noopener" class="text-primary">Abrir boleto</a>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">
                        {{ order.origin === 'loja' ? 'Nenhum pagamento registrado.' : 'Pagamento feito no próprio canal (o canal repassa o valor).' }}
                    </p>

                    <template v-if="d.mercado_pago?.length">
                        <h3 class="mt-4 text-sm font-semibold text-slate-500">Mercado Pago (conciliação)</h3>
                        <ul class="mt-2 space-y-2 text-xs text-slate-500">
                            <li v-for="mp in d.mercado_pago" :key="mp.transacao" class="flex flex-wrap gap-x-4 gap-y-1 border-b border-[var(--surface-border)] pb-2 last:border-0">
                                <span>Transação <span class="font-mono">{{ mp.transacao }}</span></span>
                                <span>{{ mp.status }}<span v-if="mp.status_detalhe"> ({{ mp.status_detalhe }})</span></span>
                                <span>{{ paymentMethodLabel(mp.meio) }}</span>
                                <span>Valor {{ formatPrice(mp.valor) }}</span>
                                <span>Líquido {{ formatPrice(mp.liquido) }}</span>
                                <span v-if="mp.reembolsado > 0" class="text-error">Reembolsado {{ formatPrice(mp.reembolsado) }}</span>
                                <span v-if="mp.aprovado_em">Aprovado {{ formatDateTime(mp.aprovado_em) }}</span>
                                <span v-if="mp.liberado_em">Liberado {{ formatDateTime(mp.liberado_em) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Envio -->
                <section class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold">Envio</h2>
                        <StatusBadge :status="envioSelo.tone" :label="envioSelo.label.replace('\n', ' ')" />
                    </div>

                    <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-400">Meio de envio</dt><dd>{{ d.envio?.label ?? '—' }}</dd></div>
                        <div v-if="d.envio?.servico"><dt class="text-xs text-slate-400">Serviço</dt><dd>{{ d.envio.servico }}</dd></div>
                        <div v-if="d.envio?.metodo_no_canal"><dt class="text-xs text-slate-400">Método informado pelo canal</dt><dd class="font-mono text-xs">{{ d.envio.metodo_no_canal }}</dd></div>
                        <div v-if="d.envio?.transportadora"><dt class="text-xs text-slate-400">Frete escolhido / transportadora</dt><dd>{{ d.envio.transportadora }}</dd></div>
                        <div>
                            <dt class="text-xs text-slate-400">Código de rastreio</dt>
                            <dd v-if="d.envio?.rastreio" class="flex flex-wrap items-center gap-2">
                                <a v-if="d.envio.rastreio_url" :href="d.envio.rastreio_url" target="_blank" rel="noopener" class="font-mono text-primary hover:underline">{{ d.envio.rastreio }}</a>
                                <span v-else class="font-mono">{{ d.envio.rastreio }}</span>
                                <button type="button" class="text-primary" aria-label="Copiar código de rastreio" @click="copy(d.envio.rastreio, 'rastreio')">
                                    <i class="fas" :class="copiedKey === 'rastreio' ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>
                                </button>
                            </dd>
                            <dd v-else>—</dd>
                        </div>
                        <div v-if="d.envio?.rastreio_cliente_url" class="sm:col-span-2">
                            <dt class="text-xs text-slate-400">Página de rastreio do cliente</dt>
                            <dd class="flex flex-wrap items-center gap-2">
                                <a :href="d.envio.rastreio_cliente_url" target="_blank" rel="noopener" class="break-all text-primary hover:underline">{{ d.envio.rastreio_cliente_url }}</a>
                                <button type="button" class="text-primary" aria-label="Copiar link de rastreio do cliente" @click="copy(d.envio.rastreio_cliente_url, 'rastreio-cliente')">
                                    <i class="fas" :class="copiedKey === 'rastreio-cliente' ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>
                                </button>
                            </dd>
                        </div>
                    </dl>

                    <template v-if="d.envio?.canal && (d.envio.canal.id_no_canal || d.envio.canal.status_no_canal || SHIPMENT_DATES.some(([key]) => d.envio.canal[key]))">
                        <h3 class="mt-4 text-sm font-semibold text-slate-500">No canal</h3>
                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div v-if="d.envio.canal.id_no_canal"><dt class="text-xs text-slate-400">Envio no canal</dt><dd class="font-mono text-xs">{{ d.envio.canal.id_no_canal }}</dd></div>
                            <div v-if="d.envio.canal.status_no_canal"><dt class="text-xs text-slate-400">Status no canal</dt><dd>{{ d.envio.canal.status_no_canal }}<span v-if="d.envio.canal.substatus_no_canal" class="text-slate-400"> / {{ d.envio.canal.substatus_no_canal }}</span></dd></div>
                            <template v-for="[key, rotulo] in SHIPMENT_DATES" :key="key">
                                <div v-if="d.envio.canal[key]"><dt class="text-xs text-slate-400">{{ rotulo }}</dt><dd>{{ formatDateTime(d.envio.canal[key]) }}</dd></div>
                            </template>
                        </dl>
                    </template>

                    <div v-if="order.channel_shipment" class="mt-4 border-t border-[var(--surface-border)] pt-3">
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="text-slate-500">Etiqueta:</span>
                            <StatusBadge :status="shipmentDisplay().color" :label="shipmentDisplay().label" context="shipment" />
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
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <Can permission="pedidos.edit">
                                <button
                                    v-if="canCheckLabel()"
                                    type="button"
                                    :disabled="checkLabelForm.processing"
                                    title="Consulta o canal (Shopee/ML) agora mesmo e grava a etiqueta se já estiver liberada — o mesmo que o sistema já tenta sozinho automaticamente, só que na hora"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="checkLabel"
                                >
                                    <i class="fas fa-rotate" :class="{ 'animate-spin': checkLabelForm.processing }"></i>
                                    Verificar etiqueta agora
                                </button>
                            </Can>
                            <!-- Pedido explícito 2026-08-21: reimprimir direto pelo
                                 navegador, sem depender do KoraSync — abre o PDF já
                                 pronto (mesmo arquivo que o agente usaria) numa aba
                                 nova, pronto pra Ctrl+P. Serve pra todo pedido com
                                 etiqueta já baixada, não só os de hoje. -->
                            <a
                                v-if="['label_ready', 'label_downloaded'].includes(order.channel_shipment.status)"
                                :href="`/admin/pedidos/${order.id}/etiqueta/imprimir`"
                                target="_blank"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary"
                            >
                                <i class="fas fa-print"></i>
                                Reimprimir etiqueta
                            </a>
                        </div>
                    </div>

                    <!-- Pré-postagem dos Correios com o QR Code (saiu da listagem
                         de pedidos em 2026-10-16 e veio para cá). -->
                    <template v-if="d.envio?.pre_postagens?.length">
                        <h3 class="mt-4 border-t border-[var(--surface-border)] pt-3 text-sm font-semibold text-slate-500">Pré-postagem dos Correios</h3>
                        <div v-for="pre in d.envio.pre_postagens" :key="pre.id" class="mt-2 flex flex-wrap gap-4 rounded-lg border border-[var(--surface-border)] p-3 text-sm">
                            <div v-if="pre.qr_payload" class="w-32 shrink-0 rounded bg-white p-1" :aria-label="`QR Code da pré-postagem ${pre.codigo_objeto ?? pre.id}`">
                                <QrCodeSvg :value="pre.qr_payload" />
                            </div>
                            <dl class="grid min-w-0 flex-1 grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
                                <div><dt class="text-xs text-slate-400">Status</dt><dd><StatusBadge :status="pre.status" context="correios" /></dd></div>
                                <div><dt class="text-xs text-slate-400">Serviço</dt><dd>{{ pre.servico ?? '—' }}<span v-if="pre.codigo_servico" class="text-xs text-slate-400"> ({{ pre.codigo_servico }})</span></dd></div>
                                <div><dt class="text-xs text-slate-400">Código do objeto</dt><dd class="font-mono">{{ pre.codigo_objeto ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-400">ID da pré-postagem</dt><dd class="font-mono text-xs">{{ pre.id_correios ?? '—' }}</dd></div>
                                <div v-if="pre.valor_postagem != null"><dt class="text-xs text-slate-400">Valor da postagem</dt><dd class="tabular-nums">{{ formatPrice(pre.valor_postagem) }}</dd></div>
                                <div v-if="pre.peso_gramas || dimensions(pre)"><dt class="text-xs text-slate-400">Peso / dimensões</dt><dd>{{ [formatWeight(pre.peso_gramas), dimensions(pre)].filter(Boolean).join(' · ') }}</dd></div>
                                <div><dt class="text-xs text-slate-400">Criada em</dt><dd>{{ formatDateTime(pre.criada_em) }}</dd></div>
                                <div class="flex items-end">
                                    <Link :href="`/admin/correios/${pre.id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary">
                                        <i class="fas fa-qrcode" aria-hidden="true"></i> Ver / imprimir etiqueta
                                    </Link>
                                </div>
                                <p v-if="pre.erro" class="text-xs text-error sm:col-span-2">{{ pre.erro }}</p>
                            </dl>
                        </div>
                    </template>
                </section>

                <!-- Devolução / estorno -->
                <section v-if="temDevolucao" class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Devolução e estorno</h2>
                    <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div v-if="d.devolucao.estornado_em"><dt class="text-xs text-slate-400">Estornado em</dt><dd class="text-error">{{ formatDateTime(d.devolucao.estornado_em) }}<span v-if="d.devolucao.estorno_origem" class="text-slate-400"> · {{ d.devolucao.estorno_origem }}</span></dd></div>
                        <div v-if="d.devolucao.estoque_devolvido_em"><dt class="text-xs text-slate-400">Estoque devolvido em</dt><dd>{{ formatDateTime(d.devolucao.estoque_devolvido_em) }}</dd></div>
                        <div v-if="d.devolucao.devolvido_pelo_canal_em"><dt class="text-xs text-slate-400">Devolvido pelo canal em</dt><dd>{{ formatDateTime(d.devolucao.devolvido_pelo_canal_em) }}</dd></div>
                        <div v-if="d.devolucao.resolucao"><dt class="text-xs text-slate-400">Resolução da devolução</dt><dd>{{ d.devolucao.resolucao }}<span v-if="d.devolucao.resolvida_em" class="text-slate-400"> · {{ formatDateTime(d.devolucao.resolvida_em) }}</span></dd></div>
                        <div v-if="d.devolucao.observacao" class="sm:col-span-2"><dt class="text-xs text-slate-400">Observação</dt><dd>{{ d.devolucao.observacao }}</dd></div>
                    </dl>
                    <template v-if="d.devolucao.notas_de_devolucao?.length">
                        <h3 class="mt-4 text-sm font-semibold text-slate-500">Notas de devolução</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="nota in d.devolucao.notas_de_devolucao" :key="nota.id" class="flex flex-wrap items-center gap-2">
                                <Link :href="`/admin/notas-fiscais/${nota.id}`" class="text-primary hover:underline">NF {{ nota.numero ?? '—' }} série {{ nota.serie ?? '—' }}</Link>
                                <StatusBadge :status="nota.status" context="invoice" />
                                <span v-if="nota.valor_total != null" class="tabular-nums text-slate-500">{{ formatPrice(nota.valor_total) }}</span>
                            </li>
                        </ul>
                    </template>
                    <template v-if="d.devolucao.reclamacoes?.length">
                        <h3 class="mt-4 text-sm font-semibold text-slate-500">Reclamações no canal</h3>
                        <ul class="mt-2 space-y-1 text-xs text-slate-500">
                            <li v-for="claim in d.devolucao.reclamacoes" :key="claim.id" class="flex flex-wrap gap-x-3">
                                <span class="font-mono">{{ claim.numero }}</span>
                                <span>{{ claim.tipo }}</span>
                                <span v-if="claim.etapa">{{ claim.etapa }}</span>
                                <span>{{ claim.status }}</span>
                                <span v-if="claim.resolucao">{{ claim.resolucao }}</span>
                                <span>aberta {{ formatDateTime(claim.aberta_em) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Nota fiscal, vídeo e históricos -->
                <section class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Nota fiscal e e-mail</h2>

                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                        <StatusBadge :status="order.invoice?.status ?? null" context="invoice" :label="order.invoice ? null : 'Ainda não emitida'" />
                        <Link v-if="d.nota" :href="`/admin/notas-fiscais/${d.nota.id}`" class="text-primary hover:underline">Abrir a nota</Link>
                        <a v-if="d.nota?.tem_danfe" :href="`/admin/pedidos/${order.id}/nota/danfe`"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary">
                            <i class="fas fa-file-pdf" aria-hidden="true"></i> Baixar DANFE
                        </a>
                        <a v-if="d.nota?.tem_xml" :href="`/admin/pedidos/${order.id}/nota/xml`"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary">
                            <i class="fas fa-file-code" aria-hidden="true"></i> Baixar XML
                        </a>
                    </div>

                    <dl v-if="d.nota" class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-400">Número / série</dt><dd>{{ d.nota.numero ?? '—' }} / {{ d.nota.serie ?? '—' }}</dd></div>
                        <div v-if="d.nota.valor_total != null"><dt class="text-xs text-slate-400">Valor da nota</dt><dd class="tabular-nums">{{ formatPrice(d.nota.valor_total) }}</dd></div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-400">Chave de acesso</dt>
                            <dd v-if="d.nota.chave" class="flex flex-wrap items-center gap-2">
                                <span class="break-all font-mono text-xs">{{ d.nota.chave }}</span>
                                <button type="button" class="text-primary" aria-label="Copiar chave de acesso" @click="copy(d.nota.chave, 'chave')">
                                    <i class="fas" :class="copiedKey === 'chave' ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>
                                </button>
                            </dd>
                            <dd v-else>—</dd>
                        </div>
                        <div v-if="d.nota.protocolo"><dt class="text-xs text-slate-400">Protocolo</dt><dd class="font-mono text-xs">{{ d.nota.protocolo }}</dd></div>
                        <div v-if="d.nota.autorizada_em"><dt class="text-xs text-slate-400">Autorizada em</dt><dd>{{ formatDateTime(d.nota.autorizada_em) }}</dd></div>
                        <div v-if="d.nota.cancelada_em"><dt class="text-xs text-slate-400">Cancelada em</dt><dd class="text-error">{{ formatDateTime(d.nota.cancelada_em) }}</dd></div>
                        <div v-if="d.nota.motivo_cancelamento" class="sm:col-span-2"><dt class="text-xs text-slate-400">Motivo do cancelamento</dt><dd>{{ d.nota.motivo_cancelamento }}</dd></div>
                        <div v-if="d.nota.motivo_rejeicao" class="sm:col-span-2"><dt class="text-xs text-slate-400">Motivo da rejeição</dt><dd class="text-error">{{ d.nota.motivo_rejeicao }}</dd></div>
                    </dl>

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

                    <!-- Vídeo da embalagem gravado no KoraSync (2026-10-11): prova do que foi embalado. -->
                    <template v-if="packingVideos.length">
                        <h3 class="mt-6 text-sm font-semibold text-slate-500"><i class="fas fa-video mr-1"></i> Vídeo da embalagem</h3>
                        <div v-for="video in packingVideos" :key="video.id" class="mt-2 rounded-xl border border-[var(--surface-border)] p-3">
                            <p v-if="video.status === 'processando'" class="text-sm text-slate-500"><i class="fas fa-circle-notch animate-spin mr-1"></i> Convertendo o vídeo…</p>
                            <video v-else :src="video.url" controls preload="metadata" class="w-full max-w-xl rounded-lg bg-black"></video>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                <span>Gravado em {{ formatDateTime(video.gravado_em) }}</span>
                                <span v-if="video.duracao">· {{ Math.floor(video.duracao / 60) }}:{{ String(video.duracao % 60).padStart(2, '0') }}</span>
                                <span v-if="video.tamanho_mb">· {{ video.tamanho_mb }} MB</span>
                                <span>· guardado até {{ formatDateTime(video.expira_em) }}</span>
                                <a :href="video.download" class="font-semibold text-primary"><i class="fas fa-download mr-1"></i> Baixar</a>
                            </div>
                        </div>
                    </template>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Linha do tempo do pedido</h3>
                    <ul v-if="fulfillmentEvents.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="event in fulfillmentEvents" :key="event.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <StatusBadge :status="event.status" context="fulfillment" />
                                <span class="ml-2 font-medium">{{ FULFILLMENT_STEP_LABELS[event.step] ?? event.step }}</span>
                                <p v-if="event.message" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ event.message }}</p>
                            </div>
                            <span class="whitespace-nowrap text-right text-xs text-slate-400">
                                {{ formatDateTime(event.created_at) }}
                                <span v-if="event.repeticoes > 1" class="block" :title="`Repetiu ${event.repeticoes} vezes; última em ${formatDateTime(event.updated_at)}`">
                                    {{ event.repeticoes }}× · última {{ formatDateTime(event.updated_at) }}
                                </span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhum evento registrado ainda.</p>

                    <h3 class="mt-6 text-sm font-semibold text-slate-500">Histórico de emissão da nota</h3>
                    <ul v-if="invoiceGenerationLogs.length" class="mt-2 space-y-2 text-sm">
                        <li v-for="log in invoiceGenerationLogs" :key="log.id" class="flex items-start justify-between gap-4 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <div>
                                <StatusBadge :status="log.status" context="invoice_log" />
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
                                <StatusBadge :status="log.status" context="email" />
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
                                    <StatusBadge :status="log.old_values.status" context="order" />
                                    para
                                    <StatusBadge :status="log.new_values.status" context="order" />
                                </span>
                                <span v-else class="text-slate-400">atualizou o pedido</span>
                            </div>
                            <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDateTime(log.created_at) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Nenhuma alteração registrada ainda.</p>
                </section>
            </div>

            <div class="h-fit space-y-6">
                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Cliente</h2>
                    <dl class="mt-2 space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Nome</dt><dd>{{ d.cliente?.nome ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-400">CPF/CNPJ</dt><dd class="font-mono">{{ formatDocument(d.cliente?.documento) }}</dd></div>
                        <div v-if="d.cliente?.inscricao_estadual"><dt class="text-xs text-slate-400">Inscrição estadual</dt><dd class="font-mono">{{ d.cliente.inscricao_estadual }}</dd></div>
                        <div><dt class="text-xs text-slate-400">E-mail</dt><dd class="break-all">
                            <a v-if="d.cliente?.email" :href="`mailto:${d.cliente.email}`" class="text-primary hover:underline">{{ d.cliente.email }}</a><span v-else>—</span>
                        </dd></div>
                        <div><dt class="text-xs text-slate-400">Telefone</dt><dd>{{ d.cliente?.telefone || '—' }}</dd></div>
                        <div v-if="d.cliente?.whatsapp"><dt class="text-xs text-slate-400">WhatsApp</dt><dd>{{ d.cliente.whatsapp }}</dd></div>
                        <div v-if="d.cliente?.whatsapp_confirmacao"><dt class="text-xs text-slate-400">Conferência no WhatsApp</dt><dd>
                            <span v-if="d.cliente.whatsapp_confirmacao === 'ok'" class="font-medium text-emerald-600">Cliente confirmou os dados</span>
                            <span v-else class="font-medium text-red-600">Cliente pediu correção</span>
                            <span class="text-xs text-slate-400"> · {{ d.cliente.whatsapp_confirmacao_em }}</span>
                        </dd></div>
                        <div v-if="d.cliente?.apelido_no_canal"><dt class="text-xs text-slate-400">Apelido no canal</dt><dd>{{ d.cliente.apelido_no_canal }}</dd></div>
                        <div v-if="d.cliente?.conta"><dt class="text-xs text-slate-400">Conta no site</dt><dd>{{ d.cliente.conta }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Endereço de entrega</h2>
                    <p class="mt-2 text-sm">
                        <span class="font-medium">{{ d.endereco?.destinatario ?? '—' }}</span><br>
                        {{ d.endereco?.rua }}, {{ d.endereco?.numero || 'S/N' }}<span v-if="d.endereco?.complemento"> - {{ d.endereco.complemento }}</span><br>
                        {{ d.endereco?.bairro }} - {{ d.endereco?.cidade }}/{{ d.endereco?.uf }}<br>
                        CEP {{ d.endereco?.cep }}
                    </p>
                </section>

                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Canal e datas</h2>
                    <dl class="mt-2 space-y-2 text-sm">
                        <div><dt class="text-xs text-slate-400">Canal</dt><dd>{{ originBadge.label }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Número do pedido</dt><dd class="font-mono">{{ d.canal?.numero ?? order.id }}</dd></div>
                        <div v-if="d.canal?.pedido_no_canal"><dt class="text-xs text-slate-400">Pedido no marketplace</dt><dd class="flex items-center gap-2">
                            <span class="font-mono">{{ d.canal.pedido_no_canal }}</span>
                            <button type="button" class="text-primary" aria-label="Copiar número do pedido no marketplace" @click="copy(d.canal.pedido_no_canal, 'externo')">
                                <i class="fas" :class="copiedKey === 'externo' ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>
                            </button>
                        </dd></div>
                        <div v-if="d.canal?.pack"><dt class="text-xs text-slate-400">Carrinho (pack)</dt><dd class="font-mono">{{ d.canal.pack }}</dd>
                            <dd v-if="d.canal.pedidos_do_pack?.length" class="mt-1 text-xs text-slate-500">
                                Outros pedidos do carrinho:
                                <Link v-for="outro in d.canal.pedidos_do_pack" :key="outro.id" :href="`/admin/pedidos/${outro.id}`" class="ml-1 text-primary hover:underline">#{{ outro.id }}</Link>
                            </dd>
                        </div>
                        <div><dt class="text-xs text-slate-400">Criado em</dt><dd>{{ formatDateTime(d.canal?.criado_em ?? order.created_at) }}</dd></div>
                        <div v-if="d.canal?.embalado_em"><dt class="text-xs text-slate-400">Embalado em</dt><dd>{{ formatDateTime(d.canal.embalado_em) }}</dd></div>
                        <div v-if="d.canal?.pronto_para_coleta_em"><dt class="text-xs text-slate-400">Pronto para coleta</dt><dd>{{ formatDateTime(d.canal.pronto_para_coleta_em) }}</dd></div>
                        <div v-if="d.canal?.coletado_em"><dt class="text-xs text-slate-400">Coletado em</dt><dd>{{ formatDateTime(d.canal.coletado_em) }}</dd></div>
                        <div v-if="d.canal?.em_disputa_desde"><dt class="text-xs text-slate-400">Em disputa desde</dt><dd class="text-error">{{ formatDateTime(d.canal.em_disputa_desde) }}</dd></div>
                        <div><dt class="text-xs text-slate-400">Última atualização</dt><dd>{{ formatDateTime(d.canal?.atualizado_em ?? order.updated_at) }}</dd></div>
                    </dl>
                </section>

                <Can permission="pedidos.edit">
                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                    <h2 class="font-semibold">Status do pedido</h2>
                    <p v-if="order.status === 'cancelled' && order.cancelado_em" class="mt-2 rounded-lg bg-lighterror p-2 text-sm text-error">
                        Cancelado em {{ formatDateTime(order.cancelado_em) }}<span v-if="order.motivo_cancelamento">: {{ order.motivo_cancelamento }}</span>
                    </p>
                    <form class="mt-2 space-y-2" @submit.prevent="updateStatus">
                        <label for="status" class="block text-sm font-medium">Status</label>
                        <select
                            id="status"
                            v-model="form.status"
                            class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        >
                            <option v-for="option in statusOptions(statuses, 'order')" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>

                        <label for="origin" class="block pt-2 text-sm font-medium">Canal</label>
                        <select
                            id="origin"
                            v-model="form.origin"
                            class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                        >
                            <option v-for="(label, channel) in CHANNEL_EDIT_OPTIONS" :key="channel" :value="channel">{{ label }}</option>
                        </select>
                        <p class="text-xs text-slate-400">Corrige o canal se o pedido foi importado errado — não gera etiqueta/nota nova sozinho.</p>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full rounded-lg bg-primary py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Atualizar status
                        </button>
                    </form>
                </section>

                <section
                    v-if="cancelamento?.pode"
                    class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm"
                >
                    <h2 class="font-semibold">Cancelar pedido</h2>
                    <button
                        type="button"
                        class="mt-2 w-full rounded-lg border border-error py-2 text-sm font-medium text-error hover:bg-error hover:text-white"
                        @click="abrirCancelamento"
                    >
                        <i class="fas fa-ban mr-1" aria-hidden="true"></i> Cancelar pedido
                    </button>

                </section>
                </Can>
            </div>
        </div>
    </AdminLayout>

    <ConfirmModal
        :open="showCancelOrder && !!cancelamento?.pode"
        title="Poxa, você tem certeza que quer fazer isso?"
        confirm-label="Sim, cancelar pedido"
        cancel-label="Não, voltar"
        danger
        :loading="cancelOrderForm.processing"
        :confirm-disabled="cancelOrderForm.motivo.trim().length < 5"
        @close="fecharCancelamento"
        @confirm="cancelOrder"
    >
        <form v-if="cancelamento" class="space-y-3" @submit.prevent="cancelOrder">
            <p class="text-sm">O pedido <strong>{{ d.canal?.numero ?? order.id }}</strong> será cancelado. Isso não tem volta.</p>
            <ul class="space-y-1.5 text-sm">
                <li v-if="cancelamento.canal" class="rounded-lg bg-lightwarning p-2 text-warning-emphasis">
                    <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>{{ cancelamento.canal }}
                </li>
                <li v-if="cancelamento.etiqueta" class="rounded-lg bg-lightwarning p-2 text-warning-emphasis">
                    <i class="fas fa-print mr-1" aria-hidden="true"></i>{{ cancelamento.etiqueta }}
                </li>
                <li><span class="text-xs text-slate-400">Nota fiscal</span><br>{{ cancelamento.nota }}</li>
                <li>
                    <span class="text-xs text-slate-400">Pagamento</span>
                    <span v-for="(linha, i) in cancelamento.pagamentos" :key="i" class="block">{{ linha }}</span>
                </li>
                <li><span class="text-xs text-slate-400">Estoque</span><br>{{ cancelamento.estoque }}</li>
                <li><span class="text-xs text-slate-400">Cliente</span><br>{{ cancelamento.cliente }}</li>
            </ul>

            <div>
                <label for="motivo_cancelamento_pedido" class="block text-sm font-medium">Motivo (fica no histórico, não vai para o cliente)</label>
                <textarea
                    id="motivo_cancelamento_pedido"
                    v-model="cancelOrderForm.motivo"
                    rows="3"
                    maxlength="500"
                    required
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                    placeholder="Ex.: sem estoque, cliente pediu, endereço não atendido"
                ></textarea>
                <p v-if="cancelOrderForm.errors.motivo" class="text-xs text-error">{{ cancelOrderForm.errors.motivo }}</p>
            </div>
        </form>
    </ConfirmModal>
</template>
