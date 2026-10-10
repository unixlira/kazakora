<script setup>
// Aviso de cookies (pedido 2026-10-10): simples, só o OK. Sem o OK a loja
// funciona normal (só os cookies necessários); com o OK entra o cookie de
// estatística. Detalhes: /politica-de-cookies e docs/privacidade-e-cookies.md.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const fechado = ref(false);
const visivel = computed(() => page.props.cookies && !page.props.cookies.aceito && !fechado.value);

const aceitar = () => {
    fechado.value = true;
    router.post('/cookies/aceitar', {}, { preserveScroll: true, preserveState: true, only: ['cookies'] });
};
</script>

<template>
    <Transition enter-from-class="translate-y-6 opacity-0" enter-active-class="transition duration-300" leave-to-class="translate-y-6 opacity-0" leave-active-class="transition duration-200">
        <div v-if="visivel" role="region" aria-label="Aviso de cookies"
            class="fixed inset-x-3 bottom-3 z-[80] mx-auto flex max-w-3xl flex-col items-center gap-3 rounded-2xl bg-[#111] px-5 py-4 text-sm text-white shadow-2xl sm:flex-row sm:gap-5 [color-scheme:light]">
            <i class="fa-solid fa-cookie-bite hidden text-2xl text-[#f27a2a] sm:block"></i>
            <p class="flex-1 text-center leading-snug text-white/90 sm:text-left">
                Usamos cookies para a loja funcionar e para melhorar sua experiência, conforme a LGPD.
                <Link href="/politica-de-cookies" class="font-semibold text-[#f27a2a] underline-offset-2 hover:underline">Saiba mais</Link>
            </p>
            <button type="button" class="w-full rounded-full bg-[#f27a2a] px-8 py-2.5 font-bold text-white transition hover:brightness-110 sm:w-auto" @click="aceitar">
                OK
            </button>
        </div>
    </Transition>
</template>
