<script setup>
// Rastrear pedido (pedido 2026-10-10): busca pelo número ou link direto
// /rastreio/{ref} (o mesmo que vai no WhatsApp de pedido aprovado). Só com
// login desde 2026-10-17: o dono do pedido ou um admin. Depois de 3 buscas
// com erro do mesmo IP, pede o código da imagem (CAPTCHA, 2026-10-22).
// Pix novo de 24 h (pedidos:novo-pix, 2026-10-23): com o Pix aberto, o link
// abre sem login e mostra o QR Code e o copia e cola para pagar.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    pedido: { type: Object, default: null },
    captcha: { type: Boolean, default: false },
});

// Cada imagem vale uma tentativa: se ela já estava na tela, troca depois de
// enviar. Na primeira vez que aparece não troca (seriam duas imagens e a
// sessão ficaria com o código da outra).
const captchaVersao = ref(Date.now());
const trocarCaptcha = () => { captchaVersao.value = Date.now(); };

const form = useForm({ pedido: '', captcha: '' });
const buscar = () => {
    const mostrava = props.captcha;
    form.post('/rastreio', {
        preserveScroll: true,
        onFinish: () => {
            form.captcha = '';
            if (mostrava) trocarCaptcha();
        },
    });
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value));

const ICONES = ['fa-receipt', 'fa-circle-check', 'fa-file-invoice', 'fa-box-open', 'fa-truck-fast', 'fa-house-circle-check'];

// Etapa atual = a primeira que ainda não aconteceu.
const etapaAtual = computed(() => props.pedido?.etapas.findIndex((etapa) => !etapa.feito) ?? -1);

const copiado = ref(false);
const copiarCodigo = async () => {
    try {
        await navigator.clipboard.writeText(props.pedido.codigo_rastreio);
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 2000);
    } catch {
        // sem permissão de área de transferência: o código continua visível na tela
    }
};

const pix = computed(() => props.pedido?.pix ?? null);
const pixCopiado = ref(false);
const copiarPix = async () => {
    try {
        await navigator.clipboard.writeText(pix.value.copia_e_cola);
        pixCopiado.value = true;
        setTimeout(() => { pixCopiado.value = false; }, 3000);
    } catch {
        // sem permissão: o código continua visível para copiar à mão
    }
};

// Confere o pagamento enquanto o Pix está na tela; pago ou vencido, recarrega.
let consulta = null;
const conferirPix = async () => {
    if (!pix.value?.status_url || document.hidden) return;
    try {
        const resposta = await fetch(pix.value.status_url, { headers: { Accept: 'application/json' } });
        if (!resposta.ok) return;
        const dados = await resposta.json();
        if (dados.pago || !dados.aberto) {
            clearInterval(consulta);
            router.reload();
        }
    } catch {
        // sem conexão: tenta na próxima
    }
};
onMounted(() => {
    if (pix.value) consulta = setInterval(conferirPix, 8000);
});
onBeforeUnmount(() => clearInterval(consulta));
</script>

