<script setup>
// Filtros da loja no layout da Beauty Kora (pedido 2026-10-15, primeiro em
// /ofertas e depois em todo departamento, categoria e busca). O mesmo
// componente aparece duas vezes na página:
// - modo "lateral": coluna de filtros do computador, cada clique já aplica;
// - modo "botao": a palavra FILTRAR do celular, que abre uma tela cinza com
//   os filtros e o botão laranja Confirmar no fim; só aplica ao confirmar.
// A tela cinza fica acima do aviso de cookies (z-80): antes ficava embaixo e
// o OK dos cookies cobria o Confirmar no celular.
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    modo: { type: String, default: 'lateral' },
    // [{ chave, titulo, opcoes: [{ valor, label, labelPainel?, total? }] }]; valor null = sem filtro.
    grupos: { type: Array, default: () => [] },
    // Caixinhas liga/desliga: [{ chave, label, icone?, corIcone? }].
    marcas: { type: Array, default: () => [] },
    // Ordens do painel do celular (no computador a página mostra o select).
    ordens: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    // Monta o endereço a partir de { chave: valor } (a página guarda o resto, como a busca).
    href: { type: Function, required: true },
});

const chavesFiltro = computed(() => [...props.grupos.map((g) => g.chave), ...props.marcas.map((m) => m.chave)]);

const atuais = () => {
    const filtros = { ordenar: props.filters.ordenar || null };
    props.grupos.forEach((g) => { filtros[g.chave] = props.filters[g.chave] || null; });
    props.marcas.forEach((m) => { filtros[m.chave] = props.filters[m.chave] ? '1' : null; });
    return filtros;
};
const filtroHref = (mudancas) => props.href({ ...atuais(), ...mudancas });
const qtdFiltros = computed(() => chavesFiltro.value.filter((chave) => props.filters[chave]).length);

// Celular: escolhas ficam no rascunho até o Confirmar.
const painelAberto = ref(false);
const rascunho = ref(atuais());
const abrirPainel = () => { rascunho.value = atuais(); painelAberto.value = true; };
const fecharPainel = () => { painelAberto.value = false; };
const escolher = (chave, valor) => { rascunho.value[chave] = valor; };
const limparRascunho = () => {
    const limpo = { ordenar: rascunho.value.ordenar };
    chavesFiltro.value.forEach((chave) => { limpo[chave] = null; });
    rascunho.value = limpo;
};
const confirmar = () => {
    painelAberto.value = false;
    router.get(props.href(rascunho.value).split('#')[0], {}, { preserveScroll: false });
};

const aoTeclar = (evento) => { if (evento.key === 'Escape') fecharPainel(); };
watch(painelAberto, (aberto) => {
    document.body.style.overflow = aberto ? 'hidden' : '';
    if (aberto) document.addEventListener('keydown', aoTeclar);
    else document.removeEventListener('keydown', aoTeclar);
});
onBeforeUnmount(() => {
    if (painelAberto.value) document.body.style.overflow = '';
    document.removeEventListener('keydown', aoTeclar);
});

const itemLateral = (ativo) => ativo
    ? 'bg-store-accent text-store-accent-contrast font-semibold'
    : 'text-store-fg-muted hover:bg-store-bg hover:text-store-fg';
const pilulaPainel = (ativo) => ativo
    ? 'border-[#f27a2a] bg-[#f27a2a] text-white'
    : 'border-neutral-300 bg-white text-neutral-700';
</script>

