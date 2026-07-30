<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    icon: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    color: {
        type: String,
        default: 'slate',
    },
    href: {
        type: String,
        default: null,
    },
});

defineEmits(['click']);

const COLOR_CLASSES = {
    slate: 'bg-slate-100 text-slate-600 hover:bg-slate-200',
    blue: 'bg-blue-50 text-blue-600 hover:bg-blue-100',
    red: 'bg-red-50 text-red-600 hover:bg-red-100',
    amber: 'bg-amber-50 text-amber-600 hover:bg-amber-100',
    violet: 'bg-violet-50 text-violet-600 hover:bg-violet-100',
};
</script>

<template>
    <Link v-if="href" :href="href" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate">
        <i class="fas text-sm" :class="icon"></i>
    </Link>
    <button v-else type="button" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate"
        @click="$emit('click')">
        <i class="fas text-sm" :class="icon"></i>
    </button>
</template>
