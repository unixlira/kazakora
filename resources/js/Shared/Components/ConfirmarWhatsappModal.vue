<script setup>
// "Confira seu WhatsApp" (pedido do Lira 2026-11-23): abre no checkout quando
// o celular tem DDD 55 e o CEP é de fora da área do DDD 55 (alertaWhatsapp.js).
// Simples, para leigos e bom no celular, nas cores do checkout. O campo já
// vem com o número do checkout (mesma máscara) e, se a pessoa digitou 55 +
// DDD + número, um botão sugere o número sem o 55. O que estiver no campo é
// o que vale: "Sim, está correto" segue com ele; "Corrigir" salva o número
// editado (sem edição, só leva o cursor para o campo).
import { maskPhone } from '@/Shared/useMasks';
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Número como está no campo do checkout (com máscara).
    atual: { type: String, default: '' },
    // Número sem o 55 do país (com máscara) ou vazio.
    sugestao: { type: String, default: '' },
});
// Os dois mandam o número que está no campo.
const emit = defineEmits(['confirmar', 'corrigir']);

const numero = ref('');
const campo = ref(null);
const valido = (valor) => String(valor ?? '').replace(/\D/g, '').length >= 10;
const mudou = () => numero.value.replace(/\D/g, '') !== String(props.atual ?? '').replace(/\D/g, '');
const usarSugestao = () => {
    numero.value = props.sugestao;
    campo.value?.focus();
};
const confirmar = () => {
    if (!valido(numero.value)) return campo.value?.focus();
    emit('confirmar', numero.value);
};
const corrigir = () => {
    if (!mudou()) {
        campo.value?.focus();
        campo.value?.select();
        return;
    }
    if (valido(numero.value)) emit('corrigir', numero.value);
};

watch(() => props.open, async (aberto) => {
    document.body.style.overflow = aberto ? 'hidden' : '';
    if (!aberto) return;
    numero.value = props.atual;
    await nextTick();
    campo.value?.focus();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[110] flex items-end justify-center p-3 sm:items-center sm:p-4 [color-scheme:light]"
            role="dialog" aria-modal="true" aria-labelledby="confira-whatsapp-titulo" aria-describedby="confira-whatsapp-texto" data-modal-whatsapp>
            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" aria-hidden="true"></div>
            <div class="relative w-full max-w-[420px] rounded-[24px] bg-white p-6 text-slate-900 shadow-xl">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#e7f6ee]">
                    <i class="fa-brands fa-whatsapp text-2xl text-[#00843f]" aria-hidden="true"></i>
                </div>
                <h2 id="confira-whatsapp-titulo" class="mt-3 text-center text-lg font-extrabold">Confira seu WhatsApp</h2>
                <p id="confira-whatsapp-texto" class="mt-2 text-center text-sm leading-relaxed text-slate-700">
                    O número do WhatsApp é importante para você receber as informações do pedido e o rastreio. Verifique se está correto.
                </p>

                <form class="mt-4" @submit.prevent="mudou() ? corrigir() : confirmar()">
                    <label class="block">
                        <span class="ml-4 text-xs font-semibold text-slate-600">WhatsApp/Celular com DDD</span>
                        <input ref="campo" :value="numero" type="tel" inputmode="numeric" autocomplete="tel" data-whatsapp-modal-campo
                            class="mt-1 w-full rounded-full border border-slate-300 bg-white px-5 py-3 text-center text-lg font-semibold tracking-wide text-slate-900 outline-none focus:border-[#00843f] focus:ring-2 focus:ring-emerald-100"
                            @input="numero = maskPhone($event.target.value)">
                    </label>
                    <div v-if="sugestao && sugestao !== numero" class="mt-3 rounded-2xl bg-amber-50 p-3 text-center text-xs text-amber-900">
                        Parece que o 55 do Brasil entrou como DDD. Seu número é este?
                        <button type="button" data-whatsapp-sugestao
                            class="mt-2 inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-amber-300 bg-white px-4 py-1.5 text-sm font-bold text-slate-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                            @click="usarSugestao">
                            <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>Usar {{ sugestao }}
                        </button>
                    </div>

                    <div class="mt-5 flex flex-col gap-2">
                        <button type="button" data-whatsapp-confirmar
                            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-[#00843f] px-6 py-3.5 text-base font-bold text-white transition hover:bg-[#006e34] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#00843f] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!valido(numero)" @click="confirmar">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>Sim, está correto
                        </button>
                        <button type="button" data-whatsapp-corrigir @click="corrigir"
                            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-full border-2 border-slate-300 bg-white px-6 py-3 text-base font-bold text-slate-800 transition hover:border-slate-400 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i>Corrigir
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
