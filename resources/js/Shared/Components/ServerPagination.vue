<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    paginator: {
        type: Object,
        required: true,
    },
});

const hasPages = () => Array.isArray(props.paginator?.links) && props.paginator.links.length > 3;
</script>

<template>
    <nav
        v-if="hasPages()"
        class="mt-4 flex flex-col items-center justify-between gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-3 text-sm shadow-sm sm:flex-row"
        aria-label="Paginação"
    >
        <p class="text-slate-500">
            Mostrando
            <span class="font-semibold text-[var(--foreground)]">{{ paginator.from ?? 0 }}-{{ paginator.to ?? 0 }}</span>
            de
            <span class="font-semibold text-[var(--foreground)]">{{ paginator.total ?? 0 }}</span>
        </p>

        <div class="flex flex-wrap justify-center gap-1">
            <template v-for="link in paginator.links" :key="`${link.label}-${link.url}`">
                <span
                    v-if="!link.url"
                    class="rounded-lg border border-transparent px-3 py-1.5 text-slate-400"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="rounded-lg border px-3 py-1.5 transition"
                    :class="link.active
                        ? 'border-primary bg-primary text-white'
                        : 'border-[var(--surface-border)] text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300'"
                    v-html="link.label"
                />
            </template>
        </div>
    </nav>
</template>
