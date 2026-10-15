<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { formatPrice } from '@/Shared/productCard';
import { useClickOutside } from '@/Shared/useClickOutside';

// Busca instantânea do topo (pedido 2026-10-15, como na Beauty Kora): ao
// digitar já aparece a lista com a foto e o nome dos produtos. Enter ou a
// lupa continuam abrindo a lista completa de resultados.
const props = defineProps({
    // O celular tem o campo maior e a lista ocupa a largura da tela.
    celular: { type: Boolean, default: false },
});

const termo = defineModel({ type: String, default: '' });
const emit = defineEmits(['buscar']);

const MINIMO = 2;
const produtos = ref([]);
const total = ref(0);
const carregando = ref(false);
const aberta = ref(false);
const ativo = ref(-1);
const caixa = ref(null);
const campo = ref(null);
useClickOutside(caixa, () => (aberta.value = false));

// Respostas guardadas por termo: apagar uma letra mostra na hora.
const guardadas = new Map();
let espera = null;
let pedido = null;

const termoLimpo = computed(() => termo.value.trim());

const buscar = async (texto) => {
    pedido?.abort();
    if (guardadas.has(texto)) {
        ({ produtos: produtos.value, total: total.value } = guardadas.get(texto));
        carregando.value = false;
        return;
    }
    pedido = new AbortController();
    carregando.value = true;
    try {
        const resposta = await fetch(`/busca/sugestoes?q=${encodeURIComponent(texto)}`, {
            headers: { Accept: 'application/json' },
            signal: pedido.signal,
        });
        if (!resposta.ok) throw new Error(String(resposta.status));
        const dados = await resposta.json();
        guardadas.set(texto, dados);
        if (texto === termoLimpo.value) {
            produtos.value = dados.produtos;
            total.value = dados.total;
        }
    } catch (erro) {
        if (erro.name === 'AbortError' || texto !== termoLimpo.value) return;
        produtos.value = [];
        total.value = 0;
    }
    // Resposta de um termo antigo não tira o "carregando" do termo atual.
    if (texto === termoLimpo.value) carregando.value = false;
};

// O termo que volta da URL depois de buscar só abre a lista ao clicar no campo.
const abrir = () => {
    aberta.value = true;
    const texto = termoLimpo.value;
    if (texto.length >= MINIMO && !guardadas.has(texto) && !carregando.value) buscar(texto);
};

watch(termoLimpo, (texto) => {
    clearTimeout(espera);
    ativo.value = -1;
    if (texto.length < MINIMO) {
        pedido?.abort();
        produtos.value = [];
        total.value = 0;
        carregando.value = false;
        return;
    }
    if (guardadas.has(texto)) return buscar(texto);
    pedido?.abort();
    carregando.value = true;
    espera = setTimeout(() => buscar(texto), 180);
});

const mostrar = computed(() => aberta.value && termoLimpo.value.length >= MINIMO);

const fechar = () => {
    aberta.value = false;
    ativo.value = -1;
};

const enviar = () => {
    if (ativo.value >= 0 && produtos.value[ativo.value]) {
        router.visit(`/produtos/${produtos.value[ativo.value].slug}`);
    } else {
        emit('buscar');
    }
    fechar();
};

const mover = (passo) => {
    if (!mostrar.value || !produtos.value.length) return;
    const ultimo = produtos.value.length - 1;
    ativo.value = ativo.value + passo > ultimo ? -1 : ativo.value + passo < -1 ? ultimo : ativo.value + passo;
};

// A lupa do celular abre a barra e já põe o cursor no campo.
defineExpose({ focus: () => campo.value?.focus() });

const pararDeOuvir = router.on('start', fechar);
onUnmounted(() => {
    pararDeOuvir();
    clearTimeout(espera);
    pedido?.abort();
});
</script>

