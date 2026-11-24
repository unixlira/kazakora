<script setup>
// Prévia do cartão no checkout (pedido 2026-11-24: "cartão black", bandeira
// certa e sem deixar pesado). Só CSS e SVG inline, sem imagem nem fonte nova.
// O Mercado Pago libera só o BIN (6 a 8 primeiros dígitos) pelo onBinChange;
// o resto do número e o nome ficam no campo seguro dele, por isso o resto
// aparece mascarado e o nome é fixo.
import { computed } from 'vue';

const props = defineProps({
    bin: { type: String, default: '' },
});

// Ordem importa: Elo, Hiper, Hipercard e Cabal usam faixas que começam com
// 4, 5 ou 6 e precisam ser testadas antes de Visa e Mastercard.
const BANDEIRAS_BIN = [
    ['elo', /^(4011(78|79)|43(1274|8935)|45(1416|7393|763(1|2))|50(4175|6699|67[0-7][0-9]|9000)|627780|63(6297|6368)|650(03([^4])|04([0-9])|05(0|1)|4(0[5-9]|3[0-9]|8[5-9]|9[0-9])|5([0-2][0-9]|3[0-8])|9([2-6][0-9]|7[0-8])|541|700|720|901)|651652|655000|655021)/],
    ['hiper', /^(637095|63737423|63743358|637568|637599|637609|637612)/],
    ['hipercard', /^(606282|3841(00|40|60))/],
    ['cabal', /^(60420[1-9]|6042[1-9][0-9]|6043[0-9]{2}|604400|589657|627170)/],
    ['amex', /^3[47]/],
    ['diners', /^3(0[0-5]|095|[689])/],
    ['visa', /^4/],
    ['mastercard', /^(5[1-5]|2(22[1-9]|2[3-9][0-9]|[3-6][0-9]{2}|7[01][0-9]|720))/],
];

// Cor do brilho do cartão para cada bandeira (transição suave entre elas).
const BRILHO = {
    visa: '#1a1f71',
    mastercard: '#eb001b',
    elo: '#ffcb05',
    amex: '#2e77bc',
    hipercard: '#b3131b',
    hiper: '#f37021',
    diners: '#0079be',
    cabal: '#00559f',
};

const NOMES = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    elo: 'Elo',
    amex: 'American Express',
    hipercard: 'Hipercard',
    hiper: 'Hiper',
    diners: 'Diners Club',
    cabal: 'Cabal',
};

const digitos = computed(() => String(props.bin ?? '').replace(/\D/g, '').slice(0, 6));
const bandeira = computed(() => (digitos.value.length ? BANDEIRAS_BIN.find(([, regra]) => regra.test(digitos.value))?.[0] ?? null : null));
const brilho = computed(() => BRILHO[bandeira.value] ?? '#3f3f46');

// 6 primeiros dígitos e o resto mascarado. Amex tem 15 dígitos (4-6-5).
const numero = computed(() => {
    const amex = bandeira.value === 'amex';
    const total = amex ? 15 : 16;
    const cheio = digitos.value.padEnd(6, '•').padEnd(total, '•');
    const grupos = amex ? [4, 6, 5] : [4, 4, 4, 4];
    let i = 0;
    return grupos.map((n) => cheio.slice(i, (i += n))).join(' ');
});
</script>

