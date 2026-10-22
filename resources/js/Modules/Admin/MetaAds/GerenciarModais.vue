<script setup>
// Editar e excluir campanha, conjunto e anúncio pelo id da Meta (Lira
// 2026-10-20), inclusive os criados direto no Gerenciador. A tela chama
// editar(alvo) ou excluir(alvo), com:
//   alvo = { nivel: 'campanha'|'conjunto'|'anuncio', id (da Meta), nome, status,
//            gasto (no período), orcamento_diario (null = não é diário aqui),
//            dentro (texto: o que sai junto), ultimoAtivo }
// Anúncio que nasceu no KazaKora usa o AnuncioModais (troca texto e arquivo).
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { notifySuccess } from '@/Shared/notify';
import { computed, reactive, ref } from 'vue';
import { formatarReais, pedirJson, statusMeta } from './metaAds';

const emit = defineEmits(['mudou']);

const BASE = '/admin/publicidade/meta-ads/gerenciar';
const NOMES = { campanha: 'a campanha', conjunto: 'o conjunto', anuncio: 'o anúncio' };
const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40';
const botao = 'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50';

const url = (alvo) => `${BASE}/${alvo.nivel}/${alvo.id}`;
const nomeDoStatus = (alvo) => statusMeta(alvo?.status)[0].toLowerCase();

// Editar ---------------------------------------------------------------------

const edicao = ref(null);
const form = reactive({ nome: '', orcamento_diario: '' });
const confirmoGasto = ref(false);
const salvando = ref(false);
const erros = ref({});
const erroGeral = ref('');

const editar = (alvo) => {
    form.nome = alvo.nome ?? '';
    form.orcamento_diario = alvo.orcamento_diario ?? '';
    confirmoGasto.value = false;
    erros.value = {};
    erroGeral.value = '';
    edicao.value = alvo;
};

const temOrcamento = computed(() => edicao.value?.nivel !== 'anuncio' && edicao.value?.orcamento_diario !== null && edicao.value?.orcamento_diario !== undefined);
const novoOrcamento = computed(() => Number(String(form.orcamento_diario).replace(',', '.')));
const mudaOrcamento = computed(() => temOrcamento.value && form.orcamento_diario !== '' && Math.round(novoOrcamento.value * 100) !== Math.round(Number(edicao.value.orcamento_diario) * 100));
const mudouAlgo = computed(() => form.nome.trim() !== (edicao.value?.nome ?? '').trim() || mudaOrcamento.value);
const tituloEdicao = computed(() => `Editar ${NOMES[edicao.value?.nivel] ?? ''} "${edicao.value?.nome ?? ''}"`);

const salvar = async () => {
    if (!edicao.value || salvando.value || !mudouAlgo.value || (mudaOrcamento.value && !confirmoGasto.value)) return;
    salvando.value = true;
    erros.value = {};
    erroGeral.value = '';
    try {
        const resposta = await pedirJson('PUT', url(edicao.value), {
            nome: form.nome.trim(),
            orcamento_diario: mudaOrcamento.value ? novoOrcamento.value : null,
            confirmo_gasto: mudaOrcamento.value && confirmoGasto.value,
        });
        notifySuccess(resposta?.message ?? 'Salvo na Meta.');
        const alvo = edicao.value;
        edicao.value = null;
        emit('mudou', { nivel: alvo.nivel, acao: 'editar' });
    } catch (erro) {
        erros.value = erro.erros ?? {};
        erroGeral.value = erro.erros ? '' : erro.message;
    } finally {
        salvando.value = false;
    }
};

// Excluir --------------------------------------------------------------------

const exclusao = ref(null);
const entendiDefinitivo = ref(false);
const entendiSemAtivo = ref(false);
const ultimoAtivo = ref(false);
const excluindo = ref(false);
const erroExclusao = ref('');

const excluir = (alvo) => {
    entendiDefinitivo.value = false;
    entendiSemAtivo.value = false;
    ultimoAtivo.value = Boolean(alvo.ultimoAtivo);
    erroExclusao.value = '';
    exclusao.value = alvo;
};

const podeExcluirDeVez = computed(() => entendiDefinitivo.value && (!ultimoAtivo.value || entendiSemAtivo.value));
const tituloExclusao = computed(() => `Excluir ${NOMES[exclusao.value?.nivel] ?? ''} "${exclusao.value?.nome ?? ''}"?`);
const avisoUltimo = computed(() => (exclusao.value?.nivel === 'conjunto'
    ? 'É o único conjunto ativo da campanha. Sem ele a campanha para de entregar.'
    : 'É o último anúncio ativo do conjunto. Sem ele o conjunto para de entregar.'));

