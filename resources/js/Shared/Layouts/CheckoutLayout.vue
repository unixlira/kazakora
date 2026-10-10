<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
import { COMPANY } from '@/Shared/company.js';
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
        <!-- Rodapé do checkout (pedido 2026-10-10, modelo izeshop): selos de
             confiança bem visíveis + dados da empresa, sem links pra fora. -->
        <footer class="mt-auto border-t border-slate-200 bg-white px-4 py-8">
            <div class="mx-auto grid max-w-[900px] grid-cols-1 gap-3 sm:grid-cols-3">
                <div v-for="selo in [
                    { icone: 'fa-lock', titulo: 'Compra 100% segura', texto: 'Pagamento criptografado' },
                    { icone: 'fa-fingerprint', titulo: 'Dados protegidos', texto: 'Seus dados não são compartilhados' },
                    { icone: 'fa-truck-fast', titulo: 'Entrega garantida', texto: 'Ou seu dinheiro de volta' },
                ]" :key="selo.titulo" class="flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#0FB930] text-white">
                        <i class="fa-solid" :class="selo.icone"></i>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-slate-800">{{ selo.titulo }}</p>
                        <p class="text-xs text-slate-500">{{ selo.texto }}</p>
                    </div>
                </div>
            </div>

            <div class="mx-auto mt-8 flex max-w-[900px] flex-col items-center gap-4 text-center sm:flex-row sm:items-start sm:text-left">
                <span class="shrink-0 rounded-lg bg-[#6d28d9] px-4 py-2 font-display text-xl font-semibold text-white">{{ COMPANY.nomeFantasia }}</span>
                <div class="text-xs leading-relaxed text-slate-500">
                    <p class="font-semibold text-slate-700">© {{ new Date().getFullYear() }} {{ COMPANY.razaoSocial }}</p>
                    <p>CNPJ: {{ COMPANY.cnpj }}</p>
                    <p>WhatsApp: {{ COMPANY.whatsappDisplay }} · E-mail: {{ COMPANY.email }}</p>
                    <p>{{ COMPANY.enderecoCompleto }}</p>
                    <p class="mt-1"><i class="fa-solid fa-shield-halved mr-1"></i> Ambiente seguro · Pagamento processado pelo Mercado Pago</p>
                </div>
            </div>
        </footer>
    </div>
</template>
