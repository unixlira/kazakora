<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, toRef } from 'vue';
import { usePollWhilePending } from '@/Shared/usePollWhilePending';

const props = defineProps({
    briefs: {
        type: Object,
        default: () => ({ data: [] }),
    },
    filters: {
        type: Object,
        default: () => ({ search: '', marketplace: '', status: '' }),
    },
    marketplaces: {
        type: Object,
        default: () => ({}),
    },
    statusOptions: {
        type: Object,
        default: () => ({}),
    },
    generationStatus: {
        type: Object,
        default: () => ({}),
    },
});

usePollWhilePending(toRef(props, 'briefs'), { interval: 5000, only: ['briefs'] });

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = reactive({
    search: props.filters.search ?? '',
    marketplace: props.filters.marketplace ?? '',
    status: props.filters.status ?? '',
});

const applyFilters = () => {
    router.get('/admin/marketplaces/fotos-anuncio', { ...form }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    form.search = '';
    form.marketplace = '';
    form.status = '';
    applyFilters();
};

const statusClass = (status) => ({
    ready_for_approval: 'bg-amber-50 text-amber-700 ring-amber-100',
    approved: 'bg-sky-50 text-sky-700 ring-sky-100',
    queued: 'bg-violet-50 text-violet-700 ring-violet-100',
    generating: 'bg-violet-50 text-violet-700 ring-violet-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    failed: 'bg-red-50 text-red-700 ring-red-100',
}[status] ?? 'bg-slate-50 text-slate-600 ring-slate-100');
</script>

<template>
    <Head title="Fotos de Anúncio" />

    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-orange-400/25 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/3 h-52 w-52 rounded-full bg-emerald-400/10 blur-3xl"></div>

                <div class="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-4xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-orange-100 ring-1 ring-white/15">
                            Marketplaces · Fotos de anúncio
                        </span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">
                            Esteira de criativos por produto
                        </h1>
                        <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-300 sm:text-base">
                            Pesquise por produto ou categoria, abra o card, aprove a matriz e deixe a geração dos criativos em fila persistente.
                        </p>
                    </div>

                    <div class="flex flex-col gap-3 rounded-2xl border border-white/10 bg-white/10 p-5 text-sm text-slate-200 backdrop-blur xl:max-w-sm">
                        <p class="font-bold text-white">{{ generationStatus.label }}</p>
                        <p class="leading-6">{{ generationStatus.mode }}</p>
                        <Link href="/admin/marketplaces/fotos-anuncio/criar" class="rounded-2xl bg-white px-4 py-3 text-center text-xs font-black uppercase tracking-wide text-slate-950 shadow-lg transition hover:bg-orange-50">
                            Criar nova foto de anúncio
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="flashSuccess" class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm">
            {{ flashSuccess }}
        </section>

        <section class="mt-6 rounded-3xl bg-white p-5 shadow-xl shadow-slate-200/70">
            <form class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_220px_auto_auto]" @submit.prevent="applyFilters">
                <input v-model="form.search" type="search" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="Pesquisar por nome, categoria ou ficha técnica">
                <select v-model="form.marketplace" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                    <option value="">Todos marketplaces</option>
                    <option v-for="(marketplace, key) in marketplaces" :key="key" :value="key">{{ marketplace.label }}</option>
                </select>
                <select v-model="form.status" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700 outline-none transition focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                    <option value="">Todos status</option>
                    <option v-for="(label, key) in statusOptions" :key="key" :value="key">{{ label }}</option>
                </select>
                <button type="submit" class="rounded-2xl bg-slate-950 px-5 py-3 text-xs font-black uppercase tracking-wide text-white shadow-lg transition hover:bg-emerald-700">
                    Buscar
                </button>
                <button type="button" class="rounded-2xl bg-slate-100 px-5 py-3 text-xs font-black uppercase tracking-wide text-slate-600 transition hover:bg-slate-200" @click="clearFilters">
                    Limpar
                </button>
            </form>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="brief in briefs.data" :key="brief.uuid" class="rounded-3xl border border-slate-100 bg-white p-5 shadow-xl shadow-slate-200/70">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-600">{{ brief.marketplaceLabel }} · {{ brief.expectedImages }} imagens</p>
                        <h2 class="mt-2 text-lg font-black leading-tight text-slate-900">{{ brief.productName }}</h2>
                        <p class="mt-1 text-xs font-semibold text-slate-400">{{ brief.categoryHint || 'Sem categoria informada' }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-[10px] font-black uppercase ring-1" :class="statusClass(brief.status)">
                        {{ brief.statusLabel }}
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs font-black uppercase tracking-wide text-slate-400">Aprovação</p>
                        <p class="mt-1 font-black text-slate-800">{{ brief.approvalLabel }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs font-black uppercase tracking-wide text-slate-400">Criativos</p>
                        <p class="mt-1 font-black text-slate-800">{{ brief.generationProgressLabel }}</p>
                    </div>
                </div>

                <div class="mt-5 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-teal-500" :style="{ width: `${brief.expectedImages ? Math.round((brief.generatedCount / brief.expectedImages) * 100) : 0}%` }"></div>
                </div>

                <div class="mt-5 flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold text-slate-400">Criado em {{ brief.createdAt }}</p>
                    <Link :href="brief.url" class="rounded-2xl bg-slate-950 px-4 py-3 text-xs font-black uppercase tracking-wide text-white shadow-lg transition hover:bg-emerald-700">
                        Abrir produto
                    </Link>
                </div>
            </article>

            <article v-if="!briefs.data?.length" class="rounded-3xl border border-dashed border-slate-200 bg-white p-8 text-center shadow-xl shadow-slate-200/60 md:col-span-2 xl:col-span-3">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-2xl text-emerald-600">
                    <i class="fas fa-images"></i>
                </span>
                <h2 class="mt-4 text-xl font-black text-slate-900">Nenhum criativo de marketplace ainda</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                    Clique em “Criar nova foto de anúncio”, envie a foto do produto e gere a matriz para aprovação.
                </p>
            </article>
        </section>

        <ServerPagination :paginator="briefs" />
    </AdminLayout>
</template>