const confirmarExclusao = async () => {
    if (!exclusao.value || excluindo.value || !podeExcluirDeVez.value) return;
    excluindo.value = true;
    erroExclusao.value = '';
    try {
        const resposta = await pedirJson('DELETE', url(exclusao.value), {
            confirmo_exclusao: entendiDefinitivo.value,
            sem_ativo: ultimoAtivo.value && entendiSemAtivo.value,
        });
        notifySuccess(resposta?.message ?? 'Excluído na Meta.');
        const alvo = exclusao.value;
        exclusao.value = null;
        emit('mudou', { nivel: alvo.nivel, acao: 'excluir' });
    } catch (erro) {
        // A Meta disse que é o último ativo (o espelho estava desatualizado).
        if (erro.erros?.sem_ativo) {
            ultimoAtivo.value = true;
            erroExclusao.value = '';
        } else {
            erroExclusao.value = erro.message;
        }
    } finally {
        excluindo.value = false;
    }
};

// Pausar criativo (sugestões do O que fazer, Lira 2026-10-21) ----------------

const pausa = ref(null);
const entendiPausarUltimo = ref(false);
const pausaUltimoAtivo = ref(false);
const pausando = ref(false);
const erroPausa = ref('');

const pausar = (alvo) => {
    entendiPausarUltimo.value = false;
    pausaUltimoAtivo.value = Boolean(alvo.ultimoAtivo);
    erroPausa.value = '';
    pausa.value = alvo;
};

const tituloPausa = computed(() => `Pausar o criativo "${pausa.value?.nome ?? ''}"?`);

const confirmarPausa = async () => {
    if (!pausa.value || pausando.value || (pausaUltimoAtivo.value && !entendiPausarUltimo.value)) return;
    pausando.value = true;
    erroPausa.value = '';
    try {
        const resposta = await pedirJson('POST', `${BASE}/anuncio/${pausa.value.id}/pausar`, {
            sem_ativo: pausaUltimoAtivo.value && entendiPausarUltimo.value,
        });
        notifySuccess(resposta?.message ?? 'Criativo pausado na Meta.');
        pausa.value = null;
        emit('mudou', { nivel: 'anuncio', acao: 'pausar' });
    } catch (erro) {
        if (erro.erros?.sem_ativo) {
            pausaUltimoAtivo.value = true;
            erroPausa.value = '';
        } else {
            erroPausa.value = erro.message;
        }
    } finally {
        pausando.value = false;
    }
};

defineExpose({ editar, excluir, pausar });
</script>

