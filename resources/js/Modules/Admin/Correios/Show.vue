<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import ShippingFiscalLabel from '@/Modules/Admin/Correios/Components/ShippingFiscalLabel.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
});

const orderLabel = props.item.externalOrderId || (props.item.orderId ? `#${props.item.orderId}` : null);

// A etiqueta gerada é o PDF do servidor (CorreiosLabelPdf) — o mesmo
// arquivo que a impressão automática manda pra térmica. Abre no leitor de
// PDF do navegador, que imprime no tamanho exato 10x15.
const pdfUrl = `/admin/correios/${props.item.id}/etiqueta.pdf`;
const printLabel = () => window.open(pdfUrl, '_blank');

// Cancela nos Correios (DELETE da pré-postagem na API deles) — só enquanto
// não foi postada. Depois disso ela sai do custo de frete e do pedido.
const cancelar = () => {
    if (!window.confirm(`Cancelar a pré-postagem ${props.item.codigoObjeto || ''} nos Correios? Isso não tem volta.`)) return;
    router.post(`/admin/correios/${props.item.id}/cancelar`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Correios — ${item.customerName}`" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div>
                <Link href="/admin/correios" class="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary">
                    <i class="fas fa-arrow-left text-xs"></i> Voltar
                </Link>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold">Pré-postagem — {{ item.customerName }}</h1>
                    <StatusBadge :status="item.status" context="correios" />
                </div>
            </div>
            <button v-if="item.status === 'gerada'" type="button"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="printLabel">
                <i class="fas fa-print mr-1.5"></i>
                Imprimir etiqueta 10×15
            </button>
            <button v-if="item.status === 'gerada'" type="button"
                class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/30"
                @click="cancelar">
                <i class="fas fa-ban mr-1.5"></i>
                Cancelar nos Correios
            </button>
            <Link v-if="item.status === 'erro'" :href="`/admin/correios/${item.id}/editar`"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-pen mr-1.5"></i>
                Corrigir e tentar de novo
            </Link>
        </div>

        <div v-if="item.status === 'erro'" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300 print:hidden">
            <i class="fas fa-triangle-exclamation mr-1.5"></i>
            <strong>Falhou:</strong> {{ item.errorMessage }}
        </div>

        <div v-if="item.status === 'gerada'" class="mx-auto max-w-md">
            <iframe :src="`${pdfUrl}#toolbar=0&view=Fit`" title="Etiqueta 10x15"
                class="aspect-[2/3] w-full rounded-lg border border-[var(--surface-border)] bg-white"></iframe>
        </div>

        <div v-else class="print-area mx-auto max-w-3xl">
            <ShippingFiscalLabel
                :recipient="item.recipient"
                :sender="item.sender"
                :invoice="item.invoice"
                :qr-payload="item.qrPayload"
                :codigo-objeto="item.codigoObjeto"
                :correios-id="item.correiosId"
                :service-label="item.serviceLabel"
                :weight-grams="item.weightGrams"
                :content-items="item.contentItems"
                :order-label="orderLabel"
            />
        </div>

        <p class="mx-auto mt-4 max-w-md text-center text-xs text-slate-400 print:hidden">
            Etiqueta 10×15 em retrato, igual à que sai sozinha na impressora: QR/código/CEP, destinatário, remetente, declaração do produto e DANFE.
        </p>
    </AdminLayout>
</template>
