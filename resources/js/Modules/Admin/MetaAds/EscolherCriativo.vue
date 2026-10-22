<script setup>
// Meta ADS (2026-10-21, pedido do Lira): "Dentro da plataforma". Lista os
// vídeos e imagens aprovados em Marketing › Validação de criativos para
// virar anúncio sem subir o arquivo de novo (o servidor copia).
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { computed, ref, watch } from 'vue';
import { pedirJson } from './metaAds';

const props = defineProps({
    open: { type: Boolean, default: false },
    vagas: { type: Number, required: true },
});

const emit = defineEmits(['close', 'escolher']);

const BASE = '/admin/publicidade/meta-ads/campanhas';
const criativos = ref([]);
const carregando = ref(false);
const erro = ref('');
const busca = ref('');
const marcados = ref([]);

const carregar = async () => {
    carregando.value = true;
    erro.value = '';
    try {
        const q = busca.value.trim() ? `?busca=${encodeURIComponent(busca.value.trim())}` : '';
        criativos.value = (await pedirJson('GET', `${BASE}/criativos-aprovados${q}`)).criativos;
    } catch (e) {
        erro.value = e.message;
    } finally {
        carregando.value = false;
    }
};

watch(() => props.open, (aberto) => {
    if (!aberto) return;
    marcados.value = [];
    carregar();
});

let timerBusca = null;
const aoBuscar = () => {
    clearTimeout(timerBusca);
    timerBusca = setTimeout(carregar, 300);
};

const marcado = (c) => marcados.value.some((m) => m.id === c.id);
const alternar = (c) => {
    if (marcado(c)) marcados.value = marcados.value.filter((m) => m.id !== c.id);
    else if (marcados.value.length < props.vagas) marcados.value.push(c);
};

const rotuloBotao = computed(() => {
    const n = marcados.value.length;
    return n ? `Adicionar ${n} anúncio${n > 1 ? 's' : ''}` : 'Adicionar';
});

const confirmar = () => {
    if (!marcados.value.length) return;
    emit('escolher', [...marcados.value]);
    emit('close');
};
</script>

<template>
    <ConfirmModal :open="open" title="Criativo da plataforma" width="max-w-4xl" @close="emit('close')">
        <p class="mb-3 text-sm text-slate-500">
            Vídeos e imagens aprovados em Validação de criativos. Cada um que você marcar vira um anúncio.
        </p>
        <label for="escolher-criativo-busca" class="sr-only">Buscar criativo</label>
        <input id="escolher-criativo-busca" v-model="busca" type="search" maxlength="100" autocomplete="off"
            placeholder="Buscar por nome, identificação ou produto"
            class="mb-3 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"
            @input="aoBuscar">

        <p v-if="erro" class="text-sm text-red-600" role="alert">{{ erro }}</p>
        <p v-else-if="carregando && !criativos.length" class="py-8 text-center text-sm text-slate-500" role="status">Carregando…</p>
        <p v-else-if="!criativos.length" class="rounded-lg border border-dashed border-[var(--surface-border)] p-6 text-center text-sm text-slate-500">
            {{ busca ? 'Nenhum criativo aprovado com essa busca.' : 'Nenhum criativo aprovado ainda. Aprove em Marketing › Validação de criativos.' }}
        </p>

        <ul v-else class="grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 lg:grid-cols-4" :aria-busy="carregando">
            <li v-for="c in criativos" :key="c.id">
                <button type="button" :aria-pressed="marcado(c)"
                    :disabled="!marcado(c) && marcados.length >= vagas"
                    class="relative flex w-full flex-col overflow-hidden rounded-lg border-2 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                    :class="marcado(c) ? 'border-primary ring-2 ring-primary/30' : 'border-[var(--surface-border)] hover:border-primary/60'"
                    @click="alternar(c)">
                    <img v-if="c.tipo === 'imagem'" :src="c.url" alt="" loading="lazy" class="aspect-[9/16] w-full bg-black object-contain">
                    <video v-else :src="`${c.url}#t=0.5`" preload="metadata" muted playsinline class="aspect-[9/16] w-full bg-black object-contain"></video>
                    <span v-if="marcado(c)" class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-full bg-primary text-white" aria-hidden="true">
                        <i class="fas fa-check text-xs"></i>
                    </span>
                    <span class="block p-2">
                        <span class="block truncate text-sm font-semibold" :title="c.titulo">{{ c.titulo }}</span>
                        <span class="block truncate text-xs text-slate-500">
                            {{ c.tipo === 'imagem' ? 'Imagem' : 'Vídeo' }}<template v-if="c.duracao"> · {{ c.duracao }}s</template><template v-if="c.proporcao"> · {{ c.proporcao }}</template> · {{ c.tamanho_mb }} MB
                        </span>
                        <span class="block truncate text-xs text-slate-500">{{ c.produto || c.plataforma }}</span>
                    </span>
                </button>
            </li>
        </ul>
        <p v-if="marcados.length >= vagas && criativos.length" class="mt-2 text-xs text-amber-700" role="status">
            Limite de anúncios da campanha atingido.
        </p>

        <template #actions>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="emit('close')">
                Voltar
            </button>
            <button type="button" :disabled="!marcados.length"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                @click="confirmar">
                {{ rotuloBotao }}
            </button>
        </template>
    </ConfirmModal>
</template>
