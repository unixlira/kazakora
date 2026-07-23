<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <section class="rounded-2xl border border-store-border bg-store-bg-raised p-6 lg:p-7">
        <h2 class="mb-4 text-lg font-semibold">{{ title }}</h2>
        <div class="space-y-3 text-sm leading-relaxed text-store-fg-muted">
            <slot />
        </div>
    </section>
</template>
