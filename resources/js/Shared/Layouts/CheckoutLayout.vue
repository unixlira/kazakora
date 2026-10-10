<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Faixa vermelha de escassez: 15 minutos regressivos. Ao acabar fica em
// 00:00; atualizar a página recomeça dos 15 (pedido do Lira, sem guardar).
const restante = ref(15 * 60);
let relogio = null;

onMounted(() => {
    const fim = Date.now() + restante.value * 1000;
    relogio = setInterval(() => {
        restante.value = Math.max(0, Math.round((fim - Date.now()) / 1000));
        if (restante.value === 0) clearInterval(relogio);
    }, 1000);
});
onBeforeUnmount(() => clearInterval(relogio));

const tempo = computed(() => {
    const minutos = String(Math.floor(restante.value / 60)).padStart(2, '0');
    const segundos = String(restante.value % 60).padStart(2, '0');

    return `${minutos}:${segundos}`;
});
</script>

<template>
    <div class="min-h-screen bg-[#FAFAFA] font-store text-slate-800 [color-scheme:light]">
        <div class="bg-[#E02424] px-4 py-2 text-center text-sm font-semibold text-white">
            <i class="fa-solid fa-stopwatch mr-1.5" :class="{ 'animate-pulse': restante > 0 }"></i>
            Seu pedido está reservado por
            <span class="ml-1 inline-block min-w-[3.25rem] rounded bg-white/20 px-1.5 py-0.5 font-mono tabular-nums">{{ tempo }}</span>
        </div>
        <div class="bg-[#0FB930] px-4 py-2 text-center text-sm font-semibold tracking-wide text-white">
            <i class="fa-solid fa-lock mr-1.5"></i> COMPRA 100% SEGURA
            <span class="mx-2 opacity-60">·</span>
            <i class="fa-solid fa-fingerprint mr-1.5"></i> DADOS PROTEGIDOS
            <span class="mx-2 hidden opacity-60 sm:inline">·</span>
            <span class="hidden sm:inline"><i class="fa-solid fa-box-open mr-1.5"></i> ENTREGA GARANTIDA</span>
        </div>
        <slot />
        <p class="px-4 pb-8 pt-2 text-center text-xs text-slate-400">
            <i class="fa-solid fa-shield-halved mr-1"></i> Ambiente seguro · Pagamento processado pelo Mercado Pago
        </p>
    </div>
</template>
