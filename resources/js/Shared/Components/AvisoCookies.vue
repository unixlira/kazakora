<script setup>
// Pop-up de cookies no meio da tela (pedido do Lira 2026-11-18). Dois botões
// honestos e do mesmo tamanho:
//  - "Aceito": POST /cookies/aceitar. Grava a prova e libera o Pixel da Meta
//    e a API de Conversões (pixelMeta.js exige `cookies.aceito`) e a tag do
//    Google Ads (tagGoogle.js, 2026-11-19, mesma regra).
//  - "Somente o básico": POST /cookies/recusar. Grava a prova da recusa;
//    Pixel e API de Conversões NÃO rodam para essa pessoa.
// Os dois lembram a escolha (cookie kazakora_cookies, 12 meses): o pop-up
// aparece uma vez. Esc só esconde até o fim da sessão do navegador, sem
// gravar nada e sem contar como aceite. Admin não tem (`cookies` é null).
// A Política de Cookies não abre o pop-up sozinha (a pessoa está lendo) e tem
// o botão "Rever minha escolha", que o reabre pelo evento ABRIR.
// Regras: docs/privacidade-e-cookies.md.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const ABRIR = 'kz:abrir-aviso-cookies';
const ADIADO = 'kz_cookies_depois';

const page = usePage();
const enviando = ref(false);
const reaberto = ref(false);
const adiado = ref(false);
const dialogo = ref(null);
const primeiroBotao = ref(null);
let foiAberto = null;

try {
    adiado.value = window.sessionStorage.getItem(ADIADO) === '1';
} catch (e) { /* sem sessionStorage: mostra normalmente */ }

const visivel = computed(() => {
    const cookies = page.props.cookies;
    if (!cookies) return false;
    if (reaberto.value) return true;
    if (page.url.startsWith('/politica-de-cookies')) return false;
    return !cookies.escolhido && !adiado.value;
});

const travarRolagem = (travar) => {
    document.documentElement.style.overflow = travar ? 'hidden' : '';
};

watch(visivel, async (mostra) => {
    travarRolagem(mostra);
    if (mostra) {
        foiAberto = document.activeElement;
        await nextTick();
        primeiroBotao.value?.focus();
    } else {
        foiAberto?.focus?.();
        foiAberto = null;
    }
}, { immediate: true, flush: 'post' });

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
const somenteBasico = () => escolher('/cookies/recusar');

const focaveis = () => Array.from(dialogo.value?.querySelectorAll('a[href], button:not([disabled])') ?? []);

const aoTeclar = (evento) => {
    if (evento.key === 'Escape') {
        // Esc não é aceite nem recusa: só esconde até o fim da sessão.
        evento.preventDefault();
        evento.stopPropagation();
        reaberto.value = false;
        adiado.value = true;
        try { window.sessionStorage.setItem(ADIADO, '1'); } catch (e) { /* ignora */ }
        return;
    }
    if (evento.key !== 'Tab') return;
    const lista = focaveis();
    if (!lista.length) return;
    const primeiro = lista[0];
    const ultimo = lista[lista.length - 1];
    if (evento.shiftKey && document.activeElement === primeiro) {
        evento.preventDefault();
        ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
        evento.preventDefault();
        primeiro.focus();
    }
};

const reabrir = () => { reaberto.value = true; };
onMounted(() => window.addEventListener(ABRIR, reabrir));
onBeforeUnmount(() => {
    window.removeEventListener(ABRIR, reabrir);
    if (visivel.value) travarRolagem(false);
});
</script>

