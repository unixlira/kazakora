<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    // 'cadastro' troca o título do painel; as outras telas (entrar, senha) usam o de quem volta.
    modo: { type: String, default: 'entrar' },
});

// Produtos campeões em uso numa casa de verdade (fotos geradas a partir da foto real do produto).
const cenas = [
    { imagem: '/images/login/power-bank.webp', etiqueta: 'Power bank de encaixe', frase: 'Bateria acabando no sofá? Nem precisa levantar.' },
    { imagem: '/images/login/cestos-bambu.webp', etiqueta: 'Cestos de bambu', frase: 'A bagunça some. A prateleira agradece.' },
    { imagem: '/images/login/ring-light.webp', etiqueta: 'Ring light com tripé', frase: 'Seu cantinho do quarto acabou de virar estúdio.' },
];

const textos = {
    entrar: { titulo: 'Que bom te ver de novo', apoio: 'Seus pedidos e favoritos estão aqui, do jeito que você deixou.' },
    cadastro: { titulo: 'Sua casa, do seu jeito', apoio: 'Salve favoritos e acompanhe cada pedido sem caçar e-mail.' },
};
const texto = computed(() => textos[props.modo] ?? textos.entrar);

const atual = ref(0);
let timer = null;

onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    timer = setInterval(() => { atual.value = (atual.value + 1) % cenas.length; }, 6000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="storefront-shell flex min-h-screen bg-store-bg font-store text-store-fg">
        <!-- Form side -->
        <div class="flex w-full flex-col items-center justify-center bg-store-bg-raised px-4 py-12 lg:w-1/2">
            <Link href="/" class="mb-8 no-underline">
                <picture>
                    <source media="(prefers-color-scheme: dark)" :srcset="$page.props.marca?.logoRodape">
                    <img :src="$page.props.marca?.logoNav ?? '/images/marca/logo-nav.webp'" alt="KazaKora" class="h-9 w-auto">
                </picture>
            </Link>

            <div class="w-full" style="max-width: 24rem;">
                <slot />
            </div>
        </div>

        <!-- Produtos da loja em casa -->
        <div class="relative hidden w-1/2 overflow-hidden bg-[#140d1e] lg:block" aria-hidden="true">
            <img v-for="(cena, i) in cenas" :key="cena.imagem" :src="cena.imagem" alt=""
                :loading="i === 0 ? 'eager' : 'lazy'" :fetchpriority="i === 0 ? 'high' : 'auto'"
                class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1500ms] ease-in-out"
                :class="i === atual ? 'opacity-100' : 'opacity-0'">

            <div class="absolute inset-x-0 bottom-0 h-3/5"
                style="background: linear-gradient(to top, rgba(20, 13, 30, 0.92) 0%, rgba(20, 13, 30, 0.6) 45%, transparent 100%);"></div>

            <div class="absolute inset-x-0 bottom-0 px-10 pb-12 text-white xl:px-14">
                <div class="relative mb-5 h-16" style="max-width: 28rem;">
                    <div v-for="(cena, i) in cenas" :key="cena.etiqueta"
                        class="absolute inset-0 transition-opacity duration-700"
                        :class="i === atual ? 'opacity-100' : 'opacity-0'">
                        <span class="inline-block rounded-full bg-store-laranja px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                            {{ cena.etiqueta }}
                        </span>
                        <p class="mb-0 mt-2 text-base text-white/90">{{ cena.frase }}</p>
                    </div>
                </div>

                <h2 class="mb-2 font-display text-3xl font-semibold text-white">{{ texto.titulo }}</h2>
                <p class="mb-6 text-white/80" style="max-width: 26rem;">{{ texto.apoio }}</p>

                <div class="flex gap-2">
                    <span v-for="(cena, i) in cenas" :key="'ponto-' + i"
                        class="h-1 rounded-full transition-all duration-500"
                        :class="i === atual ? 'w-8 bg-[#f27a2a]' : 'w-4 bg-white/40'"></span>
                </div>
            </div>
        </div>
    </div>
</template>
