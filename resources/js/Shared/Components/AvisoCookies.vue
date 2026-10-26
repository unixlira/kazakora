<script setup>
// Faixa de cookies (pedido 2026-10-10; volta em 2026-10-25). Desde 25/10:
// "ao navegar você concorda"; o OK fecha na hora e, sem clique, a faixa some
// sozinha em 3 s e grava o aceite do mesmo jeito (decisão do Lira). O aceite
// libera o Pixel e a API de Conversões da Meta. Detalhes: /politica-de-cookies
// e docs/privacidade-e-cookies.md.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const ESPERA_MS = 3000;

const page = usePage();
const fechado = ref(false);
const visivel = computed(() => page.props.cookies && !page.props.cookies.aceito && !fechado.value);
let timer = null;

const aceitar = () => {
    if (fechado.value) return;
    fechado.value = true;
    clearTimeout(timer);
    router.post('/cookies/aceitar', {}, { preserveScroll: true, preserveState: true, only: ['cookies'] });
};

watch(visivel, (mostra) => {
    clearTimeout(timer);
    if (mostra) timer = setTimeout(aceitar, ESPERA_MS);
}, { immediate: true });

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Transition enter-from-class="translate-y-6 opacity-0" enter-active-class="transition duration-300" leave-to-class="translate-y-6 opacity-0" leave-active-class="transition duration-200">
        <div v-if="visivel" role="region" aria-label="Aviso de cookies"
            class="fixed inset-x-3 bottom-3 z-[80] mx-auto flex max-w-3xl flex-col items-center gap-3 rounded-2xl bg-[#111] px-5 py-4 text-sm text-white shadow-2xl sm:flex-row sm:gap-5 [color-scheme:light]">
            <i class="fa-solid fa-cookie-bite hidden text-2xl text-[#f27a2a] sm:block"></i>
            <!-- Celular: frase curta e "Saiba mais" nunca quebra no meio. -->
            <p class="flex-1 text-center text-[13px] leading-snug text-white/90 sm:text-left sm:text-sm">
                <span class="sm:hidden">Ao navegar no site, você concorda com o uso de cookies e dados, conforme a LGPD.</span>
                <span class="hidden sm:inline">Ao continuar navegando no site, você concorda com o uso de cookies e dos seus dados de navegação, conforme a LGPD.</span>
                {{ " " }}<Link href="/politica-de-cookies" class="whitespace-nowrap font-semibold text-[#f27a2a] underline-offset-2 hover:underline">Saiba mais</Link>
            </p>
            <button type="button" class="w-full rounded-full bg-store-laranja px-8 py-2.5 font-bold text-white transition hover:bg-store-laranja-hover sm:w-auto" @click="aceitar">
                OK
            </button>
        </div>
    </Transition>
</template>