<template>
    <div ref="caixa" class="relative">
        <form role="search" @submit.prevent="enviar">
            <input ref="campo" v-model="termo" type="search" placeholder="O que você procura?" autocomplete="off"
                :enterkeyhint="celular ? 'search' : undefined"
                role="combobox" aria-autocomplete="list" aria-controls="busca-instantanea-lista" :aria-expanded="mostrar"
                class="w-full rounded-full border border-store-border-strong bg-store-bg-raised pl-4 text-sm text-store-fg placeholder:text-store-fg-faint focus:border-store-accent focus:outline-none"
                :class="celular ? 'py-2.5 pr-11' : 'py-2 pr-10'"
                @input="aberta = true" @focus="abrir" @keydown.down.prevent="mover(1)" @keydown.up.prevent="mover(-1)" @keydown.esc="fechar">
            <button type="submit" class="absolute top-1/2 flex -translate-y-1/2 items-center justify-center rounded-full text-store-fg-muted hover:text-store-accent"
                :class="celular ? 'right-1 h-8 w-8' : 'right-1 h-7 w-7'" aria-label="Buscar">
                <i class="fas fa-magnifying-glass" :class="celular ? 'text-sm' : 'text-xs'"></i>
            </button>
        </form>

        <div v-if="mostrar" id="busca-instantanea-lista"
            class="topo-menu absolute top-[calc(100%+8px)] z-[60] flex max-h-[60vh] flex-col overflow-hidden rounded-xl border border-store-border bg-store-bg-raised text-left shadow-2xl"
            :class="celular ? 'inset-x-0' : 'left-0 w-[26rem] max-w-[calc(100vw-2rem)]'">
            <!-- Primeira resposta: linhas fantasma no lugar da foto e do nome -->
            <div v-if="carregando && !produtos.length" class="space-y-4 p-4">
                <div v-for="i in 3" :key="i" class="flex items-center gap-3">
                    <div class="h-12 w-12 shrink-0 animate-pulse rounded-lg bg-store-bg-sunken"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3.5 w-3/4 animate-pulse rounded bg-store-bg-sunken"></div>
                        <div class="h-3 w-1/3 animate-pulse rounded bg-store-bg-sunken"></div>
                    </div>
                </div>
            </div>

            <template v-else-if="produtos.length">
                <ul role="listbox" class="overflow-y-auto overscroll-contain py-1" :class="{ 'opacity-60': carregando }">
                    <li v-for="(produto, i) in produtos" :key="produto.slug" role="option" :aria-selected="i === ativo">
                        <Link :href="`/produtos/${produto.slug}`"
                            class="flex items-center gap-3 border-b border-store-border/50 px-4 py-2.5 no-underline transition-colors last:border-0 hover:bg-store-bg-sunken"
                            :class="{ 'bg-store-bg-sunken': i === ativo }" @mouseenter="ativo = i">
                            <img v-if="produto.imagem" :src="produto.imagem" :alt="produto.nome" loading="lazy" decoding="async"
                                class="h-12 w-12 shrink-0 rounded-lg border border-store-border/60 bg-white object-contain">
                            <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-store-bg-sunken text-store-fg-faint">
                                <i class="far fa-image"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="line-clamp-2 text-sm font-semibold leading-snug text-store-fg">{{ produto.nome }}</span>
                                <span class="mt-0.5 block text-xs font-semibold text-store-accent">{{ formatPrice(produto.preco) }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
                <button type="button" class="border-t border-store-border px-4 py-2.5 text-center text-sm font-semibold text-store-accent hover:bg-store-bg-sunken"
                    @click="emit('buscar'); fechar()">
                    Ver todos os {{ total }} resultado{{ total === 1 ? '' : 's' }}
                </button>
            </template>

            <div v-else class="flex flex-col items-center p-6 text-center text-store-fg-muted">
                <i class="fas fa-magnifying-glass mb-2 text-2xl text-store-fg-faint"></i>
                <p class="text-sm font-semibold text-store-fg">Nenhum produto encontrado</p>
                <p class="mt-1 text-xs">Tente outra palavra.</p>
            </div>
        </div>
    </div>
</template>
