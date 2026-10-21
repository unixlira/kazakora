<script setup>
// Orçamento por dia editado direto na tabela (pedido do Lira 2026-10-21).
// Salva na Meta pelo mesmo endpoint do modal Editar (nome igual, orçamento
// novo) e pede para confirmar o gasto antes. Só muda na tela depois que a
// Meta aceita.
import { notifySuccess } from '@/Shared/notify';
import { computed, nextTick, ref } from 'vue';
import { formatarReais, pedirJson } from './metaAds';

const props = defineProps({
    nivel: { type: String, required: true },
    id: { type: String, required: true },
    nome: { type: String, required: true },
    valor: { type: Number, required: true },
    podeEditar: { type: Boolean, default: false },
});
const emit = defineEmits(['mudou']);

const MINIMO = 6;
const editando = ref(false);
const texto = ref('');
const confirmo = ref(false);
const salvando = ref(false);
const erro = ref('');
const salvo = ref(null);
const campo = ref(null);

const atual = computed(() => salvo.value ?? props.valor);
const novo = computed(() => Number(String(texto.value).replace(/\./g, '').replace(',', '.')));
const valido = computed(() => Number.isFinite(novo.value) && novo.value >= MINIMO && novo.value <= 10000);
const mudou = computed(() => valido.value && Math.round(novo.value * 100) !== Math.round(atual.value * 100));

const abrir = async () => {
    texto.value = atual.value.toFixed(2).replace('.', ',');
    confirmo.value = false;
    erro.value = '';
    editando.value = true;
    await nextTick();
    campo.value?.select();
};
const fechar = () => { editando.value = false; };

const salvar = async () => {
    if (!mudou.value || !confirmo.value || salvando.value) return;
    salvando.value = true;
    erro.value = '';
    try {
        const resposta = await pedirJson('PUT', `/admin/publicidade/meta-ads/gerenciar/${props.nivel}/${props.id}`, {
            nome: props.nome,
            orcamento_diario: Math.round(novo.value * 100) / 100,
            confirmo_gasto: true,
        });
        salvo.value = Math.round(novo.value * 100) / 100;
        editando.value = false;
        notifySuccess(resposta?.message ?? 'Orçamento salvo na Meta.');
        emit('mudou', { nivel: props.nivel, acao: 'orcamento' });
    } catch (e) {
        erro.value = e.message;
    } finally {
        salvando.value = false;
    }
};
</script>

<template>
    <span v-if="!editando" class="inline-flex items-center justify-end gap-1.5">
        <span>{{ formatarReais(atual) }}/dia</span>
        <button v-if="podeEditar" type="button" :aria-label="`Mudar orçamento por dia de ${nome}`" title="Mudar orçamento por dia"
            class="rounded p-1 text-slate-400 hover:bg-primary/10 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            @click="abrir">
            <i class="fas fa-pen text-[10px]" aria-hidden="true"></i>
        </button>
    </span>
    <form v-else class="ml-auto flex w-56 flex-col items-stretch gap-1.5 text-left font-normal" @submit.prevent="salvar" @keydown.esc="fechar">
        <label class="flex items-center gap-1.5 text-xs">
            <span class="text-slate-500">R$</span>
            <input ref="campo" v-model="texto" inputmode="decimal" :aria-label="`Orçamento por dia de ${nome}`" :aria-invalid="!valido"
                class="w-full rounded-md border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-right text-sm tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            <span class="text-slate-500">/dia</span>
        </label>
        <p v-if="texto && !valido" class="text-[11px] text-red-600">Entre R$ 6,00 e R$ 10.000,00 por dia.</p>
        <label v-if="mudou" class="flex items-start gap-1.5 text-[11px] leading-snug">
            <input v-model="confirmo" type="checkbox" class="mt-0.5">
            <span>Confirmo gastar até <strong>{{ formatarReais(novo) }}</strong> por dia na Meta.</span>
        </label>
        <p v-if="erro" class="text-[11px] text-red-600" role="alert">{{ erro }}</p>
        <span class="flex justify-end gap-1.5">
            <button type="button" class="rounded-md border border-[var(--surface-border)] px-2 py-0.5 text-xs hover:bg-[var(--surface-muted)]" @click="fechar">Cancelar</button>
            <button type="submit" :disabled="!mudou || !confirmo || salvando"
                class="inline-flex items-center gap-1 rounded-md bg-primary px-2 py-0.5 text-xs font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                <i v-if="salvando" class="fas fa-circle-notch animate-spin text-[10px]" aria-hidden="true"></i>
                {{ salvando ? 'Salvando…' : 'Salvar na Meta' }}
            </button>
        </span>
    </form>
</template>
