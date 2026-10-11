<script setup>
// Rastrear pedido (pedido 2026-10-10): busca por número + e-mail/CPF ou link
// direto /rastreio/{ref} (o mesmo que vai no WhatsApp de pedido aprovado).
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    pedido: { type: Object, default: null },
});

const form = useForm({ pedido: '', documento: '' });
const buscar = () => form.post('/rastreio', { preserveScroll: true });

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value));

const ICONES = ['fa-receipt', 'fa-circle-check', 'fa-file-invoice', 'fa-truck-fast', 'fa-house-circle-check'];

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
</script>

<template>
    <Head :title="pedido ? `Pedido #${pedido.id}` : 'Rastrear pedido'" />

    <AppLayout>
        <div class="mx-auto max-w-[720px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">
                <i class="fa-solid fa-location-dot mr-2 text-store-accent"></i>Rastrear pedido
            </h1>

            <form v-if="!pedido" class="mt-8 flex flex-col gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-6" @submit.prevent="buscar">
                <p class="text-sm text-store-fg-muted">
                    Informe o número do pedido e o e-mail ou CPF usado na compra.
                </p>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Número do pedido
                    <input v-model="form.pedido" type="text" inputmode="numeric" placeholder="Ex.: 1234"
                        class="rounded-lg border border-store-border-strong bg-store-bg px-3 py-2.5 font-normal" />
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    E-mail ou CPF
                    <input v-model="form.documento" type="text" placeholder="voce@email.com ou 000.000.000-00"
                        class="rounded-lg border border-store-border-strong bg-store-bg px-3 py-2.5 font-normal" />
                </label>
                <p v-if="form.errors.pedido || form.errors.documento" class="text-sm text-red-600">
                    {{ form.errors.pedido || form.errors.documento }}
                </p>
                <button type="submit" :disabled="form.processing"
                    class="rounded-lg bg-store-accent px-4 py-3 font-semibold text-store-accent-contrast disabled:opacity-50">
                    <i class="fa-solid mr-1.5" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-magnifying-glass'"></i>
                    Rastrear
                </button>
            </form>

            <template v-else>
                <div class="mt-8 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-lg font-semibold">Pedido #{{ pedido.id }}</p>
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

                <div v-if="pedido.codigo_rastreio" class="mt-4 rounded-2xl border border-store-border bg-store-bg-raised p-6">
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
                <p v-else-if="!pedido.cancelado" class="mt-4 rounded-2xl border border-dashed border-store-border px-6 py-4 text-sm text-store-fg-muted">
                    <i class="fa-regular fa-clock mr-1"></i> O código de rastreio aparece aqui assim que o pedido for despachado.
                </p>

                <div class="mt-4 rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <p class="text-sm font-semibold">Itens</p>
                    <div class="mt-2 flex flex-col gap-1 text-sm text-store-fg-muted">
                        <span v-for="(item, index) in pedido.itens" :key="index">{{ item.nome }} × {{ item.quantidade }}</span>
                    </div>
                    <div class="mt-3 flex items-baseline justify-between border-t border-store-border pt-3 text-sm">
                        <span>Entrega em {{ pedido.cidade || '—' }}</span>
                        <span class="font-semibold text-store-accent">{{ formatPrice(pedido.total) }}</span>
                    </div>
                </div>

                <Link href="/rastreio" class="mt-6 inline-block text-sm font-medium text-store-accent hover:underline">
                    Rastrear outro pedido
                </Link>
            </template>
        </div>
    </AppLayout>
</template>
