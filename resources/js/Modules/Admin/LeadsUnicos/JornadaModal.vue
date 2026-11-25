<script setup>
// Janela "Jornada" da tela única de Leads (plano do Lira 25/11/2026, §6):
// dados do cliente, origem completa e o passo a passo do checkout até o
// pagamento, com "Parou em" ou "Pago em" no topo. Desde 25/11/2026 mostra
// TODOS os contatos que a pessoa digitou ou tem (com a origem de cada um) e
// cada sessão de checkout dela separada (início, onde parou, origem e a linha
// do tempo). Só o NOME de cada campo é registrado no passo a passo.
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { maskCep } from '@/Shared/useCep';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dataHora } from './formato';
import { useDetalhe } from './useDetalhe';

const props = defineProps({
    lead: { type: Object, default: null },
    base: { type: String, required: true },
});
const emit = defineEmits(['close']);
const estado = useDetalhe(props, 'jornada');

const COR_ETAPA = {
    carrinho: 'bg-slate-400', abertura: 'bg-sky-500', identificacao: 'bg-indigo-500', entrega: 'bg-amber-500',
    pagamento: 'bg-violet-500', finalizar: 'bg-rose-500', comprou: 'bg-emerald-500',
};
const NOME_ETAPA = {
    carrinho: 'Carrinho', abertura: 'Abertura', identificacao: 'Identificação', entrega: 'Entrega',
    pagamento: 'Pagamento', finalizar: 'Finalizar', comprou: 'Pedido',
};

const origemTexto = (o) => [o?.rotulo, o?.utm_source, o?.utm_medium, o?.utm_campaign].filter(Boolean).join(' · ') || null;

// Cada campo com todos os valores (sem repetir) e de onde veio cada um.
const contatos = computed(() => {
    const c = estado.dados?.contatos ?? {};
    const lista = (itens, formatar, chave = 'valor') => (itens ?? []).map((i) => ({ valor: formatar(i[chave]), origens: i.origens.join(', ') }));
    return [
        ['Nome', lista(c.nomes, (v) => v)],
        ['E-mail', lista(c.emails, (v) => v)],
        ['Telefone', lista(c.telefones, (v) => maskPhone(v), 'numero')],
        ['CPF', lista(c.cpfs, (v) => maskCpfCnpj(v))],
        ['CEP', lista(c.ceps, (v) => maskCep(v))],
    ];
});
const outros = computed(() => {
    const c = estado.dados?.cliente ?? {};
    return [
        ['Loja', c.loja],
        ['Endereço do pedido', c.endereco],
        ['Origem', origemTexto(estado.dados?.origem)],
        ['Sessão criada em', c.sessao_criada_em ? dataHora(c.sessao_criada_em, true) : null],
    ];
});
const variasSessoes = computed(() => (estado.dados?.sessoes?.length ?? 0) > 1);
</script>

