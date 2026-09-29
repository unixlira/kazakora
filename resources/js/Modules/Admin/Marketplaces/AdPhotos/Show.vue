<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    brief: {
        type: Object,
        required: true,
    },
    generationStatus: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const copied = ref(false);
const now = ref(Date.now());
let timer = null;
let poller = null;

const isRunning = computed(() => ['queued', 'generating'].includes(props.brief.status));
const progressPercent = computed(() => props.brief.expectedImages ? Math.round((props.brief.generatedCount / props.brief.expectedImages) * 100) : 0);
const startedAtMs = computed(() => props.brief.generationStartedAt ? new Date(props.brief.generationStartedAt).getTime() : null);
const dueAtMs = computed(() => props.brief.dueAt ? new Date(props.brief.dueAt).getTime() : null);
const elapsedLabel = computed(() => {
    if (!startedAtMs.value) return 'Ainda não iniciado';
    const seconds = Math.max(0, Math.floor((now.value - startedAtMs.value) / 1000));
    const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
    const rest = (seconds % 60).toString().padStart(2, '0');
    return `${minutes}:${rest}`;
});
const dueLabel = computed(() => {
    if (!dueAtMs.value) return 'Sem prazo estimado';
    const seconds = Math.floor((dueAtMs.value - now.value) / 1000);
    const absolute = Math.abs(seconds);
    const minutes = Math.floor(absolute / 60).toString().padStart(2, '0');
    const rest = (absolute % 60).toString().padStart(2, '0');
    return seconds >= 0 ? `Estimado: ${minutes}:${rest}` : `Atrasado: ${minutes}:${rest}`;
});

const statusClass = (status) => ({
    ready_for_approval: 'bg-amber-50 text-amber-700 ring-amber-100',
    approved: 'bg-sky-50 text-sky-700 ring-sky-100',
    queued: 'bg-violet-50 text-violet-700 ring-violet-100',
    generating: 'bg-violet-50 text-violet-700 ring-violet-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    failed: 'bg-red-50 text-red-700 ring-red-100',
}[status] ?? 'bg-slate-50 text-slate-600 ring-slate-100');

const copyPack = async () => {
    if (!props.brief.copyPack) return;
    await navigator.clipboard.writeText(props.brief.copyPack);
    copied.value = true;
    window.setTimeout(() => { copied.value = false; }, 1800);
};

const approve = () => {
    router.patch(`/admin/marketplaces/fotos-anuncio/${props.brief.uuid}/aprovar`, {}, { preserveScroll: true });
};

const startGeneration = () => {
    router.post(`/admin/marketplaces/fotos-anuncio/${props.brief.uuid}/gerar-criativos`, {}, { preserveScroll: true });
};

onMounted(() => {
    timer = window.setInterval(() => { now.value = Date.now(); }, 1000);
    if (isRunning.value) {
        poller = window.setInterval(() => {
            router.reload({ only: ['brief'], preserveScroll: true, preserveState: true });
        }, 5000);
    }
});

onUnmounted(() => {
    if (timer) window.clearInterval(timer);
    if (poller) window.clearInterval(poller);
});
</script>

