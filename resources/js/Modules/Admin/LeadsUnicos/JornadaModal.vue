<script setup>
// Janela "Jornada" da tela única de Leads (plano do Lira 25/11/2026, §6):
// dados do cliente, origem completa e o passo a passo do checkout até o
// pagamento, com "Parou em" ou "Pago em" no topo. Só o NOME de cada campo é
// registrado; o que a pessoa digitou não aparece na linha do tempo.
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
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

const dados = computed(() => {
    const c = estado.dados?.cliente ?? {};
    const o = estado.dados?.origem ?? {};
    return [
        ['Nome', c.nome],
        ['E-mail', c.email],
        ['Telefone', c.telefone ? maskPhone(c.telefone) : null],
        ['CPF', c.cpf ? maskCpfCnpj(c.cpf) : null],
        ['CEP / endereço', [c.cep ? maskCep(c.cep) : null, c.endereco].filter(Boolean).join(' · ') || null],
        ['Loja', c.loja],
        ['Origem', [o.rotulo, o.utm_source, o.utm_medium, o.utm_campaign].filter(Boolean).join(' · ') || null],
        ['Sessão criada em', c.sessao_criada_em ? dataHora(c.sessao_criada_em, true) : null],
    ];
});
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
            </div>

            <dl class="mb-4 grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-2">
                <div v-for="[rotulo, valor] in dados" :key="rotulo" class="min-w-0">
                    <dt class="text-xs text-slate-500">{{ rotulo }}</dt>
                    <dd class="truncate" :title="valor ?? ''">{{ valor ?? '—' }}</dd>
                </div>
            </dl>

            <p v-if="!estado.dados.passos.length" class="rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                Sem registro de passos{{ estado.dados.sem_visitante ? ': este lead não tem identificação de visitante.' : ' na janela deste lead.' }}
            </p>
            <ol v-else class="relative ml-2 border-l border-[var(--surface-border)]">
                <li v-for="(passo, i) in estado.dados.passos" :key="i" class="relative mb-2.5 pl-5">
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
            <p class="mt-3 text-xs text-slate-400">Só o nome de cada campo é registrado no passo a passo; o que a pessoa digitou (CPF, cartão etc.) nunca aparece nele.</p>
        </template>

        <template #actions>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="emit('close')">Fechar</button>
        </template>
    </ConfirmModal>
</template>
