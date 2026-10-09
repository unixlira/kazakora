<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import Can from '@/Shared/Components/Can.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// Fechamento fiscal do mês pro contador (pedido 2026-10-09): o mesmo
// conteúdo do e-mail do dia 1º, mais as ações que não têm volta
// (inutilizar) e a conferência das duplicidades.
const props = defineProps({
    relatorio: { type: Object, required: true },
    meses: { type: Array, required: true },
    enviadoEm: { type: String, default: null },
    destinatario: { type: String, required: true },
    duplicidadesAbertas: { type: Array, required: true },
    ufesp: { type: Object, required: true },
});

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
const faixa = (inicio, fim) => (inicio === fim ? `${inicio}` : `${inicio} a ${fim}`);

const trocarMes = (event) => router.get('/admin/notas-fiscais/fechamento', { mes: event.target.value }, { preserveScroll: true });

const envio = useForm({ mes: props.relatorio.mes });
const enviar = () => envio.post('/admin/notas-fiscais/fechamento/enviar', { preserveScroll: true });

const conferir = (ocorrencia) => router.post(`/admin/notas-fiscais/fechamento/duplicidades/${ocorrencia.id}/conferida`, {}, { preserveScroll: true });

// Inutilização: uma faixa por vez, com justificativa e "INUTILIZAR" digitado.
const inutilizar = useForm({ inicio: null, fim: null, justificativa: '', confirmacao: '' });
const faixaAberta = ref(null);
const abrirInutilizacao = (buraco) => {
    faixaAberta.value = buraco;
    inutilizar.reset();
    inutilizar.clearErrors();
    inutilizar.inicio = buraco.inicio;
    inutilizar.fim = buraco.fim;
    inutilizar.justificativa = buraco.motivo === 'sem nota no sistema'
        ? 'Numeracao nao utilizada por falha no sistema emissor'
        : 'Nota rejeitada pela SEFAZ e pedido cancelado, numero nao sera utilizado';
};
const confirmarInutilizacao = () => inutilizar.post('/admin/notas-fiscais/fechamento/inutilizar', {
    preserveScroll: true,
    onSuccess: () => { faixaAberta.value = null; },
});

const ufespForm = useForm({ ano: props.ufesp.atual.ano, valor: props.ufesp.atual.valor, base_legal: props.ufesp.atual.base_legal ?? '' });
const editandoUfesp = ref(false);
const salvarUfesp = () => ufespForm.post('/admin/notas-fiscais/fechamento/ufesp', { preserveScroll: true, onSuccess: () => { editandoUfesp.value = false; } });
const buscarUfesp = (ano) => router.post('/admin/notas-fiscais/fechamento/ufesp', { buscar: true, ano }, { preserveScroll: true });

const card = 'rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm';
const th = 'border-b border-[var(--surface-border)] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-400';
const td = 'border-b border-[var(--surface-border)] px-3 py-2';
</script>

