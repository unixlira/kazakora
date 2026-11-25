<script setup>
// Faixa de cookies no topo do site (pedido do Lira 25/11/2026; antes era um pop-up no meio da tela,
// de 18/11). Fina, sem travar a página, e dentro da LGPD (guia de cookies da ANPD):
//  - "Aceitar ⊗" (botão laranja de destaque, com o X num círculo ao lado do texto; pedido do Lira 25/11/2026):
//    POST /cookies/aceitar. Grava a prova e libera o Pixel da Meta, a API de Conversões (pixelMeta.js exige
//    `cookies.aceito`) e a tag do Google Ads (tagGoogle.js). Está escrito "Aceitar": o clique é a autorização.
//  - "Só os necessários": POST /cookies/recusar. Fecha a faixa e fica SÓ com os necessários (carrinho,
//    login, pagamento); Pixel e Google Ads não rodam.
// As duas lembram a escolha (cookie kazakora_cookies, 12 meses): a faixa some e não volta.
// Admin não tem (`cookies` é null). Na Política de Cookies o botão "Rever minha escolha" reabre a
// faixa pelo evento ABRIR. Regras: docs/privacidade-e-cookies.md.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const ABRIR = 'kz:abrir-aviso-cookies';

const page = usePage();
const enviando = ref(false);
const reaberto = ref(false);

const visivel = computed(() => {
    const cookies = page.props.cookies;
    if (!cookies) return false;
    if (reaberto.value) return true;
    return !cookies.escolhido;
});

const escolher = (rota) => {
    if (enviando.value) return;
    enviando.value = true;
    router.post(rota, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['cookies'],
        onFinish: () => {
            enviando.value = false;
            reaberto.value = false;
        },
    });
};

const aceitar = () => escolher('/cookies/aceitar');
const soNecessarios = () => escolher('/cookies/recusar');

const reabrir = () => {
    reaberto.value = true;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
onMounted(() => window.addEventListener(ABRIR, reabrir));
onBeforeUnmount(() => window.removeEventListener(ABRIR, reabrir));
</script>

<template>
    <div v-if="visivel" role="region" aria-label="Aviso de cookies"
        class="aviso-cookies relative z-[60] bg-[#1F2225] text-white [color-scheme:light]">
        <div class="mx-auto flex max-w-7xl items-center gap-3 py-2 pl-4 pr-2 sm:gap-4 sm:pl-6">
            <i class="fa-solid fa-cookie-bite shrink-0 text-[16px] text-[#FD7B41]" aria-hidden="true"></i>
            <p class="min-w-0 flex-1 text-[12.5px] leading-snug sm:text-[13.5px]">
                Usamos cookies<span class="hidden sm:inline"> para melhorar sua experiência</span>.
                <Link href="/politica-de-cookies" class="whitespace-nowrap font-semibold underline decoration-[#FD7B41] decoration-2 underline-offset-[3px] hover:text-[#FD7B41]">Saiba mais</Link>
            </p>
            <button type="button" :disabled="enviando"
                class="shrink-0 px-1 text-[11.5px] font-medium text-white/60 underline underline-offset-2 hover:text-white disabled:opacity-60 sm:text-[12.5px]"
                @click="soNecessarios">
                Só os necessários
            </button>
            <button type="button" :disabled="enviando" aria-label="Aceitar os cookies e fechar"
                class="botao pulsa inline-flex shrink-0 items-center gap-2 rounded-full bg-[#FD7B41] py-1.5 pl-4 pr-1.5 text-[14px] font-extrabold text-[#1F2225] shadow-[0_0_0_3px_rgba(253,123,65,0.25)] hover:bg-[#ff8d58] disabled:opacity-60 sm:text-[15px]"
                @click="aceitar">
                Aceitar
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#1F2225] text-[#FD7B41]" aria-hidden="true"><i class="fa-solid fa-xmark text-[13px]"></i></span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.aviso-cookies {
    animation: aviso-cookies-entra 0.3s ease-out;
}
.botao {
    transition: background-color 0.15s, transform 0.1s;
}
.botao:active {
    transform: scale(0.97);
}
.botao:focus-visible,
.aviso-cookies a:focus-visible {
    outline: 2px solid #FD7B41;
    outline-offset: 2px;
}
/* Pulso leve no Aceitar para chamar o clique. */
.pulsa {
    animation: aviso-cookies-pulsa 2.2s ease-in-out 1s infinite;
}
@keyframes aviso-cookies-pulsa {
    0%, 100% { box-shadow: 0 0 0 3px rgba(253, 123, 65, 0.25); }
    50% { box-shadow: 0 0 0 7px rgba(253, 123, 65, 0.12); }
}
@keyframes aviso-cookies-entra {
    from { transform: translateY(-100%); }
    to { transform: none; }
}
@media (prefers-reduced-motion: reduce) {
    .aviso-cookies, .pulsa { animation: none; }
}
</style>
