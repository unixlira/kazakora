<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ShippingFiscalLabel from '@/Modules/Admin/Correios/Components/ShippingFiscalLabel.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
});

const orderLabel = props.item.externalOrderId || (props.item.orderId ? `#${props.item.orderId}` : null);

const printLabel = () => window.print();
</script>

<template>
    <Head :title="`Correios — ${item.customerName}`" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div>
                <Link href="/admin/correios" class="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary">
                    <i class="fas fa-arrow-left text-xs"></i> Voltar
                </Link>
                <h1 class="text-2xl font-bold">Pré-postagem — {{ item.customerName }}</h1>
            </div>
            <button v-if="item.status === 'gerada'" type="button"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                @click="printLabel">
                <i class="fas fa-print mr-1.5"></i>
                Imprimir etiqueta 10×15
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

        <div class="print-area mx-auto max-w-3xl">
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
            Imprima em papel/etiqueta 10×15 em modo paisagem. A etiqueta tem três áreas: QR/código/CEP, remetente KazaKora e identificação fiscal com DANFE, código de barras e declaração genérica do produto.
        </p>
    </AdminLayout>
</template>
