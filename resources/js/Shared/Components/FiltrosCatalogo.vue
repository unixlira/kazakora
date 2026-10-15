<script setup>
// Barra de filtros única da loja (pedido 2026-10-15, modelo do FerraKora com
// a paleta da KazaKora: branco, preto e laranja, sem grafite). Um botão por
// filtro (Departamento, Mostrar, Desconto, Preço...), a contagem e o Ordenar
// na mesma faixa; os filtros ligados aparecem embaixo como etiquetas com X e
// "Limpar tudo". Vale em /ofertas e em todo departamento, categoria e busca.
// - Computador (lg): cada botão abre a lista logo abaixo dele.
// - Celular (pedido 2026-10-15): um botão FILTRAR abre um painel lateral com
//   Ordenar por, Categoria (com a contagem), Preço e Desconto. As escolhas só
//   valem no Confirmar; Limpar desmarca tudo no painel. O painel fica acima do
//   aviso de cookies (z-80).
// No computador cada escolha já aplica (a página muda e a lista fecha).
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    // [{ chave, titulo, rotulo?, prefixoEtiqueta?, opcoes: [{ valor, label, total? }] }]; valor null = sem filtro.
    grupos: { type: Array, default: () => [] },
    // Botões liga/desliga: [{ chave, label, icone?, corIcone? }].
    marcas: { type: Array, default: () => [] },
    ordens: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    // Monta o endereço a partir de { chave: valor } (a página guarda o resto, como a busca).
    href: { type: Function, required: true },
    // "12 produtos", "3 de 40 ofertas"...
    contagem: { type: String, default: '' },
    // Etiquetas que a página controla (ex.: a busca): [{ label, href }].
    extras: { type: Array, default: () => [] },
    limparHref: { type: String, required: true },
    // Chaves dos grupos que entram no painel do celular, na ordem; null = todos.
    painel: { type: Array, default: null },
});

const atuais = () => {
    const filtros = { ordenar: props.filters.ordenar || null };
    props.grupos.forEach((g) => { filtros[g.chave] = props.filters[g.chave] || null; });
    props.marcas.forEach((m) => { filtros[m.chave] = props.filters[m.chave] ? '1' : null; });
    return filtros;
};
const filtroHref = (mudancas) => props.href({ ...atuais(), ...mudancas });

const valorAtual = (chave) => props.filters[chave] || null;
const opcaoAtual = (g) => g.opcoes.find((op) => op.valor !== null && op.valor === valorAtual(g.chave)) || null;
const rotuloGrupo = (g) => {
    const op = opcaoAtual(g);
    return op ? `${g.rotulo ?? g.titulo}: ${op.label}` : (g.rotulo ?? g.titulo);
};
const ordemAtual = computed(() => props.ordens.find((op) => op.valor === (props.filters.ordenar || null)) ?? props.ordens[0]);

const etiquetas = computed(() => [
    ...props.extras,
    ...props.grupos.map((g) => {
        const op = opcaoAtual(g);
        return op && { label: `${g.prefixoEtiqueta ?? ''}${op.label}`, href: filtroHref({ [g.chave]: null }) };
    }).filter(Boolean),
    ...props.marcas.filter((m) => props.filters[m.chave]).map((m) => ({ label: m.label, href: filtroHref({ [m.chave]: null }) })),
]);

// Qual lista está aberta no computador ('ordem' ou a chave do grupo). Fecha
// ao escolher (os filtros mudam), ao clicar fora ou no Esc.
const aberto = ref(null);
const barra = ref(null);
const alternar = (id) => { aberto.value = aberto.value === id ? null : id; };

// Painel do celular: as escolhas ficam num rascunho até o Confirmar.
const painelAberto = ref(false);
const rascunho = ref({});
const gruposPainel = computed(() => (props.painel
    ? props.painel.map((chave) => props.grupos.find((g) => g.chave === chave)).filter(Boolean)
    : props.grupos));
