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
                    <StatusBadge v-if="item.situacao" :label="item.situacaoLabel" :tone="item.situacaoTone" />
                    <span v-if="item.devolucao" class="ml-1.5 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"><i class="fas fa-rotate-left" aria-hidden="true"></i> Devolução</span>
                </div>
                <p v-if="item.devolucao" class="mt-1 text-sm text-slate-500">Devolução: a cliente é o remetente e a KazaKora o destinatário.</p>
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

        <div v-if="item.duplicadas?.length" class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300 print:hidden">
            <i class="fas fa-clone mr-1.5"></i>
            <strong>Possível etiqueta duplicada:</strong>
            mesmo cliente, mesmo CEP e número, gerada no mesmo dia.
            <span v-for="(dup, i) in item.duplicadas" :key="dup.id">
                {{ i === 0 ? ' Ver' : ',' }}
                <Link :href="`/admin/correios/${dup.id}`" class="font-semibold underline hover:text-amber-900 dark:hover:text-amber-100">{{ dup.codigoObjeto || `#${dup.id}` }}</Link>
                <span v-if="dup.externalOrderId || dup.orderId"> (pedido {{ dup.externalOrderId || `#${dup.orderId}` }}<template v-if="dup.situacaoLabel">, {{ dup.situacaoLabel.toLowerCase() }}</template>)</span>
            </span>.
        </div>

        <div v-if="item.situacao || item.historico?.length" class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm print:hidden">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    Situação nos Correios
                    <StatusBadge v-if="item.situacao" :label="item.situacaoLabel" :tone="item.situacaoTone" />
                </h2>
                <span class="text-xs text-slate-400">
                    <template v-if="item.consultadoEm">Rastreio consultado em {{ item.consultadoEm }}</template>
                    <template v-else-if="item.situacao">Rastreio ainda não consultado</template>
                </span>
            </div>

            <dl v-if="item.situacao" class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Rastreio</dt>
                    <dd class="font-mono">{{ item.codigoObjeto || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Postada em</dt>
                    <dd>{{ item.postadaEm || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Entregue em</dt>
                    <dd>{{ item.entregueEm || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Expirada em</dt>
                    <dd :class="item.expiradaEm ? 'font-semibold text-red-600 dark:text-red-400' : ''">{{ item.expiradaEm || '—' }}</dd>
                </div>
            </dl>

            <ol class="relative ml-2 border-l border-[var(--surface-border)]">
                <li v-for="(evento, i) in item.historico" :key="i" class="relative mb-3 ml-4 last:mb-0">
                    <span class="absolute -left-[1.4rem] top-1 h-3 w-3 rounded-full border-2 border-[var(--surface)]"
                        :class="{
                            'bg-red-500': evento.tom === 'red',
                            'bg-emerald-500': evento.tom === 'green',
                            'bg-sky-500': evento.tom === 'blue',
                            'bg-slate-400': evento.tom === 'gray',
                        }"></span>
                    <p class="text-sm" :class="evento.tom === 'red' ? 'font-semibold text-red-600 dark:text-red-400' : ''">{{ evento.descricao }}</p>
                    <p class="text-xs text-slate-400">{{ evento.data || 'sem data' }}<template v-if="evento.local"> · {{ evento.local }}</template></p>
                </li>
            </ol>
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
                :devolucao="item.devolucao"
            />
        </div>

        <p class="mx-auto mt-4 max-w-md text-center text-xs text-slate-400 print:hidden">
            <template v-if="item.devolucao">Etiqueta 10×15 de devolução: QR/código/CEP da KazaKora, destinatário KazaKora, remetente a cliente e declaração do produto (sem DANFE).</template>
            <template v-else>Etiqueta 10×15 em retrato, igual à que sai sozinha na impressora: QR/código/CEP, destinatário, remetente, declaração do produto e DANFE.</template>
        </p>
    </AdminLayout>
</template>
