<script setup>
// Barra de filtros única da loja (pedido 2026-10-15, modelo do FerraKora com
// a paleta da KazaKora: branco, preto e laranja, sem grafite). Um botão por
// filtro (Departamento, Mostrar, Desconto, Preço...), a contagem e o Ordenar
// na mesma faixa; os filtros ligados aparecem embaixo como etiquetas com X e
// "Limpar tudo". Vale em /ofertas e em todo departamento, categoria e busca.
// - Computador (lg): cada botão abre a lista logo abaixo dele.
// - Celular: a faixa rola para o lado e o botão abre a lista numa gaveta que
//   sobe de baixo. A gaveta fica acima do aviso de cookies (z-80).
// Cada escolha já aplica (a página muda e a lista fecha).
import { Link } from '@inertiajs/vue3';
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

// Qual lista está aberta ('ordem' ou a chave do grupo). Fecha ao escolher
// (os filtros mudam), ao clicar fora ou no Esc.
const aberto = ref(null);
const barra = ref(null);
const alternar = (id) => { aberto.value = aberto.value === id ? null : id; };
const fechar = () => { aberto.value = null; };
watch(() => props.filters, fechar);

const grupoAberto = computed(() => props.grupos.find((g) => g.chave === aberto.value) || null);

const ehCelular = () => typeof window !== 'undefined' && !window.matchMedia('(min-width: 1024px)').matches;
const aoClicarFora = (evento) => {
    // No celular a gaveta tem o próprio fundo escuro para fechar.
    if (aberto.value && !ehCelular() && !barra.value?.contains(evento.target)) fechar();
};
const aoTeclar = (evento) => { if (evento.key === 'Escape') fechar(); };
watch(aberto, (id) => { document.body.style.overflow = id && ehCelular() ? 'hidden' : ''; });
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
</script>

<template>
    <div ref="barra" class="mb-6">
        <p class="mb-3 text-sm text-store-fg-muted lg:hidden">{{ contagem }}</p>

        <nav class="flex items-center gap-2 overflow-x-auto rounded-xl border border-store-border bg-store-bg-raised px-2 py-2 shadow-sm [scrollbar-width:none] lg:gap-3 lg:overflow-visible lg:px-3 lg:py-2.5 [&::-webkit-scrollbar]:hidden" aria-label="Filtros">
            <div v-for="g in grupos" :key="g.chave" class="relative shrink-0">
                <button type="button" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-semibold transition-colors lg:px-4"
                    :class="botao(!!opcaoAtual(g))" :aria-expanded="aberto === g.chave" aria-haspopup="true" @click="alternar(g.chave)">
                    {{ rotuloGrupo(g) }}
                    <i class="fa-solid fa-caret-down text-xs transition-transform" :class="[opcaoAtual(g) ? 'text-white' : 'text-store-fg-faint', { 'rotate-180': aberto === g.chave }]" aria-hidden="true"></i>
                </button>
                <div v-if="aberto === g.chave" class="absolute left-0 top-full z-30 mt-2 hidden max-h-[60vh] min-w-[240px] overflow-y-auto rounded-xl border border-store-border bg-store-bg-raised py-1 shadow-lg lg:block">
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
                class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-semibold no-underline transition-colors lg:px-4"
                :class="botao(!!filters[m.chave])" :aria-pressed="!!filters[m.chave]">
                <i v-if="m.icone" class="fa-solid" :class="[m.icone, filters[m.chave] ? 'text-white' : m.corIcone]" aria-hidden="true"></i>
                {{ m.label }}
            </Link>

            <span class="ml-auto hidden whitespace-nowrap pl-2 text-sm text-store-fg-muted lg:inline">{{ contagem }}</span>

            <div v-if="ordens.length" class="relative shrink-0">
                <button type="button" class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-semibold transition-colors lg:px-4"
                    :class="botao(false)" :aria-expanded="aberto === 'ordem'" aria-haspopup="true" @click="alternar('ordem')">
                    <i class="fa-solid fa-arrow-down-wide-short text-xs text-store-fg-faint lg:hidden" aria-hidden="true"></i>
                    Ordenar: {{ ordemAtual?.label }}
                    <i class="fa-solid fa-caret-down text-xs text-store-fg-faint transition-transform" :class="{ 'rotate-180': aberto === 'ordem' }" aria-hidden="true"></i>
                </button>
                <div v-if="aberto === 'ordem'" class="absolute right-0 top-full z-30 mt-2 hidden min-w-[220px] overflow-hidden rounded-xl border border-store-border bg-store-bg-raised py-1 shadow-lg lg:block">
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

        <!-- Celular: a lista abre numa gaveta que sobe de baixo -->
        <Teleport to="body">
            <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
                <div v-if="aberto" class="fixed inset-0 z-[90] bg-black/40 lg:hidden" aria-hidden="true" @click="fechar"></div>
            </Transition>
            <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-full" enter-to-class="translate-y-0"
                leave-active-class="transition duration-200 ease-in" leave-from-class="translate-y-0" leave-to-class="translate-y-full">
                <div v-if="aberto" role="dialog" aria-modal="true" aria-labelledby="gaveta-filtro-titulo"
                    class="fixed inset-x-0 bottom-0 z-[91] flex max-h-[75dvh] flex-col rounded-t-2xl bg-store-bg-raised text-store-fg shadow-2xl lg:hidden">
                    <div class="flex items-center justify-between border-b border-store-border px-4 py-3">
                        <h2 id="gaveta-filtro-titulo" class="text-base font-bold">{{ grupoAberto ? grupoAberto.titulo : 'Ordenar por' }}</h2>
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-lg" aria-label="Fechar" @click="fechar">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <nav class="overflow-y-auto overscroll-contain py-1 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
                        <template v-if="grupoAberto">
                            <Link v-for="op in grupoAberto.opcoes" :key="op.label" :href="filtroHref({ [grupoAberto.chave]: op.valor })" preserve-scroll
                                class="flex items-center justify-between gap-4 border-b border-store-border px-4 py-3.5 text-base no-underline last:border-b-0" :class="opcao(valorAtual(grupoAberto.chave) === op.valor)">
                                <span>{{ op.label }}</span>
                                <span class="flex shrink-0 items-center gap-3 text-sm text-store-fg-faint">
                                    <template v-if="op.total != null">{{ op.total }}</template>
                                    <i v-if="valorAtual(grupoAberto.chave) === op.valor" class="fa-solid fa-check text-[#f27a2a]" aria-hidden="true"></i>
                                </span>
                            </Link>
                        </template>
                        <template v-else>
                            <Link v-for="op in ordens" :key="op.label" :href="filtroHref({ ordenar: op.valor })" preserve-scroll
                                class="flex items-center justify-between gap-4 border-b border-store-border px-4 py-3.5 text-base no-underline last:border-b-0" :class="opcao(ordemAtual === op)">
                                {{ op.label }}
                                <i v-if="ordemAtual === op" class="fa-solid fa-check text-[#f27a2a]" aria-hidden="true"></i>
                            </Link>
                        </template>
                    </nav>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