<template>
    <Head :title="`Fotos de Anúncio · ${brief.productName}`" />

    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-orange-400/25 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/3 h-52 w-52 rounded-full bg-emerald-400/10 blur-3xl"></div>

                <div class="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-4xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-orange-100 ring-1 ring-white/15">
                            {{ brief.marketplaceLabel }} · Produto automático
                        </span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">
                            {{ brief.productName }}
                        </h1>
                        <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-300 sm:text-base">
                            {{ brief.categoryHint || 'Categoria não informada' }} · matriz, títulos, descrições, dados técnicos e fila de criativos no mesmo lugar.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/10 p-5 text-sm text-slate-200 backdrop-blur xl:max-w-sm">
                        <p class="font-bold text-white">{{ generationStatus.label }}</p>
                        <p class="mt-2 leading-6">{{ generationStatus.guardrail }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="flashSuccess" class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm">
            {{ flashSuccess }}
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
            <div class="space-y-6">
                <article class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Status da geração</p>
                            <h2 class="mt-2 text-2xl font-black text-slate-900">{{ brief.generationProgressLabel }} criativos prontos</h2>
                        </div>
                        <span class="w-fit rounded-full px-3 py-1 text-xs font-black uppercase ring-1" :class="statusClass(brief.status)">
                            {{ brief.statusLabel }}
                        </span>
                    </div>

                    <div class="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-teal-500" :style="{ width: `${progressPercent}%` }"></div>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Aprovação</p>
                            <p class="mt-1 font-black text-slate-900">{{ brief.approvalLabel }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Tempo rodando</p>
                            <p class="mt-1 font-black text-slate-900">{{ elapsedLabel }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Previsão</p>
                            <p class="mt-1 font-black text-slate-900">{{ dueLabel }}</p>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <button type="button" class="rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-black uppercase tracking-wide text-white shadow-lg transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="brief.approvalStatus === 'approved'" @click="approve">
                            {{ brief.approvalStatus === 'approved' ? 'Matriz aprovada' : 'Aprovar matriz' }}
                        </button>
                        <button type="button" class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-black uppercase tracking-wide text-white shadow-lg transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="isRunning || brief.status === 'completed'" @click="startGeneration">
                            {{ isRunning ? 'Fila rodando...' : 'Gerar criativos em background' }}
                        </button>
                        <button type="button" class="rounded-2xl bg-slate-100 px-5 py-3 text-sm font-black uppercase tracking-wide text-slate-700 transition hover:bg-slate-200" @click="copyPack">
                            {{ copied ? 'Copiado' : 'Copiar pacote completo' }}
                        </button>
                    </div>
                </article>

                <article class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Matriz das imagens</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-900">Função comercial por arte</h2>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <details v-for="item in brief.matrix" :key="item.key" class="group rounded-2xl border border-slate-100 bg-slate-50 p-4 open:bg-white open:shadow-sm">
                            <summary class="flex cursor-pointer list-none items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-600">Arte {{ item.key }} · {{ item.status || 'pending' }}</p>
                                    <h3 class="mt-1 text-base font-black text-slate-900">{{ item.title }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ item.function }}</p>
                                </div>
                                <span class="mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm group-open:rotate-180">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </summary>
                            <div v-if="item.image_url" class="mt-4">
                                <img :src="item.image_url" :alt="`Arte ${item.key}`" class="w-full rounded-2xl object-cover ring-1 ring-slate-100">
                            </div>
                            <div class="mt-4 rounded-2xl bg-slate-950 p-4 text-xs leading-6 text-slate-100">
                                <pre class="whitespace-pre-wrap font-sans">{{ item.prompt }}</pre>
                            </div>
                        </details>
                    </div>
                </article>
            </div>

            <aside class="space-y-6">
                <article v-if="brief.image" class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Foto original</p>
                    <img :src="brief.image.url" :alt="brief.image.originalName" class="mt-4 max-h-80 w-full rounded-2xl object-contain ring-1 ring-slate-100">
                </article>

                <article class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Produto gerado</p>
                    <div class="mt-4 space-y-4 text-sm leading-6 text-slate-600">
                        <div>
                            <p class="font-black text-slate-900">Título Shopee</p>
                            <p>{{ brief.generatedProduct.shopeeTitle }}</p>
                        </div>
                        <div>
                            <p class="font-black text-slate-900">Título Mercado Livre</p>
                            <p>{{ brief.generatedProduct.mercadoLivreTitle }}</p>
                        </div>
                        <details class="rounded-2xl bg-slate-50 p-4">
                            <summary class="cursor-pointer font-black text-slate-900">Descrição Shopee com emojis</summary>
                            <pre class="mt-3 whitespace-pre-wrap font-sans text-xs leading-5">{{ brief.generatedProduct.shopeeDescription }}</pre>
                        </details>
                        <details class="rounded-2xl bg-slate-50 p-4">
                            <summary class="cursor-pointer font-black text-slate-900">Descrição Mercado Livre sem emojis</summary>
                            <pre class="mt-3 whitespace-pre-wrap font-sans text-xs leading-5">{{ brief.generatedProduct.mercadoLivreDescription }}</pre>
                        </details>
                    </div>
                </article>

                <article class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Dados fiscais e técnicos</p>
                    <div class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                        <p class="font-black">Pendência segura</p>
                        <p class="mt-1">NCM, GTIN, origem, peso e dimensões fiscais não são inventados. O botão de publicar deve continuar bloqueado até esses campos existirem na base.</p>
                    </div>
                    <ul class="mt-4 space-y-2 text-sm text-slate-600">
                        <li v-for="fact in brief.generatedProduct.technicalData" :key="fact" class="flex gap-2">
                            <span>•</span><span>{{ fact }}</span>
                        </li>
                    </ul>
                </article>

                <article v-if="brief.warnings?.length" class="rounded-3xl border border-amber-100 bg-amber-50 p-5 shadow-xl shadow-slate-200/70">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-amber-700">Atenção antes de publicar</p>
                    <ul class="mt-3 space-y-2 text-sm text-amber-800">
                        <li v-for="warning in brief.warnings" :key="warning" class="flex gap-2">
                            <span>•</span><span>{{ warning }}</span>
                        </li>
                    </ul>
                </article>

                <article class="rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Enviar para marketplace</p>
                    <p class="mt-3 text-sm leading-6 text-slate-600">
                        O formato antigo vira botões diretos, mas publicar de verdade exige o último gate: dados fiscais/logísticos completos e OK final do Lira.
                    </p>
                    <div class="mt-4 grid gap-3">
                        <button type="button" disabled class="rounded-2xl bg-slate-100 px-5 py-3 text-sm font-black uppercase tracking-wide text-slate-400">
                            Enviar para Shopee · driver pendente
                        </button>
                        <button type="button" disabled class="rounded-2xl bg-slate-100 px-5 py-3 text-sm font-black uppercase tracking-wide text-slate-400">
                            Enviar para Mercado Livre · aguardando gate fiscal
                        </button>
                    </div>
                </article>

                <Link href="/admin/marketplaces/fotos-anuncio" class="block rounded-2xl bg-slate-950 px-5 py-4 text-center text-sm font-black uppercase tracking-wide text-white shadow-xl transition hover:bg-emerald-700">
                    Voltar para listagem
                </Link>
            </aside>
        </section>
    </AdminLayout>
</template>
