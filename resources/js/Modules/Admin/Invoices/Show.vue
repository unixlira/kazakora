<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    invoice: {
        type: Object,
        required: true,
    },
});

const formatPrice = (value) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const formatDateTime = (value) => (value ? new Date(value).toLocaleString('pt-BR') : '—');

const invoiceBadge = {
    pending: { color: 'pending', label: 'Pendente' },
    signed: { color: 'shipped', label: 'Assinada' },
    sent: { color: 'shipped', label: 'Enviada à SEFAZ' },
    authorized: { color: 'completed', label: 'Emitida' },
    rejected: { color: 'cancelled', label: 'Rejeitada' },
    denied: { color: 'cancelled', label: 'Denegada' },
    cancelled: { color: 'cancelled', label: 'Cancelada' },
    error: { color: 'cancelled', label: 'Erro' },
    external: { color: 'shipped', label: 'Emitida pelo canal' },
};

const originLabels = {
    loja: 'Loja',
    nota_fiscal_avulsa: 'Emissão manual',
};

const showCancelModal = ref(false);
const cancelForm = useForm({ motivo: '' });
const openCancelModal = () => {
    cancelForm.reset();
    cancelForm.clearErrors();
    showCancelModal.value = true;
};
const cancelInvoice = () => {
    cancelForm.post(`/admin/notas-fiscais/${props.invoice.id}/cancelar`, {
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="`Nota Fiscal ${invoice.numero}/${invoice.serie}`" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <Link href="/admin/notas-fiscais" class="text-sm text-slate-400 hover:text-primary">
                    <i class="fas fa-arrow-left mr-1"></i> Notas Fiscais
                </Link>
                <h1 class="mt-1 text-2xl font-bold">Nota {{ invoice.numero }}/{{ invoice.serie }}</h1>
            </div>
            <StatusBadge
                :status="invoiceBadge[invoice.status]?.color ?? invoice.status"
                :label="invoiceBadge[invoice.status]?.label ?? invoice.status"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-2">
                <h2 class="font-semibold">Dados da nota</h2>

                <dl class="mt-3 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Valor</dt>
                        <dd class="mt-0.5 font-semibold">{{ formatPrice(invoice.valor_total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Ambiente</dt>
                        <dd class="mt-0.5 capitalize">{{ invoice.ambiente ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Chave de acesso</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs">{{ invoice.chave_acesso ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Origem</dt>
                        <dd class="mt-0.5">
                            {{ invoice.origem === 'sefaz' ? 'Trazida da SEFAZ (sincronização)' : 'Kazakora' }}
                            <span v-if="invoice.order" class="text-slate-400">· {{ originLabels[invoice.order.origin] ?? invoice.order.origin }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Pedido</dt>
                        <dd class="mt-0.5">
                            <Link v-if="invoice.order" :href="`/admin/pedidos/${invoice.order.id}`" class="text-primary hover:underline">
                                #{{ invoice.order.id }}
                            </Link>
                            <span v-else class="italic text-slate-400">sem pedido — nota trazida da SEFAZ</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Destinatário</dt>
                        <dd class="mt-0.5">{{ invoice.destinatario_nome ?? '—' }}</dd>
                    </div>
                    <div v-if="invoice.destinatario_documento">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">CPF/CNPJ do destinatário</dt>
                        <dd class="mt-0.5 font-mono text-xs">{{ invoice.destinatario_documento }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Autorizada em</dt>
                        <dd class="mt-0.5">{{ formatDateTime(invoice.autorizada_em) }}</dd>
                    </div>
                    <div v-if="invoice.protocolo_autorizacao">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Protocolo de autorização</dt>
                        <dd class="mt-0.5 font-mono text-xs">{{ invoice.protocolo_autorizacao }}</dd>
                    </div>
                    <div v-if="invoice.motivo_rejeicao" class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Motivo da rejeição/denegação</dt>
                        <dd class="mt-0.5 text-error">{{ invoice.motivo_rejeicao }}</dd>
                    </div>
                    <template v-if="invoice.status === 'cancelled'">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Cancelada em</dt>
                            <dd class="mt-0.5">{{ formatDateTime(invoice.cancelada_em) }}</dd>
                        </div>
                        <div v-if="invoice.protocolo_cancelamento">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Protocolo de cancelamento</dt>
                            <dd class="mt-0.5 font-mono text-xs">{{ invoice.protocolo_cancelamento }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Motivo do cancelamento</dt>
                            <dd class="mt-0.5">{{ invoice.motivo_cancelamento }}</dd>
                        </div>
                    </template>
                </dl>
            </div>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="font-semibold">Arquivos e ações</h2>

                <div class="mt-3 flex flex-col gap-2">
                    <a
                        v-if="invoice.has_danfe"
                        :href="`/admin/notas-fiscais/${invoice.id}/danfe`"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-lightprimary"
                    >
                        <i class="fas fa-file-pdf"></i> Baixar DANFE (PDF)
                    </a>
                    <p v-else class="text-xs text-slate-400">DANFE não disponível{{ invoice.origem === 'sefaz' ? ' — nota trazida da SEFAZ, sem cópia local do PDF' : '' }}.</p>

                    <a
                        v-if="invoice.has_xml"
                        :href="`/admin/notas-fiscais/${invoice.id}/xml`"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-lightprimary"
                    >
                        <i class="fas fa-file-code"></i> Baixar XML
                    </a>
                    <p v-else class="text-xs text-slate-400">XML não disponível{{ invoice.origem === 'sefaz' ? ' — nota trazida da SEFAZ, sem cópia local do XML' : '' }}.</p>
                </div>

                <Can permission="pedidos.edit">
                    <div v-if="invoice.can_cancel" class="mt-4 border-t border-[var(--surface-border)] pt-4">
                        <button
                            type="button"
                            class="w-full rounded-lg border border-error px-3 py-2 text-sm font-medium text-error hover:bg-error/10"
                            @click="openCancelModal"
                        >
                            Cancelar nota
                        </button>
                    </div>
                    <p v-else-if="invoice.status === 'authorized'" class="mt-4 border-t border-[var(--surface-border)] pt-4 text-xs text-slate-400">
                        Prazo de 24h pra cancelamento já expirou.
                    </p>
                </Can>
            </div>
        </div>

        <ConfirmModal
            :open="showCancelModal"
            title="Cancelar nota fiscal"
            confirm-label="Confirmar cancelamento"
            danger
            :loading="cancelForm.processing"
            :confirm-disabled="cancelForm.motivo.trim().length < 15"
            @close="showCancelModal = false"
            @confirm="cancelInvoice"
        >
            <p class="text-sm text-slate-500">
                O cancelamento é enviado direto pra SEFAZ e não pode ser desfeito. Tem certeza que quer cancelar a nota
                <strong>{{ invoice.numero }}/{{ invoice.serie }}</strong>?
            </p>

            <label for="motivo_cancelamento" class="mt-4 block text-sm font-medium">Motivo do cancelamento (mín. 15 caracteres)</label>
            <textarea
                id="motivo_cancelamento"
                v-model="cancelForm.motivo"
                rows="3"
                minlength="15"
                required
                class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
            ></textarea>
            <p v-if="cancelForm.errors.motivo" class="mt-1 text-xs text-error">{{ cancelForm.errors.motivo }}</p>
        </ConfirmModal>
    </AdminLayout>
</template>
