<script setup>
// Link de destino do anúncio editado direto na tabela (pedido do Lira
// 2026-10-21). Na Meta, trocar o link = criativo novo e o anúncio volta
// para a análise; por isso pede para confirmar. Só muda na tela depois que
// a Meta aceita.
import { notifySuccess } from '@/Shared/notify';
import { computed, nextTick, ref } from 'vue';
import { pedirJson } from './metaAds';

const props = defineProps({
    id: { type: String, required: true },
    nome: { type: String, required: true },
    link: { type: [String, null], default: null },
    podeEditar: { type: Boolean, default: false },
});
const emit = defineEmits(['mudou']);

const editando = ref(false);
const texto = ref('');
const confirmo = ref(false);
const salvando = ref(false);
const erro = ref('');
const salvo = ref(null);
const campo = ref(null);

const atual = computed(() => salvo.value ?? props.link);
const curto = computed(() => (atual.value ?? '').replace(/^https?:\/\/(www\.)?/, '').replace(/[?#].*$/, ''));
const valido = computed(() => /^https:\/\/[^\s/]+\.[^\s]+$/.test(texto.value.trim()));
const foraDaLoja = computed(() => valido.value && !/^https:\/\/(www\.)?kazakora\.com(\/|$)/i.test(texto.value.trim()));
const mudou = computed(() => valido.value && texto.value.trim() !== (atual.value ?? ''));

const abrir = async () => {
    texto.value = atual.value ?? 'https://kazakora.com/produtos/';
    confirmo.value = false;
    erro.value = '';
    editando.value = true;
    await nextTick();
    campo.value?.focus();
};
const fechar = () => { editando.value = false; };

const salvar = async () => {
    if (!mudou.value || !confirmo.value || salvando.value) return;
    salvando.value = true;
    erro.value = '';
    try {
        const resposta = await pedirJson('PUT', `/admin/publicidade/meta-ads/gerenciar/anuncio/${props.id}/link`, {
            link: texto.value.trim(),
            confirmo_analise: true,
        });
        salvo.value = texto.value.trim();
        editando.value = false;
        notifySuccess(resposta?.message ?? 'Link trocado na Meta.');
        emit('mudou', { nivel: 'anuncio', acao: 'link' });
    } catch (e) {
        erro.value = e.message;
    } finally {
        salvando.value = false;
    }
};
</script>

<template>
    <span v-if="!editando && (atual || podeEditar)" class="mt-1 flex max-w-xs items-center gap-1.5 text-xs">
        <i class="fas fa-link text-[10px] text-slate-400" aria-hidden="true"></i>
        <a v-if="atual" :href="atual" target="_blank" rel="noopener" class="truncate text-slate-500 hover:text-primary hover:underline" :title="atual">{{ curto }}</a>
        <span v-else class="text-slate-400">sem link</span>
        <button v-if="podeEditar" type="button" :aria-label="`Trocar o link do anúncio ${nome}`" title="Trocar o link de destino"
            class="shrink-0 rounded p-1 text-slate-400 hover:bg-primary/10 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            @click="abrir">
            <i class="fas fa-pen text-[10px]" aria-hidden="true"></i>
        </button>
    </span>
    <form v-else-if="editando" class="mt-1.5 flex w-80 max-w-full flex-col gap-1.5" @submit.prevent="salvar" @keydown.esc="fechar">
        <input ref="campo" v-model="texto" type="url" :aria-label="`Link de destino do anúncio ${nome}`" :aria-invalid="!valido" placeholder="https://kazakora.com/produtos/..."
            class="w-full rounded-md border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
        <p v-if="texto && !valido" class="text-[11px] text-red-600">Use um link completo começando com https://</p>
        <p v-else-if="foraDaLoja" class="text-[11px] text-amber-700 dark:text-amber-300">Atenção: esse link não é da kazakora.com.</p>
        <label v-if="mudou" class="flex items-start gap-1.5 text-[11px] leading-snug">
            <input v-model="confirmo" type="checkbox" class="mt-0.5">
            <span>Entendo que o anúncio volta para a análise da Meta antes de rodar de novo.</span>
        </label>
        <p v-if="erro" class="text-[11px] text-red-600" role="alert">{{ erro }}</p>
        <span class="flex gap-1.5">
            <button type="submit" :disabled="!mudou || !confirmo || salvando"
                class="inline-flex items-center gap-1 rounded-md bg-primary px-2 py-0.5 text-xs font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                <i v-if="salvando" class="fas fa-circle-notch animate-spin text-[10px]" aria-hidden="true"></i>
                {{ salvando ? 'Salvando…' : 'Salvar na Meta' }}
            </button>
            <button type="button" class="rounded-md border border-[var(--surface-border)] px-2 py-0.5 text-xs hover:bg-[var(--surface-muted)]" @click="fechar">Cancelar</button>
        </span>
    </form>
</template>
