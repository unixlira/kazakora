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

// Selos de segurança com a mesma altura das bandeiras (pedido 2026-10-12).
// As bandeiras encolhem com a coluna, então a altura é medida na tela.
const bandeiras = ref(null);
const alturaSelo = ref(37);
let medidor = null;

onMounted(() => {
    medidor = new ResizeObserver(([entrada]) => {
        alturaSelo.value = entrada.contentRect.height || alturaSelo.value;
    });
    medidor.observe(bandeiras.value);
});
onBeforeUnmount(() => medidor?.disconnect());

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
        <!-- Rodapé do checkout (pedido 2026-10-10, modelo izeshop; preto com fonte
             branca desde 2026-10-12). Grade 4-4-4 (pedido 2026-10-12): logo, nome e
             CNPJ (sem razão social, endereço e contato) | formas de pagamento | selos
             de segurança. As colunas se alinham pela base, para a linha do CNPJ
             ficar na altura da parte de baixo dos selos; embaixo, uma linha
             divisória e os quatro itens de confiança centralizados numa linha só.
             No celular, uma coluna embaixo da outra, centralizadas. -->
        <footer class="mt-auto bg-black px-4 py-10 text-white">
            <div class="mx-auto grid max-w-[1160px] grid-cols-1 gap-10 text-center md:grid-cols-12 md:items-end md:gap-8">
                <div class="flex flex-col items-center justify-center md:col-span-4">
                    <img :src="$page.props.marca?.logoRodape || '/images/marca/logo-rodape.png'" alt="KazaKora" width="160" height="28" class="h-8 w-auto">
                    <p class="mt-4 text-xs leading-relaxed text-white/80 md:-mb-1">
                        © {{ new Date().getFullYear() }} Kazakora | Grupo AlphaKora<br>
                        CNPJ: {{ COMPANY.cnpj }}
                    </p>
                </div>

                <div class="flex flex-col items-center justify-center md:col-span-4">
                    <p class="text-sm font-semibold text-white">Formas de pagamento</p>
                    <!-- As seis bandeiras numa linha só (pedido 2026-10-12): encolhem
                         juntas com a coluna em vez de quebrar. -->
                    <div ref="bandeiras" class="mt-3 flex w-full max-w-[360px] flex-nowrap items-center gap-1.5">
                        <img v-for="bandeira in BANDEIRAS" :key="bandeira" :src="`/images/payments/cartao-hd/${bandeira}.png`" :alt="bandeira"
                            width="265" height="180" class="h-auto w-full min-w-0 flex-1">
                    </div>
                </div>

                <!-- Mesmos selos do rodapé da loja (pedido 2026-10-12, no lugar da
                     imagem "Site 100% Seguro"). -->
                <div class="flex flex-col items-center justify-center md:col-span-4">
                    <p class="text-sm font-semibold text-white">Segurança</p>
                    <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                        <a href="https://transparencyreport.google.com/safe-browsing/search?url=kazakora.com" target="_blank" rel="noopener"
                            class="box-border flex items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-white px-2 transition hover:opacity-90"
                            :style="{ height: `${alturaSelo}px` }"
                            title="Ver a verificação do Google Safe Browsing">
                            <img src="/images/selos/selo-google-safe.png" alt="Google Safe Browsing" width="139" height="45" class="h-[80%] w-auto">
                        </a>
                        <a :href="$page.props.reclameAqui?.url || 'https://www.reclameaqui.com.br/busca/?q=kazakora'" target="_blank" rel="noopener"
                            class="box-border flex flex-col items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-white leading-none transition hover:opacity-90"
                            :class="$page.props.reclameAqui?.verificada ? 'px-1' : 'px-2'"
                            :style="{ height: `${alturaSelo}px` }"
                            title="Ver a KazaKora no Reclame AQUI">
                            <img v-if="$page.props.reclameAqui?.verificada" src="/images/selos/reclame-aqui-verificada-qrcode.png" alt="Verificada por Reclame AQUI, com QR Code de verificação" width="294" height="88"
                                class="h-[86%] w-auto rounded-sm">
                            <template v-else>
                                <span class="text-[7px] font-semibold uppercase tracking-wider text-slate-500">Estamos no</span>
                                <span class="text-[14px] font-black tracking-tight text-[#1a1a1a]">Reclame <span class="text-[#4caf3c]">AQUI</span></span>
                            </template>
                        </a>
                    </div>
                </div>
            </div>

            <hr class="mx-auto mt-8 max-w-[1160px] border-white/15">
            <ul class="mx-auto mt-6 flex max-w-[1160px] flex-wrap items-center justify-center gap-x-8 gap-y-2 text-sm font-medium">
                <li class="font-bold"><i class="fa-solid fa-shield-halved mr-2 text-[#0FB930]"></i>Criptografia SSL</li>
                <li><i class="fa-solid fa-bag-shopping mr-2 text-[#0FB930]"></i>Compra segura</li>
                <li><i class="fa-solid fa-fingerprint mr-2 text-[#0FB930]"></i>Dados protegidos</li>
                <li><i class="fa-solid fa-box-open mr-2 text-[#0FB930]"></i>Entrega gratuita</li>
            </ul>
        </footer>
    </div>
</template>
