<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
import { COMPANY } from '@/Shared/company.js';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Faixa vermelha de escassez: 15 minutos regressivos. Ao acabar fica em
// 00:00; atualizar a página recomeça dos 15 (pedido do Lira, sem guardar).
const BANDEIRAS = ['pix', 'visa', 'mastercard', 'elo', 'amex', 'diners'];

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
    <div class="flex min-h-screen flex-col bg-[#FAFAFA] font-store text-slate-800 [color-scheme:light]">
        <div class="flex flex-wrap items-center justify-center gap-2 bg-[#E02424] px-4 py-2.5 text-center text-base font-bold uppercase tracking-wide text-white md:text-lg">
            <i class="fa-solid fa-stopwatch" :class="{ 'animate-pulse': restante > 0 }"></i>
            Frete grátis por tempo limitado:
            <span class="inline-block min-w-[4.5rem] rounded-md bg-white/20 px-2 py-0.5 font-mono text-xl tabular-nums md:text-2xl">{{ tempo }}</span>
        </div>
        <div class="bg-[#0FB930] px-4 py-2 text-center text-sm font-semibold tracking-wide text-white">
            <i class="fa-solid fa-lock mr-1.5"></i> COMPRA 100% SEGURA
            <span class="mx-2 opacity-60">·</span>
            <i class="fa-solid fa-fingerprint mr-1.5"></i> DADOS PROTEGIDOS
            <span class="mx-2 hidden opacity-60 sm:inline">·</span>
            <span class="hidden sm:inline"><i class="fa-solid fa-box-open mr-1.5"></i> ENTREGA GARANTIDA</span>
        </div>
        <slot />
        <!-- Rodapé do checkout (pedido 2026-10-10, modelo izeshop): formas de
             pagamento + dados da empresa, selo do Google à direita e a faixa
             preta com a marca e os selos de confiança. Sem links pra fora. -->
        <footer class="mt-auto">
            <div class="border-t border-slate-200 bg-white px-4 py-8">
                <div class="mx-auto flex max-w-[1160px] flex-col items-center gap-6 text-center md:flex-row md:items-center md:justify-between md:text-left">
                    <div>
                        <p class="text-sm font-semibold text-slate-700">Formas de pagamento:</p>
                        <div class="mt-2 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                            <img v-for="bandeira in BANDEIRAS" :key="bandeira" :src="`/images/payments/${bandeira}@2x.png`" :alt="bandeira"
                                class="h-9 w-auto rounded-md border border-slate-200 bg-white p-1.5">
                        </div>
                        <p class="mt-4 text-xs leading-relaxed text-slate-500">
                            © {{ new Date().getFullYear() }} {{ COMPANY.razaoSocial }}<br>
                            CNPJ: {{ COMPANY.cnpj }}<br>
                            WhatsApp: {{ COMPANY.whatsappDisplay }}<br>
                            E-mail: {{ COMPANY.email }}<br>
                            {{ COMPANY.enderecoCompleto }}
                        </p>
                    </div>
                    <img src="/images/payments/google.png" alt="Google Safe Browsing — site verificado"
                        class="h-24 w-auto shrink-0 rounded-md bg-white p-2 md:h-28">
                </div>
            </div>

            <div class="bg-black px-4 py-5 text-white">
                <div class="mx-auto flex max-w-[1160px] flex-col items-center gap-4 md:flex-row md:justify-between">
                    <span class="font-display text-2xl font-semibold">{{ COMPANY.nomeFantasia }}</span>
                    <ul class="flex flex-col items-center gap-2 text-sm font-medium md:flex-row md:gap-6">
                        <li><i class="fa-solid fa-lock mr-2"></i>Compra Segura</li>
                        <li><i class="fa-solid fa-fingerprint mr-2"></i>Dados Protegidos</li>
                        <li><i class="fa-solid fa-box-open mr-2"></i>Entrega Garantida</li>
                    </ul>
                </div>
            </div>
        </footer>
    </div>
</template>
