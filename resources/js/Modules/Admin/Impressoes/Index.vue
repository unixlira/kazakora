<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    cards: { type: Array, default: () => [] },
    totalGeral: { type: Number, default: 0 },
});

const CARD_STYLES = {
    yellow: 'bg-yellow-50 border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-900/50',
    purple: 'bg-purple-50 border-purple-200 dark:bg-purple-900/20 dark:border-purple-900/50',
    green: 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-900/50',
    red: 'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-900/50',
};

const ICON_STYLES = {
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    purple: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
};

const TEXT_STYLES = {
    yellow: 'text-yellow-700 dark:text-yellow-300',
    purple: 'text-purple-700 dark:text-purple-300',
    green: 'text-green-700 dark:text-green-300',
    red: 'text-red-700 dark:text-red-300',
};
</script>

<template>
    <Head title="Impressões" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Impressões</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Monitoramento dos jobs de impressão consumidos pelo KoraSync (agente nativo). {{ totalGeral }} no total.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link v-for="card in props.cards" :key="card.status" :href="`/admin/impressoes/lista?status=${card.status}`"
                class="flex flex-col rounded-xl border p-5 shadow-sm transition-shadow hover:shadow-md"
                :class="CARD_STYLES[card.color]">
                <div class="flex items-start justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full" :class="ICON_STYLES[card.color]">
                        <i :class="card.icon" class="text-xl"></i>
                    </div>
                    <span class="text-3xl font-bold" :class="TEXT_STYLES[card.color]">{{ card.total }}</span>
                </div>

                <h3 class="mt-4 text-lg font-semibold">{{ card.label }}</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ card.description }}</p>
            </Link>
        </div>

        <div class="mt-6">
            <Link href="/admin/impressoes/lista" class="text-sm text-primary hover:underline">
                Ver todas as impressões &rarr;
            </Link>
        </div>
    </AdminLayout>
</template>