<template>
    <Transition
        enter-from-class="opacity-0" enter-active-class="transition duration-300 ease-out"
        leave-to-class="opacity-0" leave-active-class="transition duration-200 ease-in">
        <div v-if="visivel" class="aviso-cookies fixed inset-0 z-[90] flex items-center justify-center p-4 [color-scheme:light]"
            @keydown="aoTeclar">
            <div class="absolute inset-0 bg-[#1F2225]/65 backdrop-blur-[3px]" aria-hidden="true"></div>

            <div ref="dialogo" role="dialog" aria-modal="true" aria-labelledby="aviso-cookies-titulo" aria-describedby="aviso-cookies-texto"
                class="cartao relative w-full max-w-[440px] overflow-hidden rounded-[28px] bg-white text-[#1F2225] shadow-[0_30px_80px_-20px_rgba(31,34,37,0.55)]">
                <!-- Faixa da marca no topo -->
                <div class="h-1.5 w-full bg-gradient-to-r from-[#FD7B41] via-[#ff9a62] to-[#FD7B41]" aria-hidden="true"></div>

                <div class="px-6 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-7">
                    <div class="flex items-center gap-3.5">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#FD7B41]/12 ring-1 ring-[#FD7B41]/25" aria-hidden="true">
                            <i class="fa-solid fa-cookie-bite text-[22px] text-[#e2622a]"></i>
                        </span>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#b9501f]">Sua privacidade</p>
                            <h2 id="aviso-cookies-titulo" class="text-[20px] font-bold leading-tight sm:text-[22px]">Cookies, do seu jeito</h2>
                        </div>
                    </div>

                    <p id="aviso-cookies-texto" class="mt-4 text-[14.5px] leading-relaxed text-[#4a4f54]">
                        Usamos cookies para a loja funcionar e, se você aceitar, para medir nossos anúncios
                        (Pixel da Meta e tag do Google Ads) e mostrar ofertas que fazem sentido para você. A escolha é sua.
                    </p>

                    <ul class="mt-4 space-y-2.5 rounded-2xl bg-[#f6f5f4] p-4 text-[13.5px] leading-snug">
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-lock mt-0.5 w-4 text-center text-[#1F2225]" aria-hidden="true"></i>
                            <span><strong class="font-semibold">Necessários</strong>: carrinho, login e pagamento. Sempre ativos.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-chart-simple mt-0.5 w-4 text-center text-[#e2622a]" aria-hidden="true"></i>
                            <span><strong class="font-semibold">Medição e anúncios</strong>: só se você clicar em Aceito.</span>
                        </li>
                    </ul>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <button ref="primeiroBotao" type="button" :disabled="enviando"
                            class="botao rounded-2xl bg-[#1F2225] px-3 py-3.5 text-[15px] font-bold text-white hover:bg-[#33373b] disabled:opacity-60"
                            @click="somenteBasico">
                            Somente o básico
                        </button>
                        <button type="button" :disabled="enviando"
                            class="botao rounded-2xl bg-[#FD7B41] px-3 py-3.5 text-[15px] font-bold text-[#1F2225] hover:bg-[#ff8d58] disabled:opacity-60"
                            @click="aceitar">
                            Aceito
                        </button>
                    </div>

                    <p class="mt-4 text-center text-[12.5px] text-[#5d6267]">
                        Você pode mudar quando quiser na
                        <Link href="/politica-de-cookies" class="font-semibold text-[#1F2225] underline decoration-[#FD7B41] decoration-2 underline-offset-[3px] hover:text-[#b9501f]">Política de Cookies</Link>.
                    </p>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.cartao {
    animation: aviso-cookies-entra 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.15);
}
.botao {
    transition: background-color 0.15s, transform 0.1s;
}
.botao:active {
    transform: scale(0.98);
}
.botao:focus-visible {
    outline: 3px solid #FD7B41;
    outline-offset: 3px;
}
.aviso-cookies a:focus-visible {
    outline: 2px solid #FD7B41;
    outline-offset: 2px;
    border-radius: 4px;
}
@keyframes aviso-cookies-entra {
    from { transform: translateY(14px) scale(0.97); opacity: 0; }
    to { transform: none; opacity: 1; }
}
@media (prefers-reduced-motion: reduce) {
    .cartao { animation: none; }
}
</style>
