<script setup>
// Meta ADS: busca de estados e cidades na Meta para o público (da campanha
// ou de um conjunto). Edita o objeto { regioes, cidades } recebido.
import { ref } from 'vue';

const props = defineProps({
    local: { type: Object, required: true },
    configurado: { type: Boolean, default: false },
    id: { type: String, required: true },
});

const BASE = '/admin/publicidade/meta-ads/campanhas';
const busca = ref('');
const resultados = ref([]);
const buscando = ref(false);
const erroBusca = ref('');
let timer = null;

const buscarLocal = () => {
    clearTimeout(timer);
    erroBusca.value = '';
    if (busca.value.trim().length < 2) {
        resultados.value = [];
        return;
    }
    timer = setTimeout(async () => {
        buscando.value = true;
        try {
            const resposta = await fetch(`${BASE}/localizacoes?q=${encodeURIComponent(busca.value.trim())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = await resposta.json();
            resultados.value = json.data ?? [];
            erroBusca.value = json.erro ?? '';
        } catch {
            erroBusca.value = 'Não deu para buscar agora.';
        } finally {
            buscando.value = false;
        }
    }, 350);
};

const adicionarLocal = (item) => {
    const lista = item.tipo === 'cidade' ? props.local.cidades : props.local.regioes;
    if (!lista.some((l) => l.key === item.key)) {
        lista.push(item.tipo === 'cidade' ? { key: item.key, nome: item.nome, raio_km: null } : { key: item.key, nome: item.nome });
    }
    busca.value = '';
    resultados.value = [];
};

const removerLocal = (tipo, key) => {
    props.local[tipo] = props.local[tipo].filter((l) => l.key !== key);
};

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm';
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium">Onde mostrar</label>
        <p class="text-xs text-slate-500">Sem estado ou cidade escolhidos, mostra para o Brasil todo.</p>
        <div class="relative">
            <input :id="id" v-model="busca" type="search" autocomplete="off" :disabled="!configurado"
                :placeholder="configurado ? 'Buscar estado ou cidade...' : 'Busca disponível depois de configurar a Meta'"
                :class="campo" @input="buscarLocal">
            <ul v-if="resultados.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] shadow-lg">
                <li v-for="item in resultados" :key="item.key">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-primary/10" @click="adicionarLocal(item)">
                        <span>{{ item.nome }}</span><span class="text-xs text-slate-500">{{ item.tipo }}</span>
                    </button>
                </li>
            </ul>
        </div>
        <p v-if="buscando" class="mt-1 text-xs text-slate-500">Buscando…</p>
        <p v-if="erroBusca" class="mt-1 text-xs text-red-600">{{ erroBusca }}</p>

        <div class="mt-2 flex flex-wrap gap-2">
            <span v-if="!local.regioes.length && !local.cidades.length"
                class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Brasil inteiro</span>
            <span v-for="r in local.regioes" :key="`r${r.key}`" class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                {{ r.nome }}
                <button type="button" :aria-label="`Remover ${r.nome}`" class="ml-1" @click="removerLocal('regioes', r.key)"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </span>
            <span v-for="cid in local.cidades" :key="`c${cid.key}`" class="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                {{ cid.nome }}
                <select v-model.number="cid.raio_km" :aria-label="`Raio em volta de ${cid.nome}`" class="rounded border-none bg-transparent p-0 text-xs">
                    <option :value="null">só a cidade</option>
                    <option v-for="km in [10, 25, 40, 80]" :key="km" :value="km">+{{ km }} km</option>
                </select>
                <button type="button" :aria-label="`Remover ${cid.nome}`" @click="removerLocal('cidades', cid.key)"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </span>
        </div>
    </div>
</template>