const ligados = computed(() => props.grupos.filter((g) => opcaoAtual(g)).length + props.marcas.filter((m) => props.filters[m.chave]).length);
const abrirPainel = () => { rascunho.value = atuais(); painelAberto.value = true; };
const escolher = (chave, valor) => { rascunho.value = { ...rascunho.value, [chave]: valor }; };
const alternarMarca = (chave) => escolher(chave, rascunho.value[chave] ? null : '1');
const limparPainel = () => {
    const vazio = { ordenar: null };
    gruposPainel.value.forEach((g) => { vazio[g.chave] = null; });
    props.marcas.forEach((m) => { vazio[m.chave] = null; });
    rascunho.value = { ...rascunho.value, ...vazio };
};
const confirmar = () => {
    painelAberto.value = false;
    router.visit(props.href(rascunho.value), { preserveScroll: true });
};

const fechar = () => { aberto.value = null; painelAberto.value = false; };
watch(() => props.filters, fechar);

const ehCelular = () => typeof window !== 'undefined' && !window.matchMedia('(min-width: 1024px)').matches;
const aoClicarFora = (evento) => {
    // No celular o painel tem o próprio fundo escuro para fechar.
    if (aberto.value && !ehCelular() && !barra.value?.contains(evento.target)) aberto.value = null;
};
const aoTeclar = (evento) => { if (evento.key === 'Escape') fechar(); };
watch(painelAberto, (sim) => { document.body.style.overflow = sim ? 'hidden' : ''; });
onMounted(() => {
    document.addEventListener('mousedown', aoClicarFora);
    document.addEventListener('keydown', aoTeclar);
});
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('mousedown', aoClicarFora);
    document.removeEventListener('keydown', aoTeclar);
});

// Botão da faixa: branco com borda; ligado fica laranja com texto branco.
const botao = (ativo) => ativo
    ? 'border-[#f27a2a] bg-[#f27a2a] text-white'
    : 'border-store-border-strong bg-store-bg-raised text-store-fg hover:border-[#f27a2a]';
// Opção da lista: a marcada leva o check laranja.
const opcao = (ativo) => ativo ? 'font-semibold text-store-fg' : 'text-store-fg-muted';
// Opção do painel do celular: a marcada fica com borda e texto laranja.
const chip = (ativo) => ativo
    ? 'border-[#f27a2a] bg-[#f27a2a]/10 font-semibold text-[#f27a2a]'
    : 'border-store-border-strong bg-store-bg-raised text-store-fg';
</script>