<template>
    <ConfirmModal :open="edicao !== null" :title="tituloEdicao" width="max-w-lg" :loading="salvando" @close="edicao = null">
        <form v-if="edicao" class="space-y-4" novalidate @submit.prevent="salvar">
            <div>
                <label for="gerenciar-nome" class="block text-sm font-medium">Nome</label>
                <input id="gerenciar-nome" v-model="form.nome" name="nome" type="text" maxlength="255" :class="campo" autocomplete="off" spellcheck="false"
                    :aria-invalid="Boolean(erros.nome)">
                <p v-if="erros.nome" class="mt-1 text-xs text-red-600">{{ erros.nome[0] }}</p>
            </div>

            <div v-if="temOrcamento">
                <label for="gerenciar-orcamento" class="block text-sm font-medium">Orçamento por dia (R$)</label>
                <input id="gerenciar-orcamento" v-model="form.orcamento_diario" name="orcamento_diario" type="number" inputmode="decimal" min="6" max="10000" step="0.01"
                    :class="campo" :aria-invalid="Boolean(erros.orcamento_diario)" aria-describedby="gerenciar-orcamento-ajuda">
                <p id="gerenciar-orcamento-ajuda" class="mt-1 text-xs" :class="erros.orcamento_diario ? 'text-red-600' : 'text-slate-500'">
                    {{ erros.orcamento_diario ? erros.orcamento_diario[0] : `Hoje: ${formatarReais(edicao.orcamento_diario)} por dia.` }}
                </p>
            </div>
            <p v-else-if="edicao.nivel !== 'anuncio'" class="text-xs text-slate-500">
                O orçamento por dia não fica {{ edicao.nivel === 'campanha' ? 'na campanha (é de cada conjunto ou é total)' : 'neste conjunto (é da campanha ou é total)' }}.
            </p>

            <div v-if="mudaOrcamento" class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                <p>
                    O gasto por dia passa de <strong>{{ formatarReais(edicao.orcamento_diario) }}</strong> para <strong>{{ formatarReais(novoOrcamento) }}</strong>
                    assim que salvar. A Meta pode reaprender a entrega por alguns dias.
                </p>
                <label class="flex items-start gap-2">
                    <input v-model="confirmoGasto" type="checkbox" class="mt-0.5">
                    <span>Confirmo o novo gasto de até {{ formatarReais(novoOrcamento) }} por dia.</span>
                </label>
                <p v-if="erros.confirmo_gasto" class="text-xs text-red-600">{{ erros.confirmo_gasto[0] }}</p>
            </div>

            <p aria-live="polite" class="text-sm text-red-700" :class="{ 'sr-only': !erroGeral }">{{ erroGeral }}</p>
            <button type="submit" class="hidden" tabindex="-1" aria-hidden="true"></button>
        </form>

        <template #actions>
            <button type="button" :disabled="salvando" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="edicao = null">
                Voltar
            </button>
            <button type="button" :disabled="salvando || !mudouAlgo || (mudaOrcamento && !confirmoGasto)"
                :class="[botao, 'bg-primary text-white hover:bg-primary-emphasis']" @click="salvar">
                <i v-if="salvando" class="fas fa-spinner fa-spin" aria-hidden="true"></i> Salvar na Meta
            </button>
        </template>
    </ConfirmModal>

    <ConfirmModal :open="exclusao !== null" :title="tituloExclusao" danger :loading="excluindo" @close="exclusao = null">
        <template v-if="exclusao">
            <p class="text-sm">
                {{ exclusao.nivel === 'campanha' ? 'Esta campanha' : (exclusao.nivel === 'conjunto' ? 'Este conjunto' : 'Este anúncio') }}
                já gastou <strong>{{ formatarReais(exclusao.gasto ?? 0) }}</strong> no período e está <strong>{{ nomeDoStatus(exclusao) }}</strong>.
                Excluir apaga na Meta e não tem volta<template v-if="exclusao.dentro">, junto com {{ exclusao.dentro }}</template>.
                O resultado que já teve continua no relatório da Meta.
            </p>
            <p v-if="exclusao.status === 'ACTIVE'" class="mt-2 text-sm text-slate-500">Se a ideia é só parar de gastar, pause em vez de excluir.</p>
            <div v-if="ultimoAtivo" class="mt-3 space-y-2 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200" role="alert">
                <p>{{ avisoUltimo }}</p>
                <label class="flex items-start gap-2">
                    <input v-model="entendiSemAtivo" type="checkbox" class="mt-0.5">
                    <span>Mesmo assim, quero excluir.</span>
                </label>
            </div>
            <label class="mt-3 flex items-start gap-2 text-sm">
                <input v-model="entendiDefinitivo" type="checkbox" class="mt-0.5">
                <span>Entendi que a exclusão na Meta é definitiva.</span>
            </label>
            <p aria-live="assertive" class="mt-3 text-sm text-red-700" :class="{ 'sr-only': !erroExclusao }">{{ erroExclusao }}</p>
        </template>

        <template #actions>
            <button type="button" :disabled="excluindo" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="exclusao = null">
                Voltar
            </button>
            <button type="button" :disabled="excluindo || !podeExcluirDeVez" :class="[botao, 'bg-error text-white hover:opacity-90']" @click="confirmarExclusao">
                <i v-if="excluindo" class="fas fa-spinner fa-spin" aria-hidden="true"></i> Excluir de vez
            </button>
        </template>
    </ConfirmModal>

    <ConfirmModal :open="pausa !== null" :title="tituloPausa" :loading="pausando" @close="pausa = null">
        <template v-if="pausa">
            <p class="text-sm">
                Este anúncio já gastou <strong>{{ formatarReais(pausa.gasto ?? 0) }}</strong> no período.
                Pausar para de gastar na hora; dá para ativar de novo depois.
            </p>
            <div v-if="pausaUltimoAtivo" class="mt-3 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="alert">
                <p>É o último anúncio ativo do conjunto. Sem ele o conjunto para de entregar.</p>
                <label class="flex items-start gap-2">
                    <input v-model="entendiPausarUltimo" type="checkbox" class="mt-0.5">
                    <span>Mesmo assim, quero pausar.</span>
                </label>
            </div>
            <p aria-live="assertive" class="mt-3 text-sm text-red-700" :class="{ 'sr-only': !erroPausa }">{{ erroPausa }}</p>
        </template>

        <template #actions>
            <button type="button" :disabled="pausando" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="pausa = null">
                Voltar
            </button>
            <button type="button" :disabled="pausando || (pausaUltimoAtivo && !entendiPausarUltimo)" :class="[botao, 'bg-amber-600 text-white hover:bg-amber-700']" @click="confirmarPausa">
                <i :class="pausando ? 'fas fa-spinner fa-spin' : 'fas fa-pause'" aria-hidden="true"></i> Pausar na Meta
            </button>
        </template>
    </ConfirmModal>
</template>
