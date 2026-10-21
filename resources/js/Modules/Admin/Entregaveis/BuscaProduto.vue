<script setup>
// Busca de produto (pais ativos) dos Entregáveis: digita nome ou SKU e
// escolhe da lista. Usada em Livros Digitais e Sorteios.
import { ref } from 'vue';

defineProps({
    id: { type: String, required: true },
});
const emit = defineEmits(['escolher']);

const busca = ref('');
const resultados = ref([]);
const buscando = ref(false);
let temporizador = null;

const buscar = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const r = await fetch(`/admin/entregaveis/produtos?q=${encodeURIComponent(busca.value.trim())}`, { headers: { Accept: 'application/json' } });
            resultados.value = r.ok ? (await r.json()).produtos : [];
        } finally {
            buscando.value = false;
        }
    }, 250);
};

const escolher = (produto) => {
    emit('escolher', produto);
    resultados.value = [];
    busca.value = '';
};
</script>

<template>
    <div class="relative">
        <input :id="id" v-model="busca" type="search" autocomplete="off"
            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"
            placeholder="Nome ou SKU do produto…" @input="buscar" @focus="buscar">
        <ul v-if="resultados.length" class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] shadow-lg">
            <li v-for="p in resultados" :key="p.id">
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" @click="escolher(p)">
                    <img v-if="p.foto" :src="p.foto" alt="" class="h-8 w-8 rounded object-cover">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm">{{ p.nome }}</span>
                        <span class="block font-mono text-xs text-slate-500">{{ p.sku }}</span>
                    </span>
                </button>
            </li>
        </ul>
        <p v-if="buscando" class="mt-1 text-xs text-slate-400">Buscando…</p>
    </div>
</template>
