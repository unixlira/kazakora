<script setup>
// Layout do checkout v2 (pedido 2026-10-10): sem topo, menu e rodapé da
// loja — só a faixa de segurança e o conteúdo, pra o cliente fechar a compra
// sem distração. Sempre claro (as cores do checkout são fixas).
import { COMPANY } from '@/Shared/company.js';
import AvisoCookies from '@/Shared/Components/AvisoCookies.vue';
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
        <!-- Mercado Pago em modo teste (sandbox): nada é cobrado de verdade. -->
        <div v-if="$page.props.pagamentoTeste" class="bg-yellow-300 px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-yellow-950">
            <i class="fa-solid fa-flask mr-1"></i>Modo teste do Mercado Pago — nenhum valor é cobrado
        </div>
        <!-- Tarjas numa linha só também no celular (pedido 2026-10-10): letra menor lá. -->
        <div class="flex flex-nowrap items-center justify-center gap-1.5 whitespace-nowrap bg-[#E02424] px-2 py-2 text-center text-[11px] font-bold uppercase tracking-tight text-white sm:gap-2 sm:px-4 sm:py-2.5 sm:text-base sm:tracking-wide md:text-lg">
            <i class="fa-solid fa-stopwatch" :class="{ 'animate-pulse': restante > 0 }"></i>
            <span>Frete grátis <span class="selo-full selo-full--claro"><i class="fa-solid fa-bolt"></i>FULL</span> por tempo limitado:</span>
            <span class="inline-block rounded-md bg-white/20 px-1.5 py-0.5 font-mono text-sm tabular-nums sm:min-w-[4.5rem] sm:px-2 sm:text-xl md:text-2xl">{{ tempo }}</span>
        </div>
        <div class="flex flex-nowrap items-center justify-center gap-1.5 whitespace-nowrap bg-[#0FB930] px-2 py-2 text-center text-[11px] font-semibold tracking-tight text-white sm:gap-2 sm:px-4 sm:text-sm sm:tracking-wide">
            <span><i class="fa-solid fa-lock mr-1"></i>COMPRA 100% SEGURA</span>
            <span class="opacity-60">·</span>
            <span><i class="fa-solid fa-fingerprint mr-1"></i>DADOS PROTEGIDOS</span>
            <!-- Celular estreito: só os dois primeiros, pra caber numa linha. -->
            <span class="opacity-60 max-[429px]:hidden">·</span>
            <span class="max-[429px]:hidden"><i class="fa-solid fa-box-open mr-1"></i>ENTREGA GARANTIDA</span>
        </div>
        <slot />
        <AvisoCookies />
        <!-- Rodapé do checkout (pedido 2026-10-12): todo preto com fonte branca.
             Grade de 12 colunas no desktop, 4/4/4: logo e dados da empresa,
             formas de pagamento e segurança (só os selos que a loja tem de
             verdade). No celular as três empilham. Embaixo, linha centralizada
             com os selos de confiança. Só vale para o checkout; o rodapé da loja
             fica no AppLayout. -->
        <footer class="mt-auto bg-black text-white" aria-labelledby="rodape-checkout-titulo">
            <h2 id="rodape-checkout-titulo" class="sr-only">Informações da loja, pagamento e segurança</h2>
            <div class="mx-auto grid max-w-[1160px] grid-cols-12 gap-y-10 px-4 py-10 text-center md:gap-x-8 md:text-left">
                <!-- Coluna 1: logo (versão clara, para fundo escuro), nome e CNPJ. Sem
                     razão social, endereço e contato (pedido 2026-10-12, simetria). -->
                <div class="col-span-12 md:col-span-4">
                    <img :src="$page.props.marca?.logoRodape ?? '/images/marca/logo-rodape.png'" :alt="COMPANY.nomeFantasia"
                        width="160" height="32" class="mx-auto h-8 w-auto md:mx-0">
                    <address class="mt-4 text-xs not-italic leading-relaxed text-white">
                        © {{ new Date().getFullYear() }} {{ COMPANY.nomeFantasia }} | Grupo AlphaKora<br>
                        CNPJ: {{ COMPANY.cnpj }}
                    </address>
                </div>

                <!-- Coluna 2: formas de pagamento (mesmas bandeiras do checkout). As seis
                     numa linha só (pedido 2026-10-12): encolhem juntas com a coluna em
                     vez de quebrar. -->
                <div class="col-span-12 md:col-span-4">
                    <h3 class="text-sm font-semibold text-white">Formas de pagamento</h3>
                    <ul class="mx-auto mt-3 flex w-full max-w-[360px] flex-nowrap items-center gap-1.5 md:mx-0" aria-label="Bandeiras aceitas">
                        <li v-for="bandeira in BANDEIRAS" :key="bandeira" class="min-w-0 flex-1">
                            <img :src="`/images/payments/cartao-hd/${bandeira}.png`" :alt="bandeira" width="265" height="180" class="h-auto w-full">
                        </li>
                    </ul>
                </div>

                <!-- Coluna 3: segurança. Mesmos selos do rodapé da loja (Google Safe
                     Browsing e Reclame AQUI); nada de selo que a loja não tem. -->
                <div class="col-span-12 md:col-span-4">
                    <h3 class="text-sm font-semibold text-white">Segurança</h3>
                    <div class="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                        <a href="https://transparencyreport.google.com/safe-browsing/search?url=kazakora.com" target="_blank" rel="noopener"
                            class="flex h-[51px] items-center justify-center overflow-hidden rounded-xl bg-white px-3 transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            title="Ver a verificação do Google Safe Browsing">
                            <img src="/images/selos/selo-google-safe.png" alt="Google Safe Browsing" width="139" height="45" class="h-[40px] w-auto">
                        </a>
                        <a :href="$page.props.reclameAqui?.url || 'https://www.reclameaqui.com.br/busca/?q=kazakora'" target="_blank" rel="noopener"
                            class="flex h-[51px] flex-col items-center justify-center overflow-hidden rounded-xl bg-white leading-none transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="$page.props.reclameAqui?.verificada ? 'px-2' : 'px-4'"
                            title="Ver a KazaKora no Reclame AQUI">
                            <img v-if="$page.props.reclameAqui?.verificada" src="/images/selos/reclame-aqui-verificada-qrcode.png" alt="Verificada por Reclame AQUI, com QR Code de verificação" width="294" height="88"
                                class="h-[42px] w-auto rounded-md">
                            <template v-else>
                                <span class="text-[9px] font-semibold uppercase tracking-wider text-slate-600">Estamos no</span>
                                <span class="mt-0.5 text-[20px] font-black tracking-tight text-[#1a1a1a]">Reclame <span class="text-[#4caf3c]">AQUI</span></span>
                            </template>
                        </a>
                    </div>
                    <p class="mt-3 text-xs text-white"><i class="fa-solid fa-lock mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Conexão criptografada (HTTPS/SSL)</p>
                </div>
            </div>

            <!-- Linha 2: selos de confiança, centralizados. -->
            <div class="border-t border-white/15 px-4 py-5">
                <ul class="mx-auto flex max-w-[1160px] flex-row flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs font-medium text-white sm:text-sm">
                    <li class="whitespace-nowrap"><i class="fa-solid fa-lock mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Compra segura</li>
                    <li class="whitespace-nowrap"><i class="fa-solid fa-fingerprint mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Dados protegidos</li>
                    <li class="whitespace-nowrap"><i class="fa-solid fa-box-open mr-1.5 text-[#0FB930]" aria-hidden="true"></i>Entrega garantida</li>
                </ul>
            </div>
        </footer>
    </div>
</template>