<template>
    <ConfirmModal :open="!!lead" :title="`Jornada${lead?.nome ? ` · ${lead.nome}` : ''}`" width="max-w-[720px]" detalhe @close="emit('close')">
        <div v-if="estado.carregando" class="py-8 text-center text-sm text-slate-500">
            <i class="fas fa-spinner fa-spin mr-1.5" aria-hidden="true"></i> Carregando…
        </div>
        <p v-else-if="estado.erro" class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ estado.erro }}</p>
        <template v-else-if="estado.dados">
            <div class="mb-4 rounded-lg border-2 p-3"
                :class="estado.dados.resumo.pago ? 'border-emerald-400 bg-emerald-50 text-emerald-900' : 'border-rose-400 bg-rose-50 text-rose-900'">
                <div class="text-sm font-semibold">{{ estado.dados.resumo.texto }}</div>
                <Link v-if="estado.dados.pedido" :href="`/admin/pedidos/${estado.dados.pedido.id}`" class="text-xs underline">Pedido #{{ estado.dados.pedido.numero ?? estado.dados.pedido.id }}</Link>
                <div v-if="variasSessoes" class="text-xs opacity-80">{{ estado.dados.sessoes.length }} sessões de checkout desta pessoa (abaixo, cada uma separada).</div>
            </div>

            <dl class="mb-4 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                <div v-for="[rotulo, valores] in contatos" :key="rotulo" class="min-w-0">
                    <dt class="text-xs text-slate-500">{{ rotulo }}</dt>
                    <dd v-if="!valores.length" class="text-slate-400">não digitado</dd>
                    <dd v-for="v in valores" :key="v.valor" class="min-w-0">
                        <div class="truncate" :title="v.valor">{{ v.valor }}</div>
                        <div class="truncate text-[11px] text-slate-400" :title="v.origens">{{ v.origens }}</div>
                    </dd>
                </div>
                <div v-for="[rotulo, valor] in outros" :key="rotulo" class="min-w-0">
                    <dt class="text-xs text-slate-500">{{ rotulo }}</dt>
                    <dd class="truncate" :title="valor ?? ''">{{ valor ?? '—' }}</dd>
                </div>
            </dl>

            <details v-for="(sessao, n) in estado.dados.sessoes" :key="sessao.chave" :open="n === 0"
                class="mb-3 rounded-lg border border-[var(--surface-border)]">
                <summary class="cursor-pointer list-none p-2.5">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        <span class="font-semibold">{{ variasSessoes ? `Sessão ${n + 1}` : 'Passo a passo' }}</span>
                        <span v-if="variasSessoes && n === 0" class="text-[10px] font-semibold uppercase text-slate-400">principal</span>
                        <StatusBadge :status="sessao.situacao.codigo" :tone="sessao.situacao.tom" :label="sessao.situacao.rotulo" />
                    </div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        Início {{ dataHora(sessao.inicio, true) }} · {{ sessao.resumo.texto }}
                        <template v-if="origemTexto(sessao.origem)"> · {{ origemTexto(sessao.origem) }}</template>
                    </div>
                </summary>
                <div class="px-2.5 pb-2.5">
                    <p v-if="!sessao.passos.length" class="rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                        Sem registro de passos{{ sessao.sem_visitante ? ': esta sessão não tem identificação de visitante.' : ' na janela desta sessão.' }}
                    </p>
                    <ol v-else class="relative ml-2 border-l border-[var(--surface-border)]">
                        <li v-for="(passo, i) in sessao.passos" :key="i" class="relative mb-2.5 pl-5">
                            <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full" :class="COR_ETAPA[passo.etapa] ?? 'bg-slate-300'"></span>
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-mono text-xs tabular-nums text-slate-500">{{ dataHora(passo.hora, true) }}</span>
                                <span class="text-sm font-medium">{{ passo.titulo }}</span>
                                <span v-if="passo.vezes > 1" class="text-xs text-slate-400">({{ passo.vezes }}x)</span>
                                <span v-if="passo.etapa" class="rounded bg-[var(--surface-muted)] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-500">{{ NOME_ETAPA[passo.etapa] }}</span>
                                <Link v-if="passo.pedido" :href="`/admin/pedidos/${passo.pedido.id}`" class="text-xs text-sky-700 hover:underline">#{{ passo.pedido.numero ?? passo.pedido.id }}</Link>
                            </div>
                            <div v-if="passo.detalhe" class="text-xs text-slate-500">{{ passo.detalhe }}</div>
                        </li>
                    </ol>
                </div>
            </details>
            <p class="mt-3 text-xs text-slate-400">Só o nome de cada campo é registrado no passo a passo; o que a pessoa digitou (CPF, cartão etc.) nunca aparece nele. O que não foi digitado aparece como "não digitado" e o "Parou em" mostra até onde a pessoa chegou.</p>
        </template>

        <template #actions>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="emit('close')">Fechar</button>
        </template>
    </ConfirmModal>
</template>
