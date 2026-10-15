<script setup>
/**
 * Aviso de carregamento da rolagem infinita (pedido 2026-10-13).
 *
 * Cards fantasma no mesmo formato do ProductCard e na MESMA grade da lista de
 * Home.vue (grid-cols-2 gap-4 md:grid-cols-3 lg:gap-6 xl:grid-cols-4), para os produtos novos
 * "aparecerem" no lugar. Embaixo, uma linha curta com três pontinhos.
 * Só tokens store-* (tema claro e escuro). Sem animação com prefers-reduced-motion.
 */
defineProps({
    // Quantos cards fantasma mostrar (o ideal é o tamanho da próxima leva).
    quantidade: {
        type: Number,
        default: 4,
    },
    // Texto visível e lido pelo leitor de tela.
    texto: {
        type: String,
        default: 'Buscando mais achados pra você',
    },
});
</script>

<template>
    <div role="status" aria-live="polite" aria-busy="true" class="carregando-produtos mt-4 lg:mt-6">
        <!-- Mesmas classes de colunas da lista em Home.vue: encaixa como continuação. -->
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:gap-6 xl:grid-cols-4" aria-hidden="true">
            <div v-for="n in quantidade" :key="n"
                class="fantasma flex h-full flex-col overflow-hidden rounded-[1.15rem] border border-store-border bg-store-bg-raised shadow-[0_10px_28px_rgba(43,22,65,0.06)]"
                :style="{ '--atraso': `${(n - 1) * 90}ms` }">
                <!-- Foto quadrada, como no card -->
                <div class="osso aspect-square w-full rounded-none"></div>

                <div class="flex flex-1 flex-col gap-2 px-4 pb-4 pt-6">
                    <!-- Nome: 2 linhas no celular, 3 no computador -->
                    <div class="flex flex-col gap-1.5">
                        <div class="osso h-3.5 w-full"></div>
                        <div class="osso h-3.5 w-4/5"></div>
                        <div class="osso hidden h-3.5 w-3/5 md:block"></div>
                    </div>
                    <!-- Selo Envio Express (centralizado) -->
                    <div class="osso mx-auto h-6 w-28 rounded-full"></div>
                    <!-- Preço e frete -->
                    <div class="mt-auto flex flex-col gap-2 pt-1">
                        <div class="osso h-3 w-1/3"></div>
                        <div class="osso h-6 w-1/2"></div>
                        <div class="osso h-3.5 w-2/3"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 flex items-center justify-center gap-3 text-sm font-medium text-store-fg-muted">
            <span class="flex items-center gap-1" aria-hidden="true">
                <span class="ponto h-1.5 w-1.5 rounded-full bg-store-accent"></span>
                <span class="ponto h-1.5 w-1.5 rounded-full bg-store-accent" style="animation-delay: 160ms"></span>
                <span class="ponto h-1.5 w-1.5 rounded-full bg-store-accent" style="animation-delay: 320ms"></span>
            </span>
            <span>{{ texto }}</span>
        </div>
    </div>
</template>

<style scoped>
/* Entrada suave dos cards fantasma, em cascata. */
.fantasma {
    animation: fantasma-entra 420ms ease-out both;
    animation-delay: var(--atraso, 0ms);
}

/* "Ossos": blocos cinza no lugar do conteúdo, com brilho que atravessa. */
.osso {
    position: relative;
    overflow: hidden;
    border-radius: 0.375rem;
    background-color: var(--color-store-accent-soft);
}

.osso::after {
    content: '';
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        transparent 0%,
        color-mix(in oklab, var(--color-store-bg-raised) 70%, transparent) 50%,
        transparent 100%
    );
    animation: osso-brilho 1.6s ease-in-out infinite;
    animation-delay: var(--atraso, 0ms);
}

.ponto {
    animation: ponto-pulsa 1.2s ease-in-out infinite;
}

@keyframes fantasma-entra {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes osso-brilho {
    100% { transform: translateX(100%); }
}

@keyframes ponto-pulsa {
    0%, 80%, 100% { opacity: 0.25; transform: translateY(0); }
    40% { opacity: 1; transform: translateY(-3px); }
}

/* Quem pediu menos movimento no sistema: tudo parado, só os blocos e o texto. */
@media (prefers-reduced-motion: reduce) {
    .fantasma,
    .osso::after,
    .ponto {
        animation: none;
    }

    .osso::after {
        display: none;
    }

    .ponto {
        opacity: 0.6;
    }
}
</style>