<template>
    <Head :title="pedido ? `Pedido ${pedido.numero ?? pedido.id}` : 'Rastrear pedido'" />

    <AppLayout>
        <div class="mx-auto max-w-[720px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">
                <i class="fa-solid mr-2 text-store-accent" :class="pedido?.publico ? 'fa-receipt' : 'fa-location-dot'"></i>{{ pedido?.publico ? 'Pagamento do pedido' : 'Rastrear pedido' }}
            </h1>

            <form v-if="!pedido" class="mt-8 flex flex-col gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-6" @submit.prevent="buscar">
                <p class="text-sm text-store-fg-muted">
                    Informe o número do pedido.
                </p>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Número do pedido
                    <input v-model="form.pedido" type="text" autocapitalize="characters" placeholder="Ex.: 261011K7Q2M9XD"
                        class="rounded-lg border border-store-border-strong bg-store-bg px-3 py-2.5 font-normal" />
                </label>
                <p v-if="form.errors.pedido" class="text-sm text-red-600">
                    {{ form.errors.pedido }}
                </p>
                <div v-if="captcha" class="flex flex-col gap-2">
                    <p class="text-sm text-store-fg-muted">
                        Para continuar, digite os caracteres da imagem.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <img :src="`/rastreio/captcha?v=${captchaVersao}`" alt="Código de verificação" width="200" height="64"
                            class="rounded-lg border border-store-border" />
                        <button type="button" class="rounded-lg border border-store-border-strong px-3 py-1.5 text-sm" @click="trocarCaptcha">
                            <i class="fa-solid fa-rotate mr-1"></i> Trocar imagem
                        </button>
                    </div>
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        Código da imagem
                        <input v-model="form.captcha" type="text" autocomplete="off" autocapitalize="characters" maxlength="10"
                            class="rounded-lg border border-store-border-strong bg-store-bg px-3 py-2.5 font-normal uppercase" />
                    </label>
                    <p v-if="form.errors.captcha" class="text-sm text-red-600">
                        {{ form.errors.captcha }}
                    </p>
                </div>
                <button type="submit" :disabled="form.processing"
                    class="rounded-lg bg-store-accent px-4 py-3 font-semibold text-store-accent-contrast disabled:opacity-50">
                    <i class="fa-solid mr-1.5" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-magnifying-glass'"></i>
                    Rastrear
                </button>
            </form>

            <template v-else>
                <section v-if="pix" class="mt-8 rounded-2xl border-2 border-emerald-500 bg-store-bg-raised p-6" aria-labelledby="pix-titulo">
                    <h2 id="pix-titulo" class="text-lg font-semibold">
                        <i class="fa-brands fa-pix mr-1.5 text-emerald-600"></i>Pague com Pix para confirmar o pedido
                    </h2>
                    <p class="mt-1 text-sm text-store-fg-muted">Pedido {{ pedido.numero ?? pedido.id }}</p>
                    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-2xl font-bold text-store-accent">{{ formatPrice(pix.valor) }}</p>
                        <p v-if="pix.vencimento" class="text-sm text-store-fg-muted">
                            Vale até {{ formatDate(pix.vencimento) }}
                        </p>
                    </div>
                    <div class="mt-4 flex flex-col items-center gap-4 md:flex-row md:items-start">
                        <img v-if="pix.qr_base64" :src="`data:image/png;base64,${pix.qr_base64}`" alt="QR Code do Pix"
                            width="200" height="200" class="h-[200px] w-[200px] rounded-lg border border-store-border bg-white p-2" />
                        <div class="flex w-full min-w-0 flex-col gap-3">
                            <p class="text-sm text-store-fg-muted">
                                Abra o app do seu banco, escolha Pix e leia o QR Code ou use o código copia e cola.
                            </p>
                            <label for="pix-copia-e-cola" class="text-xs font-medium text-store-fg-muted">Código Pix copia e cola</label>
                            <textarea id="pix-copia-e-cola" readonly rows="3" :value="pix.copia_e_cola"
                                class="w-full resize-none break-all rounded-lg border border-store-border bg-store-bg px-3 py-2 font-store-mono text-xs"
                                @focus="$event.target.select()"></textarea>
                            <button type="button" class="rounded-lg bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-700" @click="copiarPix">
                                <i class="fa-regular mr-1.5" :class="pixCopiado ? 'fa-circle-check' : 'fa-copy'"></i>
                                {{ pixCopiado ? 'Código copiado! Cole no app do banco' : 'Copiar código Pix' }}
                            </button>
                            <p class="text-xs text-store-fg-muted" aria-live="polite">
                                <i class="fa-solid fa-spinner mr-1 animate-spin"></i> Assim que o pagamento cair, esta página confirma sozinha.
                            </p>
                        </div>
                    </div>
                </section>

                <div v-if="!pedido.publico" class="mt-8 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-lg font-semibold">Pedido {{ pedido.numero ?? pedido.id }}</p>
                        <p class="text-xs text-store-fg-muted">{{ formatDate(pedido.criado_em) }}</p>
                    </div>

                    <p v-if="pedido.cancelado" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                        <i class="fa-solid fa-circle-xmark mr-1"></i> Este pedido foi cancelado.
                    </p>

                    <ol v-else class="mt-6 flex flex-col">
                        <li v-for="(etapa, index) in pedido.etapas" :key="etapa.titulo" class="relative flex gap-4 pb-6 last:pb-0">
                            <span v-if="index < pedido.etapas.length - 1" class="absolute left-[17px] top-9 h-[calc(100%-36px)] w-0.5"
                                :class="etapa.feito ? 'bg-emerald-500' : 'bg-store-border'"></span>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm"
                                :class="etapa.feito ? 'bg-emerald-500 text-white' : (index === etapaAtual ? 'border-2 border-emerald-500 text-emerald-600' : 'border border-store-border text-store-fg-faint')">
                                <i class="fa-solid" :class="ICONES[index]"></i>
                            </span>
                            <div class="pt-1.5">
                                <p class="font-medium" :class="etapa.feito || index === etapaAtual ? '' : 'text-store-fg-faint'">{{ etapa.titulo }}</p>
                                <p v-if="index === etapaAtual" class="text-xs text-emerald-600">Próxima etapa</p>
                            </div>
                        </li>
                    </ol>
                </div>

                <div v-if="pedido.codigo_rastreio && !pedido.publico" class="mt-4 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <p class="text-sm text-store-fg-muted">
                        Código de rastreio<span v-if="pedido.transportadora"> · {{ pedido.transportadora }}</span>
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <span class="font-store-mono text-lg font-semibold tracking-wider">{{ pedido.codigo_rastreio }}</span>
                        <button type="button" class="rounded-lg border border-store-border-strong px-3 py-1.5 text-sm" @click="copiarCodigo">
                            <i class="fa-regular mr-1" :class="copiado ? 'fa-circle-check' : 'fa-copy'"></i>{{ copiado ? 'Copiado' : 'Copiar' }}
                        </button>
                        <a :href="`https://rastreamento.correios.com.br/app/index.php?objetos=${pedido.codigo_rastreio}`" target="_blank" rel="noopener"
                            class="rounded-lg bg-store-accent px-3 py-1.5 text-sm font-semibold text-store-accent-contrast">
                            <i class="fa-solid fa-truck-fast mr-1"></i> Ver nos Correios
                        </a>
                    </div>
                </div>
                <p v-else-if="!pedido.cancelado && !pedido.publico && !pix" class="mt-4 rounded-2xl border border-dashed border-store-border px-6 py-4 text-sm text-store-fg-muted">
                    <i class="fa-regular fa-clock mr-1"></i> O código de rastreio aparece aqui assim que o pedido for despachado.
                </p>

                <div class="mt-4 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <p class="text-sm font-semibold">Itens</p>
                    <div class="mt-2 flex flex-col gap-1 text-sm text-store-fg-muted">
                        <span v-for="(item, index) in pedido.itens" :key="index">{{ item.nome }} × {{ item.quantidade }}</span>
                    </div>
                    <div class="mt-3 flex items-baseline justify-between border-t border-store-border pt-3 text-sm">
                        <span>{{ pedido.publico ? 'Total do pedido' : `Entrega em ${pedido.cidade || '—'}` }}</span>
                        <span class="font-semibold text-store-accent">{{ formatPrice(pedido.total) }}</span>
                    </div>
                </div>

                <Link v-if="!pedido.publico" href="/rastreio" class="mt-6 inline-block text-sm font-medium text-store-accent hover:underline">
                    Rastrear outro pedido
                </Link>
            </template>
        </div>
    </AppLayout>
</template>