<template>
    <Head title="Fechamento fiscal" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <Link href="/admin/notas-fiscais" class="text-sm text-slate-400 hover:text-primary">
                    <i class="fas fa-arrow-left mr-1"></i> Notas Fiscais
                </Link>
                <h1 class="mt-1 text-2xl font-bold">Fechamento fiscal</h1>
                <p class="text-sm text-slate-500">
                    Vai por e-mail todo dia 1º para {{ destinatario }}.
                    <span v-if="enviadoEm">{{ relatorio.mes_extenso }}: enviado em {{ enviadoEm }}.</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select :value="relatorio.mes" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" @change="trocarMes">
                    <option v-for="m in meses" :key="m.valor" :value="m.valor">{{ m.rotulo }}</option>
                </select>
                <a :href="`/admin/notas-fiscais/fechamento/${relatorio.mes}/csv`" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fas fa-file-csv mr-1"></i> Planilha
                </a>
                <a :href="`/admin/notas-fiscais/fechamento/${relatorio.mes}/zip`" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">
                    <i class="fas fa-file-zipper mr-1"></i> ZIP com XMLs
                </a>
                <Can permission="pedidos.edit">
                    <button type="button" class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="envio.processing" @click="enviar">
                        <i class="fas fa-paper-plane mr-1"></i> {{ enviadoEm ? 'Reenviar e-mail' : 'Enviar e-mail agora' }}
                    </button>
                </Can>
            </div>
        </div>

        <!-- Duplicidades em aberto: número que já existia na SEFAZ e o sistema não tem -->
        <div v-if="duplicidadesAbertas.length" class="mb-6 rounded-xl border border-error/40 bg-error/10 p-4">
            <h2 class="font-semibold text-error"><i class="fas fa-triangle-exclamation mr-1"></i> Número de nota duplicado na SEFAZ</h2>
            <p class="mt-1 text-sm">
                A SEFAZ disse que estes números já tinham nota, então o sistema pulou para o próximo. Existe uma nota com esse número
                que não está no Kazakora: descubra quem emitiu (Bling, canal, teste) e mande o XML dela ao contador.
            </p>
            <ul class="mt-3 space-y-2 text-sm">
                <li v-for="o in duplicidadesAbertas" :key="o.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-[var(--surface)] px-3 py-2">
                    <span>
                        <StatusBadge status="cancelled" label="Duplicidade" />
                        <strong class="ml-2">Nº {{ o.numero }} série {{ o.serie }}</strong>
                        <span class="text-slate-500"> — pedido <Link :href="`/admin/pedidos/${o.pedido}`" class="text-primary hover:underline">#{{ o.pedido }}</Link> foi para o número seguinte · {{ o.criado_em }}</span>
                    </span>
                    <Can permission="pedidos.edit">
                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="conferir(o)">
                            Conferido
                        </button>
                    </Can>
                </li>
            </ul>
        </div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div :class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Autorizadas</p>
                <p class="mt-1 text-xl font-bold">{{ relatorio.totais.autorizadas }}</p>
                <p class="text-xs text-slate-500">{{ brl(relatorio.totais.valor_autorizado) }}</p>
            </div>
            <div :class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Canceladas</p>
                <p class="mt-1 text-xl font-bold">{{ relatorio.totais.canceladas }}</p>
                <p class="text-xs" :class="relatorio.totais.canceladas_fora_do_prazo ? 'text-error' : 'text-slate-500'">
                    {{ relatorio.totais.canceladas_fora_do_prazo }} fora do prazo
                </p>
            </div>
            <div :class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Multa estimada</p>
                <p class="mt-1 text-xl font-bold" :class="relatorio.totais.multa_estimada ? 'text-error' : ''">{{ brl(relatorio.totais.multa_estimada) }}</p>
                <p class="text-xs text-slate-500">1% da nota, mín. 6 UFESPs</p>
            </div>
            <div :class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Devoluções</p>
                <p class="mt-1 text-xl font-bold">{{ relatorio.totais.devolucoes }}</p>
            </div>
            <div :class="card">
                <p class="text-xs uppercase tracking-wide text-slate-400">Sem XML no sistema</p>
                <p class="mt-1 text-xl font-bold" :class="relatorio.totais.sem_xml ? 'text-error' : ''">{{ relatorio.totais.sem_xml }}</p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div :class="card" class="lg:col-span-2">
                <h2 class="font-semibold">Séries usadas em {{ relatorio.mes_extenso }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr><th :class="th">Série</th><th :class="th">Emissor</th><th :class="th">Números</th><th :class="th">Autorizadas</th><th :class="th">Canceladas</th><th :class="th">Devoluções</th></tr></thead>
                        <tbody>
                            <tr v-for="s in relatorio.series" :key="s.serie">
                                <td :class="td" class="font-semibold">{{ s.serie }}</td>
                                <td :class="td"><StatusBadge :status="s.serie === relatorio.serie_kazakora ? 'completed' : 'sent'" :label="s.emissor" /></td>
                                <td :class="td">{{ s.primeiro }} a {{ s.ultimo }}</td>
                                <td :class="td">{{ s.autorizadas }} <span class="text-xs text-slate-500">({{ brl(s.valor) }})</span></td>
                                <td :class="td">{{ s.canceladas }}</td>
                                <td :class="td">{{ s.devolucoes }}</td>
                            </tr>
                            <tr v-if="!relatorio.series.length"><td :class="td" colspan="6" class="text-slate-400">Nenhuma nota no mês.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div :class="card">
                <h2 class="font-semibold">UFESP</h2>
                <p class="mt-2 text-2xl font-bold">{{ brl(ufesp.atual.valor) }} <span class="text-sm font-normal text-slate-500">em {{ ufesp.atual.ano }}</span></p>
                <p v-if="ufesp.atual.base_legal" class="text-xs text-slate-500">{{ ufesp.atual.base_legal }}</p>
                <a v-if="ufesp.atual.fonte && ufesp.atual.fonte !== 'manual'" :href="ufesp.atual.fonte" target="_blank" rel="noopener" class="text-xs text-primary hover:underline">Ver na Fazenda de SP</a>
                <p v-else-if="ufesp.atual.fonte === 'manual'" class="text-xs text-warning">Valor lançado à mão.</p>
                <p v-if="ufesp.proximo" class="mt-2 text-xs text-slate-500">{{ ufesp.proximo.ano }}: {{ brl(ufesp.proximo.valor) }} (já publicado)</p>
                <p class="mt-3 text-xs text-slate-500">
                    Atualiza sozinha: todo dia o sistema procura o valor do ano até achar, e só grava depois de confirmar na página oficial da Fazenda.
                </p>
                <Can permission="pedidos.edit">
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="buscarUfesp(ufesp.atual.ano)">Buscar agora</button>
                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1 text-xs font-medium hover:bg-[var(--surface-muted)]" @click="editandoUfesp = !editandoUfesp">Lançar à mão</button>
                    </div>
                    <form v-if="editandoUfesp" class="mt-3 space-y-2 text-sm" @submit.prevent="salvarUfesp">
                        <div class="flex gap-2">
                            <input v-model="ufespForm.ano" type="number" class="w-24 rounded-lg border border-[var(--surface-border)] px-2 py-1" aria-label="Ano" />
                            <input v-model="ufespForm.valor" type="number" step="0.01" class="w-28 rounded-lg border border-[var(--surface-border)] px-2 py-1" aria-label="Valor" />
                        </div>
                        <input v-model="ufespForm.base_legal" type="text" placeholder="Base legal (ex.: Comunicado DICAR-88/25)" class="w-full rounded-lg border border-[var(--surface-border)] px-2 py-1" />
                        <p v-if="ufespForm.errors.valor" class="text-xs text-error">{{ ufespForm.errors.valor }}</p>
                        <button type="submit" class="rounded-lg bg-primary px-3 py-1 text-xs font-semibold text-white" :disabled="ufespForm.processing">Salvar</button>
                    </form>
                </Can>
            </div>
        </div>

        <div :class="card" class="mt-6">
            <h2 class="font-semibold">Notas canceladas</h2>
            <p class="text-xs text-slate-500">Até 24h da autorização é no prazo. De 24h a 480h a SEFAZ-SP aceita, mas cabe multa (RICMS-SP art. 527, IV, z1).</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr><th :class="th">Série/Nº</th><th :class="th">Autorizada</th><th :class="th">Cancelada</th><th :class="th">Prazo</th><th :class="th">Valor</th><th :class="th">Multa estimada</th><th :class="th">XML cancel.</th></tr></thead>
                    <tbody>
                        <tr v-for="n in relatorio.canceladas" :key="n.id">
                            <td :class="td"><Link :href="`/admin/notas-fiscais/${n.id}`" class="font-semibold text-primary hover:underline">{{ n.numero }}/{{ n.serie }}</Link></td>
                            <td :class="td">{{ n.autorizada_em ?? '—' }}</td>
                            <td :class="td">{{ n.cancelada_em ?? 'sem data' }}</td>
                            <td :class="td">
                                <StatusBadge v-if="n.fora_do_prazo" status="cancelled" :label="`Fora do prazo${n.horas_ate_cancelar !== null ? ` (${n.horas_ate_cancelar}h)` : ''}`" />
                                <StatusBadge v-else-if="n.horas_ate_cancelar !== null" status="completed" :label="`No prazo (${n.horas_ate_cancelar}h)`" />
                                <StatusBadge v-else status="draft" label="Sem data" />
                            </td>
                            <td :class="td">{{ brl(n.valor) }}</td>
                            <td :class="td">{{ n.multa ? brl(n.multa) : '' }}</td>
                            <td :class="td">
                                <a v-if="n.tem_xml_cancelamento" :href="`/admin/notas-fiscais/${n.id}/cancelamento-xml`" class="text-primary hover:underline">Baixar</a>
                                <StatusBadge v-else status="draft" label="Não tem" />
                            </td>
                        </tr>
                        <tr v-if="!relatorio.canceladas.length"><td :class="td" colspan="7" class="text-slate-400">Nenhuma nota cancelada no mês.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div :class="card">
                <h2 class="font-semibold">Números sem nota (série {{ relatorio.serie_kazakora }})</h2>
                <p class="text-xs text-slate-500">
                    Número que não virou nota precisa ser inutilizado na SEFAZ. Inutilizar não tem volta: confira antes se a nota não existe em outro emissor.
                </p>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-for="b in relatorio.buracos" :key="b.inicio" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <span>
                            <strong>{{ faixa(b.inicio, b.fim) }}</strong>
                            <span class="text-slate-500"> · {{ b.quantidade }} número(s) · {{ b.motivo }}</span>
                        </span>
                        <Can permission="pedidos.edit">
                            <button type="button" class="rounded-lg border border-error/40 px-3 py-1 text-xs font-medium text-error hover:bg-error/10" @click="abrirInutilizacao(b)">
                                Inutilizar
                            </button>
                        </Can>
                    </li>
                    <li v-if="!relatorio.buracos.length" class="text-slate-400">Nenhum número sem nota.</li>
                </ul>
            </div>

            <div :class="card">
                <h2 class="font-semibold">Duplicidades e inutilizações em {{ relatorio.mes_extenso }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-for="o in relatorio.inutilizacoes" :key="`i${o.id}`" class="rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <StatusBadge status="completed" label="Inutilizado" />
                        <strong class="ml-2">{{ faixa(o.inicio, o.fim) }}</strong>
                        <span class="text-slate-500"> · protocolo {{ o.protocolo }} · {{ o.criado_em }}</span>
                    </li>
                    <li v-for="o in relatorio.duplicidades" :key="`d${o.id}`" class="rounded-lg border border-[var(--surface-border)] px-3 py-2">
                        <StatusBadge :status="o.resolvido_em ? 'completed' : 'cancelled'" :label="o.resolvido_em ? 'Duplicidade conferida' : 'Duplicidade'" />
                        <strong class="ml-2">Nº {{ o.inicio }}</strong>
                        <span class="text-slate-500"> · pedido #{{ o.pedido }} · {{ o.criado_em }}</span>
                    </li>
                    <li v-if="!relatorio.inutilizacoes.length && !relatorio.duplicidades.length" class="text-slate-400">Nada no mês.</li>
                </ul>
            </div>
        </div>

        <div v-if="relatorio.sem_xml.length || relatorio.devolucoes.length" class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div v-if="relatorio.devolucoes.length" :class="card">
                <h2 class="font-semibold">Notas de devolução</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    <li v-for="n in relatorio.devolucoes" :key="n.id">
                        <Link :href="`/admin/notas-fiscais/${n.id}`" class="font-semibold text-primary hover:underline">{{ n.numero }}/{{ n.serie }}</Link>
                        <span class="text-slate-500"> · {{ n.operacao === 'sales_return' ? 'devolução de venda (entrada)' : 'devolução de compra (saída)' }} · {{ brl(n.valor) }}</span>
                    </li>
                </ul>
            </div>
            <div v-if="relatorio.sem_xml.length" :class="card">
                <h2 class="font-semibold">Notas sem XML no sistema</h2>
                <p class="text-xs text-slate-500">Estas notas existem na SEFAZ, mas o XML não está no Kazakora (vieram do Bling ou do canal). Baixe no emissor de origem.</p>
                <p class="mt-2 text-sm">{{ relatorio.sem_xml.map((n) => `${n.numero}/${n.serie}`).join(', ') }}</p>
            </div>
        </div>

        <ConfirmModal
            :open="faixaAberta !== null"
            title="Inutilizar números na SEFAZ"
            confirm-label="Inutilizar"
            danger
            :loading="inutilizar.processing"
            :confirm-disabled="inutilizar.confirmacao !== 'INUTILIZAR' || inutilizar.justificativa.trim().length < 15"
            @close="faixaAberta = null"
            @confirm="confirmarInutilizacao"
        >
            <p class="text-sm text-slate-500">
                Vai avisar a SEFAZ que os números <strong>{{ faixaAberta && faixa(faixaAberta.inicio, faixaAberta.fim) }}</strong> da série
                {{ relatorio.serie_kazakora }} nunca vão virar nota. <strong>Não tem volta.</strong>
            </p>
            <label class="mt-4 block text-sm font-medium" for="justificativa">Justificativa (mín. 15 caracteres)</label>
            <textarea id="justificativa" v-model="inutilizar.justificativa" rows="2" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm"></textarea>
            <p v-if="inutilizar.errors.justificativa" class="mt-1 text-xs text-error">{{ inutilizar.errors.justificativa }}</p>
            <label class="mt-3 block text-sm font-medium" for="confirmacao">Digite INUTILIZAR para confirmar</label>
            <input id="confirmacao" v-model="inutilizar.confirmacao" type="text" autocomplete="off" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm" />
        </ConfirmModal>
    </AdminLayout>
</template>