<template>
    <div ref="barra" class="mb-6">
        <!-- Celular: botão FILTRAR que abre o painel, com a contagem ao lado. -->
        <div class="flex items-center justify-between gap-3 lg:hidden">
            <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#f27a2a] px-4 py-2.5 text-sm font-bold uppercase tracking-wide text-white shadow-sm active:bg-[#d9661c]"
                aria-haspopup="dialog" :aria-expanded="painelAberto" @click="abrirPainel">
                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                Filtrar
                <span v-if="ligados" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-white px-1.5 text-xs text-[#f27a2a]">{{ ligados }}</span>
            </button>
            <span class="text-sm text-store-fg-muted">{{ contagem }}</span>
        </div>

        <!-- Computador: faixa branca com moldura, um botão por filtro. -->
        <nav class="hidden items-center gap-3 rounded-xl border border-store-border bg-store-bg-raised px-3 py-2.5 shadow-sm lg:flex" aria-label="Filtros">
            <div v-for="g in grupos" :key="g.chave" class="relative shrink-0">
                <button type="button" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                    :class="botao(!!opcaoAtual(g))" :aria-expanded="aberto === g.chave" aria-haspopup="true" @click="alternar(g.chave)">
                    {{ rotuloGrupo(g) }}
                    <i class="fa-solid fa-caret-down text-xs transition-transform" :class="[opcaoAtual(g) ? 'text-white' : 'text-store-fg-faint', { 'rotate-180': aberto === g.chave }]" aria-hidden="true"></i>
                </button>
                <div v-if="aberto === g.chave" class="absolute left-0 top-full z-30 mt-2 max-h-[60vh] min-w-[240px] overflow-y-auto rounded-xl border border-store-border bg-store-bg-raised py-1 shadow-lg">
                    <Link v-for="op in g.opcoes" :key="op.label" :href="filtroHref({ [g.chave]: op.valor })" preserve-scroll
                        class="flex items-center justify-between gap-4 px-4 py-2 text-sm no-underline transition-colors hover:bg-store-bg-sunken" :class="opcao(valorAtual(g.chave) === op.valor)">
                        <span class="truncate">{{ op.label }}</span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-store-fg-faint">
                            <template v-if="op.total != null">{{ op.total }}</template>
                            <i v-if="valorAtual(g.chave) === op.valor" class="fa-solid fa-check text-[#f27a2a]" aria-hidden="true"></i>
                        </span>
                    </Link>
                </div>
            </div>

            <Link v-for="m in marcas" :key="m.chave" :href="filtroHref({ [m.chave]: filters[m.chave] ? null : '1' })" preserve-scroll
                class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold no-underline transition-colors"
                :class="botao(!!filters[m.chave])" :aria-pressed="!!filters[m.chave]">
                <i v-if="m.icone" class="fa-solid" :class="[m.icone, filters[m.chave] ? 'text-white' : m.corIcone]" aria-hidden="true"></i>
                {{ m.label }}
            </Link>

            <span class="ml-auto whitespace-nowrap pl-2 text-sm text-store-fg-muted">{{ contagem }}</span>

            <div v-if="ordens.length" class="relative shrink-0">
                <button type="button" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                    :class="botao(false)" :aria-expanded="aberto === 'ordem'" aria-haspopup="true" @click="alternar('ordem')">
                    Ordenar: {{ ordemAtual?.label }}
                    <i class="fa-solid fa-caret-down text-xs text-store-fg-faint transition-transform" :class="{ 'rotate-180': aberto === 'ordem' }" aria-hidden="true"></i>
                </button>
                <div v-if="aberto === 'ordem'" class="absolute right-0 top-full z-30 mt-2 min-w-[220px] overflow-hidden rounded-xl border border-store-border bg-store-bg-raised py-1 shadow-lg">
                    <Link v-for="op in ordens" :key="op.label" :href="filtroHref({ ordenar: op.valor })" preserve-scroll
                        class="flex items-center justify-between gap-4 px-4 py-2 text-sm no-underline transition-colors hover:bg-store-bg-sunken" :class="opcao(ordemAtual === op)">
                        {{ op.label }}
                        <i v-if="ordemAtual === op" class="fa-solid fa-check text-xs text-[#f27a2a]" aria-hidden="true"></i>
                    </Link>
                </div>
            </div>
        </nav>

        <div v-if="etiquetas.length" class="mt-3 flex flex-wrap items-center gap-2">
            <Link v-for="e in etiquetas" :key="e.label" :href="e.href" preserve-scroll
                class="inline-flex items-center gap-2 rounded-full bg-store-accent px-3 py-1 text-sm font-semibold text-store-accent-contrast no-underline hover:bg-[#f27a2a] hover:text-white">
                {{ e.label }}
                <i class="fa-solid fa-xmark text-xs opacity-70" aria-label="Tirar filtro"></i>
            </Link>
            <Link :href="limparHref" class="ml-1 text-sm font-semibold text-store-fg underline decoration-[#f27a2a] underline-offset-4 hover:text-[#f27a2a]">Limpar tudo</Link>
        </div>

        <!-- Celular: painel de filtros que entra pela direita -->
        <Teleport to="body">
            <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
                <div v-if="painelAberto" class="fixed inset-0 z-[90] bg-black/40 lg:hidden" aria-hidden="true" @click="fechar"></div>
            </Transition>
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
                leave-active-class="transition duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
                <div v-if="painelAberto" role="dialog" aria-modal="true" aria-labelledby="painel-filtro-titulo"
                    class="fixed inset-y-0 right-0 z-[91] flex w-[88vw] max-w-sm flex-col bg-store-bg-raised text-store-fg shadow-2xl lg:hidden">
                    <div class="flex items-center justify-between bg-[#f27a2a] px-4 py-3 text-white">
                        <h2 id="painel-filtro-titulo" class="flex items-center gap-2 text-base font-bold uppercase tracking-wide">
                            <i class="fa-solid fa-sliders" aria-hidden="true"></i> Filtrar
                        </h2>
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-lg hover:bg-white/15" aria-label="Fechar" @click="fechar">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex-1 divide-y divide-store-border overflow-y-auto overscroll-contain">
                        <section v-if="ordens.length" class="px-4 py-4">
                            <h3 class="mb-3 text-sm font-bold">Ordenar por</h3>
                            <div class="grid grid-cols-2 gap-2">
                                <button v-for="op in ordens" :key="op.label" type="button" class="rounded-lg border px-3 py-2.5 text-sm transition-colors"
                                    :class="chip((rascunho.ordenar || null) === op.valor)" :aria-pressed="(rascunho.ordenar || null) === op.valor" @click="escolher('ordenar', op.valor)">
                                    {{ op.label }}
                                </button>
                            </div>
                        </section>

                        <section v-for="g in gruposPainel" :key="g.chave" class="px-4 py-4">
                            <h3 class="mb-3 text-sm font-bold">{{ g.tituloPainel ?? g.titulo }}</h3>
                            <!-- Lista com a contagem (ex.: Categoria) -->
                            <div v-if="g.opcoes.some((op) => op.total != null)" class="flex flex-col gap-2">
                                <button v-for="op in g.opcoes" :key="op.label" type="button" class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 text-left text-sm transition-colors"
                                    :class="chip((rascunho[g.chave] || null) === op.valor)" :aria-pressed="(rascunho[g.chave] || null) === op.valor" @click="escolher(g.chave, op.valor)">
                                    <span>{{ op.labelPainel ?? op.label }}</span>
                                    <span v-if="op.total != null" class="shrink-0 text-xs opacity-70">({{ op.total }})</span>
                                </button>
                            </div>
                            <div v-else class="grid grid-cols-2 gap-2">
                                <button v-for="op in g.opcoes" :key="op.label" type="button" class="rounded-lg border px-3 py-2.5 text-sm transition-colors"
                                    :class="chip((rascunho[g.chave] || null) === op.valor)" :aria-pressed="(rascunho[g.chave] || null) === op.valor" @click="escolher(g.chave, op.valor)">
                                    {{ op.labelPainel ?? op.label }}
                                </button>
                            </div>
                        </section>

                        <section v-if="marcas.length" class="flex flex-wrap gap-2 px-4 py-4">
                            <button v-for="m in marcas" :key="m.chave" type="button" class="inline-flex items-center gap-2 rounded-lg border px-3 py-2.5 text-sm transition-colors"
                                :class="chip(!!rascunho[m.chave])" :aria-pressed="!!rascunho[m.chave]" @click="alternarMarca(m.chave)">
                                <i v-if="m.icone" class="fa-solid" :class="[m.icone, m.corIcone]" aria-hidden="true"></i>
                                {{ m.label }}
                            </button>
                        </section>
                    </div>

                    <div class="grid grid-cols-2 gap-3 border-t border-store-border px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                        <button type="button" class="rounded-lg border-2 border-[#f27a2a] bg-store-bg-raised px-4 py-3 text-sm font-bold text-[#f27a2a]" @click="limparPainel">Limpar</button>
                        <button type="button" class="rounded-lg bg-[#f27a2a] px-4 py-3 text-sm font-bold text-white active:bg-[#d9661c]" @click="confirmar">Confirmar</button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