<template>
    <!-- Computador: coluna de filtros (modelo Beauty Kora) -->
    <aside v-if="modo === 'lateral'" class="hidden w-72 shrink-0 space-y-4 lg:block">
        <div v-for="g in grupos" :key="g.chave" class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
            <h2 class="mb-3 text-base font-bold">{{ g.titulo }}</h2>
            <nav class="space-y-1">
                <Link v-for="op in g.opcoes" :key="op.label" :href="filtroHref({ [g.chave]: op.valor })" preserve-scroll
                    class="flex items-center justify-between rounded-xl px-3 py-2 text-sm no-underline transition-colors"
                    :class="itemLateral((filters[g.chave] || null) === op.valor)">
                    <span class="truncate pr-2">{{ op.label }}</span>
                    <span v-if="op.total != null" class="text-xs opacity-80">{{ op.total }}</span>
                </Link>
            </nav>
        </div>

        <Link v-for="m in marcas" :key="m.chave" :href="filtroHref({ [m.chave]: filters[m.chave] ? null : '1' })" preserve-scroll
            class="flex items-center gap-3 rounded-2xl border border-store-border bg-store-bg-raised p-5 text-sm font-semibold text-store-fg no-underline">
            <i class="fa-solid text-lg" :class="filters[m.chave] ? 'fa-square-check text-store-accent' : 'fa-square text-store-fg-faint'" aria-hidden="true"></i>
            <span><i v-if="m.icone" class="fa-solid mr-1" :class="[m.icone, m.corIcone]" aria-hidden="true"></i>{{ m.label }}</span>
        </Link>
    </aside>

    <!-- Celular: a palavra FILTRAR abre o painel cinza -->
    <template v-else>
        <button type="button" class="inline-flex shrink-0 items-center gap-2 text-sm font-bold tracking-wide text-store-fg lg:hidden"
            :aria-expanded="painelAberto" aria-controls="painel-filtros" @click="abrirPainel">
            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
            FILTRAR<span v-if="qtdFiltros" class="rounded-full bg-[#f27a2a] px-1.5 text-xs text-white">{{ qtdFiltros }}</span>
        </button>

        <Teleport to="body">
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="-translate-y-full" enter-to-class="translate-y-0"
                leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0" leave-to-class="-translate-y-full">
                <div v-if="painelAberto" id="painel-filtros" role="dialog" aria-modal="true" aria-labelledby="painel-filtros-titulo"
                    class="fixed inset-0 z-[90] flex h-[100dvh] flex-col bg-neutral-200 text-neutral-800 lg:hidden">
                    <div class="flex items-center justify-between border-b border-neutral-300 px-4 py-3">
                        <h2 id="painel-filtros-titulo" class="text-base font-bold tracking-wide">FILTRAR</h2>
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-lg" aria-label="Fechar" @click="fecharPainel">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex-1 space-y-6 overflow-y-auto overscroll-contain px-4 py-5">
                        <fieldset v-if="ordens.length">
                            <legend class="mb-2 text-sm font-bold">Ordenar por</legend>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="op in ordens" :key="op.label" type="button" :aria-pressed="rascunho.ordenar === op.valor"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho.ordenar === op.valor)"
                                    @click="escolher('ordenar', op.valor)">{{ op.label }}</button>
                            </div>
                        </fieldset>

                        <fieldset v-for="g in grupos" :key="g.chave">
                            <legend class="mb-2 text-sm font-bold">{{ g.titulo }}</legend>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="op in g.opcoes" :key="op.label" type="button" :aria-pressed="rascunho[g.chave] === op.valor"
                                    class="rounded-full border px-3 py-1.5 text-sm" :class="pilulaPainel(rascunho[g.chave] === op.valor)"
                                    @click="escolher(g.chave, op.valor)">{{ op.labelPainel ?? op.label }}<template v-if="op.total != null"> ({{ op.total }})</template></button>
                            </div>
                        </fieldset>

                        <label v-for="m in marcas" :key="m.chave" class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 text-sm font-semibold">
                            <input type="checkbox" class="h-5 w-5 accent-[#f27a2a]" :checked="!!rascunho[m.chave]" @change="escolher(m.chave, $event.target.checked ? '1' : null)">
                            <span><i v-if="m.icone" class="fa-solid mr-1" :class="[m.icone, m.corIcone]" aria-hidden="true"></i>{{ m.label }}</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-neutral-300 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                        <button type="button" class="px-2 py-3 text-sm font-semibold text-neutral-600 underline" @click="limparRascunho">Limpar</button>
                        <button type="button" class="flex-1 rounded-xl bg-[#f27a2a] py-3 text-base font-bold text-white shadow-md active:bg-[#d9661a]" @click="confirmar">
                            Confirmar
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </template>
</template>
