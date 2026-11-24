<script setup>
// Janela "Ver fluxo" (pedido do Lira 2026-11-23): o passo a passo do que a
// pessoa fez no checkout, campo a campo, com o horário, terminando em "Parou
// aqui". Usada em Carrinhos abandonados e em Leads. Carrega sob demanda do
// endpoint JSON; só mostra o NOME dos campos, nunca o que foi digitado.
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { reactive, watch } from 'vue';

const props = defineProps({
    // Linha da tabela (precisa de id e nome) ou null com a janela fechada.
    lead: { type: Object, default: null },
    // Ex.: '/admin/carrinhos-abandonados' ou '/admin/leads'.
    base: { type: String, required: true },
});
const emit = defineEmits(['close']);

const estado = reactive({ carregando: false, erro: '', dados: null });

const carregar = async (lead) => {
    Object.assign(estado, { carregando: true, erro: '', dados: null });
    try {
        const r = await fetch(`${props.base}/${lead.id}/fluxo`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const dados = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(dados.message || 'Não foi possível carregar o passo a passo.');
        if (props.lead?.id === lead.id) estado.dados = dados;
    } catch (e) {
        if (props.lead?.id === lead.id) estado.erro = e.message;
    } finally {
        if (props.lead?.id === lead.id) estado.carregando = false;
    }
};

watch(() => props.lead?.id, (id) => { if (id) carregar(props.lead); }, { immediate: true });

const hora = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '-');
const duracao = (s) => {
    if (s == null) return null;
    if (s < 60) return `${s} s`;
    const min = Math.floor(s / 60);
    if (min < 60) return `${min} min ${String(s % 60).padStart(2, '0')} s`;
    return `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min`;
};

const COR_ETAPA = {
    carrinho: 'bg-slate-400',
    abertura: 'bg-sky-500',
    identificacao: 'bg-indigo-500',
    entrega: 'bg-amber-500',
    pagamento: 'bg-violet-500',
    finalizar: 'bg-rose-500',
    comprou: 'bg-emerald-500',
};
const NOME_ETAPA = {
    carrinho: 'Carrinho',
    abertura: 'Abertura',
    identificacao: 'Identificação',
    entrega: 'Entrega',
    pagamento: 'Pagamento',
    finalizar: 'Finalizar',
    comprou: 'Pedido',
};
</script>

<template>
    <ConfirmModal :open="!!lead" :title="`Passo a passo no checkout${lead?.nome ? ` · ${lead.nome}` : ''}`" width="max-w-2xl" @close="emit('close')">
        <div v-if="estado.carregando" class="py-8 text-center text-sm text-slate-500">
            <i class="fas fa-spinner fa-spin mr-1.5" aria-hidden="true"></i> Carregando…
        </div>
        <p v-else-if="estado.erro" class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ estado.erro }}</p>
        <template v-else-if="estado.dados">
            <div v-if="estado.dados.tem_registro" class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                <span>Início {{ hora(estado.dados.resumo.inicio) }}</span>
                <span v-if="estado.dados.resumo.duracao_segundos != null">Do primeiro ao último passo: {{ duracao(estado.dados.resumo.duracao_segundos) }}</span>
                <span v-if="estado.dados.resumo.segundos_ativos">Tempo ativo na página: {{ duracao(estado.dados.resumo.segundos_ativos) }}</span>
                <span v-if="estado.dados.resumo.aberturas > 1">Abriu o checkout {{ estado.dados.resumo.aberturas }} vezes</span>
            </div>

            <p v-if="!estado.dados.tem_registro" class="mb-3 rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                Sem registro de passos{{ estado.dados.sem_visitante ? ': este lead não tem identificação de visitante.' : ' na janela deste lead.' }}
            </p>

            <ol v-else class="relative ml-2 border-l border-[var(--surface-border)]">
                <li v-for="(passo, i) in estado.dados.passos" :key="i" class="relative mb-3 pl-5">
                    <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full" :class="COR_ETAPA[passo.etapa] ?? 'bg-slate-300'"></span>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-mono text-xs tabular-nums text-slate-500">{{ hora(passo.hora) }}</span>
                        <span class="text-sm font-medium">{{ passo.titulo }}</span>
                        <span v-if="passo.vezes > 1" class="text-xs text-slate-400">({{ passo.vezes }}x)</span>
                        <span v-if="passo.etapa" class="rounded bg-[var(--surface-muted)] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-500">{{ NOME_ETAPA[passo.etapa] }}</span>
                    </div>
                    <div v-if="passo.detalhe" class="text-xs text-slate-500">{{ passo.detalhe }}</div>
                </li>
            </ol>

            <div class="mt-2 rounded-lg border-2 p-3"
                :class="estado.dados.parou.etapa === 'comprou' ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-rose-400 bg-rose-50 text-rose-900'">
                <div class="text-xs font-bold uppercase tracking-wide">{{ estado.dados.parou.etapa === 'comprou' ? 'Terminou aqui' : 'Parou aqui' }}</div>
                <div class="text-sm font-semibold">{{ estado.dados.parou.texto }}</div>
                <div v-if="estado.dados.parou.hora" class="text-xs opacity-75">{{ hora(estado.dados.parou.hora) }}</div>
            </div>
            <p class="mt-3 text-xs text-slate-400">Só o nome de cada campo é registrado; o que a pessoa digitou (CPF, cartão etc.) nunca aparece aqui.</p>
        </template>

        <template #actions>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="emit('close')">Fechar</button>
        </template>
    </ConfirmModal>
</template>
