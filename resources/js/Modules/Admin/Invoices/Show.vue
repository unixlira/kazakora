<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
    nota_devolucao_venda: 'Devolução de venda (entrada)',
    nota_devolucao_compra: 'Devolução de compra',
};

// Prazos de SP (contador, 2026-10-08): até 24h normal, até 480h com multa
// e só se a mercadoria não saiu, depois só devolução.
const foraDoPrazo = computed(() => props.invoice.janela_cancelamento === 'extemporanea');

const showCancelModal = ref(false);
const cancelForm = useForm({ motivo: '', fora_do_prazo: false });
const mercadoriaNaoSaiu = ref(false);
const openCancelModal = () => {
    cancelForm.reset();
    cancelForm.clearErrors();
    mercadoriaNaoSaiu.value = false;
    showCancelModal.value = true;
};
const cancelInvoice = () => {
    cancelForm.fora_do_prazo = foraDoPrazo.value;
    cancelForm.post(`/admin/notas-fiscais/${props.invoice.id}/cancelar`, {
        onSuccess: () => {
            showCancelModal.value = false;
            cancelForm.reset();
        },
    });
};

// Devolução de venda pessoa física: declaração + NF-e de entrada 1202/2202.
const devolucao = computed(() => props.invoice.devolucao);
const itensDevolviveis = computed(() => (devolucao.value?.itens ?? []).filter((item) => item.disponivel > 0));
const returnForm = useForm({
    itens: Object.fromEntries((props.invoice.devolucao?.itens ?? []).map((item) => [item.id, 0])),
    motivo: '',
    volta_ao_estoque: true,
    declaracao: null,
});
const showReturnModal = ref(false);
const totalDevolvido = computed(() =>
    itensDevolviveis.value.reduce((soma, item) => soma + item.preco * (Number(returnForm.itens[item.id]) || 0), 0),
);
const temItemEscolhido = computed(() => Object.values(returnForm.itens).some((quantidade) => Number(quantidade) > 0));
const devolverTudo = () => {
    itensDevolviveis.value.forEach((item) => {
        returnForm.itens[item.id] = item.disponivel;
    });
};
const declaracaoUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries(returnForm.itens).forEach(([id, quantidade]) => {
        if (Number(quantidade) > 0) params.append(`itens[${id}]`, quantidade);
    });
    if (returnForm.motivo.trim()) params.append('motivo', returnForm.motivo.trim());
    return `/admin/notas-fiscais/${props.invoice.id}/declaracao-devolucao?${params.toString()}`;
});
const registerReturn = () => {
    returnForm.post(`/admin/notas-fiscais/${props.invoice.id}/devolucao`, {
        forceFormData: true,
        onSuccess: () => {
            showReturnModal.value = false;
        },
    });
};
const declarationForm = useForm({ declaracao: null });
const attachDeclaration = () => {
    declarationForm.post(`/admin/notas-fiscais/${props.invoice.id}/declaracao-assinada`, {
        forceFormData: true,
        onSuccess: () => declarationForm.reset(),
    });
};
const returnStatusLabel = (status) => invoiceBadge[status]?.label ?? status;
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
                        <div v-if="invoice.cancelamento_extemporaneo" class="sm:col-span-2">
                            <dd class="rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                                Cancelada depois das 24h (fora do prazo): sujeita à multa do RICMS-SP. Informe ao contador.
                            </dd>
                        </div>
                    </template>
                    <div v-if="invoice.status === 'authorized' && invoice.cancelamento_normal_ate" class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Prazo de cancelamento</dt>
                        <dd class="mt-0.5">
                            Sem multa até {{ formatDateTime(invoice.cancelamento_normal_ate) }} ·
                            com multa (se a mercadoria não saiu) até {{ formatDateTime(invoice.cancelamento_extemporaneo_ate) }}
                        </dd>
                    </div>
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

                    <a
                        v-if="invoice.has_xml_cancelamento"
                        :href="`/admin/notas-fiscais/${invoice.id}/cancelamento-xml`"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-lightprimary"
                    >
                        <i class="fas fa-file-circle-xmark"></i> Baixar XML do cancelamento
                    </a>
                </div>

                <Can permission="pedidos.edit">
                    <div v-if="invoice.can_cancel" class="mt-4 border-t border-[var(--surface-border)] pt-4">
                        <p v-if="foraDoPrazo" class="mb-2 rounded-lg bg-warning/10 px-3 py-2 text-xs text-warning">
                            Passou das 24h. Só cancele se a mercadoria não saiu; a multa estimada é de
                            <strong>{{ formatPrice(invoice.multa_cancelamento) }}</strong>. Se saiu, registre a devolução abaixo.
                        </p>
                        <button
                            type="button"
                            class="w-full rounded-lg border border-error px-3 py-2 text-sm font-medium text-error hover:bg-error/10"
                            @click="openCancelModal"
                        >
                            {{ foraDoPrazo ? 'Cancelar fora do prazo (com multa)' : 'Cancelar nota' }}
                        </button>
                    </div>
                    <p v-else-if="invoice.status === 'authorized'" class="mt-4 border-t border-[var(--surface-border)] pt-4 text-xs text-slate-400">
                        Passou o prazo de 480h para cancelar. Se a mercadoria voltou, registre a devolução abaixo.
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
            :confirm-disabled="cancelForm.motivo.trim().length < 15 || (foraDoPrazo && !mercadoriaNaoSaiu)"
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

            <div v-if="foraDoPrazo" class="mt-4 rounded-lg bg-warning/10 p-3 text-sm">
                <p class="text-warning">
                    Cancelamento fora do prazo de 24h. A SEFAZ-SP aceita até 480h, mas cabe multa de 1% do valor da nota,
                    no mínimo 6 UFESPs (estimativa: <strong>{{ formatPrice(invoice.multa_cancelamento) }}</strong>).
                </p>
                <label class="mt-2 flex items-start gap-2">
                    <input v-model="mercadoriaNaoSaiu" type="checkbox" class="mt-1" />
                    <span>Confirmo que a mercadoria <strong>não saiu</strong> da loja (não circulou).</span>
                </label>
            </div>
        </ConfirmModal>

        <!-- Devolução de venda: declaração do cliente PF + NF-e de entrada 1202/2202 -->
        <div v-if="devolucao?.tipo === 'venda'" class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <h2 class="font-semibold">Devolução de venda</h2>
            <p class="mt-1 text-sm text-slate-500">
                Cliente pessoa física não emite nota: ele assina a declaração de devolução e a loja emite a NF-e de entrada
                (CFOP 1202 dentro de SP, 2202 fora) referenciando esta nota.
            </p>

            <p v-if="devolucao.impedimento" class="mt-3 text-sm text-slate-400">{{ devolucao.impedimento }}</p>

            <template v-else>
                <p v-if="devolucao.canal_emite_devolucao" class="mt-3 rounded-lg bg-warning/10 px-3 py-2 text-sm text-warning">
                    Este pedido é de um canal que emite a nota de devolução sozinho. Confira no canal antes de emitir aqui, pra não duplicar
                    (a nota 2567 de set/2026 foi cancelada por isso).
                </p>

                <Can permission="pedidos.edit">
                    <div v-if="itensDevolviveis.length" class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th class="py-2 pr-3">Produto</th>
                                    <th class="py-2 pr-3 text-right">Vendido</th>
                                    <th class="py-2 pr-3 text-right">Já devolvido</th>
                                    <th class="py-2 text-right">Devolver</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in itensDevolviveis" :key="item.id" class="border-t border-[var(--surface-border)]">
                                    <td class="py-2 pr-3">{{ item.nome }} <span class="text-slate-400">· {{ formatPrice(item.preco) }}</span></td>
                                    <td class="py-2 pr-3 text-right">{{ item.vendido }}</td>
                                    <td class="py-2 pr-3 text-right">{{ item.devolvido }}</td>
                                    <td class="py-2 text-right">
                                        <input
                                            v-model.number="returnForm.itens[item.id]"
                                            type="number"
                                            min="0"
                                            :max="item.disponivel"
                                            class="w-20 rounded-lg border border-[var(--surface-border)] px-2 py-1 text-right"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <button type="button" class="mt-2 text-xs text-primary hover:underline" @click="devolverTudo">Devolver tudo</button>

                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="motivo_devolucao" class="block text-sm font-medium">Motivo da devolução</label>
                                <textarea
                                    id="motivo_devolucao"
                                    v-model="returnForm.motivo"
                                    rows="2"
                                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"
                                    placeholder="Ex.: produto com defeito, arrependimento da compra"
                                ></textarea>
                                <p v-if="returnForm.errors.motivo" class="mt-1 text-xs text-error">{{ returnForm.errors.motivo }}</p>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <label for="declaracao" class="block text-sm font-medium">Declaração assinada (PDF ou foto)</label>
                                    <input
                                        id="declaracao"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        class="mt-1 block w-full text-sm"
                                        @input="returnForm.declaracao = $event.target.files[0] ?? null"
                                    />
                                    <p class="mt-1 text-xs text-slate-400">Pode anexar depois, na nota de devolução.</p>
                                    <p v-if="returnForm.errors.declaracao" class="mt-1 text-xs text-error">{{ returnForm.errors.declaracao }}</p>
                                </div>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="returnForm.volta_ao_estoque" type="checkbox" />
                                    Produto voltou em condição de venda (devolve ao estoque)
                                </label>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <a
                                :href="declaracaoUrl"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-lightprimary"
                                :class="{ 'pointer-events-none opacity-50': !temItemEscolhido }"
                            >
                                <i class="fas fa-print"></i> 1. Imprimir declaração pro cliente assinar
                            </a>
                            <button
                                type="button"
                                :disabled="!temItemEscolhido || returnForm.motivo.trim().length < 10"
                                class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="showReturnModal = true"
                            >
                                2. Registrar devolução e emitir NF-e de entrada
                            </button>
                            <span class="text-sm text-slate-500">Total: <strong>{{ formatPrice(totalDevolvido) }}</strong></span>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-400">Todos os itens desta venda já têm devolução.</p>
                </Can>
            </template>

            <div v-if="devolucao.devolucoes?.length" class="mt-5 border-t border-[var(--surface-border)] pt-4">
                <h3 class="text-sm font-semibold text-slate-500">Devoluções desta venda</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="nota in devolucao.devolucoes" :key="nota.id">
                        <Link :href="`/admin/notas-fiscais/${nota.id}`" class="text-primary hover:underline">
                            NF-e de entrada {{ nota.numero ?? '(em emissão)' }}/{{ nota.serie }}
                        </Link>
                        <span class="text-slate-400"> · {{ formatPrice(nota.valor_total) }} · {{ returnStatusLabel(nota.status) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div v-else-if="devolucao?.tipo === 'entrada'" class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <h2 class="font-semibold">Nota de devolução (entrada)</h2>
            <p class="mt-1 text-sm">
                Referente à venda
                <Link v-if="devolucao.venda" :href="`/admin/notas-fiscais/${devolucao.venda.id}`" class="text-primary hover:underline">
                    NF-e {{ devolucao.venda.numero }}/{{ devolucao.venda.serie }}
                </Link>
                <span v-else class="text-slate-400">(nota de venda não encontrada no Kazakora)</span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a
                    v-if="devolucao.tem_declaracao"
                    :href="`/admin/notas-fiscais/${invoice.id}/declaracao-assinada`"
                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-lightprimary"
                >
                    <i class="fas fa-file-signature"></i> Baixar declaração assinada
                </a>
                <span v-else class="text-sm text-warning">Declaração assinada do cliente ainda não anexada.</span>
                <Can permission="pedidos.edit">
                    <form class="flex flex-wrap items-center gap-2" @submit.prevent="attachDeclaration">
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="text-sm" @input="declarationForm.declaracao = $event.target.files[0] ?? null" />
                        <button
                            type="submit"
                            :disabled="!declarationForm.declaracao || declarationForm.processing"
                            class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-lightprimary disabled:opacity-50"
                        >
                            {{ devolucao.tem_declaracao ? 'Trocar declaração' : 'Anexar declaração' }}
                        </button>
                    </form>
                </Can>
            </div>
            <p v-if="declarationForm.errors.declaracao" class="mt-1 text-xs text-error">{{ declarationForm.errors.declaracao }}</p>
        </div>

        <ConfirmModal
            :open="showReturnModal"
            title="Registrar devolução"
            confirm-label="Emitir NF-e de entrada"
            :loading="returnForm.processing"
            @close="showReturnModal = false"
            @confirm="registerReturn"
        >
            <p class="text-sm text-slate-500">
                Vai ser emitida na SEFAZ uma NF-e de entrada de <strong>{{ formatPrice(totalDevolvido) }}</strong> referenciando a nota
                <strong>{{ invoice.numero }}/{{ invoice.serie }}</strong>. Depois de autorizada, só cancela em até 24h.
            </p>
            <p v-if="!returnForm.declaracao" class="mt-2 text-sm text-warning">Sem declaração anexada: lembre de anexar depois, o contador precisa dela.</p>
        </ConfirmModal>
    </AdminLayout>
</template>