<template>
    <div
        class="cartao-previa relative mx-auto mb-4 aspect-[85.6/54] w-full max-w-[320px] overflow-hidden rounded-2xl p-[6%] text-white shadow-xl ring-1 ring-white/10"
        :style="{ '--brilho': brilho }"
        role="img"
        :aria-label="bandeira ? `Prévia do cartão ${NOMES[bandeira]}` : 'Prévia do cartão'"
    >
        <span class="cartao-previa__brilho" aria-hidden="true"></span>

        <div class="relative flex h-full flex-col justify-between" aria-hidden="true">
            <div class="flex items-start justify-between">
                <!-- Chip -->
                <svg viewBox="0 0 40 30" class="h-auto w-[15%]">
                    <defs>
                        <linearGradient id="cartao-chip" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#f5e3a3" />
                            <stop offset="0.5" stop-color="#d4af5a" />
                            <stop offset="1" stop-color="#a8832e" />
                        </linearGradient>
                    </defs>
                    <rect x="0.5" y="0.5" width="39" height="29" rx="5" fill="url(#cartao-chip)" stroke="#8a6a24" stroke-width="0.6" />
                    <path d="M0.5 10H13M0.5 20H13M27 10H39.5M27 20H39.5M13 0.5V29.5M27 0.5V29.5M13 15H27" fill="none" stroke="#8a6a24" stroke-width="0.8" opacity="0.75" />
                </svg>

                <!-- Bandeira -->
                <Transition name="cartao-bandeira" mode="out-in">
                    <svg v-if="bandeira === 'visa'" key="visa" viewBox="0 0 60 20" class="h-[1.35rem] w-auto">
                        <text x="30" y="16.5" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="19" font-weight="900" font-style="italic" letter-spacing="0.5">VISA</text>
                    </svg>
                    <svg v-else-if="bandeira === 'mastercard'" key="mastercard" viewBox="0 0 44 28" class="h-7 w-auto">
                        <circle cx="15" cy="14" r="12" fill="#eb001b" />
                        <circle cx="29" cy="14" r="12" fill="#f79e1b" />
                        <path d="M22 4.25a12 12 0 0 1 0 19.5a12 12 0 0 1 0-19.5Z" fill="#ff5f00" />
                    </svg>
                    <svg v-else-if="bandeira === 'elo'" key="elo" viewBox="0 0 52 24" class="h-6 w-auto">
                        <circle cx="6" cy="6" r="3.2" fill="#ffcb05" />
                        <circle cx="6" cy="12" r="3.2" fill="#00a4e0" />
                        <circle cx="6" cy="18" r="3.2" fill="#ef4123" />
                        <text x="32" y="18" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="19" font-weight="700">elo</text>
                    </svg>
                    <svg v-else-if="bandeira === 'amex'" key="amex" viewBox="0 0 50 22" class="h-6 w-auto">
                        <rect width="50" height="22" rx="3" fill="#2e77bc" />
                        <text x="25" y="15.5" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="11" font-weight="900" letter-spacing="0.6">AMEX</text>
                    </svg>
                    <svg v-else-if="bandeira === 'hipercard'" key="hipercard" viewBox="0 0 74 22" class="h-6 w-auto">
                        <rect width="74" height="22" rx="4" fill="#b3131b" />
                        <text x="37" y="15.5" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="12" font-weight="700" font-style="italic">Hipercard</text>
                    </svg>
                    <svg v-else-if="bandeira === 'hiper'" key="hiper" viewBox="0 0 50 22" class="h-6 w-auto">
                        <rect width="50" height="22" rx="11" fill="#f37021" />
                        <text x="25" y="15.5" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="12" font-weight="800">Hiper</text>
                    </svg>
                    <svg v-else-if="bandeira === 'diners'" key="diners" viewBox="0 0 36 28" class="h-7 w-auto">
                        <circle cx="18" cy="14" r="13" fill="#0079be" />
                        <circle cx="18" cy="14" r="9" fill="none" stroke="#fff" stroke-width="2" />
                        <path d="M14.5 7.5v13M21.5 7.5v13" stroke="#fff" stroke-width="2" />
                    </svg>
                    <svg v-else-if="bandeira === 'cabal'" key="cabal" viewBox="0 0 54 22" class="h-6 w-auto">
                        <rect width="54" height="22" rx="4" fill="#00559f" />
                        <text x="27" y="15.5" text-anchor="middle" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="11" font-weight="900" letter-spacing="0.8">CABAL</text>
                    </svg>
                    <!-- Bandeira ainda não reconhecida: ícone genérico. -->
                    <svg v-else key="generico" viewBox="0 0 34 24" class="h-6 w-auto opacity-60">
                        <rect x="1" y="1" width="32" height="22" rx="4" fill="none" stroke="#fff" stroke-width="1.6" />
                        <path d="M1 8h32" stroke="#fff" stroke-width="3" />
                        <path d="M6 17h9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </Transition>
            </div>

            <p class="font-mono text-[clamp(0.95rem,5.2vw,1.2rem)] tracking-[0.12em] tabular-nums text-white/95 [text-shadow:0_1px_1px_rgba(0,0,0,.5)]">{{ numero }}</p>

            <div class="flex items-end justify-between gap-3 text-[0.62rem] uppercase tracking-[0.14em] text-white/60">
                <div class="min-w-0">
                    <span class="block text-[0.5rem] text-white/40">Titular</span>
                    <span class="block truncate text-[0.72rem] font-semibold text-white/85">Nome do titular</span>
                </div>
                <div class="shrink-0 text-right">
                    <span class="block text-[0.5rem] text-white/40">Validade</span>
                    <span class="block font-mono text-[0.72rem] font-semibold text-white/85">••/••</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cartao-previa {
    background:
        radial-gradient(120% 90% at 100% 0%, rgba(255, 255, 255, 0.09), transparent 55%),
        linear-gradient(135deg, #2b2b30 0%, #121214 48%, #050506 100%);
}

/* Brilho da bandeira no canto: só opacidade e cor mudam (barato de pintar). */
.cartao-previa__brilho {
    position: absolute;
    right: -25%;
    bottom: -45%;
    width: 80%;
    aspect-ratio: 1;
    border-radius: 9999px;
    background-color: var(--brilho);
    opacity: 0.28;
    filter: blur(36px);
    transition: background-color 0.4s ease;
    pointer-events: none;
}

.cartao-bandeira-enter-active,
.cartao-bandeira-leave-active {
    transition: opacity 0.18s ease;
}

.cartao-bandeira-enter-from,
.cartao-bandeira-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .cartao-previa__brilho,
    .cartao-bandeira-enter-active,
    .cartao-bandeira-leave-active {
        transition: none;
    }
}
</style>
