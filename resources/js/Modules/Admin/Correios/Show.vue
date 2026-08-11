<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import QrCodeSvg from '@/Shared/Components/QrCodeSvg.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    item: { type: Object, required: true },
});

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
                Imprimir
            </button>
        </div>

        <div v-if="item.status === 'erro'" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300 print:hidden">
            <i class="fas fa-triangle-exclamation mr-1.5"></i>
            <strong>Falhou:</strong> {{ item.errorMessage }}
        </div>

        <div class="print-area mx-auto max-w-md">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 text-center shadow-sm print:border-black print:shadow-none">
                <p class="mb-1 text-xs uppercase tracking-wide text-slate-400">Pré-postagem Correios</p>
                <h2 class="mb-4 text-lg font-semibold">{{ item.customerName }}</h2>

                <div v-if="item.qrPayload" class="mx-auto mb-4 w-56">
                    <QrCodeSvg :value="item.qrPayload" />
                </div>
                <p v-if="item.qrPayload" class="mb-6 font-mono text-sm tracking-widest">{{ item.qrPayload }}</p>
                <p v-else class="mb-6 text-sm text-slate-400">QR Code ainda não disponível.</p>

                <dl class="space-y-2 text-left text-sm">
                    <div class="flex justify-between gap-4 border-t border-[var(--surface-border)] pt-2 print:border-black">
                        <dt class="text-slate-500">Endereço</dt>
                        <dd class="text-right">{{ item.address }}</dd>
                    </div>
                    <div v-if="item.customerDocument" class="flex justify-between gap-4">
                        <dt class="text-slate-500">CPF/CNPJ</dt>
                        <dd>{{ item.customerDocument }}</dd>
                    </div>
                    <div v-if="item.customerPhone" class="flex justify-between gap-4">
                        <dt class="text-slate-500">Telefone</dt>
                        <dd>{{ item.customerPhone }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Serviço</dt>
                        <dd>{{ item.serviceLabel }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Peso</dt>
                        <dd>{{ item.weightGrams }} g</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Onde comprou</dt>
                        <dd>{{ item.originLabel }} <span v-if="item.externalOrderId">— {{ item.externalOrderId }}</span></dd>
                    </div>
                    <div v-if="item.codigoObjeto" class="flex justify-between gap-4">
                        <dt class="text-slate-500">Código de rastreio</dt>
                        <dd class="font-mono">{{ item.codigoObjeto }}</dd>
                    </div>
                </dl>
            </div>

            <div class="mt-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-left text-sm print:hidden">
                <h3 class="mb-2 text-xs font-semibold uppercase text-slate-400">Conteúdo declarado</h3>
                <ul class="space-y-1">
                    <li v-for="(content, index) in item.contentItems" :key="index" class="flex justify-between">
                        <span>{{ content.quantidade }}x {{ content.conteudo }}</span>
                        <span class="text-slate-400">R$ {{ Number(content.valor).toFixed(2) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <p class="mx-auto mt-4 max-w-md text-center text-xs text-slate-400 print:hidden">
            Mostre este QR Code (ou o código acima) ao atendente da agência dos Correios pra concluir a postagem.
        </p>
    </AdminLayout>
</template>

<style>
/* Fora do escopo do componente de propósito: precisa alcançar o <body>/o
   restante do layout admin (sidebar, header) pra escondê-los na impressão,
   deixando só o .print-area (o cartão do QR Code) visível na folha. */
@media print {
    body * {
        visibility: hidden;
    }

    .print-area, .print-area * {
        visibility: visible;
    }

    .print-area {
        position: absolute;
        inset: 0;
        margin: 2rem auto;
    }
}
</style>
